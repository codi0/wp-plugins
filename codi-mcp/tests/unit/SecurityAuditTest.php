<?php

declare(strict_types=1);

namespace CodiMcpTest\Unit;

use CodiMcp\Audit\AuditLog;
use CodiMcp\Core\Abilities\AbilityMetadata;
use CodiMcp\Core\Plugin;
use CodiMcp\Core\Security\SensitiveDataRedactor;
use CodiMcpTest\Framework\TestCase;

final class SecurityAuditTest extends TestCase
{
    protected function setUp(): void
    {
        \codi_mcp_test_reset_environment();
        foreach (array(
            'CODI_MCP_ABILITY_PREFIX' => 'codi',
            'CODI_MCP_SERVER_ID' => 'codi-mcp',
            'CODI_MCP_SERVER_REST_NAMESPACE' => 'mcp',
            'CODI_MCP_SERVER_REST_ROUTE' => 'codi',
            'CODI_MCP_NETWORK_SERVER_REST_ROUTE' => 'codi-network',
            'CODI_MCP_VERSION' => 'test',
        ) as $constant => $value) {
            if (!defined($constant)) {
                define($constant, $value);
            }
        }
    }

    public function test_redactor_handles_json_and_quoted_sensitive_keys(): void
    {
        $redactor = new SensitiveDataRedactor();
        $line = $redactor->redactLine('{"access_token":"secret123","password":"hunter2","ordinary":"visible"}');
        $decoded = json_decode($line, true);

        $this->assertSame('[redacted]', (string) ($decoded['access_token'] ?? ''));
        $this->assertSame('[redacted]', (string) ($decoded['password'] ?? ''));
        $this->assertSame('visible', (string) ($decoded['ordinary'] ?? ''));

        $mixed = $redactor->redactLine('payload "client_secret":"abc" token=def Authorization: Bearer ghi');
        $this->assertFalse(str_contains($mixed, 'abc'));
        $this->assertFalse(str_contains($mixed, 'def'));
        $this->assertFalse(str_contains($mixed, 'ghi'));
    }

    public function test_redactor_never_exposes_positional_string_arguments(): void
    {
        $redactor = new SensitiveDataRedactor();
        $sanitized = $redactor->sanitize(array('ordinary-value', 42, array('token' => 'secret', 'name' => 'safe')));

        $this->assertSame('[redacted]', $sanitized[0]);
        $this->assertSame(42, $sanitized[1]);
        $this->assertSame('[redacted]', $sanitized[2]['token']);
        $this->assertSame('safe', $sanitized[2]['name']);
    }

    public function test_plugin_audits_codi_ability_without_storing_input_values(): void
    {
        $plugin = new Plugin(array());
        $plugin->register();
        wp_set_current_user(9);
        wp_register_ability('codi/test-audit', array(
            'label' => 'Audit test',
            'description' => 'Audit test ability.',
            'input_schema' => array('type' => 'object'),
            'execute_callback' => static fn (array $input): array => array('ok' => true),
            'permission_callback' => static fn (): bool => true,
            'meta' => AbilityMetadata::owned('test', false, false, false),
        ));

        $ability = wp_get_abilities()['codi/test-audit'];
        $result = $ability->execute(array('secret' => 'must-not-be-stored', 'ordinary' => 'value'));
        $this->assertSame(true, (bool) ($result['ok'] ?? false));

        $records = (new AuditLog())->list(10);
        $this->assertTrue($records !== array());
        $record = $records[0];
        $this->assertSame('codi/test-audit', (string) ($record['subject'] ?? ''));
        $this->assertSame('succeeded', (string) ($record['status'] ?? ''));
        $this->assertSame(array('ordinary', 'secret'), $record['input_keys']);
        $encoded = json_encode($record);
        $this->assertFalse(is_string($encoded) && str_contains($encoded, 'must-not-be-stored'));
        $this->assertFalse(is_string($encoded) && str_contains($encoded, 'value'));
    }

    public function test_network_mcp_route_is_audited_as_mcp_source(): void
    {
        $plugin = new Plugin(array());
        $plugin->register();
        wp_set_current_user(9);
        $_SERVER['REQUEST_URI'] = '/wp-json/mcp/codi-network';
        wp_register_ability('codi/test-network-audit', array(
            'label' => 'Network audit test',
            'description' => 'Network audit source test ability.',
            'input_schema' => array('type' => 'object'),
            'execute_callback' => static fn (array $input): array => array('ok' => true),
            'permission_callback' => static fn (): bool => true,
            'meta' => AbilityMetadata::owned('test', false, false, false),
        ));

        $result = wp_get_abilities()['codi/test-network-audit']->execute(array());
        $this->assertSame(true, (bool) ($result['ok'] ?? false));
        $records = (new AuditLog())->list(10);
        $this->assertSame('mcp', (string) ($records[0]['source'] ?? ''));
    }

    public function test_plugin_records_terminal_failure_for_codi_ability_error(): void
    {
        $plugin = new Plugin(array());
        $plugin->register();
        wp_set_current_user(9);
        wp_register_ability('codi/test-audit-failure', array(
            'label' => 'Audit failure test',
            'description' => 'Audit failure test ability.',
            'input_schema' => array('type' => 'object'),
            'execute_callback' => static fn (array $input): \WP_Error => new \WP_Error('audit_test_failure', 'Expected failure.'),
            'permission_callback' => static fn (): bool => true,
            'meta' => AbilityMetadata::owned('test', false, false, false),
        ));

        $ability = wp_get_abilities()['codi/test-audit-failure'];
        $result = $ability->execute(array('secret' => 'must-not-be-stored'));
        $this->assertTrue(is_wp_error($result));

        $records = (new AuditLog())->list(10);
        $this->assertTrue($records !== array());
        $record = $records[0];
        $this->assertSame('codi/test-audit-failure', (string) ($record['subject'] ?? ''));
        $this->assertSame('failed', (string) ($record['status'] ?? ''));
        $this->assertSame('audit_test_failure', (string) ($record['reason_code'] ?? ''));
        $this->assertTrue((string) ($record['completed_at'] ?? '') !== '');
    }

    public function test_ability_fails_closed_when_audit_start_cannot_be_persisted(): void
    {
        $plugin = new Plugin(array());
        $plugin->register();
        wp_set_current_user(9);
        $executed = false;
        wp_register_ability('codi/test-audit-storage-failure', array(
            'label' => 'Audit storage failure test',
            'description' => 'Audit storage failure test ability.',
            'input_schema' => array('type' => 'object'),
            'execute_callback' => static function (array $input) use (&$executed): array {
                $executed = true;
                return array('ok' => true);
            },
            'permission_callback' => static fn (): bool => true,
            'meta' => AbilityMetadata::owned('test', false, false, false),
        ));
        $GLOBALS['codi_mcp_test_wp_option_write_failures']['codi_mcp_audit_log_v1'] = 1;

        $result = wp_get_abilities()['codi/test-audit-storage-failure']->execute(array());
        $this->assertTrue(is_wp_error($result));
        $this->assertSame('codi_mcp_audit_unavailable', $result->get_error_code());
        $this->assertFalse($executed, 'Ability callback must not run when its required audit start cannot be persisted.');
    }

    public function test_plugin_does_not_disable_the_adapter_default_server_globally(): void
    {
        $plugin = new Plugin(array());
        $plugin->register();
        $filters = (array) ($GLOBALS['codi_mcp_test_filters']['mcp_adapter_create_default_server'] ?? array());
        $this->assertSame(array(), $filters);
    }
}
