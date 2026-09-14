<?php

declare(strict_types=1);

use CodiMcp\Audit\AuditedAbility;
use CodiMcp\Audit\AuditLog;
use CodiMcp\Auth\OAuthStore;

final class AuditOAuthIntegrationTest extends WP_UnitTestCase
{
    /** @var string[] */
    private array $clientIds = array();

    public function set_up(): void
    {
        parent::set_up();
        delete_option('codi_mcp_audit_log_v1');
        delete_option('codi_mcp_oauth_client_index');
        delete_option('codi_mcp_oauth_registration_rate_v1');
    }

    public function tear_down(): void
    {
        $store = new OAuthStore();
        foreach ($this->clientIds as $clientId) {
            try {
                $store->deleteClient($clientId);
            } catch (\Throwable) {
            }
        }
        $this->clientIds = array();
        delete_option('codi_mcp_audit_log_v1');
        delete_option('codi_mcp_oauth_client_index');
        delete_option('codi_mcp_oauth_registration_rate_v1');
        parent::tear_down();
    }

    public function test_audited_ability_records_real_core_success_and_failure_results(): void
    {
        $success = new AuditedAbility('codi/integration-success', array(
            'label' => 'Integration success',
            'description' => 'Exercise Codi auditing around the real WordPress ability execution path.',
            'category' => 'codi-integration',
            'input_schema' => array(
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => array('value' => array('type' => 'string')),
                'required' => array('value'),
            ),
            'output_schema' => array(
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => array('ok' => array('type' => 'boolean')),
                'required' => array('ok'),
            ),
            'permission_callback' => static fn (array $input): bool => true,
            'execute_callback' => static fn (array $input): array => array('ok' => true),
        ));

        $result = $success->execute(array('value' => 'not-stored'));
        $this->assertSame(array('ok' => true), $result);
        $records = (new AuditLog())->list(10);
        $this->assertSame('succeeded', $records[0]['status']);
        $this->assertSame(array('value'), $records[0]['input_keys']);
        $this->assertStringNotContainsString('not-stored', wp_json_encode($records));

        $failure = new AuditedAbility('codi/integration-failure', array(
            'label' => 'Integration failure',
            'description' => 'Exercise terminal failure auditing around the real WordPress ability execution path.',
            'category' => 'codi-integration',
            'input_schema' => array(
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => array(),
            ),
            'permission_callback' => static fn (array $input): bool => true,
            'execute_callback' => static fn (array $input): WP_Error => new WP_Error('integration_failure', 'Expected failure.'),
        ));

        $failureResult = $failure->execute(array());
        $this->assertWPError($failureResult);
        $records = (new AuditLog())->list(10);
        $this->assertSame('failed', $records[0]['status']);
        $this->assertSame('integration_failure', $records[0]['reason_code']);
        $this->assertNotSame('', $records[0]['completed_at']);
    }

    public function test_oauth_client_store_round_trips_through_real_wordpress_options(): void
    {
        $store = new OAuthStore();
        $clientId = 'integration-client-' . wp_generate_uuid4();
        $this->clientIds[] = $clientId;
        $now = time();
        $record = array(
            'client_id' => $clientId,
            'client_name' => 'Integration Client',
            'redirect_uris' => array('https://client.example/callback'),
            'grant_types' => array('authorization_code', 'refresh_token'),
            'token_endpoint_auth_method' => 'none',
            'application_type' => 'web',
            'created_at' => $now,
            'used_at' => 0,
            'last_used_at' => 0,
        );

        $this->assertTrue($store->registerClient($clientId, $record, 200, 3600, 7776000));
        $stored = $store->client($clientId);
        $this->assertIsArray($stored);
        $this->assertSame($clientId, $stored['client_id']);

        $store->touchClient($clientId);
        $touched = $store->client($clientId);
        $this->assertGreaterThan(0, (int) $touched['used_at']);
        $this->assertGreaterThan(0, (int) $touched['last_used_at']);

        $store->deleteClient($clientId);
        $this->assertNull($store->client($clientId));
    }
}
