<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Diagnostics;

final class DiagnosticRunner
{
    private DiagnosticStore $store;

    public function __construct(?DiagnosticStore $store = null)
    {
        $this->store = $store ?? new DiagnosticStore();
    }

    public function run(array $input = array())
    {
        $target = $this->targetUrl((string) ($input['url'] ?? ''));
        if ($target instanceof \WP_Error) {
            return $target;
        }

        $config = $this->config($input);
        $userId = $this->authorizingUserId();
        $effectiveUserId = $this->effectiveUserId($input, $userId);
        if ($effectiveUserId instanceof \WP_Error) {
            return $effectiveUserId;
        }
        $issued = $this->store->issue($userId, (string) $target['request_uri'], $config, $effectiveUserId);
        if ($issued instanceof \WP_Error) {
            return $issued;
        }

        $response = wp_remote_get((string) $target['url'], array(
            'timeout' => 15,
            'redirection' => 0,
            'limit_response_size' => 65536,
            'headers' => array(
                'X-Codi-Diagnostic-Token' => $issued['token'],
                'Cache-Control' => 'no-cache',
            ),
        ));

        $capture = $this->store->get($issued['diagnostic_id']);
        if ($capture === false) {
            $message = $response instanceof \WP_Error
                ? 'The diagnostic request failed before a capture was returned: ' . $response->get_error_message()
                : 'The diagnostic request completed without returning a capture.';
            return new \WP_Error('codi_mcp_diagnostic_capture_missing', $message);
        }

        return $this->summary($issued['diagnostic_id'], $capture);
    }

    public function arm(array $input = array())
    {
        $target = $this->targetUrl((string) ($input['url'] ?? ''));
        if ($target instanceof \WP_Error) {
            return $target;
        }

        $method = strtoupper(trim((string) ($input['method'] ?? 'GET')));
        if (!in_array($method, array('GET', 'POST', 'PUT', 'PATCH', 'DELETE'), true)) {
            return new \WP_Error('codi_mcp_diagnostic_method_invalid', 'method must be GET, POST, PUT, PATCH, or DELETE.');
        }

        $issued = $this->store->issue(
            $this->authorizingUserId(),
            (string) $target['request_uri'],
            $this->config($input),
            null,
            $method,
            'request'
        );
        if ($issued instanceof \WP_Error) {
            return $issued;
        }

        return array(
            'diagnostic_id' => (string) $issued['diagnostic_id'],
            'target' => (string) $target['request_uri'],
            'method' => $method,
            'authorization' => array(
                'header_name' => 'X-Codi-Diagnostic-Token',
                'header_value' => (string) $issued['token'],
                'expires_at' => gmdate('c', (int) ($issued['expires_at'] ?? time())),
            ),
        );
    }

    public function inspect(array $input = array())
    {
        $diagnosticId = strtolower(trim((string) ($input['diagnostic_id'] ?? '')));
        $section = strtolower(trim((string) ($input['section'] ?? 'overview')));
        $capture = $this->store->get($diagnosticId);
        if ($capture === false) {
            return new \WP_Error('codi_mcp_diagnostic_not_found', 'The diagnostic capture was not found or has expired.');
        }
        if (!array_key_exists($section, $capture) || !is_array($capture[$section])) {
            return new \WP_Error('codi_mcp_diagnostic_section_unavailable', 'The requested diagnostic section is unavailable.');
        }

        return array('section' => $section, 'data' => $capture[$section]);
    }

    private function summary(string $diagnosticId, array $capture): array
    {
        $overview = is_array($capture['overview'] ?? null) ? $capture['overview'] : array();
        $request = is_array($overview['request'] ?? null) ? $overview['request'] : array();
        $summary = is_array($overview['summary'] ?? null) ? $overview['summary'] : array();

        return array(
            'diagnostic_id' => $diagnosticId,
            'target' => (string) ($request['url'] ?? ''),
            'request' => array(
                'status' => (int) ($request['status'] ?? 0),
                'duration_ms' => (float) ($request['duration_ms'] ?? 0.0),
                'peak_memory_bytes' => (int) ($request['peak_memory_bytes'] ?? 0),
                'type' => (string) ($request['type'] ?? 'unknown'),
                'effective_user_id' => (int) ($request['user_id'] ?? 0),
            ),
            'summary' => array(
                'errors' => (int) ($summary['errors'] ?? 0),
                'wp_errors' => (int) ($summary['wp_errors'] ?? 0),
                'queries' => (int) ($summary['queries'] ?? 0),
                'query_time_ms' => (float) ($summary['query_time_ms'] ?? 0.0),
                'state_changes' => (int) ($summary['state_changes'] ?? 0),
                'http_requests' => (int) ($summary['http_requests'] ?? 0),
                'redirects' => (int) ($summary['redirects'] ?? 0),
                'security_failures' => (int) ($summary['security_failures'] ?? 0),
                'assets' => (int) ($summary['assets'] ?? 0),
            ),
            'available_sections' => array_values(array_filter(array_keys($capture), static fn (string $key): bool => $key !== 'overview')),
        );
    }

