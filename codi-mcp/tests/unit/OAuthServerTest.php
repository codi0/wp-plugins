<?php

declare(strict_types=1);

namespace CodiMcpTest\Unit;

use CodiMcp\Audit\AuditLog;
use CodiMcp\Auth\OAuthProfile;
use CodiMcp\Auth\OAuthServer;
use CodiMcp\Auth\OAuthStore;
use CodiMcpTest\Framework\TestCase;

final class OAuthServerTest extends TestCase
{
    protected function setUp(): void
    {
        \codi_mcp_test_reset_environment();
        foreach (array(
            'CODI_MCP_SERVER_REST_NAMESPACE' => 'mcp',
            'CODI_MCP_SERVER_REST_ROUTE' => 'codi',
            'CODI_MCP_OAUTH_REST_NAMESPACE' => 'codi-mcp/v1',
        ) as $constant => $value) {
            if (!defined($constant)) {
                define($constant, $value);
            }
        }
    }

    public function test_registration_prunes_stale_unused_clients_but_keeps_recently_used_clients(): void
    {
        $store = new OAuthStore();
        $staleId = 'stale-client';
        $usedId = 'used-client';
        $staleUsedId = 'stale-used-client';
        $store->registerClient($staleId, $this->clientRecord($staleId, time() - 7200, 0, 0), 200, 3600, 7776000);
        $store->registerClient($usedId, $this->clientRecord($usedId, time() - 7200, time() - 7000, time() - 7000), 200, 3600, 7776000);
        $store->registerClient($staleUsedId, $this->clientRecord($staleUsedId, time() - 9000000, time() - 8900000, time() - 8000000), 200, 3600, 7776000);

        $result = $this->server($store)->registerClient(array(
            'client_name' => 'Fresh client',
            'redirect_uris' => array('https://fresh.example/callback'),
            'grant_types' => array('authorization_code'),
        ), 'test-source');

        $this->assertFalse(isset($result['error']));
        $this->assertSame(null, $store->client($staleId));
        $this->assertTrue(is_array($store->client($usedId)));
        $this->assertSame(null, $store->client($staleUsedId));
        $fresh = $store->client((string) ($result['client_id'] ?? ''));
        $this->assertSame(0, (int) ($fresh['used_at'] ?? -1));
    }

    public function test_registration_rejects_unbounded_client_metadata(): void
    {
        $server = $this->server(new OAuthStore());
        $redirects = array();
        for ($index = 0; $index < 11; $index++) {
            $redirects[] = 'https://client.example/callback/' . $index;
        }
        $tooMany = $server->registerClient(array('redirect_uris' => $redirects), 'metadata-source');
        $this->assertSame('invalid_client_metadata', (string) ($tooMany['error'] ?? ''));

        $longName = $server->registerClient(array(
            'client_name' => str_repeat('x', 201),
            'redirect_uris' => array('https://client.example/callback'),
        ), 'metadata-source');
        $this->assertSame('invalid_client_metadata', (string) ($longName['error'] ?? ''));
    }

    public function test_registration_is_rate_limited_per_source(): void
    {
        $server = $this->server(new OAuthStore());
        for ($index = 0; $index < 20; $index++) {
            $result = $server->registerClient(array(
                'client_name' => 'Client ' . $index,
                'redirect_uris' => array('https://client.example/callback/' . $index),
            ), '198.51.100.8');
            $this->assertFalse(isset($result['error']));
        }

        $limited = $server->registerClient(array(
            'client_name' => 'One too many',
            'redirect_uris' => array('https://client.example/callback/limited'),
        ), '198.51.100.8');
        $this->assertSame('temporarily_unavailable', (string) ($limited['error'] ?? ''));
        $this->assertSame(429, (int) ($limited['status'] ?? 0));
        $this->assertTrue((int) ($limited['retry_after'] ?? 0) > 0);
    }

