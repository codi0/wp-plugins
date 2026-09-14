<?php

namespace CodiMcp\Auth;

final class OAuthController
{
    private const NONCE_FIELD = 'codi_mcp_consent_nonce';

    private OAuthServer $server;
    private OAuthProfile $profile;

    public function __construct(OAuthServer $server, OAuthProfile $profile)
    {
        $this->server = $server;
        $this->profile = $profile;
    }

    public function registerClient($request = null)
    {
        $result = $this->server->registerClient($this->params($request), $this->registrationSource());
        return $this->responseFromPayload($result, isset($result['error']) ? null : 201);
    }

    public function token($request = null)
    {
        return $this->responseFromPayload($this->server->token($this->params($request)));
    }

    public function authorize($request = null)
    {
        $params = $this->params($request);
        $prepared = $this->server->prepareAuthorization($params);
        if (empty($prepared['ok'])) {
            return $this->authorizationError($prepared);
        }

        $userId = $this->currentUserId();
        if ($userId < 1) {
            return $this->loginRedirect($prepared);
        }

        if ($this->requestMethod($request) !== 'POST') {
            return $this->consentResponse($prepared, $userId);
        }

        $decision = strtolower(trim((string) ($params['decision'] ?? '')));
        if (!$this->validNonce($params, $prepared, $userId)) {
            return $this->consentResponse($prepared, $userId, 'The approval request expired. Please approve again.', 403);
        }

        if ($decision === 'deny') {
            return $this->responseFromPayload($this->server->deny($prepared, $userId));
        }
        if ($decision !== 'approve') {
            return $this->authorizationError(array_merge($prepared, [
                'status' => 400,
                'error' => 'invalid_request',
                'error_description' => 'decision must be approve or deny.',
            ]));
        }

        return $this->responseFromPayload($this->server->authorize($prepared, $userId));
    }

    /** @param array<string,mixed> $payload */
    private function authorizationError(array $payload)
    {
        $redirectUri = trim((string) ($payload['redirect_uri'] ?? ''));
        if ($redirectUri !== '') {
            $query = [
                'error' => (string) ($payload['error'] ?? 'invalid_request'),
                'state' => (string) ($payload['state'] ?? ''),
                'iss' => $this->profile->issuer(),
            ];
            if (!empty($payload['error_description'])) {
                $query['error_description'] = (string) $payload['error_description'];
            }
            $separator = strpos($redirectUri, '?') === false ? '?' : '&';
            return $this->response([], 302, ['Location' => $redirectUri . $separator . http_build_query(array_filter($query, 'strlen'))]);
        }

        return $this->responseFromPayload($payload);
    }

    /** @param array<string,mixed> $prepared */
    private function loginRedirect(array $prepared)
    {
        $query = $prepared;
        unset($query['ok'], $query['client_name']);
        $authorizationUrl = $this->profile->authorizationEndpoint() . '?' . http_build_query($query);
        $loginUrl = function_exists('wp_login_url') ? (string) wp_login_url($authorizationUrl) : $authorizationUrl;

        return $this->response([], 302, ['Location' => $loginUrl]);
    }

