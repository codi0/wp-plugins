<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Diagnostics;

use CodiMcp\Core\Abilities\AbilityMetadata;
use CodiMcp\Core\AbilityPackage;
use CodiMcp\Core\PackageRuntime;
use CodiMcp\Core\RuntimePackage;

final class Package implements AbilityPackage, RuntimePackage
{
    private ?DiagnosticRunner $runner = null;
    private ?DiagnosticRuntime $diagnosticRuntime = null;

    public function key(): string
    {
        return 'diagnostics';
    }

    public function label(): string
    {
        return 'Diagnostics';
    }

    public function abilityNames(): array
    {
        return array(
            $this->abilityName('diagnostic-run'),
            $this->abilityName('diagnostic-arm'),
            $this->abilityName('diagnostic-inspect'),
        );
    }

    public function registerRuntime(PackageRuntime $runtime): void
    {
        $this->diagnosticRuntime()->register();
    }

    public function registerCategories(): void
    {
        if (!function_exists('wp_register_ability_category')) {
            return;
        }
        wp_register_ability_category($this->category(), array(
            'label' => 'Codi MCP — Diagnostics',
            'description' => 'Bounded same-site request diagnostics for WordPress runtime, database, errors, state changes, HTTP, cache, rendering and related development evidence.',
        ));
    }

    public function registerAbilities(): void
    {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        wp_register_ability($this->abilityName('diagnostic-run'), array(
            'label' => 'Run site diagnostic request',
            'description' => 'Run one authenticated same-origin GET request and capture bounded server-side diagnostic evidence. Target application code may have side effects, so this ability is marked destructive. Use diagnostic-inspect for detail.',
            'category' => $this->category(),
            'input_schema' => $this->runInputSchema(),
            'output_schema' => $this->runOutputSchema(),
            'execute_callback' => array($this->runner(), 'run'),
            'permission_callback' => array($this, 'canDiagnose'),
            'meta' => AbilityMetadata::owned($this->key(), false, true, false, true),
        ));

        wp_register_ability($this->abilityName('diagnostic-arm'), array(
            'label' => 'Arm diagnostic request',
            'description' => 'Create one short-lived, one-use diagnostic credential bound to an exact same-origin target and HTTP method. A matching request that presents the credential is observed under its own WordPress identity; request bodies and header values are not persisted.',
            'category' => $this->category(),
            'input_schema' => $this->armInputSchema(),
            'output_schema' => $this->armOutputSchema(),
            'execute_callback' => array($this->runner(), 'arm'),
            'permission_callback' => array($this, 'canDiagnose'),
            'meta' => AbilityMetadata::owned($this->key(), false, false, false),
        ));

        wp_register_ability($this->abilityName('diagnostic-inspect'), array(
            'label' => 'Inspect diagnostic capture',
            'description' => 'Read one bounded section from a recent diagnostic capture. Captures expire automatically.',
            'category' => $this->category(),
            'input_schema' => $this->inspectInputSchema(),
            'output_schema' => $this->inspectOutputSchema(),
            'execute_callback' => array($this->runner(), 'inspect'),
            'permission_callback' => array($this, 'canDiagnose'),
            'meta' => AbilityMetadata::owned($this->key(), true, false, true),
        ));
    }

