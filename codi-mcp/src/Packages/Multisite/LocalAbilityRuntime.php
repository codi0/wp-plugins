<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Multisite;

use CodiMcp\Core\PackageRuntime;

final class LocalAbilityRuntime
{
    /** @var array<string,true> */
    private array $reserved;

    /** @param string[] $reservedAbilityNames */
    public function __construct(private PackageRuntime $runtime, array $reservedAbilityNames)
    {
        $this->reserved = array_fill_keys(array_values(array_filter(array_map('strval', $reservedAbilityNames))), true);
    }

    /** @return array<int,array<string,mixed>> */
    public function catalog(): array
    {
        $items = array();
        foreach ($this->runtime->catalogue()->all() as $descriptor) {
            $abilityName = (string) $descriptor['name'];
            if (isset($this->reserved[$abilityName]) || !$this->runtime->isAbilityExposed($abilityName)) {
                continue;
            }
            $annotations = is_array($descriptor['annotations'] ?? null) ? $descriptor['annotations'] : array();
            $items[] = array(
                'name' => $abilityName,
                'label' => (string) $descriptor['label'],
                'description' => (string) $descriptor['description'],
                'category' => (string) $descriptor['category'],
                'type' => (string) $descriptor['type'],
                'input_schema' => $descriptor['input_schema'],
                'output_schema' => $descriptor['output_schema'],
                'annotations' => array(
                    'readonly' => (bool) ($annotations['readonly'] ?? false),
                    'destructive' => (bool) ($annotations['destructive'] ?? false),
                    'idempotent' => (bool) ($annotations['idempotent'] ?? false),
                ),
            );
        }
        return $items;
    }

    public function execute(string $abilityName, $input = null)
    {
        $abilityName = trim($abilityName);
        if ($abilityName === '' || isset($this->reserved[$abilityName])) {
            return $this->error('codi_multisite_invalid_ability', 'The requested ability cannot be routed through the multisite gateway.', 400);
        }
        if (!$this->runtime->isAbilityExposed($abilityName)) {
            return $this->error('codi_multisite_ability_not_exposed', 'The requested ability is not exposed by Codi MCP on the target site.', 403);
        }

        $descriptor = $this->runtime->catalogue()->get($abilityName);
        $ability = $this->runtime->catalogue()->ability($abilityName);
        if ($descriptor === null || !is_object($ability) || !method_exists($ability, 'execute')) {
            return $this->error('codi_multisite_ability_unavailable', 'The requested ability is not registered on the target site.', 404);
        }

        $external = empty($descriptor['owned']);
        try {
            $result = $ability->execute($this->normalizeInput($ability, $input));
        } catch (\Throwable $throwable) {
            if ($external) {
                $this->auditExternal($abilityName, 'failed', 'exception');
            }
            throw $throwable;
        }

        if ($external) {
            $failed = function_exists('is_wp_error') && is_wp_error($result);
            $reason = $failed && method_exists($result, 'get_error_code') ? (string) $result->get_error_code() : '';
            $this->auditExternal($abilityName, $failed ? 'failed' : 'succeeded', $reason);
        }
        return $result;
    }

    private function normalizeInput(object $ability, $input)
    {
        if ($input !== null || !method_exists($ability, 'get_input_schema')) {
            return $input;
        }
        $schema = $ability->get_input_schema();
        return is_array($schema) && ($schema['type'] ?? null) === 'object' ? array() : $input;
    }

    private function auditExternal(string $abilityName, string $status, string $reason): void
    {
        try {
            $this->runtime->audit()->recordAbilityOutcome($abilityName, $status, 'federation', $reason);
        } catch (\Throwable) {
            if (function_exists('error_log')) {
                error_log('Codi MCP could not persist a federated adopted ability audit event.');
            }
        }
    }

    private function error(string $code, string $message, int $status): \WP_Error
    {
        return new \WP_Error($code, $message, array('status' => $status));
    }
}