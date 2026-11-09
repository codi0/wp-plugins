<?php

declare(strict_types=1);

namespace CodiMcpTest\Unit;

use CodiMcp\Adapter\CodiServer;
use CodiMcp\Adapter\McpPresentation;
use CodiMcpTest\Framework\TestCase;

final class ToolPresentationTest extends TestCase
{
    public function test_schema_normalization_preserves_literal_values_and_named_property_definitions(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'default' => ['type' => 'object', 'properties' => []],
                'value' => ['type' => 'object', 'default' => ['properties' => []]],
                'id' => ['type' => 'string', 'minLength' => 32, 'maxLength' => 32, 'pattern' => '^[a-f0-9]{32}$'],
            ],
            'anyOf' => [['type' => 'object', 'properties' => []]],
            'additionalProperties' => false,
        ];
        $normalized = \CodiMcp\Core\Abilities\SchemaPresentation::normalize($schema);
        $this->assertFalse(isset($normalized['properties']['default']['properties']));
        $this->assertSame(['properties' => []], $normalized['properties']['value']['default']);
        $this->assertFalse(isset($normalized['anyOf'][0]['properties']));
        $this->assertSame(false, $normalized['additionalProperties']);
        $this->assertFalse(isset($normalized['properties']['id']['minLength']));
        $this->assertFalse(isset($normalized['properties']['id']['maxLength']));
        $this->assertFalse(isset($normalized['properties']['id']['pattern']));
        $this->assertSame([], $schema['properties']['default']['properties']);
        $this->assertSame(32, $schema['properties']['id']['minLength']);
        $this->assertSame('^[a-f0-9]{32}$', $schema['properties']['id']['pattern']);
    }

    public function test_only_codi_model_contracts_are_compacted_without_mutating_native_contracts(): void
    {
        $presentation = new class implements McpPresentation {
            public function components(array $excludedAbilities = [], ?array $includedAbilities = null): array
            { return ['tools' => [], 'resources' => [], 'prompts' => []]; }
        };
        $codi = new CodiServer($presentation, 'fixture', 'fixture');
        $server = new class {
            public function get_server_id(): string { return 'fixture'; }
        };
        $other = new class {
            public function get_server_id(): string { return 'other'; }
        };
        $schema = new class {
            public function fromArray(string $class, array $data): object { return (object) $data; }
        };
        $data = (object) [
            'name' => 'fixture/no-input',
            'inputSchema' => (object) ['type' => 'object', 'additionalProperties' => false, 'properties' => []],
            'outputSchema' => (object) ['type' => 'object'],
        ];
        $tool = new class($data) implements \JsonSerializable {
            public function __construct(private object $data) {}
            public function jsonSerialize(): object { return $this->data; }
        };
        $this->assertSame([$tool], $codi->presentTools([$tool], $other, $schema));
        $projected = $codi->presentTools([$tool], $server, $schema)[0];
        $this->assertFalse(isset($projected->outputSchema));
        $this->assertFalse(isset($projected->inputSchema->properties));
        $this->assertSame(false, $projected->inputSchema->additionalProperties);
        $this->assertSame([], $data->inputSchema->properties);
        $this->assertTrue(isset($data->outputSchema));
    }


    public function test_codi_owned_ability_can_compact_only_its_model_facing_input_contract(): void
    {
        $presentation = new class implements McpPresentation {
            public function components(array $excludedAbilities = [], ?array $includedAbilities = null): array
            { return ['tools' => [], 'resources' => [], 'prompts' => []]; }
        };
        $codi = new CodiServer($presentation, 'fixture', 'fixture');
        $server = new class {
            public function get_server_id(): string { return 'fixture'; }
        };
        $schema = new class {
            public function fromArray(string $class, array $data): object { return (object) $data; }
        };

        $native = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => ['strict_field' => ['type' => 'string']],
            'required' => ['strict_field'],
        ];
        $compact = [
            'type' => 'object',
            'additionalProperties' => true,
            'properties' => ['action' => ['type' => 'string']],
            'required' => ['action'],
        ];

        \wp_register_ability('codi/compact-fixture', [
            'label' => 'Compact fixture',
            'description' => 'fixture',
            'category' => 'fixture',
            'input_schema' => $native,
            'output_schema' => ['type' => 'object'],
            'execute_callback' => static fn () => [],
            'permission_callback' => static fn (): bool => true,
            'meta' => ['codi_mcp' => ['owned' => true, 'package' => 'fixture', 'model_input_schema' => $compact]],
        ]);

        $data = (object) [
            'name' => 'codi/compact-fixture',
            'inputSchema' => (object) $native,
            'outputSchema' => (object) ['type' => 'object'],
        ];
        $tool = new class($data) implements \JsonSerializable {
            public function __construct(private object $data) {}
            public function jsonSerialize(): object { return $this->data; }
        };

        $projected = $codi->presentTools([$tool], $server, $schema)[0];
        $this->assertArrayHasKey('action', (array) ($projected->inputSchema['properties'] ?? []));
        $this->assertFalse(isset($projected->inputSchema['properties']['strict_field']));
        $this->assertArrayHasKey('strict_field', (array) $data->inputSchema->properties);

        \wp_register_ability('external/compact-fixture', [
            'label' => 'External compact fixture',
            'description' => 'fixture',
            'category' => 'fixture',
            'input_schema' => $native,
            'output_schema' => ['type' => 'object'],
            'execute_callback' => static fn () => [],
            'permission_callback' => static fn (): bool => true,
            'meta' => ['codi_mcp' => ['owned' => false, 'model_input_schema' => $compact]],
        ]);
        $externalData = (object) ['name' => 'external/compact-fixture', 'inputSchema' => (object) $native];
        $externalTool = new class($externalData) implements \JsonSerializable {
            public function __construct(private object $data) {}
            public function jsonSerialize(): object { return $this->data; }
        };
        $external = $codi->presentTools([$externalTool], $server, $schema)[0];
        $this->assertArrayHasKey('strict_field', (array) $external->inputSchema->properties);
        $this->assertFalse(isset($external->inputSchema->properties->action));
    }
}
