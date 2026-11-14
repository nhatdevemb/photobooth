<?php

declare(strict_types=1);

namespace Photobooth\Operator\Auth;

use PDO;
use Photobooth\Operator\Config\AppConfig;
use Photobooth\Operator\Storage\AuditLogger;
use Photobooth\Operator\Storage\Database;
use Photobooth\Operator\Support\ApiException;
use Photobooth\Operator\Support\Clock;
use Photobooth\Operator\Support\Uuid;

final class AuthService
{
    public const COOKIE_NAME = 'pb_operator_sid';
    private const RATE_LIMIT_WINDOW_SECONDS = 900; // 15 minutes
    private const RATE_LIMIT_MAX_FAILURES = 5;

    private Database $db;
    private AppConfig $config;

    public function __construct(Database $db, AppConfig $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    /**
     * Create or update a user account (used by CLI manage-user.php).
     *
     * @return array{id: string, username: string, role: string}
     */
    public function createUser(string $username, string $plainPassword, string $role = 'operator'): array
    {
        $username = trim($username);
        if ($username === '' || preg_match('/^[a-zA-Z0-9_.-]{3,64}$/', $username) !== 1) {
            throw new ApiException('INVALID_INPUT', 'Tên đăng nhập không hợp lệ (3-64 ký tự chữ, số, _, ., -).', 422);
        }
        if (strlen($plainPassword) < 8) {
            throw new ApiException('INVALID_INPUT', 'Mật khẩu phải có ít nhất 8 ký tự.', 422);
        }
        if (!in_array($role, ['operator', 'admin'], true)) {
            throw new ApiException('INVALID_INPUT', "Vai trò không hợp lệ: '{$role}'.", 422);
        }

        $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
        $now = Clock::nowIso();

        return $this->db->transaction(static function (PDO $pdo) use ($username, $hash, $role, $now): array {
            $check = $pdo->prepare('SELECT id FROM users WHERE username = :u');
            $check->execute([':u' => $username]);
            $existing = $check->fetch();

            if (is_array($existing)) {
                $userId = (string) $existing['id'];
                $upd = $pdo->prepare(
                    'UPDATE users SET password_hash = :h, role = :r, disabled_at = NULL, updated_at = :now WHERE id = :id'
                );
                $upd->execute([
                    ':h' => $hash,
                    ':r' => $role,
                    ':now' => $now,
                    ':id' => $userId,
                ]);
                AuditLogger::record($pdo, 'user.updated', 'SUCCEEDED', $userId, null, null, [
                    'username' => $username,
                    'role' => $role,
                ]);
                return ['id' => $userId, 'username' => $username, 'role' => $role];
            }

            $userId = Uuid::v4();
            $ins = $pdo->prepare(
                'INSERT INTO users (id, username, password_hash, role, disabled_at, created_at, updated_at)
                 VALUES (:id, :u, :h, :r, NULL, :now, :now)'
            );
            $ins->execute([
                ':id' => $userId,
                ':u' => $username,
                ':h' => $hash,
                ':r' => $role,
                ':now' => $now,
            ]);
            AuditLogger::record($pdo, 'user.created', 'SUCCEEDED', $userId, null, null, [
                'username' => $username,
                'role' => $role,
            ]);
            return ['id' => $userId, 'username' => $username, 'role' => $role];
        });
    }

    public function disableUser(string $username): void
    {
        $now = Clock::nowIso();
        $this->db->transaction(static function (PDO $pdo) use ($username, $now): void {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u');
            $stmt->execute([':u' => $username]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                throw new ApiException('NOT_FOUND', 'Không tìm thấy tài khoản.', 404);
            }
            $uid = (string) $row['id'];
            $pdo->prepare('UPDATE users SET disabled_at = :now, updated_at = :now WHERE id = :id')
                ->execute([':now' => $now, ':id' => $uid]);
            $pdo->prepare('DELETE FROM auth_sessions WHERE user_id = :id')
                ->execute([':id' => $uid]);
            AuditLogger::record($pdo, 'user.disabled', 'SUCCEEDED', $uid, null, null, ['username' => $username]);
        });
    }

    /**
     * Authenticate username + password with brute-force rate limit and Origin verification.
     *
     * @return array{
     *   session_token: string,
     *   csrf_token: string,
     *   expires_at: string,
     *   user: array{id: string, username: string, role: string}
     * }
     */
    public function login(
        string $username,
        string $password,
        string $ipAddress = '127.0.0.1',
        string $userAgent = 'CLI',
        ?string $originHeader = null,
        ?string $hostHeader = null
    ): array
    {
        $this->verifySameOrigin($originHeader, $hostHeader);

        $username = trim($username);
        $nowUnix = Clock::nowUnix();
        $windowStart = $nowUnix - self::RATE_LIMIT_WINDOW_SECONDS;

        $pdo = $this->db->pdo();

        // Check brute force rate limit (5 failures in 15 min per username + IP)
        $countStmt = $pdo->prepare(
            'SELECT COUNT(*) AS cnt, MIN(attempted_at) AS oldest
             FROM login_attempts
             WHERE username = :u AND ip_address = :ip AND attempted_at >= :since'
        );
        $countStmt->execute([
            ':u' => $username,
            ':ip' => $ipAddress,
            ':since' => $windowStart,
        ]);
        $rateRow = $countStmt->fetch();
        $countStmt->closeCursor();
        $failCount = is_array($rateRow) ? (int) $rateRow['cnt'] : 0;

        if ($failCount >= self::RATE_LIMIT_MAX_FAILURES) {
            $oldest = is_array($rateRow) && $rateRow['oldest'] !== null ? (int) $rateRow['oldest'] : $nowUnix;
            $retryAfter = max(1, ($oldest + self::RATE_LIMIT_WINDOW_SECONDS) - $nowUnix);
            throw new ApiException(
                'LOGIN_RATE_LIMITED',
                'Quá nhiều lần đăng nhập sai. Vui lòng thử lại sau 15 phút.',
                429,
                ['retry_after_seconds' => $retryAfter],
                ['Retry-After' => (string) $retryAfter]
            );
        }

        $userStmt = $pdo->prepare('SELECT id, username, password_hash, role, disabled_at FROM users WHERE username = :u');
        $userStmt->execute([':u' => $username]);
        $user = $userStmt->fetch();
        $userStmt->closeCursor();

        $valid = is_array($user)
            && $user['disabled_at'] === null
            && password_verify($password, (string) $user['password_hash']);

        if (!$valid) {
            $this->db->transaction(static function (PDO $txPdo) use ($username, $ipAddress, $nowUnix, $user): void {
                $ins = $txPdo->prepare(
                    'INSERT INTO login_attempts (username, ip_address, attempted_at) VALUES (:u, :ip, :ts)'
                );
                $ins->execute([
                    ':u' => $username,
                    ':ip' => $ipAddress,
                    ':ts' => $nowUnix,
                ]);
                AuditLogger::record(
                    $txPdo,
                    'auth.login',
                    'FAILED',
                    is_array($user) ? (string) $user['id'] : null,
                    null,
                    null,
                    ['username' => $username, 'ip' => $ipAddress]
                );
            });

            throw new ApiException(
                'AUTH_REQUIRED',
                'Tên đăng nhập hoặc mật khẩu không đúng.',
                401
            );
        }

        $rawSessionToken = bin2hex(random_bytes(32));
        $sessionHash = hash('sha256', $rawSessionToken);
        $csrfToken = bin2hex(random_bytes(32));
        $nowIso = Clock::fromUnixIso($nowUnix);
        $expiresIso = Clock::fromUnixIso($nowUnix + $this->config->authIdleTimeoutSeconds);
        $userId = (string) $user['id'];
        $role = (string) $user['role'];

        $this->db->transaction(static function (PDO $txPdo) use (
            $username,
            $ipAddress,
            $sessionHash,
            $userId,
            $csrfToken,
            $userAgent,
            $nowIso,
            $expiresIso
        ): void {
            $txPdo->prepare('DELETE FROM login_attempts WHERE username = :u AND ip_address = :ip')
                ->execute([':u' => $username, ':ip' => $ipAddress]);

            $txPdo->prepare(
                'INSERT INTO auth_sessions (id, user_id, csrf_token, ip_address, user_agent, created_at, last_activity_at, expires_at)
                 VALUES (:id, :uid, :csrf, :ip, :ua, :now, :now, :exp)'
            )->execute([
                ':id' => $sessionHash,
                ':uid' => $userId,
                ':csrf' => $csrfToken,
                ':ip' => $ipAddress,
                ':ua' => substr($userAgent, 0, 255),
                ':now' => $nowIso,
                ':exp' => $expiresIso,
            ]);

            AuditLogger::record($txPdo, 'auth.login', 'SUCCEEDED', $userId, null, null, [
                'username' => $username,
                'ip' => $ipAddress,
            ]);
        });

        return [
            'session_token' => $rawSessionToken,
            'csrf_token' => $csrfToken,
            'expires_at' => $expiresIso,
            'user' => [
                'id' => $userId,
                'username' => (string) $user['username'],
                'role' => $role,
            ],
        ];
    }

    /**
     * Validate session cookie token and return auth context.
     *
     * @return array{
     *   session_id_hash: string,
     *   csrf_token: string,
     *   user: array{id: string, username: string, role: string}
     * }
     */
    public function authenticate(?string $rawSessionToken): array
    {
        if ($rawSessionToken === null || trim($rawSessionToken) === '') {
            throw new ApiException('AUTH_REQUIRED', 'Vui lòng đăng nhập để thực hiện thao tác này.', 401);
        }

        $sessionHash = hash('sha256', trim($rawSessionToken));
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            'SELECT s.id AS session_hash, s.csrf_token, s.expires_at,
                    u.id AS user_id, u.username, u.role, u.disabled_at
             FROM auth_sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.id = :id'
        );
        $stmt->execute([':id' => $sessionHash]);
        $row = $stmt->fetch();
        // End the read snapshot before the sliding-expiry write; a worker may commit in between.
        $stmt->closeCursor();

        if (!is_array($row) || $row['disabled_at'] !== null) {
            throw new ApiException('AUTH_REQUIRED', 'Phiên đăng nhập không hợp lệ hoặc đã bị thu hồi.', 401);
        }

        $nowUnix = Clock::nowUnix();
        $expiresUnix = Clock::toUnix((string) $row['expires_at']);
        if ($expiresUnix < $nowUnix) {
            $pdo->prepare('DELETE FROM auth_sessions WHERE id = :id')->execute([':id' => $sessionHash]);
            throw new ApiException('AUTH_REQUIRED', 'Phiên đăng nhập đã hết hạn do không hoạt động.', 401);
        }

        // Slide idle expiration window
        $newNowIso = Clock::fromUnixIso($nowUnix);
        $newExpIso = Clock::fromUnixIso($nowUnix + $this->config->authIdleTimeoutSeconds);
        $pdo->prepare('UPDATE auth_sessions SET last_activity_at = :now, expires_at = :exp WHERE id = :id')
            ->execute([':now' => $newNowIso, ':exp' => $newExpIso, ':id' => $sessionHash]);

        return [
            'session_id_hash' => $sessionHash,
            'csrf_token' => (string) $row['csrf_token'],
            'user' => [
                'id' => (string) $row['user_id'],
                'username' => (string) $row['username'],
                'role' => (string) $row['role'],
            ],
        ];
    }

