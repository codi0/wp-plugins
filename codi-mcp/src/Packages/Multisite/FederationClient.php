<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Multisite;

final class FederationClient
{
    private const HEADER = 'X-Codi-MCP-Federation';

    private SiteDirectory $sites;
    private FederationSigner $signer;
    private LocalAbilityRuntime $local;

    public function __construct(SiteDirectory $sites, FederationSigner $signer, LocalAbilityRuntime $local)
    {
        $this->sites = $sites;
        $this->signer = $signer;
        $this->local = $local;
    }

    /** @return array<int,array<string,mixed>>|\WP_Error */
    public function abilities(int $siteId, int $userId)
    {
        if (!$this->sites->canAccessSite($userId, $siteId)) {
            return $this->error('codi_multisite_site_forbidden', 'You do not have access to the requested site.', 403);
        }
        if ($siteId === $this->sites->currentSiteId()) {
            return $this->local->catalog();
        }

        $response = $this->request($siteId, $userId, 'abilities', array());
        if (is_wp_error($response)) {
            return $response;
        }
        return is_array($response['abilities'] ?? null) ? $response['abilities'] : array();
    }

    public function execute(int $siteId, int $userId, string $abilityName, $input = null)
    {
        if (!$this->sites->canAccessSite($userId, $siteId)) {
            return $this->error('codi_multisite_site_forbidden', 'You do not have access to the requested site.', 403);
        }
        if ($siteId === $this->sites->currentSiteId()) {
            return $this->local->execute($abilityName, $input);
        }

        $response = $this->request($siteId, $userId, 'execute', array(
            'ability' => $abilityName,
            'arguments' => $input,
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        return $response['result'] ?? null;
    }

    /** @return array<string,mixed>|\WP_Error */
    private function request(int $siteId, int $userId, string $operation, array $payload)
    {
        if (!function_exists('wp_remote_post')) {
            return $this->error('codi_multisite_http_unavailable', 'WordPress HTTP transport is unavailable for multisite federation.', 503);
        }

        $body = $this->encode(array('operation' => $operation, 'payload' => $payload));
        try {
            $token = $this->signer->issue($siteId, $userId, $operation, $body);
        } catch (\Throwable $exception) {
            return $this->error('codi_multisite_signing_failed', $exception->getMessage(), 500);
        }

        $url = $this->targetUrl($siteId);
        if (is_wp_error($url)) {
            return $url;
        }

        $response = wp_remote_post($url, array(
            'timeout' => 15,
            'redirection' => 0,
            'headers' => array(
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                self::HEADER => $token,
            ),
            'body' => $body,
            'data_format' => 'body',
        ));
        if (is_wp_error($response)) {
            return $this->error('codi_multisite_target_unavailable', 'The target site could not be reached: ' . $response->get_error_message(), 502);
        }

        $status = function_exists('wp_remote_retrieve_response_code')
            ? (int) wp_remote_retrieve_response_code($response)
            : (int) ($response['response']['code'] ?? 0);
        $rawBody = function_exists('wp_remote_retrieve_body') ? (string) wp_remote_retrieve_body($response) : (string) ($response['body'] ?? '');
        $decoded = json_decode($rawBody, true);

        if ($status < 200 || $status >= 300) {
            $code = is_array($decoded) && is_string($decoded['code'] ?? null) ? $decoded['code'] : 'codi_multisite_remote_error';
            $message = is_array($decoded) && is_string($decoded['message'] ?? null)
                ? $decoded['message']
                : 'The target site rejected the federation request.';
            return $this->error($code, $message, $status > 0 ? $status : 502);
        }
        if (!is_array($decoded)) {
            return $this->error('codi_multisite_bad_remote_response', 'The target site returned an invalid federation response.', 502);
        }

        return $decoded;
    }

    /** @return string|\WP_Error */
    private function targetUrl(int $siteId)
    {
        if (function_exists('get_rest_url')) {
            $url = (string) get_rest_url($siteId, trim(CODI_MCP_OAUTH_REST_NAMESPACE, '/') . '/federation');
        } else {
            $details = function_exists('get_blog_details') ? get_blog_details($siteId) : null;
            $home = is_object($details) && isset($details->home) ? (string) $details->home : '';
            $url = rtrim($home, '/') . '/wp-json/' . trim(CODI_MCP_OAUTH_REST_NAMESPACE, '/') . '/federation';
        }

        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        $allowInsecure = function_exists('apply_filters')
            ? (bool) apply_filters('codi_mcp_multisite_allow_insecure_federation', false, $siteId, $url)
            : false;
        if (!is_array($parts)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ($scheme !== 'https' && !($scheme === 'http' && $allowInsecure))) {
            return $this->error('codi_multisite_insecure_target', 'The target site must expose federation over a clean HTTPS URL.', 502);
        }
        return $url;
    }

    private function encode(array $payload): string
    {
        $json = function_exists('wp_json_encode') ? wp_json_encode($payload, JSON_UNESCAPED_SLASHES) : json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('Unable to encode multisite federation request.');
        }
        return $json;
    }

    private function error(string $code, string $message, int $status): \WP_Error
    {
        return new \WP_Error($code, $message, array('status' => $status));
    }
}
