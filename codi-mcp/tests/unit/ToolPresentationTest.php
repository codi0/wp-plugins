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
            ],
            'anyOf' => [['type' => 'object', 'properties' => []]],
            'additionalProperties' => false,
        ];
        $normalized = \CodiMcp\Core\Abilities\SchemaPresentation::normalize($schema);
        $this->assertFalse(isset($normalized['properties']['default']['properties']));
        $this->assertSame(['properties' => []], $normalized['properties']['value']['default']);
        $this->assertFalse(isset($normalized['anyOf'][0]['properties']));
        $this->assertSame(false, $normalized['additionalProperties']);
        $this->assertSame([], $schema['properties']['default']['properties']);
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
}
