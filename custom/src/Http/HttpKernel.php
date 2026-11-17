<?php

declare(strict_types=1);

namespace Photobooth\Operator\Http;

use Photobooth\Operator\Auth\AuthService;
use Photobooth\Operator\Container;
use Photobooth\Operator\Share\ShareService;
use Photobooth\Operator\Support\ApiException;
use Photobooth\Operator\Support\Uuid;
use Throwable;

final class HttpKernel
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Handle an HTTP request and return a structured response array:
     * [
     *   'status' => int,
     *   'headers' => array<string, string>,
     *   'cookies' => list<array<string, mixed>>,
     *   'body' => string,
     *   'file_path' => ?string
     * ]
     *
     * @param array{
     *   method?: string,
     *   path?: string,
     *   query?: array<string, mixed>,
     *   headers?: array<string, string>,
     *   cookies?: array<string, string>,
     *   body?: string|array<string, mixed>|null,
     *   ip?: string,
     *   listener_mode?: string,
     *   https?: bool
     * } $request
     * @return array{
     *   status: int,
     *   headers: array<string, string>,
     *   cookies: list<array<string, mixed>>,
     *   body: string,
     *   file_path: ?string
     * }
     */
    public function handle(array $request): array
    {
        $requestId = Uuid::v4();
        $this->container->logger->setRequestId($requestId);

        $method = strtoupper((string) ($request['method'] ?? 'GET'));
        $rawPath = (string) ($request['path'] ?? '/');
        $path = '/' . ltrim((string) parse_url($rawPath, PHP_URL_PATH), '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        $query = is_array($request['query'] ?? null) ? $request['query'] : [];
        $headers = $this->normalizeHeaders($request['headers'] ?? []);
        $cookies = is_array($request['cookies'] ?? null) ? $request['cookies'] : [];
        $ip = (string) ($request['ip'] ?? '127.0.0.1');
        $listenerMode = strtolower((string) ($request['listener_mode'] ?? 'operator'));
        $isHttps = (bool) ($request['https'] ?? false);

        try {
            // Strictly enforce Guest LAN listener surface isolation (Section 9.2 & IT-13)
            if ($listenerMode === 'guest') {
                return $this->handleGuestListener($method, $path, $query, $requestId);
            }

            // Also serve /share/{token} on operator listener for previewing
            if (str_starts_with($path, '/share/')) {
                return $this->handleShareRoute($method, $path, $query);
            }

            if (!str_starts_with($path, '/custom/api/')) {
                throw new ApiException('NOT_FOUND', 'Đường dẫn API không tồn tại.', 404);
            }

            $apiRoute = substr($path, strlen('/custom/api'));
            return $this->handleOperatorApi(
                $method,
                $apiRoute,
                $query,
                $headers,
                $cookies,
                $request['body'] ?? null,
                $ip,
                $isHttps,
                $requestId
            );
        } catch (ApiException $e) {
            $respHeaders = array_merge(
                [
                    'Content-Type' => 'application/json; charset=utf-8',
                    'Cache-Control' => 'no-store',
                    'X-Content-Type-Options' => 'nosniff',
                    'X-Request-Id' => $requestId,
                ],
                $e->getHeaders()
            );

            $errorBody = [
                'error' => array_merge(
                    [
                        'code' => $e->getErrorCode(),
                        'message' => $e->getMessage(),
                        'request_id' => $requestId,
                    ],
                    $e->getDetails()
                ),
            ];

            return [
                'status' => $e->getHttpStatus(),
                'headers' => $respHeaders,
                'cookies' => [],
                'body' => json_encode($errorBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                'file_path' => null,
            ];
        } catch (Throwable $e) {
            $this->container->logger->error('unhandled_api_exception', 'INTERNAL_ERROR', [
                'request_id' => $requestId,
                'path' => $path,
                'exception' => $e->getMessage(),
            ]);

            return [
                'status' => 500,
                'headers' => [
                    'Content-Type' => 'application/json; charset=utf-8',
                    'Cache-Control' => 'no-store',
                    'X-Content-Type-Options' => 'nosniff',
                    'X-Request-Id' => $requestId,
                ],
                'cookies' => [],
                'body' => json_encode([
                    'error' => [
                        'code' => 'INTERNAL_ERROR',
                        'message' => 'Lỗi hệ thống nội bộ. Vui lòng cung cấp mã request_id để kiểm tra nhật ký.',
                        'request_id' => $requestId,
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                'file_path' => null,
            ];
        }
    }

    /**
     * Guest listener only serves /share/{token} and /share/{token}/download.
     * Blocks all /custom/api/*, /api/*, /admin/*, and private paths (IT-13).
     *
     * @param array<string, mixed> $query
     * @return array{
     *   status: int,
     *   headers: array<string, string>,
     *   cookies: list<array<string, mixed>>,
     *   body: string,
     *   file_path: ?string
     * }
     */
    private function handleGuestListener(string $method, string $path, array $query, string $requestId): array
    {
        if (!str_starts_with($path, '/share/')) {
            throw new ApiException(
                'FORBIDDEN',
                'Cổng mạng khách (Guest LAN) chỉ cho phép truy cập đường dẫn tải ảnh chia sẻ (/share/{token}).',
                403
            );
        }

        if ($method !== 'GET') {
            throw new ApiException('FORBIDDEN', 'Phương thức HTTP không được phép trên cổng khách.', 403);
        }

        return $this->handleShareRoute($method, $path, $query);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{
     *   status: int,
     *   headers: array<string, string>,
     *   cookies: list<array<string, mixed>>,
     *   body: string,
     *   file_path: ?string
     * }
     */
    private function handleShareRoute(string $method, string $path, array $query): array
    {
        if ($method !== 'GET') {
            throw new ApiException('FORBIDDEN', 'Phương thức không được phép.', 403);
        }

        $commonSecurityHeaders = [
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];

        if (preg_match('#^/share/([^/]+)/download$#', $path, $m) === 1) {
            $rawToken = $m[1];
            try {
                $resolved = $this->container->shareService->resolveValidToken($rawToken);
            } catch (ApiException) {
                return [
                    'status' => 404,
                    'headers' => array_merge($commonSecurityHeaders, [
                        'Content-Type' => 'application/json; charset=utf-8',
                    ]),
                    'cookies' => [],
                    'body' => json_encode([
                        'error' => [
                            'code' => 'NOT_FOUND',
                            'message' => ShareService::PUBLIC_INVALID_MESSAGE,
                        ],
                    ], JSON_UNESCAPED_UNICODE) ?: '{}',
                    'file_path' => null,
                ];
            }

            $isInline = isset($query['inline']) && (string) $query['inline'] === '1';
            $disposition = $isInline
                ? 'inline; filename="photobooth-photo.jpg"'
                : 'attachment; filename="photobooth-photo.jpg"';

            return [
                'status' => 200,
                'headers' => array_merge($commonSecurityHeaders, [
                    'Content-Type' => 'image/jpeg',
                    'Content-Disposition' => $disposition,
                    'Content-Length' => (string) $resolved['bytes'],
                    'ETag' => '"' . $resolved['sha256'] . '"',
                ]),
                'cookies' => [],
                'body' => '',
                'file_path' => $resolved['final_abs_path'],
            ];
        }

        if (preg_match('#^/share/([^/]+)$#', $path, $m) === 1) {
            $rawToken = $m[1];
            try {
                $resolved = $this->container->shareService->resolveValidToken($rawToken);
                $html = $this->container->shareService->renderGuestViewerHtml($rawToken, $resolved);
                return [
                    'status' => 200,
                    'headers' => array_merge($commonSecurityHeaders, [
                        'Content-Type' => 'text/html; charset=utf-8',
                    ]),
                    'cookies' => [],
                    'body' => $html,
                    'file_path' => null,
                ];
            } catch (ApiException) {
                return [
                    'status' => 404,
                    'headers' => array_merge($commonSecurityHeaders, [
                        'Content-Type' => 'text/html; charset=utf-8',
                    ]),
                    'cookies' => [],
                    'body' => $this->container->shareService->renderPublicErrorHtml(),
                    'file_path' => null,
                ];
            }
        }

        return [
            'status' => 404,
            'headers' => array_merge($commonSecurityHeaders, [
                'Content-Type' => 'text/html; charset=utf-8',
            ]),
            'cookies' => [],
            'body' => $this->container->shareService->renderPublicErrorHtml(),
            'file_path' => null,
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     * @param string|array<string, mixed>|null $rawBody
     * @return array{
     *   status: int,
     *   headers: array<string, string>,
     *   cookies: list<array<string, mixed>>,
     *   body: string,
     *   file_path: ?string
     * }
     */
    private function handleOperatorApi(
        string $method,
        string $route,
        array $query,
        array $headers,
        array $cookies,
        string|array|null $rawBody,
        string $ip,
        bool $isHttps,
        string $requestId
    ): array {
        $payload = in_array($route, ['/admin/templates/import', '/admin/luts/import'], true) ? [] : $this->decodeJsonBody($rawBody);
        $originHeader = $headers['origin'] ?? null;
        $hostHeader = $headers['host'] ?? null;

        // 1. POST /auth/login (no prior cookie required, checks Origin + rate limit)
        if ($method === 'POST' && $route === '/auth/login') {
            $username = (string) ($payload['username'] ?? '');
            $password = (string) ($payload['password'] ?? '');
            $ua = $headers['user-agent'] ?? 'Browser';

            $loginData = $this->container->authService->login(
                $username,
                $password,
                $ip,
                $ua,
                $originHeader,
                $hostHeader
            );

            $cookieSpec = [
                'name' => AuthService::COOKIE_NAME,
                'value' => $loginData['session_token'],
                'expires' => 0,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => $isHttps,
            ];

            return $this->jsonResponse(200, [
                'user' => $loginData['user'],
                'csrf_token' => $loginData['csrf_token'],
                'expires_at' => $loginData['expires_at'],
            ], $requestId, [$cookieSpec]);
        }

        // All other /custom/api/* routes require an authenticated login session cookie
        $sessionToken = $cookies[AuthService::COOKIE_NAME] ?? null;
        $authContext = $this->container->authService->authenticate($sessionToken);
        $actorId = $authContext['user']['id'];

        // All mutating methods (POST/PUT/DELETE/PATCH) require valid X-CSRF-Token and Origin check
        if ($method !== 'GET' && $method !== 'HEAD') {
            $csrfHeader = $headers['x-csrf-token'] ?? null;
            $this->container->authService->assertCsrfAndOrigin(
                $authContext,
                $csrfHeader,
                $originHeader,
                $hostHeader
            );
        }

        // 2. GET /auth/me
        if ($method === 'GET' && $route === '/auth/me') {
            return $this->jsonResponse(200, [
                'user' => $authContext['user'],
                'csrf_token' => $authContext['csrf_token'],
            ], $requestId);
        }

        // 3. POST /auth/logout
        if ($method === 'POST' && $route === '/auth/logout') {
            $this->container->authService->logout($authContext);
            $clearCookie = [
                'name' => AuthService::COOKIE_NAME,
                'value' => '',
                'expires' => 1,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => $isHttps,
            ];
            return $this->jsonResponse(200, ['logged_out' => true], $requestId, [$clearCookie]);
        }

        // 4. GET /health
        if ($route === '/camera' && $method === 'GET') {
            return $this->jsonResponse(200, $this->container->cameraAdapter->health(), $requestId);
        }
        if (str_starts_with($route, '/camera/preview')) {
            $camera = $this->container->cameraAdapter;
            if (!$camera instanceof \Photobooth\Operator\Camera\DigiCamControlCameraAdapter) {
                throw new ApiException('INVALID_CAMERA_MODE', 'Live view này cần digiCamControl.', 409);
            }
            if ($route === '/camera/preview/start' && $method === 'POST') {
                return $this->jsonResponse(200, $camera->startPreview(), $requestId);
            }
            if ($route === '/camera/preview/stop' && $method === 'POST') {
                $camera->stopPreview();
                return $this->jsonResponse(200, ['status' => 'stopped'], $requestId);
            }
            if ($route === '/camera/preview.jpg' && $method === 'GET') {
                return ['status' => 200, 'headers' => ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'], 'cookies' => [], 'body' => $camera->previewFrame(), 'file_path' => null];
            }
        }
        if ($method === 'GET' && $route === '/health') {
            return $this->jsonResponse(200, $this->container->healthService->getHealthReport(), $requestId);
        }

        if ($method === 'GET' && $route === '/layouts') {
            return $this->jsonResponse(200, ['items'=>$this->container->catalog->layouts()], $requestId);
        }
        if ($method === 'GET' && $route === '/templates') {
            return $this->jsonResponse(200, ['items'=>$this->container->catalog->templates(isset($query['layout_id']) ? (string) $query['layout_id'] : null)], $requestId);
        }
        if ($method === 'GET' && preg_match('#^/catalog/layouts/([a-z0-9-]+)/([0-9.]+)/preview\.jpg$#D', $route, $m) === 1) {
            $layout = $this->container->catalog->layout($m[1], $m[2]);
            return $this->catalogFile($this->container->config->customRoot . '/layouts/' . $layout['id'] . '-preview.jpg', 'image/jpeg');
        }
        if ($method === 'GET' && preg_match('#^/catalog/templates/([a-z0-9-]+)/([0-9.]+)/(overlay\.png|background\.png|preview\.jpg)$#D', $route, $m) === 1) {
            $file = $this->container->catalog->asset($m[1], $m[2], $m[3], $authContext['user']['role'] === 'admin');
            return $this->catalogFile($file, str_ends_with($file, '.png') ? 'image/png' : 'image/jpeg');
        }
        if (str_starts_with($route, '/admin/templates')) {
            $this->container->authService->assertAdmin($authContext);
            if ($method === 'GET' && $route === '/admin/templates') {
                return $this->jsonResponse(200, ['items'=>$this->container->catalog->templates(null, true)], $requestId);
            }
            if ($method === 'POST' && $route === '/admin/templates/import') {
                if (!is_string($rawBody) || !str_starts_with(strtolower($headers['content-type'] ?? ''), 'application/zip')) {
                    throw new ApiException('INVALID_PACKAGE', 'Gửi nội dung ZIP với Content-Type application/zip.', 422);
                }
                $item = $this->container->catalog->importZip($rawBody, $actorId, $headers['idempotency-key'] ?? '');
                return $this->jsonResponse(201, $item, $requestId);
            }
            if ($method === 'POST' && preg_match('#^/admin/templates/([a-z0-9-]+)/([0-9.]+)/(activate|disable)$#D', $route, $m) === 1) {
                $item = $this->container->catalog->setStatus($m[1], $m[2], $m[3] === 'activate' ? 'active' : 'disabled', $actorId, $headers['idempotency-key'] ?? '');
                return $this->jsonResponse(200, $item, $requestId);
            }
        }
        if ($method === 'POST' && preg_match('#^/sessions/([^/]+)/(configuration|restart)$#', $route, $m) === 1) {
            $revision = $this->extractRevision($payload);
            $key = $headers['idempotency-key'] ?? '';
            $result = $m[2] === 'configuration'
                ? $this->container->sessionService->configure($m[1], $actorId, $revision, $payload, $key)
                : $this->container->sessionService->restart($m[1], $actorId, $revision, ($payload['confirm'] ?? false) === true, $key);
            return $this->jsonResponse($result['status_code'], $result['body'], $requestId);
        }

        if ($method === 'GET' && preg_match('#^/catalog/luts/([a-z0-9-]+)/([0-9.]+)/preview\.jpg$#D', $route, $m) === 1) {
            $file = $this->container->luts->samplePath($m[1], $m[2], $authContext['user']['role'] === 'admin', isset($query['sha']) ? (string) $query['sha'] : null);
            return $this->catalogFile($file, 'image/jpeg');
        }
        if ($method === 'GET' && preg_match('#^/catalog/luts/([a-z0-9-]+)/([0-9.]+)/filter\.cube$#D', $route, $m) === 1) {
            $file = $this->container->luts->cubePath($m[1], $m[2], $authContext['user']['role'] === 'admin');
            return $this->catalogFile($file, 'text/plain; charset=utf-8');
        }
        if (str_starts_with($route, '/admin/luts')) {
            $this->container->authService->assertAdmin($authContext);
            if ($method === 'GET' && $route === '/admin/luts') {
                return $this->jsonResponse(200, ['items'=>$this->container->luts->items(true)], $requestId);
            }
            if ($method === 'POST' && $route === '/admin/luts/import') {
                if (!str_starts_with(strtolower($headers['content-type'] ?? ''), 'multipart/form-data') || !is_array($rawBody) || !is_array($rawBody['file'] ?? null)) {
                    throw new ApiException('INVALID_LUT_UPLOAD', 'Gửi multipart/form-data với một file .cube.', 422);
                }
                $item = $this->container->luts->import($rawBody['file'], $actorId, $headers['idempotency-key'] ?? '');
                return $this->jsonResponse(201, $item, $requestId);
            }
            if ($method === 'POST' && preg_match('#^/admin/luts/([a-z0-9-]+)/([0-9.]+)/(draft|versions|activate|disable)$#D', $route, $m) === 1) {
                $revision = $this->extractRevision($payload);
                $key = $headers['idempotency-key'] ?? '';
                if ($m[3] === 'draft') {
                    $item = $this->container->luts->editDraft($m[1], $m[2], $revision, $payload, $actorId, $key);
                } elseif ($m[3] === 'versions') {
                    $item = $this->container->luts->createVersion($m[1], $m[2], $revision, $actorId, $key);
                } else {
                    if (isset($payload['preview_sha256']) && !is_string($payload['preview_sha256'])) {
                        throw new ApiException('INVALID_INPUT', 'preview_sha256 phải là chuỗi.', 422);
                    }
                    $item = $this->container->luts->setStatus($m[1], $m[2], $revision, $m[3] === 'activate' ? 'active' : 'disabled', $payload['preview_sha256'] ?? null, $actorId, $key);
                }
                return $this->jsonResponse($m[3] === 'versions' ? 201 : 200, $item, $requestId);
            }
        }

        // 5. GET /presets
        if ($method === 'GET' && $route === '/presets') {
            return $this->jsonResponse(200, [
                'default_lut' => $this->container->config->defaultLut,
                'default_template' => $this->container->config->defaultTemplate,
                'presets' => $this->container->luts->items(),
            ], $requestId);
        }

        // 6. POST /sessions
        if ($method === 'POST' && $route === '/sessions') {
            $idemKey = $headers['idempotency-key'] ?? '';
            $res = $this->container->sessionService->createSession($actorId, $idemKey);
            return $this->jsonResponse($res['status_code'], $res['body'], $requestId);
        }

        // 7. GET /sessions/active
        if ($method === 'GET' && $route === '/sessions/active') {
            $active = $this->container->sessionService->getActiveSession();
            return $this->jsonResponse(200, ['active_session' => $active], $requestId);
        }

        // 8. GET /sessions
        if ($method === 'GET' && $route === '/sessions') {
            $cursor = isset($query['cursor']) ? (string) $query['cursor'] : null;
            $limit = isset($query['limit']) ? (int) $query['limit'] : 20;
            $list = $this->container->sessionService->listSessions($cursor, $limit);
            return $this->jsonResponse(200, $list, $requestId);
        }

        // 9. GET /sessions/{id}
        if ($method === 'GET' && preg_match('#^/sessions/([^/]+)$#', $route, $m) === 1) {
            $session = $this->container->sessionService->getSession($m[1]);
            return $this->jsonResponse(200, $session, $requestId);
        }

        // 10. POST /sessions/{id}/slots/{slot}/capture & /retake
        if ($method === 'POST' && preg_match('#^/sessions/([^/]+)/slots/([^/]+)/(capture|retake)$#', $route, $m) === 1) {
            $sessionId = $m[1];
            $rawSlot = $m[2];
            $mode = $m[3];
            $idemKey = $headers['idempotency-key'] ?? '';

            if (preg_match('/^-?\d+$/', $rawSlot) !== 1) {
                throw new ApiException('INVALID_SLOT', 'Số slot phải là số nguyên 1, 2 hoặc 3.', 422);
            }
            $slot = (int) $rawSlot;
            $revision = $this->extractRevision($payload);

            $res = $this->container->sessionService->enqueueSlotCaptureOrRetake(
                $sessionId,
                $actorId,
                $slot,
                $mode,
                $revision,
                $idemKey,
                is_array($payload['file'] ?? null) ? $payload['file'] : null
            );
            return $this->jsonResponse($res['status_code'], $res['body'], $requestId);
        }

        // 11. POST /sessions/{id}/render
        if ($method === 'POST' && preg_match('#^/sessions/([^/]+)/render$#', $route, $m) === 1) {
            $sessionId = $m[1];
            $idemKey = $headers['idempotency-key'] ?? '';
            $revision = $this->extractRevision($payload);
            $current = $this->container->sessionService->getSession($sessionId);
            foreach (['lut_version','lut_intensity','lut_sha256'] as $field) {
                if (isset($payload[$field]) && $payload[$field] !== $current[$field]) {
                    throw new ApiException('CONFIGURATION_LOCKED', 'Không được thay phiên bản hoặc cường độ filter qua render.', 409);
                }
            }
            $lutId = (string) ($payload['lut_id'] ?? $current['selected_lut']);
            $templateId = (string) ($payload['template_id'] ?? $current['template_id']);

            $res = $this->container->sessionService->enqueueRender(
                $sessionId,
                $actorId,
                $revision,
                $lutId,
                $templateId,
                $idemKey
            );
            return $this->jsonResponse($res['status_code'], $res['body'], $requestId);
        }

        // 12. POST /sessions/{id}/finalize
        if ($method === 'POST' && preg_match('#^/sessions/([^/]+)/finalize$#', $route, $m) === 1) {
            $sessionId = $m[1];
            $idemKey = $headers['idempotency-key'] ?? '';
            $revision = $this->extractRevision($payload);
            $renderId = (string) ($payload['render_id'] ?? '');

            $res = $this->container->sessionService->finalizeSession(
                $sessionId,
                $actorId,
                $revision,
                $renderId,
                $idemKey
            );
            return $this->jsonResponse($res['status_code'], $res['body'], $requestId);
        }

        // 13. POST /sessions/{id}/reopen
        if ($method === 'POST' && preg_match('#^/sessions/([^/]+)/reopen$#', $route, $m) === 1) {
            $sessionId = $m[1];
            $idemKey = $headers['idempotency-key'] ?? '';
            $revision = $this->extractRevision($payload);

            $res = $this->container->sessionService->reopenSession($sessionId, $actorId, $revision, $idemKey);
            return $this->jsonResponse($res['status_code'], $res['body'], $requestId);
        }

        // 14. POST /sessions/{id}/close
        if ($method === 'POST' && preg_match('#^/sessions/([^/]+)/close$#', $route, $m) === 1) {
            $sessionId = $m[1];
            $idemKey = $headers['idempotency-key'] ?? '';
            $revision = $this->extractRevision($payload);

            $res = $this->container->sessionService->closeSession($sessionId, $actorId, $revision, $idemKey);
            return $this->jsonResponse($res['status_code'], $res['body'], $requestId);
        }

        // 15. POST /sessions/{id}/cancel
        if ($method === 'POST' && preg_match('#^/sessions/([^/]+)/cancel$#', $route, $m) === 1) {
            $sessionId = $m[1];
            $idemKey = $headers['idempotency-key'] ?? '';
            $revision = $this->extractRevision($payload);

            $res = $this->container->sessionService->cancelSession($sessionId, $actorId, $revision, $idemKey);
            return $this->jsonResponse($res['status_code'], $res['body'], $requestId);
        }

        // 16. GET /jobs/{id}
        if ($method === 'GET' && preg_match('#^/jobs/([^/]+)$#', $route, $m) === 1) {
            $job = $this->container->sessionService->getJob($m[1]);
            return $this->jsonResponse(200, $job, $requestId);
        }

        // 17. GET /assets/{id}
        if ($method === 'GET' && preg_match('#^/assets/([^/]+)$#', $route, $m) === 1) {
            $assetId = $m[1];
            $variant = isset($query['variant']) ? (string) $query['variant'] : 'original';
            $asset = $this->container->sessionService->resolveOperatorAsset($assetId, $variant);

            return [
                'status' => 200,
                'headers' => [
                    'Content-Type' => $asset['mime'],
                    'Cache-Control' => 'private, no-store',
                    'X-Content-Type-Options' => 'nosniff',
                    'ETag' => '"' . $asset['sha256'] . '"',
                    'X-Request-Id' => $requestId,
                ],
                'cookies' => [],
                'body' => '',
                'file_path' => $asset['abs_path'],
            ];
        }

        // 18. POST /sessions/{id}/prints
        if ($method === 'POST' && preg_match('#^/sessions/([^/]+)/prints$#', $route, $m) === 1) {
            $sessionId = $m[1];
            $idemKey = $headers['idempotency-key'] ?? '';
            $revision = $this->extractRevision($payload);
            $finalAssetId = (string) ($payload['final_asset_id'] ?? '');
            $copies = array_key_exists('copies', $payload) ? (int) $payload['copies'] : 1;
            $action = (string) ($payload['action'] ?? 'print');

            $res = $this->container->printService->createPrintRequest(
                $sessionId,
                $actorId,
                $revision,
                $finalAssetId,
                $copies,
                $action,
                $idemKey
            );
            return $this->jsonResponse($res['status_code'], $res['body'], $requestId);
        }

        // 19. GET /prints/{id}
        if ($method === 'GET' && preg_match('#^/prints/([^/]+)$#', $route, $m) === 1) {
            $pr = $this->container->printService->getPrintRequest($m[1]);
            return $this->jsonResponse(200, $pr, $requestId);
        }

        // 20. POST /prints/{id}/resolve (Admin only)
        if ($method === 'POST' && preg_match('#^/prints/([^/]+)/resolve$#', $route, $m) === 1) {
            $this->container->authService->assertAdmin($authContext);
            $printId = $m[1];
            $idemKey = $headers['idempotency-key'] ?? '';
            $revision = $this->extractRevision($payload);
            $outcome = (string) ($payload['outcome'] ?? '');
            $note = (string) ($payload['note'] ?? '');

            $res = $this->container->printService->resolveUnknownPrint(
                $printId,
                $actorId,
                $revision,
                $outcome,
                $note,
                $idemKey
            );
            return $this->jsonResponse($res['status_code'], $res['body'], $requestId);
        }

        // 21. POST /sessions/{id}/shares
        if ($method === 'POST' && preg_match('#^/sessions/([^/]+)/shares$#', $route, $m) === 1) {
            $sessionId = $m[1];
            $idemKey = $headers['idempotency-key'] ?? '';
            $revision = $this->extractRevision($payload);
            $finalAssetId = (string) ($payload['final_asset_id'] ?? '');

            $res = $this->container->sessionService->createShareToken(
                $sessionId,
                $actorId,
                $revision,
                $finalAssetId,
                $idemKey
            );
            return $this->jsonResponse($res['status_code'], $res['body'], $requestId);
        }

        // 22. POST /shares/{id}/revoke
        if ($method === 'POST' && preg_match('#^/shares/([^/]+)/revoke$#', $route, $m) === 1) {
            $shareId = $m[1];
            $idemKey = $headers['idempotency-key'] ?? '';
            $revision = $this->extractRevision($payload);

            $res = $this->container->sessionService->revokeShareToken($shareId, $actorId, $revision, $idemKey);
            return $this->jsonResponse($res['status_code'], $res['body'], $requestId);
        }

        // 23. POST /sessions/{id}/delete (Admin only)
        if ($method === 'POST' && preg_match('#^/sessions/([^/]+)/delete$#', $route, $m) === 1) {
            $this->container->authService->assertAdmin($authContext);
            $sessionId = $m[1];
            $idemKey = $headers['idempotency-key'] ?? '';
            $revision = $this->extractRevision($payload);
            $confirmed = (bool) ($payload['confirm'] ?? false);

            $res = $this->container->sessionService->requestDeleteSession(
                $sessionId,
                $actorId,
                $revision,
                $confirmed,
                $idemKey
            );
            return $this->jsonResponse($res['status_code'], $res['body'], $requestId);
        }

        throw new ApiException('NOT_FOUND', 'Không tìm thấy endpoint yêu cầu.', 404);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<array<string, mixed>> $cookies
     * @return array{
     *   status: int,
     *   headers: array<string, string>,
     *   cookies: list<array<string, mixed>>,
     *   body: string,
     *   file_path: ?string
     * }
     */
    private function jsonResponse(int $status, array $data, string $requestId, array $cookies = []): array
    {
        return [
            'status' => $status,
            'headers' => [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
                'X-Request-Id' => $requestId,
            ],
            'cookies' => $cookies,
            'body' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'file_path' => null,
        ];
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function catalogFile(string $path, string $mime): array
    {
        if (!is_file($path)) {
            throw new ApiException('NOT_FOUND', 'Không tìm thấy tài nguyên.', 404);
        }
        return ['status'=>200, 'headers'=>['Content-Type'=>$mime, 'Cache-Control'=>'private, no-store', 'X-Content-Type-Options'=>'nosniff'], 'cookies'=>[], 'body'=>'', 'file_path'=>$path];
    }

    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $k => $v) {
            $normalized[strtolower((string) $k)] = (string) $v;
        }
        return $normalized;
    }

    /**
     * @param string|array<string, mixed>|null $rawBody
     * @return array<string, mixed>
     */
    private function decodeJsonBody(string|array|null $rawBody): array
    {
        if (is_array($rawBody)) {
            return $rawBody;
        }
        if ($rawBody === null || trim($rawBody) === '') {
            return [];
        }
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new ApiException('INVALID_JSON', 'Nội dung JSON của yêu cầu không hợp lệ.', 400);
        }
        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractRevision(array $payload): int
    {
        if (!array_key_exists('revision', $payload) || !is_int($payload['revision']) && !ctype_digit((string) $payload['revision'])) {
            throw new ApiException('INVALID_REVISION', 'Thiếu hoặc sai định dạng trường revision.', 422);
        }
        return (int) $payload['revision'];
    }
}

