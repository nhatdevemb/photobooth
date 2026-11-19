<?php

declare(strict_types=1);

namespace Photobooth\Operator\Share;

use PDO;
use Photobooth\Operator\Event\EventSettings;
use Photobooth\Operator\Config\AppConfig;
use Photobooth\Operator\Storage\AssetStore;
use Photobooth\Operator\Storage\AuditLogger;
use Photobooth\Operator\Storage\Database;
use Photobooth\Operator\Support\ApiException;
use Photobooth\Operator\Support\Clock;
use Photobooth\Operator\Support\Uuid;

final class ShareService
{
    public const PUBLIC_INVALID_MESSAGE = 'Liên kết tải ảnh không tồn tại, đã hết hạn hoặc đã bị thu hồi.';

    private Database $db;
    private AppConfig $config;
    private AssetStore $assetStore;

    public function __construct(Database $db, AppConfig $config, AssetStore $assetStore)
    {
        $this->db = $db;
        $this->config = $config;
        $this->assetStore = $assetStore;
    }

    /**
     * Deterministically construct the signed token from shareId and finalAssetId using SHARE_SIGNING_KEY.
     * Allows reconstructing the exact URL on idempotent API replay without storing plaintext tokens in SQLite.
     */
    public function buildSignedToken(string $shareId, string $finalAssetId): string
    {
        $mac = hash_hmac('sha256', $shareId . ':' . $finalAssetId, $this->config->shareSigningKey);
        return $shareId . '.' . $mac;
    }

    public function buildShareUrl(string $signedToken): string
    {
        AppConfig::validateShareBaseUrl($this->config->shareBaseUrl);
        return rtrim($this->config->shareBaseUrl, '/') . '/share/' . rawurlencode($signedToken);
    }

    public function buildDownloadUrl(string $signedToken): string
    {
        AppConfig::validateShareBaseUrl($this->config->shareBaseUrl);
        return rtrim($this->config->shareBaseUrl, '/') . '/share/' . rawurlencode($signedToken) . '/download';
    }

    /**
     * Render a local offline QR code PNG data URI using the bundled phpqrcode library (or fallback SVG).
     */
    public function generateOfflineQrDataUri(string $url): string
    {
        $qrLibPath = '';
        foreach ([$this->config->customRoot . '/desktop/runtime/phpqrcode/lib/full/qrlib.php',
            $this->config->projectRoot . '/vendor/phpqrcode/lib/full/qrlib.php',
            $this->config->projectRoot . '/vendor/phpqrcode/qrlib.php'] as $candidate) {
            if (is_file($candidate)) { $qrLibPath = $candidate; break; }
        }
        if (is_file($qrLibPath)) {
            require_once $qrLibPath;
            if (class_exists('\QRcode')) {
                if (function_exists('imagecreate')) {
                    ob_start();
                    \QRcode::png($url, false, QR_ECLEVEL_M, 6, 4);
                    $pngBytes = (string) ob_get_clean();
                    if ($pngBytes !== '') {
                        return 'data:image/png;base64,' . base64_encode($pngBytes);
                    }
                }
                // Pure-PHP ISO/IEC 18004 QR matrix via phpqrcode QRcode::text() rendered as SVG
                $matrix = \QRcode::text($url, false, QR_ECLEVEL_M);
                if (is_array($matrix) && $matrix !== []) {
                    $size = count($matrix);
                    $margin = 4;
                    $total = $size + ($margin * 2);
                    $rects = '';
                    foreach ($matrix as $r => $rowStr) {
                        $len = strlen((string) $rowStr);
                        for ($c = 0; $c < $len; $c++) {
                            if ($rowStr[$c] === '1') {
                                $x = $c + $margin;
                                $y = $r + $margin;
                                $rects .= "<rect x=\"{$x}\" y=\"{$y}\" width=\"1\" height=\"1\" fill=\"#000\"/>";
                            }
                        }
                    }
                    $svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 {$total} {$total}\" shape-rendering=\"crispEdges\"><rect width=\"{$total}\" height=\"{$total}\" fill=\"#fff\"/>{$rects}</svg>";
                    return 'data:image/svg+xml;base64,' . base64_encode($svg);
                }
            }
        }

        throw new ApiException('QR_UNAVAILABLE', 'Thiếu thư viện QR thật. Chuẩn bị runtime hoặc cài dependency QR trước khi phát hành mã chia sẻ.', 503);
    }

