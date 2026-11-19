<?php

declare(strict_types=1);

use Photobooth\Operator\Container;
use Photobooth\Operator\Http\HttpKernel;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$listenerMode = strtolower((string) (getenv('PHOTOBOOTH_LISTENER_MODE') ?: ($_SERVER['PHOTOBOOTH_LISTENER_MODE'] ?? 'operator')));
if ($listenerMode === 'guest') {
    require __DIR__ . '/guest.php';
    exit;
}

$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = '/' . ltrim((string) parse_url($requestUri, PHP_URL_PATH), '/');
if ($path !== '/') {
    $path = rtrim($path, '/');
}

// Serve static assets from custom/public/assets or custom/presets
$staticTarget = null;
$allowedBase = null;

if (str_starts_with($path, '/custom/assets/')) {
    $rel = substr($path, strlen('/custom/assets/'));
    $allowedBase = realpath(__DIR__ . '/assets');
    if ($allowedBase !== false) {
        $staticTarget = realpath($allowedBase . '/' . $rel);
    }
} elseif (str_starts_with($path, '/assets/')) {
    $rel = substr($path, strlen('/assets/'));
    $allowedBase = realpath(__DIR__ . '/assets');
    if ($allowedBase !== false) {
        $staticTarget = realpath($allowedBase . '/' . $rel);
    }
} elseif (str_starts_with($path, '/custom/presets/')) {
    $rel = substr($path, strlen('/custom/presets/'));
    $allowedBase = realpath(dirname(__DIR__) . '/presets');
    if ($allowedBase !== false) {
        $staticTarget = realpath($allowedBase . '/' . $rel);
    }
} elseif (str_starts_with($path, '/presets/')) {
    $rel = substr($path, strlen('/presets/'));
    $allowedBase = realpath(dirname(__DIR__) . '/presets');
    if ($allowedBase !== false) {
        $staticTarget = realpath($allowedBase . '/' . $rel);
    }
}

if (
    $staticTarget !== null
    && $staticTarget !== false
    && $allowedBase !== null
    && $allowedBase !== false
    && str_starts_with($staticTarget, $allowedBase . DIRECTORY_SEPARATOR)
    && is_file($staticTarget)
) {
    $ext = strtolower(pathinfo($staticTarget, PATHINFO_EXTENSION));
    $mimeMap = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'mjs' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
        'ttf' => 'font/ttf',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];
    if (!isset($mimeMap[$ext])) {
        http_response_code(403);
        echo 'Forbidden static file type';
        exit;
    }

    header('Content-Type: ' . $mimeMap[$ext]);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-cache');
    readfile($staticTarget);
    exit;
}

