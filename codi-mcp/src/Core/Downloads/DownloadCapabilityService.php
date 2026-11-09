<?php

declare(strict_types=1);

namespace CodiMcp\Core\Downloads;

/** Short-lived, site-bound access to an immutable, permission-checked export. */
final class DownloadCapabilityService
{
    private const MAX_BYTES = 50 * 1024 * 1024;
    private const PURPOSES = ['plugins.export', 'themes.export'];
    /** @var array<int,array{request:object,response:object,stream:resource,size:int}> */
    private array $pending = [];

    public function __construct(
        private ArtifactDownloadStore $downloads,
        private ?string $secret = null,
        private ?\Closure $clock = null
    ) {}

    /** Direct download is the default; sequential base64 transfer must be selected explicitly. */
    public function response(string $downloadId, string $purpose, array $input): array|\WP_Error
    {
        $transfer = $input['transfer'] ?? 'url';
        if ($transfer === 'chunks') {
            $chunk = $this->downloads->readBase64($downloadId, $purpose, (int) ($input['offset'] ?? 0));
            if (is_array($chunk)) { unset($chunk['meta']); }
            return $chunk;
        }
        if ($transfer !== 'url' || array_key_exists('offset', $input)) {
            return new \WP_Error('codi_mcp_bad_transfer', 'Use transfer=url without an offset, or transfer=chunks with an offset.');
        }
        $download = $this->downloads->get($downloadId, $purpose);
        if ($download instanceof \WP_Error) { return $download; }
        if (!in_array($purpose, self::PURPOSES, true) || $this->key() === '') {
            return new \WP_Error('codi_mcp_download_unavailable', 'Secure artifact download is unavailable.');
        }
        $expires = min($this->now() + 300, (int) $download['expires_at']);
        $signature = $this->signature($download, $purpose, $expires);
        $namespace = defined('CODI_MCP_OAUTH_REST_NAMESPACE') ? trim(CODI_MCP_OAUTH_REST_NAMESPACE, '/') : 'codi-mcp/v1';
        $url = function_exists('rest_url') ? rest_url($namespace . '/downloads/' . $purpose . '/' . $downloadId . '/' . $expires . '/' . $signature) : '';
        if (!is_string($url) || !preg_match('#^https://#i', $url)) {
            return new \WP_Error('codi_mcp_download_url_unavailable', 'Download requires an HTTPS WordPress REST URL.');
        }
        $meta = $download['meta'];
        return [
            'download_id' => $downloadId, 'filename' => $meta['filename'],
            'size' => $meta['size'], 'sha256' => $meta['sha256'],
            'download' => ['method' => 'GET', 'url' => $url, 'content_type' => 'application/zip', 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expires)],
        ];
    }

    public static function transferSchema(): array
    {
        return [
            'type' => 'string', 'enum' => ['url', 'chunks'],
            'description' => 'Default url; chunks returns sequential base64 data with next_offset.',
        ];
    }

    public static function outputSchema(string $identityField): array
    {
        $properties = [
            'download_id' => ['type' => 'string'], $identityField => ['type' => 'string'], 'filename' => ['type' => 'string'],
            'size' => ['type' => 'integer'], 'sha256' => ['type' => 'string'],
        ];
        $object = static fn (array $fields): array => ['type' => 'object', 'additionalProperties' => false, 'properties' => $fields, 'required' => array_keys($fields)];
        return ['type' => 'object', 'oneOf' => [
            $object($properties + ['download' => $object([
                'method' => ['type' => 'string', 'enum' => ['GET']], 'url' => ['type' => 'string'],
                'content_type' => ['type' => 'string'], 'expires_at' => ['type' => 'string'],
            ])]),
            $object($properties + [
                'offset' => ['type' => 'integer'], 'next_offset' => ['type' => 'integer'], 'chunk_size' => ['type' => 'integer'],
                'data' => ['type' => 'string'], 'complete' => ['type' => 'boolean'],
            ]),
        ]];
    }

    public static function registerRoutes(): void
    {
        $service = new self(new ArtifactDownloadStore(self::MAX_BYTES, 256 * 1024));
        $namespace = defined('CODI_MCP_OAUTH_REST_NAMESPACE') ? trim(CODI_MCP_OAUTH_REST_NAMESPACE, '/') : 'codi-mcp/v1';
        register_rest_route($namespace, '/downloads/(?P<purpose>plugins\.export|themes\.export)/(?P<download_id>[a-f0-9]{32})/(?P<expires>[0-9]{1,12})/(?P<token>[a-f0-9]{64})', [
            'methods' => ['GET', 'HEAD'],
            // The URL capability is verified before opening or returning any archive bytes.
            'permission_callback' => '__return_true',
            'callback' => [$service, 'handle'],
        ]);
        add_filter('rest_pre_serve_request', [$service, 'serve'], 10, 4);
    }

    public function handle($request): \WP_REST_Response|\WP_Error
    {
        $purpose = (string) $request->get_param('purpose');
        $id = (string) $request->get_param('download_id');
        $expires = (int) $request->get_param('expires');
        $token = (string) $request->get_param('token');
        if (!in_array($purpose, self::PURPOSES, true) || !preg_match('/^[a-f0-9]{32}$/', $id)
            || !preg_match('/^[a-f0-9]{64}$/', $token) || $expires <= $this->now()
            || $expires > $this->now() + 300 || $this->key() === '') {
            return $this->denied();
        }
        $download = $this->downloads->get($id, $purpose);
        if ($download instanceof \WP_Error || $expires > $download['expires_at']
            || !hash_equals($this->signature($download, $purpose, $expires), $token)) {
            return $this->denied();
        }
        $meta = $download['meta'];
        if ($meta['size'] <= 0 || $meta['size'] > self::MAX_BYTES
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,199}\.zip$/', (string) ($meta['filename'] ?? ''))) {
            return $this->denied();
        }
        $stream = @fopen($download['path'], 'rb');
        if (!is_resource($stream)) { return $this->denied(); }
        $stat = fstat($stream);
        $hash = hash_init('sha256');
        $read = hash_update_stream($hash, $stream, $meta['size'] + 1);
        if (($stat['size'] ?? -1) !== $meta['size'] || $read !== $meta['size']
            || !hash_equals($meta['sha256'], hash_final($hash)) || !rewind($stream)) {
            fclose($stream);
            return $this->denied();
        }
        $response = new \WP_REST_Response(null, 200, [
            'Content-Type' => 'application/zip', 'Content-Length' => (string) $meta['size'],
            'Content-Disposition' => 'attachment; filename="' . $meta['filename'] . '"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
        $this->pending[spl_object_id($response)] = ['request' => $request, 'response' => $response, 'stream' => $stream, 'size' => $meta['size']];
        return $response;
    }

    public function serve($served, $result, $request, $server): bool
    {
        foreach ($this->pending as $id => $pending) {
            if ($pending['request'] !== $request) { continue; }
            unset($this->pending[$id]);
            try {
                if ($served || $result !== $pending['response'] || $result->get_status() !== 200) {
                    return (bool) $served;
                }
                if (strtoupper($request->get_method()) !== 'HEAD') {
                    $output = fopen('php://output', 'wb');
                    if (is_resource($output)) {
                        stream_copy_to_stream($pending['stream'], $output, $pending['size']);
                        fclose($output);
                    }
                }
                return true;
            } finally {
                fclose($pending['stream']);
            }
        }
        return (bool) $served;
    }

    public function __destruct()
    {
        foreach ($this->pending as $pending) { fclose($pending['stream']); }
    }

    private function signature(array $download, string $purpose, int $expires): string
    {
        $meta = $download['meta'];
        return hash_hmac('sha256', implode("\n", [
            (string) get_current_blog_id(), $purpose, $download['download_id'], (string) $expires,
            (string) $meta['size'], $meta['sha256'], $meta['filename'],
        ]), $this->key());
    }

    private function now(): int { return $this->clock ? ($this->clock)() : time(); }
    private function key(): string { return $this->secret ?? (function_exists('wp_salt') ? (string) wp_salt('auth') : ''); }
    private function denied(): \WP_Error
    {
        return new \WP_Error('codi_mcp_download_denied', 'Download URL is invalid or has expired.', ['status' => 403]);
    }
}
