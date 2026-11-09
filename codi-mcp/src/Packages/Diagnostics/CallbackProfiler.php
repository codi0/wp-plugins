<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Diagnostics;

use CodiMcp\Core\SourceAttribution;

final class CallbackProfiler
{
    private const MAX_CALLBACKS_PER_HOOK = 250;
    private const MAX_RETURNED_CALLBACKS_PER_HOOK = 100;

    private SourceAttribution $attribution;
    private array $active = array();
    private array $stats = array();
    private array $meta = array();
    private array $invocationStack = array();
    private int $bookkeepingNs = 0;
    private int $setupNs = 0;
    private int $restoreNs = 0;
    private int $invocationBookkeepingNs = 0;

    public function __construct(?SourceAttribution $attribution = null)
    {
        $this->attribution = $attribution ?? new SourceAttribution();
    }

    public function beginHook(string $hook, object $hookObject, int|false $currentPriority, int $endPriority, object $owner): void
    {
        $started = hrtime(true);
        $hook = trim($hook);
        if ($hook === '' || !isset($hookObject->callbacks) || !is_array($hookObject->callbacks)) {
            $this->recordSetup($hook, hrtime(true) - $started);
            return;
        }

        if (!isset($this->active[$hook])) {
            $this->active[$hook] = array(
                'depth' => 0,
                'baseline' => $this->snapshot($hookObject->callbacks),
                'wrapped' => array(),
            );
        }
        $this->active[$hook]['depth']++;

        $lowerPriority = is_int($currentPriority) ? $currentPriority : PHP_INT_MIN;
        foreach ($hookObject->callbacks as $priority => &$registrations) {
            $priority = (int) $priority;
            if ($priority <= $lowerPriority || $priority >= $endPriority || !is_array($registrations)) {
                continue;
            }
            foreach ($registrations as $key => &$registration) {
                if (!is_array($registration) || !array_key_exists('function', $registration)) {
                    continue;
                }
                $callback = $registration['function'];
                if ($this->ownedBy($callback, $owner)) {
                    continue;
                }

                $slot = (string) $priority . ':' . (string) $key;
                $activeSlot = $this->active[$hook]['wrapped'][$slot] ?? null;
                if (is_array($activeSlot) && ($activeSlot['wrapper'] ?? null) === $callback) {
                    continue;
                }
                if (is_array($activeSlot) && ($activeSlot['wrapper'] ?? null) !== $callback) {
                    $this->markRegistryChanged($hook);
                    unset($this->active[$hook]['wrapped'][$slot]);
                }

                $acceptedArgs = (int) ($registration['accepted_args'] ?? 1);
                $statKey = $this->statKey($hook, $slot, $callback, $acceptedArgs);
                if ($statKey === null) {
                    continue;
                }
                if (!isset($this->stats[$hook][$statKey])) {
                    $status = $this->profileStatus($callback, $acceptedArgs);
                    $this->stats[$hook][$statKey] = array(
                        'priority' => $priority,
                        'accepted_args' => $acceptedArgs,
                        'source' => $this->attribution->fromCallable($callback),
                        'status' => $status,
                        'executions' => 0,
                        'wall_ns' => 0,
                        'max_ns' => 0,
                        'child_ns' => 0,
                    );
                }

                if (($this->stats[$hook][$statKey]['status'] ?? '') !== 'profiled') {
                    continue;
                }

                $original = $callback;
                $wrapper = function (...$args) use ($hook, $statKey, $original) {
                    return $this->invoke($hook, $statKey, $original, $args);
                };
                $registration['function'] = $wrapper;
                $this->active[$hook]['wrapped'][$slot] = array(
                    'priority' => $priority,
                    'key' => $key,
                    'original' => $original,
                    'wrapper' => $wrapper,
                );
            }
            unset($registration);
        }
        unset($registrations);

        $this->recordSetup($hook, hrtime(true) - $started);
    }

    public function endHook(string $hook, object $hookObject): void
    {
        $started = hrtime(true);
        if (!isset($this->active[$hook])) {
            $this->recordRestore($hook, hrtime(true) - $started);
            return;
        }

        $this->active[$hook]['depth'] = max(0, (int) $this->active[$hook]['depth'] - 1);
        if ($this->active[$hook]['depth'] > 0) {
            $this->recordRestore($hook, hrtime(true) - $started);
            return;
        }

        $this->restoreHook($hook, $hookObject);
        unset($this->active[$hook]);
        $this->recordRestore($hook, hrtime(true) - $started);
    }