    /**
     * Resolve and validate a guest share token for viewing or downloading.
     * Throws uniform 404 NOT_FOUND with PUBLIC_INVALID_MESSAGE on any failure so no session/path info leaks.
     *
     * @return array{
     *   share_id: string,
     *   session_id: string,
     *   final_asset_id: string,
     *   final_abs_path: string,
     *   sha256: string,
     *   bytes: int,
     *   width: int,
     *   height: int,
     *   expires_at: string
     * }
     */
    public function resolveValidToken(string $rawToken): array
    {
        $trimmed = trim(rawurldecode($rawToken));
        $parts = explode('.', $trimmed, 2);
        if (count($parts) !== 2) {
            $this->throwPublicInvalid();
        }

        [$shareId, $providedMac] = $parts;
        if (!Uuid::isValid($shareId) || preg_match('/^[0-9a-f]{64}$/i', $providedMac) !== 1) {
            $this->throwPublicInvalid();
        }

        $tokenHash = hash('sha256', $trimmed);
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            'SELECT st.id AS share_id, st.session_id, st.final_asset_id, st.token_hash, st.expires_at, st.revoked_at,
                    s.event_configuration, s.deleted_at AS session_deleted_at, s.expires_at AS session_expires_at,
                    fa.path AS final_path, fa.sha256, fa.bytes, fa.width, fa.height
             FROM share_tokens st
             JOIN sessions s ON s.id = st.session_id
             JOIN final_assets fa ON fa.id = st.final_asset_id
             WHERE st.id = :id AND st.token_hash = :th'
        );
        $stmt->execute([
            ':id' => $shareId,
            ':th' => $tokenHash,
        ]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            $this->throwPublicInvalid();
        }

        // Verify HMAC signature with current SHARE_SIGNING_KEY
        $expectedToken = $this->buildSignedToken((string) $row['share_id'], (string) $row['final_asset_id']);
        if (!hash_equals($expectedToken, $trimmed)) {
            $this->throwPublicInvalid();
        }

        // Check revocation, tombstone deletion, and expiry
        if ($row['revoked_at'] !== null || $row['session_deleted_at'] !== null) {
            $this->throwPublicInvalid();
        }

        $nowUnix = Clock::nowUnix();
        if (Clock::toUnix((string) $row['expires_at']) <= $nowUnix) {
            $this->throwPublicInvalid();
        }

        if ($row['session_expires_at'] !== null && Clock::toUnix((string) $row['session_expires_at']) <= $nowUnix) {
            $this->throwPublicInvalid();
        }

        // Verify file integrity on disk before serving to guest (IT-11)
        try {
            $absPath = $this->assetStore->verifyAssetIntegrity(
                (string) $row['final_path'],
                (string) $row['sha256'],
                $pdo,
                (string) $row['session_id']
            );
        } catch (ApiException) {
            $this->throwPublicInvalid();
        }

