<?php

namespace CodiMcp\Auth;

final class OAuthProfile
{
    private string $serverRoute;
    private string $oauthNamespace;

    public function __construct(?string $serverRoute = null, ?string $oauthNamespace = null, private bool $networkScoped = false)
    {
        $this->serverRoute = trim((string) ($serverRoute ?? CODI_MCP_SERVER_REST_ROUTE), '/');
        $this->oauthNamespace = trim((string) ($oauthNamespace ?? CODI_MCP_OAUTH_REST_NAMESPACE), '/');
        if ($this->serverRoute === '' || $this->oauthNamespace === '') {
            throw new \InvalidArgumentException('OAuth profile routes must not be empty.');
        }
    }

    public function primaryEndpoint(): string
    {
        return rtrim($this->restUrl($this->mcpRestRoute()), '/');
    }

    public function resourceKey(): string
    {
        $route = strtolower($this->mcpRestRoute());
        if ($this->networkScoped) {
            $networkId = function_exists('get_current_network_id') ? max(1, (int) get_current_network_id()) : 1;
            return 'network:' . $networkId . '|' . $route;
        }

        $siteId = function_exists('get_current_blog_id') ? max(1, (int) get_current_blog_id()) : 1;
        return 'site:' . $siteId . '|' . $route;
    }

    public function issuer(): string
    {
        return rtrim($this->restUrl($this->oauthNamespace), '/');
    }

    public function authorizationEndpoint(): string
    {
        return $this->restUrl($this->oauthNamespace . '/oauth/authorize');
    }

    public function tokenEndpoint(): string
    {
        return $this->restUrl($this->oauthNamespace . '/oauth/token');
    }

    public function registrationEndpoint(): string
    {
        return $this->restUrl($this->oauthNamespace . '/oauth/register');
    }

    public function authorizationServerMetadataPath(): string
    {
        $issuerPath = trim((string) parse_url($this->issuer(), PHP_URL_PATH), '/');
        return $this->rootUrl() . '/.well-known/oauth-authorization-server/' . $issuerPath;
    }

    public function protectedResourceMetadataPath(): string
    {
        $resourcePath = trim((string) parse_url($this->primaryEndpoint(), PHP_URL_PATH), '/');
        return $this->rootUrl() . '/.well-known/oauth-protected-resource/' . $resourcePath;
    }

    public function oauthNamespace(): string
    {
        return $this->oauthNamespace;
    }

    public function mcpRestRoute(): string
    {
        return trim(CODI_MCP_SERVER_REST_NAMESPACE, '/') . '/' . $this->serverRoute;
    }

    public function mcpRequestRoute(): string
    {
        return '/' . $this->mcpRestRoute();
    }

    public function isNetworkScoped(): bool
    {
        return $this->networkScoped;
    }

    /** @return string[] */
    public function scopes(): array
    {
        return ['mcp', 'offline_access'];
    }

    /** @return string[] */
    public function resourceScopes(): array
    {
        return ['mcp'];
    }

    /** @return array<string,mixed> */
    public function authorizationServerMetadata(): array
    {
        return [
            'issuer' => $this->issuer(),
            'authorization_endpoint' => $this->authorizationEndpoint(),
            'token_endpoint' => $this->tokenEndpoint(),
            'registration_endpoint' => $this->registrationEndpoint(),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
            'authorization_response_iss_parameter_supported' => true,
            'scopes_supported' => $this->scopes(),
        ];
    }

    /** @return array<string,mixed> */
    public function protectedResourceMetadata(): array
    {
        return [
            'resource' => $this->primaryEndpoint(),
            'authorization_servers' => [$this->issuer()],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => $this->resourceScopes(),
        ];
    }

    public function challenge(): string
    {
        return sprintf(
            'Bearer resource_metadata="%s", scope="mcp"',
            $this->protectedResourceMetadataPath()
        );
    }

    public function resourceAllowed(string $resource): bool
    {
        $candidate = $this->canonicalResource($resource);
        $expected = $this->canonicalResource($this->primaryEndpoint());
        return $candidate !== null && $expected !== null && hash_equals($expected, $candidate);
    }

    private function canonicalResource(string $resource): ?string
    {
        $resource = trim($resource);
        $parts = parse_url($resource);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) && (int) $parts['port'] !== 443 ? ':' . (int) $parts['port'] : '';
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        return 'https://' . $host . $port . $path;
    }

    private function restUrl(string $path): string
    {
        if ($this->networkScoped && function_exists('get_rest_url')) {
            return (string) get_rest_url($this->mainSiteId(), $path);
        }
        if (!$this->networkScoped && function_exists('rest_url')) {
            return (string) rest_url($path);
        }

        return $this->rootUrl() . '/wp-json/' . ltrim($path, '/');
    }

    private function rootUrl(): string
    {
        if ($this->networkScoped) {
            $mainSiteId = $this->mainSiteId();
            if (function_exists('get_home_url')) {
                return rtrim((string) get_home_url($mainSiteId, '/'), '/');
            }
            if (function_exists('get_blog_details')) {
                $site = get_blog_details($mainSiteId);
                if (is_object($site) && !empty($site->home)) {
                    return rtrim((string) $site->home, '/');
                }
            }
        }
        if (function_exists('home_url')) {
            return rtrim((string) home_url('/'), '/');
        }

        return 'https://example.test';
    }

    private function mainSiteId(): int
    {
        $networkId = function_exists('get_current_network_id') ? max(1, (int) get_current_network_id()) : 1;
        if (function_exists('get_main_site_id')) {
            return max(1, (int) get_main_site_id($networkId));
        }
        if (defined('BLOG_ID_CURRENT_SITE')) {
            return max(1, (int) BLOG_ID_CURRENT_SITE);
        }
        return 1;
    }
}