    /** @param array<string,mixed> $prepared */
    private function consentResponse(array $prepared, int $userId, string $warning = '', int $status = 200)
    {
        $nonceAction = $this->nonceAction($prepared, $userId);
        $nonce = function_exists('wp_create_nonce') ? (string) wp_create_nonce($nonceAction) : hash('sha256', $nonceAction);

        $hidden = '';
        foreach ($prepared as $key => $value) {
            if ($key === 'ok' || $key === 'client_name' || !is_scalar($value)) {
                continue;
            }
            $hidden .= '<input type="hidden" name="' . $this->escape((string) $key) . '" value="' . $this->escape((string) $value) . '">';
        }
        $hidden .= '<input type="hidden" name="' . self::NONCE_FIELD . '" value="' . $this->escape($nonce) . '">';

        $warningHtml = $warning === '' ? '' : '<p style="padding:10px;border:1px solid #d63638">' . $this->escape($warning) . '</p>';
        $scopeItems = '';
        foreach (array_filter(explode(' ', (string) ($prepared['scope'] ?? 'mcp')), 'strlen') as $scope) {
            $label = $scope === 'mcp'
                ? ($this->profile->isNetworkScoped()
                    ? 'Use the MCP abilities exposed by this WordPress network'
                    : 'Use the MCP abilities enabled for this site')
                : ($scope === 'offline_access' ? 'Stay connected using refresh access' : $scope);
            $scopeItems .= '<li>' . $this->escape($label) . '</li>';
        }

        $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Authorize Codi MCP</title></head>'
            . '<body style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;max-width:680px;margin:40px auto;padding:0 16px;line-height:1.5">'
            . '<h1>Authorize Codi MCP</h1>'
            . '<p><strong>' . $this->escape((string) ($prepared['client_name'] ?? 'Connected app')) . '</strong> wants to connect to this WordPress ' . ($this->profile->isNetworkScoped() ? 'network' : 'site') . '.</p>'
            . $warningHtml
            . '<p><strong>Signed in as:</strong> ' . $this->escape($this->userLabel($userId)) . '</p>'
            . '<p><strong>Resource:</strong> ' . $this->escape((string) ($prepared['resource'] ?? '')) . '</p>'
            . '<h2>Requested access</h2><ul>' . $scopeItems . '</ul>'
            . '<form method="post" action="' . $this->escape($this->profile->authorizationEndpoint()) . '">' . $hidden
            . '<button type="submit" name="decision" value="approve" style="margin-right:10px;padding:8px 14px">Approve</button>'
            . '<button type="submit" name="decision" value="deny" style="padding:8px 14px">Deny</button>'
            . '</form></body></html>';

        return $this->response($html, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
        ]);
    }

    private function currentUserId(): int
    {
        $userId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($userId > 0) {
            return $userId;
        }

        if (function_exists('wp_validate_auth_cookie') && function_exists('wp_set_current_user')) {
            $userId = (int) wp_validate_auth_cookie('', 'logged_in');
            if ($userId > 0) {
                wp_set_current_user($userId);
            }
        }

        return $userId;
    }

    /** @param array<string,mixed> $params @param array<string,mixed> $prepared */
    private function validNonce(array $params, array $prepared, int $userId): bool
    {
        $nonce = trim((string) ($params[self::NONCE_FIELD] ?? ''));
        if ($nonce === '') {
            return false;
        }

        $action = $this->nonceAction($prepared, $userId);
        return function_exists('wp_verify_nonce')
            ? (bool) wp_verify_nonce($nonce, $action)
            : hash_equals(hash('sha256', $action), $nonce);
    }

    /** @param array<string,mixed> $prepared */
    private function nonceAction(array $prepared, int $userId): string
    {
        unset($prepared['client_name']);
        ksort($prepared);
        return 'codi_mcp_oauth|' . $userId . '|' . hash('sha256', json_encode($prepared));
    }

    private function userLabel(int $userId): string
    {
        if (function_exists('get_userdata')) {
            $user = get_userdata($userId);
            if (is_object($user) && !empty($user->user_login)) {
                return (string) $user->user_login;
            }
        }
        return (string) $userId;
    }

    private function requestMethod($request): string
    {
        return is_object($request) && method_exists($request, 'get_method')
            ? strtoupper((string) $request->get_method())
            : 'GET';
    }

    private function registrationSource(): string
    {
        $address = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        return $address === '' ? 'unknown' : $address;
    }

    /** @return array<string,mixed> */
    private function params($request): array
    {
        if (is_array($request)) {
            return $request;
        }
        if (is_object($request) && method_exists($request, 'get_params')) {
            $params = $request->get_params();
            return is_array($params) ? $params : [];
        }
        return [];
    }

    /** @param array<string,mixed> $payload */
    private function responseFromPayload(array $payload, ?int $forcedStatus = null)
    {
        $status = $forcedStatus ?? (int) ($payload['status'] ?? 200);
        $headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];
        if (!empty($payload['location'])) {
            $headers['Location'] = (string) $payload['location'];
        }

        unset($payload['status'], $payload['headers'], $payload['location']);
        return $this->response($payload, $status, $headers);
    }

    private function response($body, int $status = 200, array $headers = [])
    {
        if (class_exists('WP_REST_Response')) {
            return new \WP_REST_Response($body, $status, $headers);
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
