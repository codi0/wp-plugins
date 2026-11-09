<?php

declare(strict_types=1);

namespace CodiMcpTest\Unit;

use CodiMcp\Packages\Diagnostics\CallbackProfiler;
use CodiMcpTest\Framework\TestCase;

final class CallbackProfilerTestHook
{
    public array $callbacks = array();
    private int $priority;

    public function __construct(int $priority = -1000000)
    {
        $this->priority = $priority;
    }

    public function current_priority(): int
    {
        return $this->priority;
    }
}

final class CallbackProfilerTest extends TestCase
{
    public function test_profiles_nested_callbacks_and_restores_original_callables(): void
    {
        $hook = new CallbackProfilerTestHook();
        $inner = static function (string $value): string {
            usleep(2000);
            return $value . ':inner';
        };
        $outer = function (string $value) use ($hook): string {
            usleep(1000);
            $callback = $hook->callbacks[20]['inner']['function'];
            return $callback($value . ':outer');
        };
        $hook->callbacks = array(
            10 => array('outer' => array('function' => $outer, 'accepted_args' => 1)),
            20 => array('inner' => array('function' => $inner, 'accepted_args' => 1)),
        );

        $profiler = new CallbackProfiler();
        $owner = new \stdClass();
        $profiler->beginHook('fixture_hook', $hook, $hook->current_priority(), 1000000, $owner);

        $wrappedOuter = $hook->callbacks[10]['outer']['function'];
        $this->assertFalse($wrappedOuter === $outer);
        $this->assertSame('value:outer:inner', $wrappedOuter('value'));

        $profiler->endHook('fixture_hook', $hook);
        $this->assertTrue($hook->callbacks[10]['outer']['function'] === $outer);
        $this->assertTrue($hook->callbacks[20]['inner']['function'] === $inner);

        $report = $profiler->report('fixture_hook');
        $this->assertSame(2, (int) ($report['profiled_callbacks'] ?? 0));
        $this->assertSame(2, (int) ($report['executions'] ?? 0));
        $this->assertFalse((bool) ($report['registry_changed'] ?? true));
        $this->assertTrue((bool) ($report['coverage_complete'] ?? false));
        $this->assertTrue((float) ($report['inclusive_wall_ms_sum'] ?? 0.0) >= 3.0);
        $this->assertArrayHasKey('setup_ms', $report);
        $this->assertArrayHasKey('restore_ms', $report);
        $this->assertArrayHasKey('bookkeeping_ms', $report);

        $rows = (array) ($report['items'] ?? array());
        $outerRow = null;
        foreach ($rows as $row) {
            if ((int) ($row['priority'] ?? 0) === 10) {
                $outerRow = $row;
                break;
            }
        }
        $this->assertTrue(is_array($outerRow));
        $this->assertTrue((float) ($outerRow['profiled_child_ms'] ?? 0.0) >= 1.0);
        $this->assertTrue((float) ($outerRow['exclusive_profiled_ms'] ?? 0.0) >= 0.0);
    }

    public function test_removal_during_execution_is_not_resurrected(): void
    {
        $hook = new CallbackProfilerTestHook();
        $original = static fn (string $value): string => $value;
        $hook->callbacks = array(
            10 => array('fixture' => array('function' => $original, 'accepted_args' => 1)),
        );

        $profiler = new CallbackProfiler();
        $profiler->beginHook('fixture_hook', $hook, $hook->current_priority(), 1000000, new \stdClass());
        unset($hook->callbacks[10]['fixture']);
        $profiler->endHook('fixture_hook', $hook);

        $this->assertFalse(isset($hook->callbacks[10]['fixture']));
        $this->assertTrue((bool) ($profiler->report('fixture_hook')['registry_changed'] ?? false));
    }

    public function test_replacement_during_execution_is_not_overwritten(): void
    {
        $hook = new CallbackProfilerTestHook();
        $original = static fn (string $value): string => $value;
        $replacement = static fn (string $value): string => $value . ':replacement';
        $hook->callbacks = array(
            10 => array('fixture' => array('function' => $original, 'accepted_args' => 1)),
        );

        $profiler = new CallbackProfiler();
        $profiler->beginHook('fixture_hook', $hook, $hook->current_priority(), 1000000, new \stdClass());
        $hook->callbacks[10]['fixture']['function'] = $replacement;
        $profiler->endHook('fixture_hook', $hook);

        $this->assertTrue($hook->callbacks[10]['fixture']['function'] === $replacement);
        $this->assertTrue((bool) ($profiler->report('fixture_hook')['registry_changed'] ?? false));
    }