    /**
     * @param array{session_id_hash: string, csrf_token: string, user: array{id: string, username: string, role: string}} $authContext
     */
    public function logout(array $authContext): void
    {
        $this->db->transaction(static function (PDO $pdo) use ($authContext): void {
            $pdo->prepare('DELETE FROM auth_sessions WHERE id = :id')
                ->execute([':id' => $authContext['session_id_hash']]);
            AuditLogger::record($pdo, 'auth.logout', 'SUCCEEDED', $authContext['user']['id']);
        });
    }

    /**
     * @param array{session_id_hash: string, csrf_token: string, user: array{id: string, username: string, role: string}} $authContext
     */
    public function assertCsrfAndOrigin(
        array $authContext,
        ?string $csrfHeader,
        ?string $originHeader = null,
        ?string $hostHeader = null
    ): void
    {
        $this->verifySameOrigin($originHeader, $hostHeader);

        if ($csrfHeader === null || $csrfHeader === '' || !hash_equals($authContext['csrf_token'], $csrfHeader)) {
            throw new ApiException(
                'CSRF_INVALID',
                'Mã bảo vệ CSRF không hợp lệ hoặc bị thiếu. Vui lòng tải lại trang.',
                403
            );
        }
    }

    /**
     * @param array{session_id_hash: string, csrf_token: string, user: array{id: string, username: string, role: string}} $authContext
     */
    public function assertAdmin(array $authContext): void
    {
        if (($authContext['user']['role'] ?? '') !== 'admin') {
            throw new ApiException(
                'FORBIDDEN',
                'Thao tác này yêu cầu quyền Quản trị viên (Admin).',
                403
            );
        }
    }

    public function verifySameOrigin(?string $originHeader, ?string $hostHeader): void
    {
        if ($originHeader === null || trim($originHeader) === '') {
            return;
        }
        $parts = parse_url($originHeader);
        if (!is_array($parts) || !isset($parts['host'])) {
            throw new ApiException('FORBIDDEN', 'Origin header không hợp lệ.', 403);
        }
        $originHost = strtolower((string) $parts['host']);
        $originHostWithPort = isset($parts['port']) ? $originHost . ':' . $parts['port'] : $originHost;

        if ($hostHeader !== null && trim($hostHeader) !== '') {
            $reqHost = strtolower(trim($hostHeader));
            $reqHostNoPort = explode(':', $reqHost)[0];
            if ($originHost === $reqHostNoPort || $originHostWithPort === $reqHost) {
                return;
            }
        }

        foreach ($this->config->trustedHosts as $trusted) {
            $t = strtolower(trim($trusted));
            if ($originHost === $t || $originHostWithPort === $t) {
                return;
            }
        }

        throw new ApiException('FORBIDDEN', 'Yêu cầu từ Origin không được phép.', 403);
    }
}