        return [
            'event' => EventSettings::snapshot($row['event_configuration']),
            'share_id' => (string) $row['share_id'],
            'session_id' => (string) $row['session_id'],
            'final_asset_id' => (string) $row['final_asset_id'],
            'final_abs_path' => $absPath,
            'sha256' => (string) $row['sha256'],
            'bytes' => (int) $row['bytes'],
            'width' => (int) $row['width'],
            'height' => (int) $row['height'],
            'expires_at' => (string) $row['expires_at'],
        ];
    }

    /**
     * Render self-contained offline HTML viewer page for a guest with a valid token.
     * Contains zero external fonts, scripts, analytics, or CDN links (Section 9.2 & IT-17).
     */
    public function renderGuestViewerHtml(string $rawToken, array $resolved): string
    {
        $downloadPath = '/share/' . rawurlencode(trim($rawToken)) . '/download';
        $inlineImgPath = $downloadPath . '?inline=1';
        $expiresLocal = htmlspecialchars(
            Clock::formatLocal($resolved['expires_at'], $this->config->appTimezone),
            ENT_QUOTES,
            'UTF-8'
        );
        $dims = (int) $resolved['width'] . ' × ' . (int) $resolved['height'] . ' px';
        $sizeKb = number_format(((int) $resolved['bytes']) / 1024, 1);

        $event = $resolved['event'];
        $heading = htmlspecialchars($event['name'] ?: 'Ảnh kỷ niệm của bạn', ENT_QUOTES, 'UTF-8');
        $footer = htmlspecialchars($event['footer'], ENT_QUOTES, 'UTF-8');
        $accent = $event['color'];
        $brand = '';
        foreach (['logo', 'banner'] as $kind) {
            $image = EventSettings::brandImage($this->db->pdo(), $event[$kind]);
            if ($image !== null) {
                $brand .= '<img class="event-' . $kind . '" src="' . htmlspecialchars($image, ENT_QUOTES, 'UTF-8') . '" alt="">';
            }
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex,nofollow">
    <title>{$heading}</title>
    <style>
        :root {
            --bg: #0f172a;
            --card: #1e293b;
            --text: #f8fafc;
            --muted: #94a3b8;
            --accent: {$accent};
            --accent-hover: #2563eb;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: "Be Vietnam Pro", "Plus Jakarta Sans", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
            font-kerning: normal;
            text-rendering: optimizeLegibility;
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .viewer-card {
            background: var(--card);
            border: 1px solid #334155;
            border-radius: 16px;
            max-width: 460px;
            width: 100%;
            padding: 24px;
            text-align: center;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.4);
        }
        h1 { font-size: 1.3rem; font-weight: 700; letter-spacing: -0.015em; line-height: 1.3; margin-bottom: 6px; text-wrap: balance; }
        .sub { color: var(--muted); font-size: 0.9rem; line-height: 1.55; margin-bottom: 18px; }
        .photo-frame {
            background: #090d16;
            padding: 12px;
            border-radius: 12px;
            margin-bottom: 18px;
        }
        .photo-frame img {
            max-width: 100%;
            height: auto;
            border-radius: 6px;
            display: block;
            margin: 0 auto;
        }
        .btn-download {
            display: inline-block;
            width: 100%;
            background: var(--accent);
            color: #fff;
            text-decoration: none;
            font-weight: 600;
            font-size: 1rem;
            padding: 14px 20px;
            border-radius: 10px;
            transition: background 0.15s ease;
        }
        .btn-download:hover { filter: brightness(0.9); }
        .event-logo { display: block; width: auto; max-width: 40%; max-height: 64px; margin: 0 0 12px; }
        .event-banner { display: block; width: 100%; max-height: 160px; object-fit: contain; margin-bottom: 12px; }
        .meta {
            margin-top: 14px;
            font-size: 0.78rem;
            color: var(--muted);
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <main class="viewer-card">
        {$brand}
        <h1>{$heading}</h1>
        <p class="sub">Nhấn nút bên dưới để lưu ảnh chất lượng gốc về điện thoại</p>
        <div class="photo-frame">
            <img src="{$inlineImgPath}" alt="Ảnh Photobooth hoàn thiện">
        </div>
        <a class="btn-download" href="{$downloadPath}" download="photobooth-photo.jpg">Tải ảnh JPEG chất lượng cao ({$sizeKb} KB)</a>
        <div class="meta">
            Độ phân giải: {$dims} (sRGB 300 DPI)<br>
            Hạn tải: {$expiresLocal}<br>
            {$footer}
        </div>
    </main>
</body>
</html>
HTML;
    }

    public function renderPublicErrorHtml(): string
    {
        $msg = htmlspecialchars(self::PUBLIC_INVALID_MESSAGE, ENT_QUOTES, 'UTF-8');
        return <<<HTML
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <title>Không tìm thấy ảnh</title>
    <style>
        body {
            font-family: "Be Vietnam Pro", "Plus Jakarta Sans", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #0f172a;
            color: #f8fafc;
            line-height: 1.6;
            text-rendering: optimizeLegibility;
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            text-align: center;
        }
        .box {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 14px;
            padding: 28px;
            max-width: 420px;
        }
        h1 { font-size: 1.25rem; font-weight: 700; letter-spacing: -0.015em; line-height: 1.3; margin-bottom: 10px; color: #f87171; }
        p { color: #cbd5e1; font-size: 0.95rem; line-height: 1.6; }
    </style>
</head>
<body>
    <div class="box">
        <h1>Không thể truy cập liên kết</h1>
        <p>{$msg}</p>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Rotate signing key & revoke all active tokens (Section 9.3).
     */
    public function revokeAllActiveTokens(?string $actorId = null): int
    {
        $now = Clock::nowIso();
        return $this->db->transaction(static function (PDO $pdo) use ($now, $actorId): int {
            $stmt = $pdo->prepare('UPDATE share_tokens SET revoked_at = :now WHERE revoked_at IS NULL');
            $stmt->execute([':now' => $now]);
            $count = $stmt->rowCount();
            AuditLogger::record($pdo, 'share.revoke_all', 'SUCCEEDED', $actorId, null, null, [
                'revoked_count' => $count,
            ]);
            return $count;
        });
    }

    private function throwPublicInvalid(): never
    {
        throw new ApiException('NOT_FOUND', self::PUBLIC_INVALID_MESSAGE, 404);
    }
}

