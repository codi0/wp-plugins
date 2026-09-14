<?php

namespace CodiMcp\Auth;

use CodiMcp\Audit\AuditLog;

final class OAuthServer
{
    public const ACCESS_TOKEN_TTL = 3600;
    public const REFRESH_TOKEN_TTL = 2592000;
    private const AUTH_CODE_TTL = 300;
    private const MAX_CLIENTS = 200;
    private const UNUSED_CLIENT_TTL = 3600;
    private const USED_CLIENT_IDLE_TTL = 7776000;
    private const REGISTRATION_WINDOW = 3600;
    private const REGISTRATION_PER_SOURCE_LIMIT = 20;
    private const REGISTRATION_GLOBAL_LIMIT = 100;
    private const MAX_REDIRECT_URIS = 10;
    private const MAX_REDIRECT_URI_LENGTH = 2048;
    private const MAX_CLIENT_NAME_LENGTH = 200;

    private OAuthStore $store;
    private OAuthProfile $profile;
    private AuditLog $audit;

    public function __construct(OAuthStore $store, OAuthProfile $profile, AuditLog $audit)
    {
        $this->store = $store;
        $this->profile = $profile;
        $this->audit = $audit;
    }

    /** @return array<string,mixed> */
    public function registerClient(array $input, string $registrationSource): array
    {
        try {
            $retryAfter = $this->store->consumeRegistrationBudget(
                $registrationSource,
                self::REGISTRATION_PER_SOURCE_LIMIT,
                self::REGISTRATION_GLOBAL_LIMIT,
                self::REGISTRATION_WINDOW
            );
        } catch (\Throwable) {
            return $this->error('server_error', 503, 'OAuth registration storage is unavailable.');
        }
        if ($retryAfter > 0) {
            return array_merge(
                $this->error('temporarily_unavailable', 429, 'OAuth client registration rate limit reached.'),
                array('headers' => array('Retry-After' => (string) $retryAfter), 'retry_after' => $retryAfter)
            );
        }

        $rawRedirectUris = (array) ($input['redirect_uris'] ?? array());
        if (count($rawRedirectUris) > self::MAX_REDIRECT_URIS) {
            return $this->error('invalid_client_metadata', 400, 'Too many redirect URIs.');
        }
        $redirectUris = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            $rawRedirectUris
        ), 'strlen')));

        if ($redirectUris === []) {
            return $this->error('invalid_client_metadata', 400, 'redirect_uris is required.');
        }
        foreach ($redirectUris as $redirectUri) {
            if (strlen($redirectUri) > self::MAX_REDIRECT_URI_LENGTH) {
                return $this->error('invalid_client_metadata', 400, 'A redirect URI is too long.');
            }
            if (!$this->validRedirectUri($redirectUri)) {
                return $this->error('invalid_redirect_uri', 400, 'Redirect URIs must use HTTPS, except localhost callbacks may use HTTP.');
            }
        }

        $grantTypes = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            (array) ($input['grant_types'] ?? ['authorization_code', 'refresh_token'])
        ), 'strlen')));
        if ($grantTypes === []) {
            return $this->error('invalid_client_metadata', 400, 'grant_types is required.');
        }
        foreach ($grantTypes as $grantType) {
            if (!in_array($grantType, ['authorization_code', 'refresh_token'], true)) {
                return $this->error('invalid_client_metadata', 400, 'Unsupported grant type.');
            }
        }

        $authMethod = trim((string) ($input['token_endpoint_auth_method'] ?? 'none'));
        if ($authMethod !== 'none') {
            return $this->error('invalid_client_metadata', 400, 'Only public OAuth clients are supported.');
        }

        $applicationType = strtolower(trim((string) ($input['application_type'] ?? 'web')));
        if (!in_array($applicationType, ['web', 'native'], true)) {
            return $this->error('invalid_client_metadata', 400, 'Unsupported application_type.');
        }

        $clientName = trim((string) ($input['client_name'] ?? 'Codi MCP Client'));
        if ($clientName === '' || strlen($clientName) > self::MAX_CLIENT_NAME_LENGTH) {
            return $this->error('invalid_client_metadata', 400, 'client_name must be between 1 and 200 bytes.');
        }

        $clientId = $this->secureToken('codi_client', 12);
        $client = [
            'client_id' => $clientId,
            'client_name' => $clientName,
            'redirect_uris' => $redirectUris,
            'grant_types' => $grantTypes,
            'token_endpoint_auth_method' => $authMethod,
            'application_type' => $applicationType,
            'created_at' => time(),
            'used_at' => 0,
            'last_used_at' => 0,
        ];

        try {
            $registered = $this->store->registerClient(
                $clientId,
                $client,
                self::MAX_CLIENTS,
                self::UNUSED_CLIENT_TTL,
                self::USED_CLIENT_IDLE_TTL
            );
        } catch (\Throwable) {
            return $this->error('server_error', 503, 'OAuth client registration storage is unavailable.');
        }
        if (!$registered) {
            return $this->error('temporarily_unavailable', 429, 'OAuth client registration capacity reached.');
        }
        try {
            $this->audit->recordOAuth('oauth.client_registered', $clientId, 'succeeded');
        } catch (\Throwable) {
            try {
                $this->store->deleteClient($clientId);
            } catch (\Throwable) {
                $this->logCleanupFailure('client registration compensation');
            }
            return $this->error('server_error', 503, 'OAuth audit storage is unavailable.');
        }
        return [
            'client_id' => $clientId,
            'client_name' => $client['client_name'],
            'redirect_uris' => $redirectUris,
            'grant_types' => $grantTypes,
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'application_type' => $applicationType,
        ];
    }

    public function client(string $clientId): ?array
    {
        return $this->store->client($clientId);
    }

    /** @return array<string,mixed> */
    public function prepareAuthorization(array $input): array
    {
        $clientId = trim((string) ($input['client_id'] ?? ''));
        $redirectUri = trim((string) ($input['redirect_uri'] ?? ''));
        $client = $clientId !== '' ? $this->store->client($clientId) : null;
        if (!is_array($client)) {
            return $this->error('invalid_client', 400);
        }

        if ($redirectUri === '' || !in_array($redirectUri, (array) ($client['redirect_uris'] ?? []), true)) {
            return $this->error('invalid_redirect_uri', 400);
        }

        $errorContext = [
            'redirect_uri' => $redirectUri,
            'state' => (string) ($input['state'] ?? ''),
        ];

        if (trim((string) ($input['response_type'] ?? 'code')) !== 'code') {
            return array_merge($this->error('unsupported_response_type', 400), $errorContext);
        }
        if (!in_array('authorization_code', (array) ($client['grant_types'] ?? []), true)) {
            return array_merge($this->error('unauthorized_client', 400), $errorContext);
        }

        $challenge = trim((string) ($input['code_challenge'] ?? ''));
        $challengeMethod = strtoupper(trim((string) ($input['code_challenge_method'] ?? 'S256')));
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge)) {
            return array_merge($this->error('invalid_request', 400, 'A valid PKCE S256 code_challenge is required.'), $errorContext);
        }
        if ($challengeMethod !== 'S256') {
            return array_merge($this->error('invalid_request', 400, 'Only PKCE S256 is supported.'), $errorContext);
        }

        $resource = trim((string) ($input['resource'] ?? ''));
        if (!$this->profile->resourceAllowed($resource)) {
            return array_merge($this->error('invalid_target', 400), $errorContext);
        }
        $resource = $this->profile->primaryEndpoint();

        $scopes = $this->normalizeScopes((string) ($input['scope'] ?? ''));
        if ($scopes === null) {
            return array_merge($this->error('invalid_scope', 400), $errorContext);
        }

        return [
            'ok' => true,
            'client_id' => $clientId,
            'client_name' => (string) ($client['client_name'] ?? 'Connected app'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'scope' => implode(' ', $scopes),
            'state' => (string) ($input['state'] ?? ''),
            'resource' => $resource,
        ];
    }

    /** @return array<string,mixed> */
    public function authorize(array $prepared, int $userId): array
    {
        if ($userId < 1 || empty($prepared['ok'])) {
            return $this->error('access_denied', 403);
        }

        $code = $this->secureToken('codi_code', 24);
        $record = [
            'client_id' => (string) $prepared['client_id'],
            'redirect_uri' => (string) $prepared['redirect_uri'],
            'code_challenge' => (string) $prepared['code_challenge'],
            'resource' => (string) $prepared['resource'],
            'scope' => (string) $prepared['scope'],
            'user_id' => $userId,
        ];
        $codeHash = $this->hash($code);
        $codeSaved = false;
        try {
            $this->audit->recordOAuth('oauth.authorization', (string) $prepared['client_id'], 'approved', $userId);
            $this->store->saveCode($codeHash, $record, self::AUTH_CODE_TTL);
            $codeSaved = true;
            $this->touchClient((string) $prepared['client_id']);
        } catch (\Throwable) {
            if ($codeSaved) {
                try {
                    $this->store->deleteCode($codeHash);
                } catch (\Throwable) {
                    $this->logCleanupFailure('authorization-code compensation');
                }
            }
            return $this->error('server_error', 503, 'OAuth authorization storage is unavailable.');
        }

        return [
            'status' => 302,
            'location' => $this->appendQuery((string) $prepared['redirect_uri'], [
                'code' => $code,
                'state' => (string) $prepared['state'],
                'iss' => $this->profile->issuer(),
            ]),
        ];
    }

    /** @return array<string,mixed> */
    public function deny(array $prepared, int $userId): array
    {
        try {
            $this->audit->recordOAuth('oauth.authorization', (string) ($prepared['client_id'] ?? ''), 'denied', $userId);
        } catch (\Throwable) {
            return $this->error('server_error', 503, 'OAuth audit storage is unavailable.');
        }
        return [
            'status' => 302,
            'location' => $this->appendQuery((string) ($prepared['redirect_uri'] ?? ''), [
                'error' => 'access_denied',
                'state' => (string) ($prepared['state'] ?? ''),
                'iss' => $this->profile->issuer(),
            ]),
        ];
    }

    /** @return array<string,mixed> */
    public function token(array $input): array
    {
        $grantType = trim((string) ($input['grant_type'] ?? ''));
        if ($grantType === 'authorization_code') {
            return $this->tokenForCode($input);
        }
        if ($grantType === 'refresh_token') {
            return $this->tokenForRefresh($input);
        }

        return $this->error('unsupported_grant_type', 400);
    }

    public function userIdForBearerTokenEarly(string $token): ?int
    {
        $record = $this->bearerRecord($token);
        if (!is_array($record)) {
            return null;
        }

        $resourceKey = trim((string) ($record['resource_key'] ?? ''));
        if ($resourceKey === '' || !hash_equals($this->profile->resourceKey(), $resourceKey)) {
            return null;
        }

        return $this->userIdFromBearerRecord($record);
    }

    public function userIdForBearerToken(string $token): ?int
    {
        $record = $this->bearerRecord($token);
        if (!is_array($record)) {
            return null;
        }

        $resource = rtrim((string) ($record['resource'] ?? ''), '/');
        if ($resource === '' || !$this->profile->resourceAllowed($resource)) {
            return null;
        }

        $resourceKey = trim((string) ($record['resource_key'] ?? ''));
        if ($resourceKey === '' || !hash_equals($this->profile->resourceKey(), $resourceKey)) {
            return null;
        }

        return $this->userIdFromBearerRecord($record);
    }

    private function bearerRecord(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $record = $this->store->accessToken($this->hash($token));
        return is_array($record) ? $record : null;
    }

    private function userIdFromBearerRecord(array $record): ?int
    {
        if (!in_array('mcp', preg_split('/\s+/', trim((string) ($record['scope'] ?? ''))) ?: [], true)) {
            return null;
        }

        $userId = (int) ($record['user_id'] ?? 0);
        return $userId > 0 ? $userId : null;
    }

    /** @return array<string,mixed> */
    private function tokenForCode(array $input): array
    {
        $code = trim((string) ($input['code'] ?? ''));
        $verifier = trim((string) ($input['code_verifier'] ?? ''));
        $clientId = trim((string) ($input['client_id'] ?? ''));
        $redirectUri = trim((string) ($input['redirect_uri'] ?? ''));
        if ($code === '' || !preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier) || $clientId === '' || $redirectUri === '') {
            return $this->error('invalid_request', 400);
        }

        try {
            $result = $this->store->exchangeCode($this->hash($code), function (array $record) use ($input, $verifier, $clientId, $redirectUri): array {
                $client = $this->store->client($clientId);
                if (!is_array($client) || $clientId !== (string) ($record['client_id'] ?? '')) {
                    return ['consume' => false, 'result' => $this->error('invalid_client', 401)];
                }
                if (!$this->validClientSecret($input, $client)) {
                    return ['consume' => false, 'result' => $this->error('invalid_client', 401)];
                }
                if ($redirectUri !== (string) ($record['redirect_uri'] ?? '')) {
                    return ['consume' => false, 'result' => $this->error('invalid_grant', 400)];
                }
                if (!hash_equals((string) ($record['code_challenge'] ?? ''), $this->s256($verifier))) {
                    return ['consume' => false, 'result' => $this->error('invalid_grant', 400)];
                }
                if (!in_array('authorization_code', (array) ($client['grant_types'] ?? []), true)) {
                    return ['consume' => false, 'result' => $this->error('unauthorized_client', 400)];
                }

                $resource = trim((string) ($input['resource'] ?? ''));
                if (!$this->profile->resourceAllowed($resource)
                    || !$this->profile->resourceAllowed((string) ($record['resource'] ?? ''))) {
                    return ['consume' => false, 'result' => $this->error('invalid_target', 400)];
                }

                $record['resource'] = $this->profile->primaryEndpoint();
                $withRefresh = in_array('refresh_token', (array) ($client['grant_types'] ?? []), true);
                return [
                    'consume' => true,
                    'result' => array(
                        'issue' => array('record' => $record, 'client_id' => $clientId, 'with_refresh' => $withRefresh),
                    ),
                ];
            });
        } catch (\Throwable) {
            return $this->error('server_error', 503, 'OAuth credential storage is unavailable.');
        }

        if (!is_array($result)) {
            return $this->error('invalid_grant', 400);
        }
        if (!isset($result['issue']) || !is_array($result['issue'])) {
            return $result;
        }
        return $this->issueTokensSafely(
            (array) $result['issue']['record'],
            (string) $result['issue']['client_id'],
            (bool) $result['issue']['with_refresh']
        );
    }

    /** @return array<string,mixed> */
    private function tokenForRefresh(array $input): array
    {
        $refreshToken = trim((string) ($input['refresh_token'] ?? ''));
        $clientId = trim((string) ($input['client_id'] ?? ''));
        if ($refreshToken === '' || $clientId === '') {
            return $this->error('invalid_request', 400);
        }

        try {
            $result = $this->store->exchangeRefreshToken($this->hash($refreshToken), function (array $record) use ($input, $clientId): array {
                $client = $this->store->client($clientId);
                if (!is_array($client) || $clientId !== (string) ($record['client_id'] ?? '')) {
                    return ['consume' => false, 'result' => $this->error('invalid_client', 401)];
                }
                if (!$this->validClientSecret($input, $client) || !in_array('refresh_token', (array) ($client['grant_types'] ?? []), true)) {
                    return ['consume' => false, 'result' => $this->error('invalid_client', 401)];
                }

                $resource = trim((string) ($input['resource'] ?? ''));
                if (!$this->profile->resourceAllowed($resource)
                    || !$this->profile->resourceAllowed((string) ($record['resource'] ?? ''))) {
                    return ['consume' => false, 'result' => $this->error('invalid_target', 400)];
                }

                $record['resource'] = $this->profile->primaryEndpoint();
                return [
                    'consume' => true,
                    'result' => array('issue' => array('record' => $record, 'client_id' => $clientId, 'with_refresh' => true)),
                ];
            });
        } catch (\Throwable) {
            return $this->error('server_error', 503, 'OAuth credential storage is unavailable.');
        }

        if (!is_array($result)) {
            return $this->error('invalid_grant', 400);
        }
        if (!isset($result['issue']) || !is_array($result['issue'])) {
            return $result;
        }
        return $this->issueTokensSafely((array) $result['issue']['record'], (string) $result['issue']['client_id'], true);
    }

    /** @return array<string,mixed> */
    private function issueTokensSafely(array $source, string $clientId, bool $withRefresh): array
    {
        $accessToken = $this->secureToken('codi_at', 32);
        $accessHash = $this->hash($accessToken);
        $record = [
            'client_id' => $clientId,
            'user_id' => (int) ($source['user_id'] ?? 0),
            'resource' => (string) ($source['resource'] ?? $this->profile->primaryEndpoint()),
            'resource_key' => $this->profile->resourceKey(),
            'scope' => (string) ($source['scope'] ?? 'mcp'),
        ];
        $refreshToken = '';
        $refreshHash = '';
        $accessSaved = false;
        $refreshSaved = false;

        $response = [
            'status' => 200,
            'headers' => ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'],
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TOKEN_TTL,
        ];

        try {
            $this->store->saveAccessToken($accessHash, $record, self::ACCESS_TOKEN_TTL);
            $accessSaved = true;
            if ($withRefresh) {
                $refreshToken = $this->secureToken('codi_rt', 32);
                $refreshHash = $this->hash($refreshToken);
                $this->store->saveRefreshToken($refreshHash, $record, self::REFRESH_TOKEN_TTL);
                $refreshSaved = true;
                $response['refresh_token'] = $refreshToken;
            }
            $this->touchClient($clientId);
            return $response;
        } catch (\Throwable) {
            try {
                if ($refreshSaved) {
                    $this->store->deleteRefreshToken($refreshHash);
                }
                if ($accessSaved) {
                    $this->store->deleteAccessToken($accessHash);
                }
            } catch (\Throwable) {
                $this->logCleanupFailure('token issuance compensation');
            }
            return $this->error('server_error', 503, 'OAuth token issuance storage is unavailable.');
        }
    }

    private function touchClient(string $clientId): void
    {
        $this->store->touchClient($clientId);
    }

    private function validClientSecret(array $input, array $client): bool
    {
        return (string) ($client['token_endpoint_auth_method'] ?? 'none') === 'none'
            && empty($input['client_secret'])
            && empty($input['client_assertion']);
    }

    /** @return string[]|null */
    private function normalizeScopes(string $scope): ?array
    {
        $requested = array_values(array_unique(array_filter(preg_split('/\s+/', trim($scope)) ?: [], 'strlen')));
        if ($requested === []) {
            return ['mcp'];
        }

        foreach ($requested as $candidate) {
            if (!in_array($candidate, $this->profile->scopes(), true)) {
                return null;
            }
        }

        return in_array('mcp', $requested, true) ? $requested : null;
    }

    private function validRedirectUri(string $uri): bool
    {
        $parts = parse_url($uri);
        if (!is_array($parts) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme === 'https' && $host !== '') {
            return true;
        }

        return $scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    }

    private function secureToken(string $prefix, int $bytes): string
    {
        return $prefix . '_' . rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    private function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    private function s256(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** @param array<string,string> $params */
    private function appendQuery(string $url, array $params): string
    {
        $separator = strpos($url, '?') === false ? '?' : '&';
        return $url . $separator . http_build_query(array_filter($params, static fn ($value): bool => $value !== ''));
    }

    private function logCleanupFailure(string $context): void
    {
        if (function_exists('error_log')) {
            error_log('[codi-mcp] OAuth cleanup failed during ' . $context . '.');
        }
    }

    /** @return array<string,mixed> */
    private function error(string $name, int $status, string $description = ''): array
    {
        $result = ['status' => $status, 'error' => $name];
        if ($description !== '') {
            $result['error_description'] = $description;
        }
        return $result;
    }
}
