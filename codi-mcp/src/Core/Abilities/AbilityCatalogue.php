<?php

declare(strict_types=1);

namespace CodiMcp\Core\Abilities;

final class AbilityCatalogue
{
    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        $items = array();
        foreach (wp_get_abilities() as $ability) {
            $items[] = $this->describe($ability);
        }

        usort($items, static fn (array $left, array $right): int => strcmp((string) $left['name'], (string) $right['name']));
        return $items;
    }

    /** @return array<string,mixed>|null */
    public function get(string $abilityName): ?array
    {
        $ability = $this->ability($abilityName);
        return $ability === null ? null : $this->describe($ability);
    }

    public function ability(string $abilityName): ?\WP_Ability
    {
        $abilityName = trim($abilityName);
        if ($abilityName === '') {
            return null;
        }

        $abilities = wp_get_abilities();
        return $abilities[$abilityName] ?? null;
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
    private function describe(\WP_Ability $ability): array
    {
        $abilityName = $ability->get_name();
        $meta = $ability->get_meta();
        $mcp = is_array($meta['mcp'] ?? null) ? $meta['mcp'] : array();
        $annotations = is_array($meta['annotations'] ?? null) ? $meta['annotations'] : array();
        $codi = is_array($meta['codi_mcp'] ?? null) ? $meta['codi_mcp'] : array();

        $type = strtolower(trim((string) ($mcp['type'] ?? 'tool')));
        if (!in_array($type, array('tool', 'resource', 'prompt'), true)) {
            $type = 'tool';
        }

        $prefix = rtrim((string) CODI_MCP_ABILITY_PREFIX, '/') . '/';
        $owned = str_starts_with($abilityName, $prefix) && ($codi['owned'] ?? false) === true;
        $mcpPublic = array_key_exists('public', $mcp)
            ? $mcp['public'] === true
            : ($meta['public'] ?? false) === true;

        $inputSchema = $ability->get_input_schema();
        $outputSchema = $ability->get_output_schema();

        return array(
            'name' => $abilityName,
            'namespace' => explode('/', $abilityName, 2)[0] ?? $abilityName,
            'label' => $ability->get_label(),
            'description' => $ability->get_description(),
            'category' => $ability->get_category(),
            'package' => is_scalar($codi['package'] ?? null) ? trim((string) $codi['package']) : '',
            'type' => $type,
            'owned' => $owned,
            'origin' => $owned ? 'codi' : 'external',
            'mcp_public' => $mcpPublic,
            'input_schema' => is_array($inputSchema) && $inputSchema !== array() ? $inputSchema : null,
            'output_schema' => is_array($outputSchema) && $outputSchema !== array() ? $outputSchema : null,
            'annotations' => array(
                'readonly' => (bool) ($annotations['readonly'] ?? false),
                'destructive' => (bool) ($annotations['destructive'] ?? false),
                'idempotent' => (bool) ($annotations['idempotent'] ?? false),
                'open_world' => (bool) ($annotations['openWorldHint'] ?? false),
            ),
        );
    }
}