// Route /custom/api/* and /share/* through HttpKernel
if (str_starts_with($path, '/custom/api/') || str_starts_with($path, '/share/')) {
    $container = Container::boot();
    $kernel = new HttpKernel($container);

    $headers = [];
    if (function_exists('getallheaders')) {
        foreach ((array) getallheaders() as $k => $v) {
            $headers[(string) $k] = (string) $v;
        }
    } else {
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = str_replace('_', '-', strtolower(substr($k, 5)));
                $headers[$name] = (string) $v;
            }
        }
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443);

    $rawBody = file_get_contents('php://input', false, null, 0, 30 * 1024 * 1024 + 1);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('#^/custom/api/sessions/[^/]+/slots/[^/]+/(capture|retake)$#D', $path) && str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data')) {
        $upload = $_FILES['file'] ?? [];
        $uploadContent = null;
        if (is_string($upload['tmp_name'] ?? null) && is_uploaded_file($upload['tmp_name'])) {
            $uploadContent = file_get_contents($upload['tmp_name'], false, null, 0, 5 * 1024 * 1024 + 1);
        }
        $rawBody = [
            'revision' => isset($_POST['revision']) && is_string($_POST['revision']) && ctype_digit($_POST['revision']) ? (int) $_POST['revision'] : null,
            'file' => ['error' => $upload['error'] ?? UPLOAD_ERR_NO_FILE, 'content' => $uploadContent],
        ];
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($path === '/custom/api/admin/luts/import' || (preg_match('#^/custom/api/admin/luts/[a-z0-9-]+/[0-9.]+/draft$#D', $path) && str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data')))) {
        $upload = $_FILES['file'] ?? [];
        $uploadContent = null;
        if (is_string($upload['tmp_name'] ?? null) && is_uploaded_file($upload['tmp_name'])) {
            $uploadContent = file_get_contents($upload['tmp_name'], false, null, 0, 5 * 1024 * 1024 + 1);
        }
        $rawBody = ['file'=>['name'=>$upload['name'] ?? '', 'error'=>$upload['error'] ?? UPLOAD_ERR_NO_FILE, 'content'=>$uploadContent]];
        if ($path !== '/custom/api/admin/luts/import') {
            $rawBody['revision'] = isset($_POST['revision']) && is_string($_POST['revision']) && ctype_digit($_POST['revision']) ? (int) $_POST['revision'] : null;
            $rawBody['intensity'] = isset($_POST['intensity']) && is_string($_POST['intensity']) && ctype_digit($_POST['intensity']) ? (int) $_POST['intensity'] : null;
            $rawBody['name'] = $_POST['name'] ?? null;
        }
    }
    $response = $kernel->handle([
        'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        'path' => $requestUri,
        'query' => $_GET,
        'headers' => $headers,
        'cookies' => $_COOKIE,
        'body' => $rawBody !== false ? $rawBody : null,
        'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
        'listener_mode' => 'operator',
        'https' => $isHttps,
    ]);

    http_response_code($response['status']);
    foreach ($response['headers'] as $name => $value) {
        header("{$name}: {$value}");
    }
    foreach ($response['cookies'] as $cookie) {
        setcookie(
            (string) $cookie['name'],
            (string) $cookie['value'],
            [
                'expires' => (int) ($cookie['expires'] ?? 0),
                'path' => (string) ($cookie['path'] ?? '/'),
                'httponly' => (bool) ($cookie['httponly'] ?? true),
                'samesite' => (string) ($cookie['samesite'] ?? 'Lax'),
                'secure' => (bool) ($cookie['secure'] ?? false),
            ]
        );
    }

    if ($response['file_path'] !== null && is_file($response['file_path'])) {
        readfile($response['file_path']);
        exit;
    }

    echo $response['body'];
    exit;
}