    public function restoreAll(array $hooks): void
    {
        foreach ($this->active as $hook => $_state) {
            $hookObject = $hooks[$hook] ?? null;
            if (is_object($hookObject)) {
                $started = hrtime(true);
                $this->restoreHook((string) $hook, $hookObject);
                $this->recordRestore((string) $hook, hrtime(true) - $started);
            }
        }
        $this->active = array();
    }

    public function report(string $hook): array
    {
        $rows = array_values((array) ($this->stats[$hook] ?? array()));
        usort($rows, static function (array $a, array $b): int {
            $wall = (int) ($b['wall_ns'] ?? 0) <=> (int) ($a['wall_ns'] ?? 0);
            return $wall !== 0 ? $wall : ((int) ($a['priority'] ?? 0) <=> (int) ($b['priority'] ?? 0));
        });

        $profiled = 0;
        $skipped = 0;
        $executions = 0;
        $wallNs = 0;
        foreach ($rows as &$row) {
            $status = (string) ($row['status'] ?? '');
            if ($status === 'profiled') {
                ++$profiled;
            } else {
                ++$skipped;
            }
            $executions += (int) ($row['executions'] ?? 0);
            $wallNs += (int) ($row['wall_ns'] ?? 0);
            $row['wall_ms'] = round(((int) ($row['wall_ns'] ?? 0)) / 1000000, 3);
            $row['max_ms'] = round(((int) ($row['max_ns'] ?? 0)) / 1000000, 3);
            $row['profiled_child_ms'] = round(((int) ($row['child_ns'] ?? 0)) / 1000000, 3);
            $row['exclusive_profiled_ms'] = round(max(0, (int) ($row['wall_ns'] ?? 0) - (int) ($row['child_ns'] ?? 0)) / 1000000, 3);
            unset($row['wall_ns'], $row['max_ns'], $row['child_ns']);
        }
        unset($row);

        $returnedRows = array_slice($rows, 0, self::MAX_RETURNED_CALLBACKS_PER_HOOK);

        return array(
            'enabled' => true,
            'profiled_callbacks' => $profiled,
            'skipped_callbacks' => $skipped,
            'callbacks_omitted_by_limit' => max(0, (int) ($this->meta[$hook]['limit_omitted'] ?? 0)),
            'executions' => $executions,
            'inclusive_wall_ms_sum' => round($wallNs / 1000000, 3),
            'skipped_reference' => count(array_filter($rows, static fn (array $row): bool => (string) ($row['status'] ?? '') === 'skipped_reference')),
            'skipped_unreflectable' => count(array_filter($rows, static fn (array $row): bool => (string) ($row['status'] ?? '') === 'skipped_unreflectable')),
            'setup_ms' => round(((int) ($this->meta[$hook]['setup_ns'] ?? 0)) / 1000000, 3),
            'restore_ms' => round(((int) ($this->meta[$hook]['restore_ns'] ?? 0)) / 1000000, 3),
            'bookkeeping_ms' => round(((int) ($this->meta[$hook]['invocation_bookkeeping_ns'] ?? 0)) / 1000000, 3),
            'registry_changed' => !empty($this->meta[$hook]['registry_changed']),
            'coverage_complete' => $skipped === 0
                && max(0, (int) ($this->meta[$hook]['limit_omitted'] ?? 0)) === 0
                && empty($this->meta[$hook]['registry_changed']),
            'items_returned' => count($returnedRows),
            'items_truncated' => count($rows) > count($returnedRows),
            'items' => $returnedRows,
        );
    }

    public function overhead(): array
    {
        return array(
            'setup_ms' => round($this->setupNs / 1000000, 3),
            'restore_ms' => round($this->restoreNs / 1000000, 3),
            'bookkeeping_ms' => round($this->invocationBookkeepingNs / 1000000, 3),
            'measured_overhead_ms' => round($this->bookkeepingNs / 1000000, 3),
            'incomplete_invocations' => count($this->invocationStack),
        );
    }