    public function canDiagnose(): bool
    {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            return false;
        }
        return !function_exists('is_multisite') || !is_multisite() || (function_exists('is_super_admin') && is_super_admin());
    }

    private function runner(): DiagnosticRunner
    {
        return $this->runner ??= new DiagnosticRunner();
    }

    private function diagnosticRuntime(): DiagnosticRuntime
    {
        return $this->diagnosticRuntime ??= new DiagnosticRuntime();
    }

    private function abilityName(string $slug): string
    {
        return rtrim(CODI_MCP_ABILITY_PREFIX, '/') . '/' . ltrim($slug, '/');
    }

    private function category(): string
    {
        return rtrim(CODI_MCP_ABILITY_PREFIX, '/') . '-diagnostics';
    }

    private function runInputSchema(): array
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'url' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 2000, 'description' => 'Same-origin absolute or root-relative URL. GET only.'),
                'actor_user_id' => array('type' => 'integer', 'minimum' => 0, 'description' => 'Optional effective WordPress user for the target request. Omit to use the MCP caller; use 0 for anonymous.'),
                'explain_slow_queries' => array('type' => 'boolean', 'description' => 'When true, EXPLAIN at most three captured SELECT queries taking at least 5 ms; original SQL literals are never stored.'),
                'include_assets' => array('type' => 'boolean', 'description' => 'Include enqueued script/style dependency data. Defaults to true.'),
                'hooks' => array('type' => 'array', 'maxItems' => 20, 'items' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 191), 'description' => 'Optional hook names whose registered callbacks should be inspected.'),
                'capabilities' => array('type' => 'array', 'maxItems' => 20, 'items' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 191), 'description' => 'Optional capabilities whose checks should be traced during the request.'),
            ),
            'required' => array('url'),
        );
    }

    private function armInputSchema(): array
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'url' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 2000, 'description' => 'Exact same-origin absolute or root-relative target URL, including query string when present.'),
                'method' => array('type' => 'string', 'enum' => array('GET', 'POST', 'PUT', 'PATCH', 'DELETE')),
                'explain_slow_queries' => array('type' => 'boolean', 'description' => 'When true, EXPLAIN at most three captured SELECT queries taking at least 5 ms; original SQL literals are never stored.'),
                'include_assets' => array('type' => 'boolean', 'description' => 'Include enqueued script/style dependency data. Defaults to true.'),
                'hooks' => array('type' => 'array', 'maxItems' => 20, 'items' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 191)),
                'capabilities' => array('type' => 'array', 'maxItems' => 20, 'items' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 191)),
            ),
            'required' => array('url', 'method'),
        );
    }

    private function armOutputSchema(): array
    {
        return $this->strictObject(array(
            'diagnostic_id' => array('type' => 'string', 'pattern' => '^[a-f0-9]{32}$'),
            'target' => array('type' => 'string'),
            'method' => array('type' => 'string'),
            'authorization' => $this->strictObject(array(
                'header_name' => array('type' => 'string'),
                'header_value' => array('type' => 'string', 'pattern' => '^[a-f0-9]{64}$'),
                'expires_at' => array('type' => 'string'),
            )),
        ));
    }

    private function runOutputSchema(): array
    {
        return $this->strictObject(array(
            'diagnostic_id' => array('type' => 'string', 'pattern' => '^[a-f0-9]{32}$'),
            'target' => array('type' => 'string'),
            'request' => $this->strictObject(array(
                'status' => array('type' => 'integer'),
                'duration_ms' => array('type' => 'number'),
                'peak_memory_bytes' => array('type' => 'integer'),
                'type' => array('type' => 'string'),
                'effective_user_id' => array('type' => 'integer'),
            )),
            'summary' => $this->strictObject(array(
                'errors' => array('type' => 'integer'),
                'wp_errors' => array('type' => 'integer'),
                'queries' => array('type' => 'integer'),
                'query_time_ms' => array('type' => 'number'),
                'state_changes' => array('type' => 'integer'),
                'http_requests' => array('type' => 'integer'),
                'redirects' => array('type' => 'integer'),
                'security_failures' => array('type' => 'integer'),
                'assets' => array('type' => 'integer'),
            )),
            'available_sections' => array('type' => 'array', 'items' => array('type' => 'string')),
        ));
    }

    private function inspectInputSchema(): array
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'diagnostic_id' => array('type' => 'string', 'minLength' => 32, 'maxLength' => 32, 'pattern' => '^[a-f0-9]{32}$'),
                'section' => array(
                    'type' => 'string',
                    'enum' => array('overview', 'errors', 'database', 'state', 'http', 'wordpress', 'redirects', 'cache', 'assets', 'mail', 'security', 'capabilities', 'hooks', 'timeline'),
                ),
            ),
            'required' => array('diagnostic_id', 'section'),
        );
    }

    private function inspectOutputSchema(): array
    {
        return $this->strictObject(array(
            'section' => array('type' => 'string'),
            'data' => array('type' => 'object', 'additionalProperties' => true),
        ));
    }

    private function strictObject(array $properties): array
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => $properties,
            'required' => array_keys($properties),
        );
    }
}