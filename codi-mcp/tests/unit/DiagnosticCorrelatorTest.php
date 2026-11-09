<?php

declare(strict_types=1);

namespace CodiMcpTest\Unit;

use CodiMcp\Packages\Diagnostics\DiagnosticCorrelator;
use CodiMcpTest\Framework\TestCase;

final class DiagnosticCorrelatorTest extends TestCase
{
    public function test_same_source_callback_and_http_evidence_produces_bounded_explicit_correlation(): void
    {
        $source = $this->source('action-scheduler', 'ActionScheduler_QueueRunner->maybe_dispatch_async_request', 'shutdown');
        $capture = $this->baseCapture();
        $capture['hooks']['items'][] = array(
            'hook' => 'shutdown',
            'execution' => array('time_ms' => 1030.0),
            'cost_sources' => array(),
            'callback_timing' => array('items' => array(array(
                'priority' => 10,
                'status' => 'profiled',
                'executions' => 1,
                'wall_ms' => 1018.0,
                'source' => array_merge($source, array('hook' => '')),
            ))),
        );
        $capture['http']['items'][] = array(
            'duration_ms' => 1005.0,
            'source' => $this->source('woocommerce', 'NestedHttp::dispatch', 'shutdown'),
            'callback_source' => $source,
        );

        $result = (new DiagnosticCorrelator())->synthesize($capture);

        $this->assertSame(1, (int) ($result['count'] ?? 0));
        $finding = (array) ($result['items'][0] ?? array());
        $this->assertSame('callback_http_overlap', (string) ($finding['rule_id'] ?? ''));
        $this->assertSame('high', (string) ($finding['confidence'] ?? ''));
        $this->assertSame('shutdown', (string) ($finding['hook'] ?? ''));
        $this->assertTrue(count((array) ($finding['evidence'] ?? array())) <= 4);
        $this->assertTrue(str_contains((string) ($finding['summary'] ?? ''), 'not proof'));
    }

    public function test_same_source_sql_requires_material_ratio_and_reports_correlation_not_causation(): void
    {
        $source = $this->source('woocommerce', 'Example::run', 'admin_init');
        $capture = $this->baseCapture();
        $capture['hooks']['items'][] = array(
            'hook' => 'admin_init',
            'execution' => array('time_ms' => 210.0),
            'cost_sources' => array(),
            'callback_timing' => array('items' => array(array(
                'priority' => 10,
                'status' => 'profiled',
                'executions' => 1,
                'wall_ms' => 160.0,
                'source' => array_merge($source, array('hook' => '')),
            ))),
        );
        $capture['database']['callback_costs'][] = array(
            'source' => $source,
            'queries' => 8,
            'query_time_ms' => 60.0,
        );

        $warning = '';
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;
            return true;
        });
        try {
            $result = (new DiagnosticCorrelator())->synthesize($capture);
        } finally {
            restore_error_handler();
        }
        $finding = (array) ($result['items'][0] ?? array());

        $this->assertSame('', $warning);
        $this->assertSame('callback_sql_overlap', (string) ($finding['rule_id'] ?? ''));
        $this->assertSame('high', (string) ($finding['confidence'] ?? ''));
        $this->assertTrue(str_contains((string) ($finding['summary'] ?? ''), 'without claiming'));
    }

    public function test_cache_read_path_issues_correlate_only_with_material_repeated_option_reads(): void
    {
        foreach (array('object_cache_read_unavailable', 'object_cache_cross_request_miss') as $kind) {
            $capture = $this->baseCapture();
            $capture['cache']['opportunities'][] = array('kind' => $kind);
            $capture['database']['patterns'][] = array(
                'query' => 'SELECT option_value FROM wp_options WHERE option_name = ? LIMIT ?',
                'count' => 40,
                'time_ms' => 48.0,
                'source' => $this->source('woocommerce', 'get_option', 'plugins_loaded'),
            );

            $result = (new DiagnosticCorrelator())->synthesize($capture);
            $finding = (array) ($result['items'][0] ?? array());

            $this->assertSame('cache_read_path_with_repeated_option_reads', (string) ($finding['rule_id'] ?? ''));
            $this->assertSame('medium', (string) ($finding['confidence'] ?? ''));
            $this->assertSame(48.0, (float) ($finding['impact_ms'] ?? 0.0));
            $this->assertTrue(str_contains((string) ($finding['summary'] ?? ''), 'does not by itself prove backend persistence failure'));
        }
    }

    public function test_findings_are_bounded_even_when_many_hooks_qualify(): void
    {
        $capture = $this->baseCapture();
        for ($i = 0; $i < 30; ++$i) {
            $source = $this->source('fixture-' . $i, 'Fixture' . $i . '::run', 'hook_' . $i);
            $capture['hooks']['items'][] = array(
                'hook' => 'hook_' . $i,
                'execution' => array('time_ms' => 100.0),
                'cost_sources' => array(),
                'callback_timing' => array('items' => array(array(
                    'priority' => 10,
                    'status' => 'profiled',
                    'executions' => 1,
                    'wall_ms' => 80.0,
                    'source' => array_merge($source, array('hook' => '')),
                ))),
            );
        }

        $result = (new DiagnosticCorrelator())->synthesize($capture);

        $this->assertSame(20, (int) ($result['count'] ?? 0));
        $this->assertTrue((bool) ($result['truncated'] ?? false));
        $this->assertSame(20, count((array) ($result['items'] ?? array())));
    }

    private function baseCapture(): array
    {
        return array(
            'hooks' => array('items' => array()),
            'http' => array('items' => array()),
            'cache' => array('opportunities' => array()),
            'database' => array('patterns' => array(), 'callback_costs' => array()),
        );
    }

    private function source(string $component, string $callable, string $hook): array
    {
        return array(
            'component_type' => 'plugin',
            'component' => $component,
            'file' => $component . '/example.php',
            'callable' => $callable,
            'hook' => $hook,
        );
    }
}