    private function config(array $input): array
    {
        return array(
            'include_assets' => !array_key_exists('include_assets', $input) || (bool) $input['include_assets'],
            'hooks' => array_values(array_filter(
                $this->names((array) ($input['hooks'] ?? array()), 20),
                static fn (string $hook): bool => strtolower($hook) !== 'all'
            )),
            'capabilities' => $this->names((array) ($input['capabilities'] ?? array()), 20),
            'explain_slow_queries' => (bool) ($input['explain_slow_queries'] ?? false),
        );
    }

    private function authorizingUserId(): int
    {
        return function_exists('get_current_user_id')
            ? (int) get_current_user_id()
            : (function_exists('wp_get_current_user') ? (int) (wp_get_current_user()->ID ?? 0) : 0);
    }

    private function effectiveUserId(array $input, int $authorizingUserId): int|\WP_Error
    {
        if (!array_key_exists('actor_user_id', $input)) {
            return max(0, $authorizingUserId);
        }

        $actorUserId = (int) $input['actor_user_id'];
        if ($actorUserId < 0) {
            return new \WP_Error('codi_mcp_diagnostic_actor_invalid', 'actor_user_id must be zero for anonymous or a positive WordPress user ID.');
        }
        if ($actorUserId === 0) {
            return 0;
        }
        if (!function_exists('get_userdata') || !get_userdata($actorUserId)) {
            return new \WP_Error('codi_mcp_diagnostic_actor_not_found', 'The requested diagnostic actor does not exist.');
        }

        if (function_exists('is_multisite') && is_multisite()) {
            $superAdmin = function_exists('is_super_admin') && is_super_admin($actorUserId);
            $siteMember = function_exists('is_user_member_of_blog') && is_user_member_of_blog(
                $actorUserId,
                function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0
            );
            if (!$superAdmin && !$siteMember) {
                return new \WP_Error('codi_mcp_diagnostic_actor_not_member', 'The requested diagnostic actor is not a member of the current site.');
            }
        }

        return $actorUserId;
    }

    /** @return array{url:string,request_uri:string}|\WP_Error */
    private function targetUrl(string $input): array|\WP_Error
    {
        $input = trim($input);
        if ($input === '' || strlen($input) > 2000) {
            return new \WP_Error('codi_mcp_diagnostic_url_invalid', 'url must be a non-empty same-site URL of at most 2000 bytes.');
        }
        if (str_contains($input, '#')) {
            return new \WP_Error('codi_mcp_diagnostic_url_fragment', 'Diagnostic URLs must not contain fragments.');
        }

        $bases = array_filter(array_unique(array(
            function_exists('home_url') ? (string) home_url('/') : '',
            function_exists('site_url') ? (string) site_url('/') : '',
        )));
        if ($bases === array()) {
            return new \WP_Error('codi_mcp_diagnostic_url_unavailable', 'The current WordPress site URL is unavailable.');
        }

        $absolute = str_starts_with($input, '/') ? $this->origin((string) reset($bases)) . $input : $input;
        $parts = parse_url($absolute);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), array('http', 'https'), true)
            || (string) ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass'])) {
            return new \WP_Error('codi_mcp_diagnostic_url_invalid', 'url must be an HTTP(S) URL on the current WordPress origin.');
        }

        $origin = $this->origin($absolute);
        $allowed = false;
        foreach ($bases as $base) {
            if (hash_equals(strtolower($this->origin((string) $base)), strtolower($origin))) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            return new \WP_Error('codi_mcp_diagnostic_url_off_origin', 'Diagnostic requests are restricted to the current WordPress origin.');
        }

        $path = (string) ($parts['path'] ?? '/');
        $query = (string) ($parts['query'] ?? '');
        $requestUri = ($path !== '' ? $path : '/') . ($query !== '' ? '?' . $query : '');
        return array('url' => $origin . $requestUri, 'request_uri' => $requestUri);
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return '';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        return $scheme . '://' . $host . $port;
    }

    /** @return string[] */
    private function names(array $items, int $limit): array
    {
        $result = array();
        foreach ($items as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $name = trim((string) $item);
            if ($name === '' || strlen($name) > 191) {
                continue;
            }
            $result[$name] = true;
            if (count($result) >= $limit) {
                break;
            }
        }
        return array_keys($result);
    }
}