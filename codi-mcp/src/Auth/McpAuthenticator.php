<?php

namespace CodiMcp\Auth;

final class McpAuthenticator
{
    private OAuthServer $server;
    private OAuthProfile $profile;
    private AuthHeader $headers;

    public function __construct(OAuthServer $server, OAuthProfile $profile, AuthHeader $headers)
    {
        $this->server = $server;
        $this->profile = $profile;
        $this->headers = $headers;
    }

    public function determineCurrentUser($userId)
    {
        if ((int) $userId > 0 || !$this->isMcpRequestUri()) {
            return $userId;
        }

        $token = $this->headers->bearerToken();
        if ($token === '') {
            return $userId;
        }

        // WordPress current-user resolution is re-entrant. Keep this phase free of
        // rest_url()/home_url() calls and reject nested resolution attempts.
        static $resolving = false;
        if ($resolving) {
            return $userId;
        }
        $resolving = true;

        try {
            $resolved = $this->server->userIdForBearerTokenEarly($token);
            return $resolved !== null ? $resolved : $userId;
        } finally {
            $resolving = false;
        }
    }

    public function preDispatch($result, $server = null, $request = null)
    {
        if ($result !== null || !$this->isMcpRequest($request)) {
            return $result;
        }

        $authorization = $this->headers->fromRequest($request);
        if ($authorization === '') {
            $authorization = $this->headers->value();
        }

        $token = $this->headers->bearerTokenFromRequest($request);
        if ($token === '') {
            $token = $this->headers->bearerToken();
        }

        $currentUserId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($token !== '') {
            $userId = $this->server->userIdForBearerToken($token);
            if ($userId !== null) {
                if ($currentUserId < 1 && function_exists('wp_set_current_user')) {
                    wp_set_current_user($userId);
                }
                return $result;
            }
        }

        if ($currentUserId > 0) {
            return $result;
        }

        if ($authorization !== '' && stripos($authorization, 'Bearer ') !== 0) {
            return $result;
        }

        // The Adapter's transport permission callback remains the fail-closed gate.
        return $result;
    }

    public function postDispatch($response, $server = null, $request = null)
    {
        if (!$this->isMcpRequest($request) || !is_object($response) || !method_exists($response, 'get_status')) {
            return $response;
        }
        if ((int) $response->get_status() !== 401) {
            return $response;
        }

        if (method_exists($response, 'header')) {
            $response->header('WWW-Authenticate', $this->profile->challenge());
            $response->header('Cache-Control', 'no-store');
        }

        return $response;
    }

    public function isMcpRequest($request): bool
    {
        if (!is_object($request) || !method_exists($request, 'get_route')) {
            return false;
        }
        return rtrim((string) $request->get_route(), '/') === $this->profile->mcpRequestRoute();
    }

    public function isMcpRequestUri(): bool
    {
        $path = strtolower(rtrim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/'));
        $route = strtolower($this->profile->mcpRequestRoute());

        return $path !== ''
            && strlen($path) >= strlen($route)
            && substr($path, -strlen($route)) === $route;
    }

}