    public function test_refresh_rotation_issues_nothing_when_old_token_cannot_be_consumed(): void
    {
        [$server, $clientId, $refreshToken] = $this->authorizedRefreshToken();
        $GLOBALS['codi_mcp_test_wp_option_delete_failures']['codi_mcp_oauth_credentials_refresh'] = 1;

        $failed = $server->token(array(
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'resource' => (new OAuthProfile())->primaryEndpoint(),
        ));
        $this->assertSame('server_error', (string) ($failed['error'] ?? ''));
        $this->assertFalse(isset($failed['access_token']));

        $retry = $server->token(array(
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'resource' => (new OAuthProfile())->primaryEndpoint(),
        ));
        $this->assertFalse(isset($retry['error']), 'The old refresh token should remain usable when consumption failed.');
    }

    public function test_refresh_rotation_consumes_old_token_before_replacement_storage(): void
    {
        [$server, $clientId, $refreshToken] = $this->authorizedRefreshToken();
        $GLOBALS['codi_mcp_test_wp_option_write_failures']['codi_mcp_oauth_credentials_access'] = 1;

        $failed = $server->token(array(
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'resource' => (new OAuthProfile())->primaryEndpoint(),
        ));
        $this->assertSame('server_error', (string) ($failed['error'] ?? ''));

        $retry = $server->token(array(
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'resource' => (new OAuthProfile())->primaryEndpoint(),
        ));
        $this->assertSame('invalid_grant', (string) ($retry['error'] ?? ''));
    }

    public function test_oauth_credentials_survive_transient_cache_flush(): void
    {
        [$server, $clientId, $refreshToken, $accessToken] = $this->authorizedRefreshToken();
        $GLOBALS['codi_mcp_test_wp_transients'] = array();

        $this->assertSame(7, $server->userIdForBearerToken($accessToken));
        $refreshed = $server->token(array(
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'resource' => (new OAuthProfile())->primaryEndpoint(),
        ));
        $this->assertFalse(isset($refreshed['error']));
        $this->assertTrue((string) ($refreshed['access_token'] ?? '') !== '');
        $this->assertTrue((string) ($refreshed['refresh_token'] ?? '') !== '');
    }

    public function test_network_scoped_oauth_state_uses_network_options(): void
    {
        $store = new OAuthStore(true);
        [$server, $clientId, , $accessToken] = $this->authorizedRefreshToken($store);
        $clientOption = 'codi_mcp_oauth_client_' . substr(hash('sha256', $clientId), 0, 40);
        $siteOptions = (array) (($GLOBALS['codi_mcp_test_wp_options'][1] ?? array()));
        $networkOptions = (array) ($GLOBALS['codi_mcp_test_wp_site_options'] ?? array());

        $this->assertFalse(array_key_exists('codi_mcp_oauth_client_index', $siteOptions));
        $this->assertFalse(array_key_exists($clientOption, $siteOptions));
        $this->assertFalse(array_key_exists('codi_mcp_oauth_credentials_access', $siteOptions));
        $this->assertTrue(array_key_exists('codi_mcp_oauth_client_index', $networkOptions));
        $this->assertTrue(array_key_exists($clientOption, $networkOptions));
        $this->assertTrue(array_key_exists('codi_mcp_oauth_credentials_access', $networkOptions));
        $this->assertTrue(array_key_exists('codi_mcp_oauth_credentials_refresh', $networkOptions));
        $this->assertSame(7, $server->userIdForBearerToken($accessToken));
    }