    public function test_recursive_hook_entry_does_not_double_wrap_or_restore_early(): void
    {
        $hook = new CallbackProfilerTestHook();
        $original = static fn (string $value): string => $value;
        $hook->callbacks = array(
            10 => array('fixture' => array('function' => $original, 'accepted_args' => 1)),
        );

        $profiler = new CallbackProfiler();
        $owner = new \stdClass();
        $profiler->beginHook('fixture_hook', $hook, $hook->current_priority(), 1000000, $owner);
        $firstWrapper = $hook->callbacks[10]['fixture']['function'];
        $profiler->beginHook('fixture_hook', $hook, $hook->current_priority(), 1000000, $owner);
        $this->assertTrue($hook->callbacks[10]['fixture']['function'] === $firstWrapper);

        $profiler->endHook('fixture_hook', $hook);
        $this->assertTrue($hook->callbacks[10]['fixture']['function'] === $firstWrapper);
        $profiler->endHook('fixture_hook', $hook);
        $this->assertTrue($hook->callbacks[10]['fixture']['function'] === $original);
    }

    public function test_reference_sensitive_callback_is_left_untouched(): void
    {
        $hook = new CallbackProfilerTestHook();
        $original = static function (&$value): void {
            $value = 'changed';
        };
        $hook->callbacks = array(
            10 => array('fixture' => array('function' => $original, 'accepted_args' => 1)),
        );

        $profiler = new CallbackProfiler();
        $profiler->beginHook('fixture_hook', $hook, $hook->current_priority(), 1000000, new \stdClass());

        $this->assertTrue($hook->callbacks[10]['fixture']['function'] === $original);
        $report = $profiler->report('fixture_hook');
        $this->assertSame(0, (int) ($report['profiled_callbacks'] ?? -1));
        $this->assertSame(1, (int) ($report['skipped_callbacks'] ?? 0));
        $this->assertSame('skipped_reference', (string) ($report['items'][0]['status'] ?? ''));
        $this->assertFalse((bool) ($report['coverage_complete'] ?? true));
    }

    public function test_wrapper_preserves_slot_identity_and_downstream_source_attribution(): void
    {
        $hook = new CallbackProfilerTestHook();
        $attribution = new \CodiMcp\Core\SourceAttribution();
        $observed = array();
        $observedContext = array();
        $original = static function () use (&$observed, &$observedContext, $attribution): void {
            $previous = $GLOBALS['wp_current_filter'] ?? array();
            $GLOBALS['wp_current_filter'] = array('fixture_hook', 'nested_hook');
            try {
                $observed = $attribution->fromBacktrace();
                $observedContext = (array) ($attribution->currentContextSource() ?? array());
            } finally {
                $GLOBALS['wp_current_filter'] = $previous;
            }
        };
        $hook->callbacks = array(
            10 => array('stable-slot' => array('function' => $original, 'accepted_args' => 0)),
        );
        $keys = array_keys($hook->callbacks[10]);

        $profiler = new CallbackProfiler($attribution);
        $profiler->beginHook('fixture_hook', $hook, $hook->current_priority(), 1000000, new \stdClass());
        $this->assertSame($keys, array_keys($hook->callbacks[10]));
        $wrapped = $hook->callbacks[10]['stable-slot']['function'];
        $wrapped();
        $profiler->endHook('fixture_hook', $hook);

        $this->assertTrue($hook->callbacks[10]['stable-slot']['function'] === $original);
        $this->assertTrue(str_contains((string) ($observed['file'] ?? ''), 'CallbackProfilerTest.php'));
        $this->assertFalse(str_contains((string) ($observed['file'] ?? ''), 'CallbackProfiler.php'));
        $this->assertSame('fixture_hook', (string) ($observedContext['hook'] ?? ''));
    }

    public function test_reference_return_callback_is_left_untouched(): void
    {
        $hook = new CallbackProfilerTestHook();
        $value = 'original';
        $original = static function &() use (&$value): string {
            return $value;
        };
        $hook->callbacks = array(
            10 => array('fixture' => array('function' => $original, 'accepted_args' => 0)),
        );

        $profiler = new CallbackProfiler();
        $profiler->beginHook('fixture_hook', $hook, $hook->current_priority(), 1000000, new \stdClass());

        $this->assertTrue($hook->callbacks[10]['fixture']['function'] === $original);
        $report = $profiler->report('fixture_hook');
        $this->assertSame('skipped_reference', (string) ($report['items'][0]['status'] ?? ''));
        $this->assertFalse((bool) ($report['coverage_complete'] ?? true));
    }

    public function test_registry_shape_changes_are_detected_without_overwriting_application_state(): void
    {
        $hook = new CallbackProfilerTestHook();
        $original = static fn (string $value): string => $value;
        $hook->callbacks = array(
            10 => array('fixture' => array('function' => $original, 'accepted_args' => 1)),
        );

        $profiler = new CallbackProfiler();
        $profiler->beginHook('fixture_hook', $hook, $hook->current_priority(), 1000000, new \stdClass());
        $hook->callbacks[10]['fixture']['accepted_args'] = 2;
        $profiler->endHook('fixture_hook', $hook);

        $this->assertTrue($hook->callbacks[10]['fixture']['function'] === $original);
        $this->assertSame(2, (int) $hook->callbacks[10]['fixture']['accepted_args']);
        $this->assertTrue((bool) ($profiler->report('fixture_hook')['registry_changed'] ?? false));
    }