    private function invoke(string $hook, string $statKey, callable $original, array $args)
    {
        $preStarted = hrtime(true);
        $source = is_array($this->stats[$hook][$statKey]['source'] ?? null)
            ? (array) $this->stats[$hook][$statKey]['source']
            : array();
        $source['hook'] = $hook;
        $this->attribution->pushContext($source, array(__FILE__));
        $this->invocationStack[] = array('hook' => $hook, 'child_ns' => 0);
        $callStarted = hrtime(true);
        $this->recordInvocationBookkeeping($hook, $callStarted - $preStarted);

        try {
            return $original(...$args);
        } finally {
            $callEnded = hrtime(true);
            $postStarted = $callEnded;
            $this->attribution->popContext();
            $frame = array_pop($this->invocationStack);
            $wallNs = max(0, $callEnded - $callStarted);
            $childNs = is_array($frame) ? max(0, (int) ($frame['child_ns'] ?? 0)) : 0;

            if (isset($this->stats[$hook][$statKey])) {
                $this->stats[$hook][$statKey]['executions']++;
                $this->stats[$hook][$statKey]['wall_ns'] += $wallNs;
                $this->stats[$hook][$statKey]['max_ns'] = max((int) $this->stats[$hook][$statKey]['max_ns'], $wallNs);
                $this->stats[$hook][$statKey]['child_ns'] += min($wallNs, $childNs);
            }

            $parent = count($this->invocationStack) - 1;
            if ($parent >= 0) {
                $this->invocationStack[$parent]['child_ns'] += $wallNs;
            }

            $this->recordInvocationBookkeeping($hook, hrtime(true) - $postStarted);
        }
    }

    private function restoreHook(string $hook, object $hookObject): void
    {
        if (!isset($hookObject->callbacks) || !is_array($hookObject->callbacks)) {
            $this->markRegistryChanged($hook);
            return;
        }

        $state = (array) ($this->active[$hook] ?? array());
        foreach ((array) ($state['wrapped'] ?? array()) as $wrapped) {
            $priority = (int) ($wrapped['priority'] ?? 0);
            $key = $wrapped['key'] ?? '';
            if (!isset($hookObject->callbacks[$priority][$key])) {
                $this->markRegistryChanged($hook);
                continue;
            }
            $current = $hookObject->callbacks[$priority][$key]['function'] ?? null;
            if ($current !== ($wrapped['wrapper'] ?? null)) {
                $this->markRegistryChanged($hook);
                continue;
            }
            $hookObject->callbacks[$priority][$key]['function'] = $wrapped['original'];
        }

        if ($this->snapshot($hookObject->callbacks) !== (array) ($state['baseline'] ?? array())) {
            $this->markRegistryChanged($hook);
        }
    }

    private function snapshot(array $callbacks): array
    {
        $hash = hash_init('sha256');
        $count = 0;
        foreach ($callbacks as $priority => $registrations) {
            foreach ((array) $registrations as $key => $registration) {
                if (!is_array($registration) || !array_key_exists('function', $registration)) {
                    continue;
                }
                ++$count;
                hash_update($hash, (string) ((int) $priority));
                hash_update($hash, "\0");
                hash_update($hash, (string) $key);
                hash_update($hash, "\0");
                hash_update($hash, (string) ((int) ($registration['accepted_args'] ?? 1)));
                hash_update($hash, "\0");
                hash_update($hash, $this->callbackIdentity($registration['function']));
                hash_update($hash, "\n");
            }
        }
        return array('count' => $count, 'sha256' => hash_final($hash));
    }

    private function callbackIdentity($callback): string
    {
        if ($callback instanceof \Closure) {
            return 'closure:' . spl_object_id($callback);
        }
        if (is_string($callback)) {
            return 'string:' . $callback;
        }
        if (is_array($callback) && count($callback) >= 2) {
            $target = $callback[0];
            $method = (string) $callback[1];
            return is_object($target)
                ? 'object:' . get_class($target) . ':' . spl_object_id($target) . ':' . $method
                : 'class:' . (string) $target . ':' . $method;
        }
        if (is_object($callback)) {
            return 'invokable:' . get_class($callback) . ':' . spl_object_id($callback);
        }
        return 'other:' . gettype($callback);
    }