    public function test_site_and_network_profiles_are_distinct_oauth_resources(): void
    {
        $site = new OAuthProfile('codi', 'codi-mcp/v1', false);
        $network = new OAuthProfile('codi-network', 'codi-mcp/v1/network', true);

        $this->assertSame('https://example.test/wp-json/mcp/codi', $site->primaryEndpoint());
        $this->assertSame('https://example.test/wp-json/mcp/codi-network', $network->primaryEndpoint());
        $this->assertSame('https://example.test/wp-json/codi-mcp/v1', $site->issuer());
        $this->assertSame('https://example.test/wp-json/codi-mcp/v1/network', $network->issuer());
        $this->assertSame('site:1|mcp/codi', $site->resourceKey());
        $this->assertSame('network:1|mcp/codi-network', $network->resourceKey());
        $GLOBALS['codi_mcp_test_wp_current_blog_id'] = 2;
        $this->assertSame('https://docs.example.test/wp-json/mcp/codi', $site->primaryEndpoint());
        $this->assertSame('https://example.test/wp-json/mcp/codi-network', $network->primaryEndpoint());
        $this->assertSame('https://docs.example.test/wp-json/codi-mcp/v1', $site->issuer());
        $this->assertSame('https://example.test/wp-json/codi-mcp/v1/network', $network->issuer());
        $this->assertSame(
            'https://example.test/.well-known/oauth-protected-resource/wp-json/mcp/codi-network',
            $network->protectedResourceMetadataPath()
        );
        $this->assertSame('site:2|mcp/codi', $site->resourceKey());
        $this->assertSame('network:1|mcp/codi-network', $network->resourceKey());

        $this->assertTrue($site->resourceAllowed($site->primaryEndpoint()));
        $this->assertFalse($site->resourceAllowed($network->primaryEndpoint()));
        $this->assertTrue($network->resourceAllowed($network->primaryEndpoint()));
        $this->assertFalse($network->resourceAllowed($site->primaryEndpoint()));
    }

    /** @return array{0:OAuthServer,1:string,2:string,3:string} */
    private function authorizedRefreshToken(?OAuthStore $store = null): array
    {
        $profile = new OAuthProfile();
        $server = $this->server($store ?? new OAuthStore(), $profile);
        $registered = $server->registerClient(array(
            'client_name' => 'Flow client',
            'redirect_uris' => array('https://client.example/callback'),
            'grant_types' => array('authorization_code', 'refresh_token'),
        ), 'flow-source');
        $clientId = (string) ($registered['client_id'] ?? '');
        $this->assertTrue($clientId !== '');

        $verifier = str_repeat('v', 43);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $prepared = $server->prepareAuthorization(array(
            'client_id' => $clientId,
            'redirect_uri' => 'https://client.example/callback',
            'response_type' => 'code',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'scope' => 'mcp',
            'state' => 'state-1',
            'resource' => $profile->primaryEndpoint(),
        ));
        $this->assertTrue(!empty($prepared['ok']));
        $authorized = $server->authorize($prepared, 7);
        $query = array();
        parse_str((string) parse_url((string) ($authorized['location'] ?? ''), PHP_URL_QUERY), $query);
        $code = (string) ($query['code'] ?? '');
        $this->assertTrue($code !== '');

        $tokens = $server->token(array(
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'client_id' => $clientId,
            'redirect_uri' => 'https://client.example/callback',
            'resource' => $profile->primaryEndpoint(),
        ));
        $refreshToken = (string) ($tokens['refresh_token'] ?? '');
        $accessToken = (string) ($tokens['access_token'] ?? '');
        $this->assertTrue($refreshToken !== '');
        $this->assertTrue($accessToken !== '');
        return array($server, $clientId, $refreshToken, $accessToken);
    }

    private function server(OAuthStore $store, ?OAuthProfile $profile = null): OAuthServer
    {
        return new OAuthServer($store, $profile ?? new OAuthProfile(), new AuditLog());
    }

    /** @return array<string,mixed> */
    private function clientRecord(string $clientId, int $createdAt, int $usedAt, int $lastUsedAt): array
    {
        return array(
            'client_id' => $clientId,
            'client_name' => $clientId,
            'redirect_uris' => array('https://client.example/callback'),
            'grant_types' => array('authorization_code'),
            'token_endpoint_auth_method' => 'none',
            'application_type' => 'web',
            'created_at' => $createdAt,
            'used_at' => $usedAt,
            'last_used_at' => $lastUsedAt,
        );
    }
}