    public function test_original_callback_identity_can_remove_wrapped_slot_without_resurrection(): void
    {
        $hook = new CallbackProfilerTestHook();
        $original = null;
        $original = static function (string $value) use ($hook, &$original): string {
            $key = 'spl_object_id:' . spl_object_id($original);
            unset($hook->callbacks[10][$key]);
            return $value . ':removed';
        };
        $key = 'spl_object_id:' . spl_object_id($original);
        $hook->callbacks = array(
            10 => array($key => array('function' => $original, 'accepted_args' => 1)),
        );

        $profiler = new CallbackProfiler();
        $profiler->beginHook('fixture_hook', $hook, $hook->current_priority(), 1000000, new \stdClass());
        $wrapped = $hook->callbacks[10][$key]['function'];
        $this->assertSame('value:removed', $wrapped('value'));
        $profiler->endHook('fixture_hook', $hook);

        $this->assertFalse(isset($hook->callbacks[10][$key]));
        $this->assertTrue((bool) ($profiler->report('fixture_hook')['registry_changed'] ?? false));
    }

    public function test_reused_hook_slot_creates_a_new_timing_identity_for_a_new_callable(): void
    {
        $hook = new CallbackProfilerTestHook();
        $first = static fn (string $value): string => $value . ':first';
        $second = static fn (string $value): string => $value . ':second';
        $hook->callbacks = array(
            10 => array('shared-slot' => array('function' => $first, 'accepted_args' => 1)),
        );

        $profiler = new CallbackProfiler();
        $owner = new \stdClass();
        $profiler->beginHook('fixture_hook', $hook, $hook->current_priority(), 1000000, $owner);
        $wrapped = $hook->callbacks[10]['shared-slot']['function'];
        $this->assertSame('value:first', $wrapped('value'));
        $profiler->endHook('fixture_hook', $hook);

        $hook->callbacks[10]['shared-slot'] = array('function' => $second, 'accepted_args' => 1);
        $profiler->beginHook('fixture_hook', $hook, $hook->current_priority(), 1000000, $owner);
        $wrapped = $hook->callbacks[10]['shared-slot']['function'];
        $this->assertSame('value:second', $wrapped('value'));
        $profiler->endHook('fixture_hook', $hook);

        $report = $profiler->report('fixture_hook');
        $this->assertSame(2, (int) ($report['profiled_callbacks'] ?? 0));
        $this->assertSame(2, (int) ($report['executions'] ?? 0));
        $this->assertSame(2, count((array) ($report['items'] ?? array())));
    }

    public function test_per_hook_limit_preserves_later_hook_coverage_and_bounds_returned_rows(): void
    {
        $callback = static fn (): null => null;
        $first = new CallbackProfilerTestHook();
        $first->callbacks = array(10 => array());
        for ($index = 0; $index < 260; ++$index) {
            $first->callbacks[10]['fixture-' . $index] = array('function' => $callback, 'accepted_args' => 0);
        }

        $second = new CallbackProfilerTestHook();
        $second->callbacks = array(
            10 => array('later' => array('function' => $callback, 'accepted_args' => 0)),
        );

        $profiler = new CallbackProfiler();
        $owner = new \stdClass();

        $profiler->beginHook('large_hook', $first, $first->current_priority(), 1000000, $owner);
        foreach ($first->callbacks[10] as $registration) {
            ($registration['function'])();
        }
        $profiler->endHook('large_hook', $first);

        $large = $profiler->report('large_hook');
        $this->assertSame(250, (int) ($large['profiled_callbacks'] ?? 0));
        $this->assertSame(10, (int) ($large['callbacks_omitted_by_limit'] ?? 0));
        $this->assertSame(100, (int) ($large['items_returned'] ?? 0));
        $this->assertTrue((bool) ($large['items_truncated'] ?? false));
        $this->assertFalse((bool) ($large['coverage_complete'] ?? true));

        $profiler->beginHook('later_hook', $second, $second->current_priority(), 1000000, $owner);
        ($second->callbacks[10]['later']['function'])();
        $profiler->endHook('later_hook', $second);

        $later = $profiler->report('later_hook');
        $this->assertSame(1, (int) ($later['profiled_callbacks'] ?? 0));
        $this->assertSame(0, (int) ($later['callbacks_omitted_by_limit'] ?? -1));
        $this->assertSame(1, (int) ($later['executions'] ?? 0));
    }

    public function test_exception_propagates_and_timing_is_recorded(): void
    {
        $hook = new CallbackProfilerTestHook();
        $original = static function (): void {
            throw new \RuntimeException('fixture failure');
        };
        $hook->callbacks = array(
            10 => array('fixture' => array('function' => $original, 'accepted_args' => 0)),
        );

        $profiler = new CallbackProfiler();
        $profiler->beginHook('fixture_hook', $hook, $hook->current_priority(), 1000000, new \stdClass());
        $wrapped = $hook->callbacks[10]['fixture']['function'];

        $message = '';
        try {
            $wrapped();
        } catch (\RuntimeException $exception) {
            $message = $exception->getMessage();
        }
        $this->assertSame('fixture failure', $message);
        $this->assertSame(1, (int) ($profiler->report('fixture_hook')['executions'] ?? 0));
    }
}