// Render Vietnamese Operator UI Shell on /, /custom, /index.php
if (!in_array($path, ['/', '/custom', '/index.php'], true)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => [
            'code' => 'NOT_FOUND',
            'message' => 'Không tìm thấy trang yêu cầu.',
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$container = Container::boot();
$cameraMode = $container->config->cameraAdapter;
$isBrowserCamera = $cameraMode === 'browser';
$appVersion = htmlspecialchars($container->config->appVersion, ENT_QUOTES, 'UTF-8');
$upstreamSha = htmlspecialchars(substr($container->config->upstreamSha, 0, 10), ENT_QUOTES, 'UTF-8');

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Photobooth — Chụp ảnh lưu niệm</title>
    <link rel="stylesheet" href="/custom/assets/css/operator.css">
</head>
<body data-mock="<?= $cameraMode === 'mock' ? 'true' : 'false' ?>" data-camera="<?= htmlspecialchars($cameraMode, ENT_QUOTES, 'UTF-8') ?>" data-countdown="<?= $container->config->countdownSeconds ?>">
<div class="sim-banner" role="status">Camera: <?= $isBrowserCamera ? 'laptop / điện thoại (trình duyệt)' : ($cameraMode === 'mock' ? 'ảnh MÔ PHỎNG (MOCK)' : ($cameraMode === 'digicamcontrol' ? 'Canon qua digiCamControl' : 'máy ảnh gphoto2')) ?> · Máy in: <?= $container->config->printerAdapter === 'mock' ? 'MÔ PHỎNG (MOCK) — không in giấy thật' : 'phần cứng' ?> · <?= $appVersion ?> · <?= $upstreamSha ?></div>
<header class="app-header">
    <div class="brand"><span aria-hidden="true">📸</span> Photobooth <span class="brand-sparkle" aria-hidden="true">✦</span></div>
    <nav id="wizard-nav" class="step-nav" aria-label="Các bước chụp ảnh" hidden>
        <button type="button" data-step="1">1. Bố cục</button><span>—</span>
        <button type="button" data-step="2">2. Chụp ảnh</button><span>—</span>
        <button type="button" data-step="3">3. Filter</button><span>—</span>
        <button type="button" data-step="4">4. Template</button><span>—</span>
        <button type="button" data-step="5">5. Duyệt / QR</button>
    </nav>
</header>
<div id="auth-user-bar" class="utility-bar" hidden>
    <span id="current-user-display"></span><span id="current-role-badge"></span>
    <button id="btn-nav-workspace" type="button">Bàn chụp</button>
    <button id="btn-nav-history" type="button">Lịch sử</button>
    <button id="btn-nav-admin" type="button" hidden>Quản lý template</button>
    <button id="btn-nav-luts" type="button" hidden>Quản lý filter</button>
    <button id="btn-nav-settings" type="button" hidden>Cài đặt sự kiện</button>
    <button id="btn-nav-health" type="button">Trạng thái hệ thống</button>
    <button id="btn-logout" type="button">Đăng xuất</button>
</div>
<aside id="health-panel" class="panel health-panel" hidden><h2>Trạng thái hệ thống</h2><div id="health-summary">Đang kiểm tra…</div><p id="health-lkmagic"></p><p id="health-last-beauty"></p><p id="health-beauty-test" aria-live="polite"></p><button id="btn-beauty-test" type="button">Chạy thử Beauty</button><div id="health-warnings"></div></aside>
<div id="toast-container" class="toast-container" role="status" aria-live="polite"></div>
<main id="login-view" class="login-wrap">
    <form id="login-form" class="panel login-card"><span class="eyebrow">CHÀO MỪNG ĐẾN BÀN CHỤP</span><h1>Lưu lại khoảnh khắc của bạn ✨</h1>
        <p>Đăng nhập để bắt đầu một phiên chụp mới.</p>
        <label>Tên đăng nhập<input id="login-username" autocomplete="username" required></label>
        <label>Mật khẩu<input id="login-password" type="password" autocomplete="current-password" required></label>
        <p id="login-error" class="alert error" hidden></p><button id="btn-login-submit" class="primary" type="submit">Đăng nhập →</button>
    </form>
</main>
<main id="workspace-view" class="workspace" hidden>
    <div class="session-toolbar"><span id="session-code-display">Chưa có phiên đang mở</span><span id="session-state-badge"></span><strong id="session-event-name" hidden></strong>
        <button id="btn-restart-session" type="button" hidden>Bắt đầu lại</button>
    </div>
    <p id="configuration-summary" class="muted" hidden></p>
    <div id="workspace-stage" class="workspace-stage">
        <div id="liveview-panel" class="camera-column"><div class="live-preview-box"><img id="live-preview-img" src="/custom/assets/mock-preview/frame-1.jpg" alt="Khung ngắm trực tiếp" <?= $cameraMode === 'mock' ? '' : 'hidden' ?>><video id="webcam-video" class="mirrored" autoplay muted playsinline hidden></video><canvas id="webcam-lut-canvas" class="mirrored" hidden aria-hidden="true"></canvas><div id="selection-preview-dock" class="preview-column" hidden><div class="paper-wrap"><canvas id="selection-preview" width="1181" height="1748" aria-label="Bản minh họa lựa chọn trên giấy 100 × 148 mm"></canvas></div><p class="preview-caption">Xem trước filter và khung</p></div><span id="camera-badge" class="camera-badge"><?= $isBrowserCamera ? 'Camera chưa bật' : ($cameraMode === 'mock' ? '● Khung ngắm MÔ PHỎNG' : ($cameraMode === 'digicamcontrol' ? 'Live view R10 chưa bật' : 'Live view thiết bị chưa được hỗ trợ')) ?></span><div id="crop-guide" class="crop-guide" aria-label="Vùng ảnh được giữ sau khi crop"></div><div id="countdown-overlay" class="countdown-overlay" hidden><strong id="countdown-number">…</strong><span id="countdown-label">Đang xử lý</span></div></div><p class="preview-caption"><?= $isBrowserCamera ? 'Live view bật cả lúc đếm ngược. Filter và template chọn sau khi chụp.' : ($cameraMode === 'mock' ? 'Khung ngắm mô phỏng. Chọn filter và template sau khi chụp.' : 'Căn khung trên máy ảnh; vùng nét đứt minh họa tỷ lệ crop.') ?></p>
        <div id="dcc-controls" class="webcam-controls" <?= $cameraMode === 'digicamcontrol' ? '' : 'hidden' ?>><button id="btn-dcc-enable" type="button">Bật live view R10</button><button id="btn-dcc-stop" type="button">Tắt live view</button><span id="dcc-status" role="status">Mở digiCamControl, kết nối USB và bật Webserver.</span></div>
        <div id="webcam-controls" class="webcam-controls" <?= $isBrowserCamera ? '' : 'hidden' ?>><button id="btn-webcam-enable" type="button">Bật camera</button><button id="btn-webcam-switch" type="button">Đổi trước / sau</button><button id="btn-webcam-mirror" type="button" class="outline" aria-pressed="true">Lật gương: Bật</button><label><span class="sr-only">Camera</span><select id="webcam-device" aria-label="Chọn camera"><option value="">Camera mặc định</option></select></label><button id="btn-webcam-stop" type="button">Tắt camera</button></div><p id="webcam-status" class="preview-caption" role="status" <?= $isBrowserCamera ? '' : 'hidden' ?>>Bật camera và cho phép trình duyệt truy cập. Điện thoại qua Wi-Fi cần HTTPS được tin cậy.</p></div>
    <div class="workflow-column">
    <section id="empty-session" class="panel empty-state"><span class="hero-icon">✦</span><h1>Một tấm ảnh, nhiều kỷ niệm</h1><p>Chọn bố cục và chụp ảnh trước, rồi chọn filter và template bạn thích.</p><button id="btn-new-session" type="button" class="primary">+ Tạo phiên chụp mới</button></section>
    <section id="selection-panel" class="wizard-grid" hidden>

        <div class="choices-column"><h1 id="selection-title"></h1><p id="selection-description" class="subtitle"></p><label id="filter-search-wrap" class="filter-search" hidden><span class="sr-only">Tìm filter theo tên</span><input id="filter-search" type="search" placeholder="Tìm filter theo tên…" autocomplete="off"></label><div id="selection-cards" class="selection-cards"></div><div id="beauty-settings" class="beauty-box" hidden><h2><span>✦</span> Làm đẹp da (Beauty Retouch)</h2><div class="beauty-presets-row" role="radiogroup" aria-label="Chế độ làm đẹp da"><button type="button" class="beauty-preset-btn" data-beauty-preset="off">Tắt</button><button type="button" class="beauty-preset-btn" data-beauty-preset="natural">Tự nhiên</button><button type="button" class="beauty-preset-btn" data-beauty-preset="soft">Mềm mại</button><button type="button" class="beauty-preset-btn" data-beauty-preset="korean-glow">Hàn Quốc</button></div><details id="beauty-advanced" class="beauty-advanced"><summary style="cursor:pointer;font-size:0.8rem;color:var(--deep);font-weight:600;margin-top:6px;">Tùy chỉnh nâng cao (Smooth · Brighten · Tone)</summary><div class="beauty-sliders"><label>Làm mịn: <span id="val-smooth">20%</span><input id="range-smooth" type="range" min="0" max="100" step="5" value="20"></label><label>Làm sáng: <span id="val-brighten">5%</span><input id="range-brighten" type="range" min="0" max="100" step="5" value="5"></label><label>Đều màu: <span id="val-tone">10%</span><input id="range-tone" type="range" min="0" max="100" step="5" value="10"></label></div></details><p class="beauty-note">Bảo toàn lỗ chân lông, mắt, môi và chân mày. AI xử lý an toàn offline trên thiết bị.</p></div><p id="configuration-note" class="muted"></p></div>
    </section>
    <section id="capture-panel" class="capture-grid" hidden>
        <div class="capture-slots"><h1 id="capture-title">Sẵn sàng tạo dáng nhé!</h1><p class="subtitle">Mỗi ảnh có đếm ngược riêng. Bạn có thể chụp lại từng khung.</p><div id="slots-container"></div><button id="btn-auto-capture-all" type="button" class="primary">Bắt đầu chụp</button><p id="capture-progress" aria-live="polite"></p></div>
    </section>
    <section id="review-panel" class="wizard-grid" hidden>
        <div class="preview-column"><div class="paper-wrap"><img id="collage-preview-img" alt="Bản ảnh ghép sẽ được duyệt" hidden><div id="review-placeholder">Đang dựng ảnh của bạn…</div></div><p class="preview-caption">Ảnh in 100 × 148 mm · sRGB · 300 DPI</p></div>
        <div class="review-actions"><h1>✦ Xem lại và in ảnh</h1><p id="render-status-text" class="subtitle">Kiểm tra bản xem trước trước khi duyệt.</p><p id="review-error" class="alert error" hidden></p>
            <button id="btn-retry-render" type="button" hidden>Dựng lại bản xem trước</button>
            <button id="btn-retry-lkmagic" type="button" hidden>Thử lại với LK Magic</button>
            <button id="btn-finalize-session" type="button" class="primary" disabled>Duyệt ảnh và tạo QR</button>
            <div id="post-finalize-actions" hidden><div class="output-cards"><div class="panel"><h2>In ảnh</h2><p id="print-quota" role="status"></p><div class="print-copies"><button id="print-minus" type="button" aria-label="Giảm số bản">−</button><label>Số bản<input id="print-copies" type="number" min="1" max="10" step="1" value="1"></label><button id="print-plus" type="button" aria-label="Tăng số bản">+</button></div><button id="btn-open-print-modal" type="button" class="primary">In ảnh</button></div><div class="panel qr-panel"><h2>Tải ảnh ghép qua QR</h2><img id="modal-qr-img" alt="QR tải ảnh ghép cuối" hidden><p id="qr-placeholder">Chưa có QR</p><button id="btn-open-qr-modal" type="button">Hiển thị mã QR</button><a id="modal-qr-open-link" target="_blank" rel="noopener">Mở thử</a><p id="modal-qr-url" class="small-url"></p><p id="modal-qr-expires" class="muted"></p><button id="btn-revoke-qr" type="button" hidden>Thu hồi QR</button></div></div></div>
            <div class="print-history"><h2>Lịch sử in</h2><div id="session-prints-list"></div></div>
            <button id="btn-review-retake" type="button">↶ Chụp lại từng ảnh</button><button id="btn-close-session" type="button" class="outline" hidden>✓ Hoàn tất phiên</button>
        </div>
    </section>
    </div></div>
    <footer id="wizard-footer" class="wizard-footer" hidden><button id="btn-back" type="button" class="outline">← Quay lại</button><span id="wizard-progress"></span><button id="btn-next" type="button" class="primary">Tiếp tục →</button></footer>
</main>
<main id="settings-view" class="workspace" hidden>
    <h1>Cài đặt sự kiện</h1>
    <p id="settings-lock" class="alert" hidden>Hãy đóng hoặc hủy phiên hiện tại để sửa cài đặt.</p>
    <form id="event-settings-form" class="event-settings">
        <fieldset id="event-settings-fields">
            <section class="panel event-fields">
                <h2>Nhận diện sự kiện</h2>
                <label>Tên sự kiện<input id="event-name" maxlength="100"></label>
                <label>Màu chủ đạo<input id="event-color" type="color"></label>
                <label>Lời nhắn trên trang QR<textarea id="event-footer" maxlength="200" rows="2"></textarea></label>
                <label>Thời hạn QR (giờ)<input id="event-ttl" type="number" min="1" max="8760" required></label>
                <div class="brand-field"><label>Logo<input id="event-logo" type="file" accept="image/png,image/jpeg"></label><img id="event-logo-preview" alt="Logo sự kiện" hidden><label><input id="event-logo-clear" type="checkbox"> Xóa logo</label></div>
                <div class="brand-field"><label>Banner<input id="event-banner" type="file" accept="image/png,image/jpeg"></label><img id="event-banner-preview" alt="Banner sự kiện" hidden><label><input id="event-banner-clear" type="checkbox"> Xóa banner</label></div>
                <small>PNG hoặc JPEG, tối đa 512 KiB và 2000 × 2000 px mỗi ảnh.</small>
            </section>
            <section class="panel event-fields">
                <h2>Template và chữ trên frame</h2>
                <label>Chủ đề<select id="event-category"><option value="all">Tất cả</option><option value="wedding">Đám cưới</option><option value="birthday">Sinh nhật</option><option value="other">Khác</option></select></label>
                <label>Tên hiển thị<input id="event-frame-name" maxlength="80"></label>
                <label>Ngày<input id="event-frame-date" type="date"></label>
                <label>Lời chúc<input id="event-frame-wish" maxlength="100"></label>
                <label>Mẫu xem trước<select id="event-preview-template"></select></label>
                <button type="button" id="btn-event-preview">Xem trước</button>
                <canvas id="event-frame-preview" width="1181" height="1748" aria-label="Ảnh mẫu của frame sự kiện" hidden></canvas>
            </section>
            <section class="panel event-fields">
                <h2>Hạn mức in</h2>
                <p id="event-quota" role="status"></p>
                <label><input id="event-no-limit" type="checkbox"> Không giới hạn</label>
                <label>Tổng số bản được in<input id="event-print-limit" type="number" min="1" max="1000000" step="1"></label>
            </section>
            <div class="button-row">
                <button id="btn-event-save" type="submit" class="primary">Lưu cấu hình</button>
                <button id="btn-event-new" type="submit">Tạo sự kiện mới</button>
            </div>
        </fieldset>
    </form>
</main>
<main id="history-view" class="workspace" hidden><div class="section-heading"><h1>Lịch sử phiên chụp</h1><button id="btn-refresh-history" type="button">Làm mới</button></div><div class="table-scroll panel"><table><thead><tr><th>Phiên</th><th>Trạng thái</th><th>Bố cục</th><th>Ảnh</th><th>In</th><th>Thao tác</th></tr></thead><tbody id="history-table-body"></tbody></table></div><button id="btn-history-load-more" type="button" hidden>Tải thêm</button></main>
<main id="admin-view" class="workspace" hidden><h1>Thư viện template</h1><div class="panel import-box"><h2>Nhập mẫu mới từ ZIP</h2><p>template.json, overlay.png RGBA 1181 × 1748, preview.jpg và background.png tùy chọn. Tối đa 30 MiB.</p><input id="template-zip" type="file" accept=".zip,application/zip"><button id="btn-import-template" type="button" class="primary">Nhập bản nháp</button><p>Nhập xong, xem thử bản ghép bằng ảnh mẫu rồi mới kích hoạt.</p></div><div id="admin-template-list" class="admin-template-list"></div></main>
<main id="luts-view" class="workspace" hidden><h1>Thư viện filter LUT</h1><div class="panel import-box"><h2>Nhập filter từ máy</h2><p>Chọn tối đa 30 file .cube mỗi lượt, 5 MiB/file. Hỗ trợ LUT 3D size 33, domain 0–1 cho ảnh đã xử lý màu.</p><input id="lut-files" type="file" accept=".cube" multiple><button id="btn-import-luts" type="button" class="primary">Nhập các file đã chọn</button><p>File được nhập nháp riêng. Xem thử màu trên ảnh mẫu, chỉnh cường độ rồi kích hoạt.</p><ol id="lut-import-results" class="lut-import-results" aria-live="polite"></ol></div><div id="admin-lut-list" class="admin-template-list"></div></main>
<div id="modal-lut-editor" class="modal-backdrop" hidden><section class="panel modal lut-modal" role="dialog" aria-modal="true" aria-labelledby="lut-editor-title"><h2 id="lut-editor-title">Xem thử filter</h2><p id="lut-editor-meta" class="muted"></p><div class="lut-fields"><label>Tên filter<input id="lut-name" maxlength="100"></label><label>Cường độ: <output id="lut-amount">80%</output><input id="lut-intensity" type="range" min="0" max="100" step="1" value="80"></label></div><label id="lut-replacement-wrap">Thay file cho bản nháp (tùy chọn)<input id="lut-replacement" type="file" accept=".cube"></label><div class="lut-comparison"><figure><figcaption>Ảnh gốc</figcaption><img src="/custom/assets/samples/source-portrait.jpg" alt="Ảnh chân dung mẫu gốc"></figure><figure><figcaption>Sau khi áp filter</figcaption><img id="lut-after" alt="Ảnh mẫu với filter và cường độ đang xem"></figure></div><p id="lut-preview-state" role="status">Đang tải ảnh mẫu…</p><p class="muted">Ảnh mẫu AI minh họa · Pipeline ảnh sRGB của Photobooth. Hãy duyệt màu thực tế trước khi sử dụng.</p><div class="button-row"><button data-close="modal-lut-editor" type="button">Đóng</button><button id="btn-save-lut" type="button">Lưu và tải lại xem thử</button><button id="btn-activate-lut" class="primary" type="button" disabled>Kích hoạt filter</button></div></section></div>
<div id="modal-print-confirm" class="modal-backdrop" hidden><section class="panel modal" role="dialog" aria-modal="true" aria-labelledby="modal-print-title"><h2 id="modal-print-title">Xác nhận in</h2><p id="modal-print-quota"></p><label id="modal-print-copies-wrap">Số bản<input id="modal-print-copies" type="number" min="1" max="10" step="1" value="1"></label><div id="modal-print-unknown-warning" class="alert" hidden>Có lệnh in UNKNOWN. Hãy kiểm tra queue và bản giấy trước khi quyết định in lại.</div><img id="modal-print-thumb" class="print-thumb" alt="Ảnh sẽ in"><p id="modal-print-session-code"></p><p id="modal-print-action-label"></p><div class="button-row"><button type="button" data-close="modal-print-confirm">Hủy</button><button id="btn-confirm-print-submit" class="primary" type="button">Xác nhận in · 1 bản</button></div></section></div>
<div id="modal-resolve-unknown" class="modal-backdrop" hidden><section class="panel modal" role="dialog" aria-modal="true" aria-labelledby="resolve-title"><h2 id="resolve-title">Đối chiếu UNKNOWN</h2><p id="resolve-print-id-display"></p><label>Kết quả<select id="resolve-outcome"><option value="observed_completed">Đã ra giấy</option><option value="confirmed_not_printed">Xác nhận chưa in</option></select></label><label id="resolve-copies-wrap">Số bản đã ra<input id="resolve-copies" type="number" min="1" step="1" required></label><label>Ghi chú<textarea id="resolve-note" required></textarea></label><div class="button-row"><button data-close="modal-resolve-unknown" type="button">Hủy</button><button id="btn-resolve-unknown-submit" class="primary" type="button">Lưu đối chiếu</button></div></section></div>
<div id="modal-history-detail" class="modal-backdrop" hidden><section class="panel modal" role="dialog" aria-modal="true" aria-labelledby="history-detail-title"><h2 id="history-detail-title">Chi tiết phiên</h2><div id="modal-history-body"></div><div class="button-row"><button data-close="modal-history-detail" type="button">Đóng</button><button id="btn-detail-reprint" class="primary" type="button" hidden>In lại</button><button id="btn-detail-share" type="button" hidden>Tạo QR ảnh đã chọn</button><button id="btn-detail-admin-delete" type="button" hidden>Xóa phiên</button></div></section></div>
<div id="modal-template-preview" class="modal-backdrop" hidden><section class="panel modal template-modal" role="dialog" aria-modal="true" aria-labelledby="template-preview-title"><h2 id="template-preview-title">Xem thử template</h2><canvas id="admin-preview" width="1181" height="1748"></canvas><p>Ảnh mẫu minh họa — ghép từ layout và tài nguyên thực tế của gói.</p><div class="button-row"><button data-close="modal-template-preview" type="button">Đóng</button><button id="btn-activate-template" class="primary" type="button" disabled>Kích hoạt mẫu</button></div></section></div>
<script type="module" src="/custom/assets/js/operator.js"></script>
</body></html>
