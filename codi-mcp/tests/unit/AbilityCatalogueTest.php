<?php

declare(strict_types=1);

namespace WP\MCP\Infrastructure\Observability\Contracts {
    if (!interface_exists(McpObservabilityHandlerInterface::class)) {
        interface McpObservabilityHandlerInterface
        {
            public function record_event(string $event, array $tags = array(), ?float $duration_ms = null): void;
        }
    }
}

namespace CodiMcpTest\Unit {
    use CodiMcp\Adapter\DirectMcpPresentation;
    use CodiMcp\Audit\AuditLog;
    use CodiMcp\Audit\McpObservabilityHandler;
    use CodiMcp\Core\Abilities\AbilityCatalogue;
    use CodiMcp\Core\Abilities\AbilityMetadata;
    use CodiMcp\Exposure\ExposurePolicy;
    use CodiMcpTest\Framework\TestCase;

    final class AbilityCatalogueTest extends TestCase
    {
        protected function setUp(): void
        {
            \codi_mcp_test_reset_environment();
            if (!defined('CODI_MCP_ABILITY_PREFIX')) {
                define('CODI_MCP_ABILITY_PREFIX', 'codi');
            }
        }

        public function test_catalogue_distinguishes_owned_and_adoptable_abilities(): void
        {
            $this->register('codi/owned', AbilityMetadata::owned('test', true, false, true));
            $this->register('example/public', array('public' => true));
            $this->register('example/mcp-private', array('public' => true, 'mcp' => array('public' => false)));
            $this->register('example/mcp-public', array('public' => false, 'mcp' => array('public' => true)));
            $this->register('example/private', array());

            $catalogue = new AbilityCatalogue();

            $owned = $catalogue->get('codi/owned');
            $this->assertSame('codi', (string) ($owned['origin'] ?? ''));
            $this->assertTrue((bool) ($owned['owned'] ?? false));
            $this->assertFalse((bool) ($owned['adoptable'] ?? true));

            $public = $catalogue->get('example/public');
            $this->assertSame('external', (string) ($public['origin'] ?? ''));
            $this->assertTrue((bool) ($public['adoptable'] ?? false));

            $mcpPrivate = $catalogue->get('example/mcp-private');
            $this->assertFalse((bool) ($mcpPrivate['mcp_public'] ?? true));
            $this->assertFalse((bool) ($mcpPrivate['adoptable'] ?? true));

            $mcpPublic = $catalogue->get('example/mcp-public');
            $this->assertTrue((bool) ($mcpPublic['mcp_public'] ?? false));
            $this->assertTrue((bool) ($mcpPublic['adoptable'] ?? false));

            $private = $catalogue->get('example/private');
            $this->assertFalse((bool) ($private['adoptable'] ?? true));
        }

        public function test_policy_and_direct_presentation_only_use_eligible_selected_abilities(): void
        {
            $this->register('codi/owned', AbilityMetadata::owned('test', true, false, true));
            $this->register('example/public', array('public' => true));
            $this->register('example/private', array());

            $catalogue = new AbilityCatalogue();
            $policy = new ExposurePolicy($catalogue);
            $this->assertTrue($policy->saveSelection(array('codi/owned', 'example/public', 'example/private')));

            $this->assertTrue($policy->isEnabled('codi/owned'));
            $this->assertTrue($policy->isEnabled('example/public'));
            $this->assertFalse($policy->isEnabled('example/private'));
            $this->assertSame(array('codi/owned', 'example/public'), $policy->enabledNames());

            $components = (new DirectMcpPresentation($catalogue, $policy))->components();
            $this->assertSame(array('codi/owned', 'example/public'), $components['tools']);
        }

        public function test_adopted_mcp_observability_records_external_outcome_without_duplicate_owned_record(): void
        {
            $this->register('example/public', array('public' => true));
            $this->register('codi/owned', AbilityMetadata::owned('test', true, false, true));

            $handler = new McpObservabilityHandler();
            $handler->record_event('mcp.request', array(
                'ability_name' => 'example/public',
                'status' => 'success',
            ));
            $handler->record_event('mcp.request', array(
                'ability_name' => 'codi/owned',
                'status' => 'success',
            ));

            $records = (new AuditLog())->list(10);
            $this->assertCount(1, $records);
            $this->assertSame('example/public', (string) ($records[0]['subject'] ?? ''));
            $this->assertSame('succeeded', (string) ($records[0]['status'] ?? ''));
            $this->assertSame('mcp', (string) ($records[0]['source'] ?? ''));
        }

        /** @param array<string,mixed> $meta */
        private function register(string $name, array $meta): void
        {
            wp_register_ability($name, array(
                'label' => $name,
                'description' => 'Test ability.',
                'category' => 'test',
                'input_schema' => array('type' => 'object', 'additionalProperties' => false, 'properties' => array()),
                'output_schema' => array('type' => 'object'),
                'execute_callback' => static fn (array $input = array()): array => array(),
                'permission_callback' => static fn (array $input = array()): bool => true,
                'meta' => $meta,
            ));
        }
    }
}