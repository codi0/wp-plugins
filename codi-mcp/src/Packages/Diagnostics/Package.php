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
            $this->abilityName('diagnostic-compare'),
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
            'description' => 'Run one authenticated same-origin GET and capture bounded SQL, HTTP, state, cache, error, hook, and optional callback timing. Target code may have side effects; inspect with diagnostic-inspect.',
            'category' => $this->category(),
            'input_schema' => $this->runInputSchema(),
            'output_schema' => $this->runOutputSchema(),
            'execute_callback' => array($this->runner(), 'run'),
            'permission_callback' => array($this, 'canDiagnose'),
            'meta' => AbilityMetadata::owned($this->key(), false, true, false, true),
        ));

        wp_register_ability($this->abilityName('diagnostic-arm'), array(
            'label' => 'Arm diagnostic request',
            'description' => 'Arm a short-lived, one-use credential for an exact same-origin target/method so a separate request is captured under its own WP identity. Bodies and header values are not stored.',
            'category' => $this->category(),
            'input_schema' => $this->armInputSchema(),
            'output_schema' => $this->armOutputSchema(),
            'execute_callback' => array($this->runner(), 'arm'),
            'permission_callback' => array($this, 'canDiagnose'),
            'meta' => AbilityMetadata::owned($this->key(), false, false, false),
        ));

        wp_register_ability($this->abilityName('diagnostic-inspect'), array(
            'label' => 'Inspect diagnostic capture',
            'description' => 'Read one capture section. Defaults to summary; use items for pagination or full for complete bounded evidence. Overview includes top offenders, correlations, and measured diagnostic overhead.',
            'category' => $this->category(),
            'input_schema' => $this->inspectInputSchema(),
            'output_schema' => $this->inspectOutputSchema(),
            'execute_callback' => array($this->runner(), 'inspect'),
            'permission_callback' => array($this, 'canDiagnose'),
            'meta' => AbilityMetadata::owned($this->key(), true, false, true),
        ));

        wp_register_ability($this->abilityName('diagnostic-compare'), array(
            'label' => 'Compare diagnostic captures',
            'description' => 'Compare 2–10 captures of the same target/config. Summary reports metric ranges, recurring evidence, and material one-offs; full adds wider recurring evidence.',
            'category' => $this->category(),
            'input_schema' => $this->compareInputSchema(),
            'output_schema' => $this->compareOutputSchema(),
            'execute_callback' => array($this->runner(), 'compare'),
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
                'url' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 2000, 'description' => 'Same-origin URL. GET only.'),
                'profile' => array('type' => 'string', 'enum' => array('none', 'admin', 'frontend', 'rest', 'cron'), 'default' => 'none', 'description' => 'Preset hook set; explicit hooks are added, max 20 total.'),
                'actor_user_id' => array('type' => 'integer', 'minimum' => 0, 'description' => 'Effective WordPress user; omit for MCP caller, 0 for anonymous.'),
                'explain_slow_queries' => array('type' => 'boolean', 'description' => 'EXPLAIN up to three captured SELECTs ≥5 ms; SQL literals are not stored.'),
                'callback_timing' => array('type' => 'boolean', 'description' => 'Profile compatible callbacks on selected hooks; reference-sensitive callbacks are skipped.'),
                'include_assets' => array('type' => 'boolean', 'description' => 'Capture enqueued scripts/styles; default true.'),
                'hooks' => array('type' => 'array', 'maxItems' => 20, 'items' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 191), 'description' => 'Additional hooks to inspect.'),
                'capabilities' => array('type' => 'array', 'maxItems' => 20, 'items' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 191), 'description' => 'Capabilities whose checks should be traced.'),
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
                'url' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 2000, 'description' => 'Exact same-origin target URL, including query string.'),
                'method' => array('type' => 'string', 'enum' => array('GET', 'POST', 'PUT', 'PATCH', 'DELETE')),
                'profile' => array('type' => 'string', 'enum' => array('none', 'admin', 'frontend', 'rest', 'cron'), 'default' => 'none', 'description' => 'Optional bounded hook profile. Explicit hooks are added to the profile up to the existing 20-hook limit.'),
                'explain_slow_queries' => array('type' => 'boolean', 'description' => 'When true, EXPLAIN at most three captured SELECT queries taking at least 5 ms; original SQL literals are never stored.'),
                'callback_timing' => array('type' => 'boolean', 'description' => 'Opt in to bounded wall-time profiling for compatible callbacks on the selected hooks. Reference-sensitive callbacks are skipped rather than wrapped.'),
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
                'correlations' => array('type' => 'integer'),
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
                    'enum' => array('overview', 'errors', 'database', 'state', 'http', 'wordpress', 'redirects', 'cache', 'assets', 'mail', 'security', 'capabilities', 'hooks', 'correlations', 'timeline'),
                ),
                'detail' => array('type' => 'string', 'enum' => array('summary', 'full', 'items'), 'default' => 'summary', 'description' => 'summary=aggregates; full=bounded section; items=paginated collection.'),
                'collection' => array('type' => 'string', 'enum' => array('items', 'cost_sources', 'callback_timings', 'patterns', 'slowest', 'explains', 'by_component', 'by_caller', 'by_type', 'callback_costs', 'opportunities', 'autoload'), 'description' => 'For detail=items. Database defaults to patterns; hooks to items; callback_timings flattens hook timing rows.'),
                'page' => array('type' => 'integer', 'minimum' => 1),
                'per_page' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100),
                'component' => array('type' => 'string', 'maxLength' => 191),
                'hook' => array('type' => 'string', 'maxLength' => 191),
                'query_type' => array('type' => 'string', 'enum' => array('select', 'show', 'insert', 'update', 'delete', 'create', 'alter', 'drop', 'replace', 'other')),
                'minimum_ms' => array('type' => 'number', 'minimum' => 0),
                'sort' => array('type' => 'string', 'enum' => array('count', 'time_ms', 'duration_ms', 'wall_ms', 'max_ms', 'exclusive_profiled_ms', 'executions', 'offset_ms', 'queries', 'query_time_ms', 'http_requests', 'state_changes')),
                'include_callbacks' => array('type' => 'boolean', 'description' => 'For hooks, false omits raw callback descriptors.'),
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

    private function compareInputSchema(): array
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'diagnostic_ids' => array(
                    'type' => 'array',
                    'minItems' => 2,
                    'maxItems' => 10,
                    'uniqueItems' => true,
                    'items' => array('type' => 'string', 'minLength' => 32, 'maxLength' => 32, 'pattern' => '^[a-f0-9]{32}$'),
                ),
                'detail' => array('type' => 'string', 'enum' => array('summary', 'full'), 'default' => 'summary'),
            ),
            'required' => array('diagnostic_ids'),
        );
    }

    private function compareOutputSchema(): array
    {
        $stats = $this->strictObject(array(
            'min' => array('type' => 'number'),
            'median' => array('type' => 'number'),
            'max' => array('type' => 'number'),
        ));
        return $this->strictObject(array(
            'diagnostic_ids' => array('type' => 'array', 'items' => array('type' => 'string')),
            'capture_count' => array('type' => 'integer'),
            'identity' => $this->strictObject(array(
                'target' => array('type' => 'string'),
                'method' => array('type' => 'string'),
                'type' => array('type' => 'string'),
                'effective_user_id' => array('type' => 'integer'),
                'site_id' => array('type' => 'integer'),
                'profile' => array('type' => 'string'),
                'configuration_fingerprint' => array('type' => 'string'),
            )),
            'metrics' => $this->strictObject(array(
                'duration_ms' => $stats,
                'queries' => $stats,
                'query_time_ms' => $stats,
                'state_changes' => $stats,
                'http_requests' => $stats,
            )),
            'recurring' => $this->strictObject(array(
                'components' => array('type' => 'array', 'items' => array('type' => 'object', 'additionalProperties' => true)),
                'query_patterns' => array('type' => 'array', 'items' => array('type' => 'object', 'additionalProperties' => true)),
                'http_requests' => array('type' => 'array', 'items' => array('type' => 'object', 'additionalProperties' => true)),
                'opportunities' => array('type' => 'array', 'items' => array('type' => 'object', 'additionalProperties' => true)),
            )),
            'intermittent' => $this->strictObject(array(
                'http_requests' => array('type' => 'array', 'items' => array('type' => 'object', 'additionalProperties' => true)),
                'opportunities' => array('type' => 'array', 'items' => array('type' => 'object', 'additionalProperties' => true)),
            )),
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