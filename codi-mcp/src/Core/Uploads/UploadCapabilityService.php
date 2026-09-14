<?php

declare(strict_types=1);

namespace CodiMcp\Core\Uploads;

final class UploadCapabilityService
{
    public const DEFAULT_MAX_BYTES = 50 * 1024 * 1024;
    public const CLEANUP_HOOK = 'codi_mcp_cleanup_uploads';

    private const TOKEN_HASH_KEY = '_codi_upload_token_hash';
    private const CONTENT_TYPE_KEY = '_codi_upload_content_type';

    private UploadStore $uploads;

    public function __construct(?UploadStore $uploads = null)
    {
        $this->uploads = $uploads ?? new UploadStore(self::DEFAULT_MAX_BYTES);
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed>|\WP_Error */
    public function create(
        string $purpose,
        int $size,
        string $sha256,
        string $contentType = 'application/octet-stream',
        array $metadata = array()
    ): array|\WP_Error {
        $purpose = strtolower(trim($purpose));
        $sha256 = strtolower(trim($sha256));
        $contentType = strtolower(trim($contentType));
        if ($contentType === '') {
            $contentType = 'application/octet-stream';
        }
        if (strlen($contentType) > 255 || preg_match('/[\r\n]/', $contentType)) {
            return new \WP_Error('codi_mcp_bad_content_type', 'Upload content type is invalid.');
        }
        if (array_key_exists(self::TOKEN_HASH_KEY, $metadata) || array_key_exists(self::CONTENT_TYPE_KEY, $metadata)) {
            return new \WP_Error('codi_mcp_bad_metadata', 'Upload metadata uses a reserved key.');
        }

        try {
            $token = bin2hex(random_bytes(32));
        } catch (\Throwable $error) {
            return new \WP_Error('codi_mcp_storage', 'Could not create a secure upload capability.');
        }

        $metadata[self::TOKEN_HASH_KEY] = hash('sha256', $token);
        $metadata[self::CONTENT_TYPE_KEY] = $contentType;
        $started = $this->uploads->start($purpose, $size, $sha256, $metadata);
        if ($started instanceof \WP_Error) {
            return $started;
        }

        $uploadId = (string) ($started['upload_id'] ?? '');
        $namespace = defined('CODI_MCP_OAUTH_REST_NAMESPACE') ? trim((string) CODI_MCP_OAUTH_REST_NAMESPACE, '/') : 'codi-mcp/v1';
        $uploadUrl = function_exists('rest_url')
            ? rest_url($namespace . '/uploads/' . rawurlencode($purpose) . '/' . rawurlencode($uploadId) . '/' . rawurlencode($token))
            : '';
        if (!is_string($uploadUrl) || !preg_match('#^https://#i', $uploadUrl)) {
            $this->uploads->discard($uploadId, $purpose);
            return new \WP_Error('codi_mcp_upload_url_unavailable', 'Upload requires an HTTPS WordPress REST URL.');
        }

        return array(
            'upload_id' => $uploadId,
            'complete' => false,
            'upload' => array(
                'method' => 'PUT',
                'url' => $uploadUrl,
                'content_type' => $contentType,
                'size' => $size,
                'sha256' => $sha256,
                'expires_at' => (string) ($started['expires_at'] ?? ''),
            ),
        );
    }

    /** @param resource|null $stream @return array<string,mixed>|\WP_Error */
    public function receive(string $purpose, string $uploadId, string $token, $stream = null): array|\WP_Error
    {
        $purpose = strtolower(trim($purpose));
        if (1 !== preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/', $purpose)
            || 1 !== preg_match('/^[a-f0-9]{32}$/', $uploadId)
            || 1 !== preg_match('/^[a-f0-9]{64}$/', $token)) {
            return $this->capabilityError();
        }

        $upload = $this->uploads->get($uploadId, $purpose);
        if ($upload instanceof \WP_Error) {
            return $this->capabilityError();
        }
        $metadata = is_array($upload['meta']['metadata'] ?? null) ? $upload['meta']['metadata'] : array();
        $expectedTokenHash = (string) ($metadata[self::TOKEN_HASH_KEY] ?? '');
        if ($expectedTokenHash === '' || !hash_equals($expectedTokenHash, hash('sha256', $token))) {
            return $this->capabilityError();
        }

        $ownsStream = false;
        if (!is_resource($stream)) {
            $stream = @fopen('php://input', 'rb');
            $ownsStream = true;
        }
        if (!is_resource($stream)) {
            return new \WP_Error('codi_mcp_bad_upload_stream', 'Upload body stream is unavailable.', array('status' => 400));
        }

        try {
            $received = $this->uploads->writeStream($uploadId, $purpose, $stream);
        } finally {
            if ($ownsStream && is_resource($stream)) {
                fclose($stream);
            }
        }
        if ($received instanceof \WP_Error) {
            return $received;
        }

        return array(
            'upload_id' => $uploadId,
            'received' => (int) ($received['meta']['size'] ?? 0),
            'complete' => true,
        );
    }

    /** @return array<string,mixed>|\WP_Error */
    public function complete(string $uploadId, string $purpose): array|\WP_Error
    {
        return $this->uploads->complete(trim($uploadId), $purpose);
    }

    public function consume(string $uploadId, string $purpose): bool|\WP_Error
    {
        return $this->uploads->consume(trim($uploadId), $purpose);
    }

    public function discard(string $uploadId, string $purpose): bool|\WP_Error
    {
        return $this->uploads->discard(trim($uploadId), $purpose);
    }

    public static function registerCleanupCron(): void
    {
        if (function_exists('add_action')) {
            add_action(self::CLEANUP_HOOK, array(self::class, 'cleanupExpiredUploads'));
        }
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
            return;
        }
        if (false === wp_next_scheduled(self::CLEANUP_HOOK)) {
            wp_schedule_event(time() + 3600, 'hourly', self::CLEANUP_HOOK);
        }
    }

    public static function cleanupExpiredUploads(): void
    {
        (new UploadStore(self::DEFAULT_MAX_BYTES))->cleanupExpired();
    }

    public static function clearCleanupCron(): void
    {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(self::CLEANUP_HOOK);
        }
    }

    public static function registerRoute(): void
    {
        if (!function_exists('register_rest_route')) {
            return;
        }
        $namespace = defined('CODI_MCP_OAUTH_REST_NAMESPACE') ? trim((string) CODI_MCP_OAUTH_REST_NAMESPACE, '/') : 'codi-mcp/v1';
        register_rest_route($namespace, '/uploads/(?P<purpose>[a-z0-9][a-z0-9._-]{0,99})/(?P<upload_id>[a-f0-9]{32})/(?P<token>[a-f0-9]{64})', array(
            'methods' => 'PUT',
            'callback' => static function ($request) {
                $purpose = is_object($request) && method_exists($request, 'get_param') ? (string) $request->get_param('purpose') : '';
                $uploadId = is_object($request) && method_exists($request, 'get_param') ? (string) $request->get_param('upload_id') : '';
                $token = is_object($request) && method_exists($request, 'get_param') ? (string) $request->get_param('token') : '';
                return (new self())->receive($purpose, $uploadId, $token);
            },
            'permission_callback' => '__return_true',
        ));
    }

    private function capabilityError(): \WP_Error
    {
        return new \WP_Error('codi_mcp_upload_capability_invalid', 'Upload capability is invalid or expired.', array('status' => 404));
    }
}