    private function statKey(string $hook, string $slot, $callback, int $acceptedArgs): ?string
    {
        $state = is_array($this->meta[$hook]['slot_state'][$slot] ?? null)
            ? $this->meta[$hook]['slot_state'][$slot]
            : array();
        $generation = max(0, (int) ($state['generation'] ?? 0));
        $sameCallback = array_key_exists('callback', $state) && ($state['callback'] === $callback);
        $sameAcceptedArgs = array_key_exists('accepted_args', $state)
            && (int) $state['accepted_args'] === $acceptedArgs;

        if ($state !== array() && $sameCallback && $sameAcceptedArgs) {
            return $slot . '#' . $generation;
        }
        if (count((array) ($this->stats[$hook] ?? array())) >= self::MAX_CALLBACKS_PER_HOOK) {
            $this->meta[$hook]['limit_omitted'] = max(0, (int) ($this->meta[$hook]['limit_omitted'] ?? 0)) + 1;
            return null;
        }
        if ($state !== array()) {
            ++$generation;
        }

        $this->meta[$hook]['slot_state'][$slot] = array(
            'callback' => $callback,
            'accepted_args' => $acceptedArgs,
            'generation' => $generation,
        );

        return $slot . '#' . $generation;
    }

    private function profileStatus($callback, int $acceptedArgs): string
    {
        try {
            $reflection = $this->reflection($callback);
            if (!$reflection instanceof \ReflectionFunctionAbstract) {
                return 'skipped_unreflectable';
            }
            if ($reflection->returnsReference()) {
                return 'skipped_reference';
            }
            foreach ($reflection->getParameters() as $index => $parameter) {
                if ($index >= max(0, $acceptedArgs) && !$parameter->isVariadic()) {
                    break;
                }
                if ($parameter->isPassedByReference() && ($index < max(0, $acceptedArgs) || $parameter->isVariadic())) {
                    return 'skipped_reference';
                }
            }
        } catch (\Throwable) {
            return 'skipped_unreflectable';
        }
        return 'profiled';
    }

    private function reflection($callback): ?\ReflectionFunctionAbstract
    {
        if ($callback instanceof \Closure) {
            return new \ReflectionFunction($callback);
        }
        if (is_string($callback)) {
            if (str_contains($callback, '::')) {
                [$class, $method] = explode('::', $callback, 2);
                return new \ReflectionMethod($class, $method);
            }
            return function_exists($callback) ? new \ReflectionFunction($callback) : null;
        }
        if (is_array($callback) && count($callback) >= 2) {
            $target = $callback[0];
            $class = is_object($target) ? get_class($target) : (string) $target;
            return new \ReflectionMethod($class, (string) $callback[1]);
        }
        if (is_object($callback) && method_exists($callback, '__invoke')) {
            return new \ReflectionMethod($callback, '__invoke');
        }
        return null;
    }

    private function ownedBy($callback, object $owner): bool
    {
        return is_array($callback) && is_object($callback[0] ?? null) && $callback[0] === $owner;
    }

    private function markRegistryChanged(string $hook): void
    {
        if ($hook !== '') {
            $this->meta[$hook]['registry_changed'] = true;
        }
    }

    private function recordSetup(string $hook, int $ns): void
    {
        $ns = max(0, $ns);
        $this->setupNs += $ns;
        $this->recordOverhead($hook, $ns, 'setup_ns');
    }

    private function recordRestore(string $hook, int $ns): void
    {
        $ns = max(0, $ns);
        $this->restoreNs += $ns;
        $this->recordOverhead($hook, $ns, 'restore_ns');
    }

    private function recordInvocationBookkeeping(string $hook, int $ns): void
    {
        $ns = max(0, $ns);
        $this->invocationBookkeepingNs += $ns;
        $this->recordOverhead($hook, $ns, 'invocation_bookkeeping_ns');
    }

    private function recordOverhead(string $hook, int $ns, string $bucket): void
    {
        $this->bookkeepingNs += $ns;
        if ($hook !== '') {
            $this->meta[$hook][$bucket] = (int) ($this->meta[$hook][$bucket] ?? 0) + $ns;
        }
    }
}
