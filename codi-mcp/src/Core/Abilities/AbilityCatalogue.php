<?php

declare(strict_types=1);

namespace CodiMcp\Core\Abilities;

final class AbilityCatalogue
{
    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        $items = array();
        foreach ((array) wp_get_abilities() as $name => $ability) {
            if (!is_object($ability)) {
                continue;
            }
            $abilityName = is_string($name) ? $name : $this->string($ability, 'get_name');
            if ($abilityName === '' || str_starts_with($abilityName, 'mcp-adapter/')) {
                continue;
            }
            $items[] = $this->describe($abilityName, $ability);
        }

        usort($items, static fn (array $left, array $right): int => strcmp((string) $left['name'], (string) $right['name']));
        return $items;
    }

    /** @return array<string,mixed>|null */
    public function get(string $abilityName): ?array
    {
        $abilityName = trim($abilityName);
        if ($abilityName === '') {
            return null;
        }
        $ability = $this->ability($abilityName);
        return $ability === null ? null : $this->describe($abilityName, $ability);
    }

    public function ability(string $abilityName): ?object
    {
        $abilities = (array) wp_get_abilities();
        $ability = $abilities[trim($abilityName)] ?? null;
        return is_object($ability) ? $ability : null;
    }

    /** @param string[] $abilityNames @return array{tools:string[],resources:string[],prompts:string[]} */
    public function components(array $abilityNames): array
    {
        $requested = array_fill_keys(array_values(array_filter(array_map('strval', $abilityNames), 'strlen')), true);
        $components = array('tools' => array(), 'resources' => array(), 'prompts' => array());
        foreach ($this->all() as $item) {
            $name = (string) $item['name'];
            if (!isset($requested[$name])) {
                continue;
            }
            $type = (string) $item['type'];
            $bucket = $type === 'resource' ? 'resources' : ($type === 'prompt' ? 'prompts' : 'tools');
            $components[$bucket][] = $name;
        }
        return $components;
    }

    /** @return array<string,mixed> */
    private function describe(string $abilityName, object $ability): array
    {
        $meta = $this->array($ability, 'get_meta');
        $mcp = is_array($meta['mcp'] ?? null) ? $meta['mcp'] : array();
        $annotations = is_array($meta['annotations'] ?? null) ? $meta['annotations'] : array();
        $codi = is_array($meta['codi_mcp'] ?? null) ? $meta['codi_mcp'] : array();
        $type = strtolower(trim((string) ($mcp['type'] ?? 'tool')));
        if (!in_array($type, array('tool', 'resource', 'prompt'), true)) {
            $type = 'tool';
        }

        $prefix = rtrim(defined('CODI_MCP_ABILITY_PREFIX') ? (string) CODI_MCP_ABILITY_PREFIX : 'codi', '/') . '/';
        $owned = str_starts_with($abilityName, $prefix) && ($codi['owned'] ?? false) === true;
        $mcpPublic = array_key_exists('public', $mcp)
            ? ($mcp['public'] === true)
            : (($meta['public'] ?? false) === true);

        return array(
            'name' => $abilityName,
            'namespace' => explode('/', $abilityName, 2)[0] ?? $abilityName,
            'label' => $this->string($ability, 'get_label', $abilityName),
            'description' => $this->string($ability, 'get_description'),
            'category' => $this->string($ability, 'get_category'),
            'package' => is_scalar($codi['package'] ?? null) ? trim((string) $codi['package']) : '',
            'type' => $type,
            'owned' => $owned,
            'origin' => $owned ? 'codi' : 'external',
            'mcp_public' => $mcpPublic,
            'adoptable' => !$owned && $mcpPublic,
            'input_schema' => $this->schema($ability, 'get_input_schema'),
            'output_schema' => $this->schema($ability, 'get_output_schema'),
            'annotations' => array(
                'readonly' => (bool) ($annotations['readonly'] ?? false),
                'destructive' => (bool) ($annotations['destructive'] ?? false),
                'idempotent' => (bool) ($annotations['idempotent'] ?? false),
                'open_world' => (bool) ($annotations['openWorldHint'] ?? false),
            ),
        );
    }

    private function string(object $ability, string $method, string $fallback = ''): string
    {
        if (!method_exists($ability, $method)) {
            return $fallback;
        }
        $value = $ability->{$method}();
        return is_scalar($value) ? (string) $value : $fallback;
    }

    /** @return array<string,mixed> */
    private function array(object $ability, string $method): array
    {
        if (!method_exists($ability, $method)) {
            return array();
        }
        $value = $ability->{$method}();
        return is_array($value) ? $value : array();
    }

    /** @return array<string,mixed>|null */
    private function schema(object $ability, string $method): ?array
    {
        $value = $this->array($ability, $method);
        return $value === array() ? null : $value;
    }
}