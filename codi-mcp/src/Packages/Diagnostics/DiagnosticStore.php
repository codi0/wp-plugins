<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Diagnostics;

final class DiagnosticStore
{
    private const AUTH_PREFIX = 'codi_mcp_diag_auth_';
    private const CAPTURE_PREFIX = 'codi_mcp_diag_capture_';
    private const AUTH_TTL = 60;
    private const CAPTURE_TTL = 300;

    /** @return array{token:string,diagnostic_id:string}|\WP_Error */
    public function issue(
        int $authorizingUserId,
        string $requestUri,
        array $config = array(),
        ?int $effectiveUserId = null,
        string $method = 'GET',
        string $identityMode = 'fixed'
    ): array|\WP_Error
    {
        if ($authorizingUserId < 1) {
            return new \WP_Error('codi_mcp_diagnostic_user_required', 'A logged-in WordPress user is required to run a diagnostic request.');
        }
        if (!function_exists('set_transient')) {
            return new \WP_Error('codi_mcp_diagnostic_store_unavailable', 'WordPress transient storage is unavailable.');
        }

        $method = strtoupper(trim($method));
        if (!in_array($method, array('GET', 'POST', 'PUT', 'PATCH', 'DELETE'), true)) {
            return new \WP_Error('codi_mcp_diagnostic_method_invalid', 'The diagnostic HTTP method is not supported.');
        }
        $identityMode = strtolower(trim($identityMode));
        if (!in_array($identityMode, array('fixed', 'request'), true)) {
            return new \WP_Error('codi_mcp_diagnostic_identity_invalid', 'The diagnostic identity mode is invalid.');
        }

        $token = bin2hex(random_bytes(32));
        $diagnosticId = bin2hex(random_bytes(16));
        $effectiveUserId ??= $authorizingUserId;
        $effectiveUserId = max(0, $effectiveUserId);
        $record = array(
            'diagnostic_id' => $diagnosticId,
            'authorized_by_user_id' => $authorizingUserId,
            'user_id' => $identityMode === 'fixed' ? $effectiveUserId : 0,
            'identity_mode' => $identityMode,
            'request_uri_hash' => hash('sha256', $this->normalizeRequestUri($requestUri)),
            'method' => $method,
            'expires_at' => time() + self::AUTH_TTL,
            'config' => $config,
        );

        if (!set_transient($this->authKey($token), $record, self::AUTH_TTL)) {
            return new \WP_Error('codi_mcp_diagnostic_store_failed', 'The one-time diagnostic authorization could not be stored.');
        }

        return array('token' => $token, 'diagnostic_id' => $diagnosticId, 'expires_at' => (int) $record['expires_at']);
    }

    /** @return array<string,mixed>|\WP_Error */
    public function consume(string $token, string $requestUri, string $method): array|\WP_Error
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token) || !function_exists('get_transient') || !function_exists('delete_transient')) {
            return new \WP_Error('codi_mcp_diagnostic_token_invalid', 'The diagnostic authorization is invalid.');
        }

        $key = $this->authKey($token);
        $record = get_transient($key);
        $deleted = delete_transient($key);

        if (!is_array($record) || (int) ($record['expires_at'] ?? 0) < time()) {
            return new \WP_Error('codi_mcp_diagnostic_token_expired', 'The diagnostic authorization is missing or expired.');
        }
        if (!$deleted) {
            return new \WP_Error('codi_mcp_diagnostic_token_consume_failed', 'The one-time diagnostic authorization could not be consumed safely.');
        }
        if (strtoupper($method) !== (string) ($record['method'] ?? 'GET')) {
            return new \WP_Error('codi_mcp_diagnostic_method_mismatch', 'The diagnostic authorization does not permit this HTTP method.');
        }
        if (!hash_equals((string) ($record['request_uri_hash'] ?? ''), hash('sha256', $this->normalizeRequestUri($requestUri)))) {
            return new \WP_Error('codi_mcp_diagnostic_target_mismatch', 'The diagnostic authorization does not match this request target.');
        }

        return $record;
    }

    public function save(string $diagnosticId, array $capture): bool
    {
        return preg_match('/^[a-f0-9]{32}$/', $diagnosticId) === 1
            && function_exists('set_transient')
            && set_transient(self::CAPTURE_PREFIX . $diagnosticId, $capture, self::CAPTURE_TTL);
    }

    /** @return array<string,mixed>|false */
    public function get(string $diagnosticId): array|false
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $diagnosticId) || !function_exists('get_transient')) {
            return false;
        }
        $capture = get_transient(self::CAPTURE_PREFIX . $diagnosticId);
        return is_array($capture) ? $capture : false;
    }

    private function authKey(string $token): string
    {
        return self::AUTH_PREFIX . substr(hash('sha256', $token), 0, 32);
    }

    private function normalizeRequestUri(string $uri): string
    {
        $parts = parse_url($uri);
        if (!is_array($parts)) {
            return '/';
        }
        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        $query = (string) ($parts['query'] ?? '');
        return $path . ($query !== '' ? '?' . $query : '');
    }
}