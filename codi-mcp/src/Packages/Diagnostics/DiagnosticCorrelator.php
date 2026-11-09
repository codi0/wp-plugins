<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Diagnostics;

final class DiagnosticCorrelator
{
    private const MAX_FINDINGS = 20;

    /** @return array<string,mixed> */
    public function synthesize(array $capture): array
    {
        $findings = array();

        $hooks = is_array($capture['hooks']['items'] ?? null) ? (array) $capture['hooks']['items'] : array();
        $http = is_array($capture['http']['items'] ?? null) ? (array) $capture['http']['items'] : array();
        $callbackCosts = is_array($capture['database']['callback_costs'] ?? null) ? (array) $capture['database']['callback_costs'] : array();

        foreach ($hooks as $hookRow) {
            if (!is_array($hookRow)) {
                continue;
            }
            $hook = (string) ($hookRow['hook'] ?? '');
            $hookTime = max(0.0, (float) ($hookRow['execution']['time_ms'] ?? 0.0));

            foreach ((array) ($hookRow['callback_timing']['items'] ?? array()) as $callback) {
                if (!is_array($callback) || (string) ($callback['status'] ?? '') !== 'profiled') {
                    continue;
                }
                $wallMs = max(0.0, (float) ($callback['wall_ms'] ?? 0.0));
                if ($wallMs < 50.0) {
                    continue;
                }
                $source = is_array($callback['source'] ?? null) ? (array) $callback['source'] : array();
                $sourceKey = $this->sourceKey($source);

                $matchedHttp = array();
                $httpMs = 0.0;
                $httpContextExact = true;
                foreach ($http as $request) {
                    if (!is_array($request)) {
                        continue;
                    }
                    $callbackSource = is_array($request['callback_source'] ?? null) ? (array) $request['callback_source'] : null;
                    $requestSource = is_array($request['source'] ?? null) ? (array) $request['source'] : array();
                    $contextMatches = is_array($callbackSource)
                        && (string) ($callbackSource['hook'] ?? '') === $hook
                        && $this->sourceKey($callbackSource) === $sourceKey;
                    $visibleMatches = !is_array($callbackSource)
                        && (string) ($requestSource['hook'] ?? '') === $hook
                        && $this->sourceKey($requestSource) === $sourceKey;
                    if (!$contextMatches && !$visibleMatches) {
                        continue;
                    }
                    if (!$contextMatches) {
                        $httpContextExact = false;
                    }
                    $matchedHttp[] = $request;
                    $httpMs += max(0.0, (float) ($request['duration_ms'] ?? 0.0));
                }

                if ($matchedHttp !== array() && $httpMs >= 100.0) {
                    $confidence = $httpContextExact
                        && count($matchedHttp) === 1
                        && (int) ($callback['executions'] ?? 0) === 1
                        && abs($wallMs - $httpMs) <= max(100.0, $wallMs * 0.25)
                        ? 'high'
                        : 'medium';
                    $findings[] = $this->finding(
                        'callback_http_overlap',
                        $wallMs >= 500.0 ? 'high' : 'medium',
                        $confidence,
                        'Slow callback overlaps outbound HTTP from its active callback context',
                        'The outbound HTTP was collected while this callback context was active (or, for older captures, shares the same visible hook/source). The timing supports a correlation, not proof that the HTTP call explains the entire callback duration.',
                        $hook,
                        $source,
                        $wallMs,
                        array(
                            $this->evidence('hooks', 'callback_timing', $hook, $source, 'wall_ms', $wallMs),
                            $this->evidence('http', 'request', $hook, $source, 'duration_ms_sum', round($httpMs, 3)),
                            array('section' => 'http', 'kind' => 'request_count', 'value' => count($matchedHttp)),
                        )
                    );

                    if (count($findings) >= self::MAX_FINDINGS) {
                        break 2;
                    }
                    continue;
                }

                $cost = $this->matchingCallbackCost($callbackCosts, $source, $hook);
                $queryMs = max(0.0, (float) ($cost['query_time_ms'] ?? 0.0));
                $queries = max(0, (int) ($cost['queries'] ?? 0));
                if ($queries > 0 && $queryMs >= 20.0 && $queryMs / max(1.0, $wallMs) >= 0.25) {
                    $findings[] = $this->finding(
                        'callback_sql_overlap',
                        $wallMs >= 250.0 && $queryMs >= 100.0 ? 'high' : 'medium',
                        'high',
                        'Slow callback contains material SQL activity in its active callback context',
                        'The SQL was collected while this callback context was active. Its aggregate query time is material relative to the callback wall time; this links the work to the callback without claiming SQL explains all remaining wall time.',
                        $hook,
                        $source,
                        $wallMs,
                        array(
                            $this->evidence('hooks', 'callback_timing', $hook, $source, 'wall_ms', $wallMs),
                            $this->evidence('database', 'callback_cost', $hook, $source, 'query_time_ms', round($queryMs, 3)),
                            array('section' => 'database', 'kind' => 'callback_cost', 'hook' => $hook, 'metric' => 'queries', 'value' => $queries),
                        )
                    );
                    if (count($findings) >= self::MAX_FINDINGS) {
                        break 2;
                    }
                    continue;
                }

                if ($hookTime > 0.0 && $wallMs / $hookTime >= 0.60) {
                    $findings[] = $this->finding(
                        'callback_dominates_hook',
                        $wallMs >= 250.0 ? 'high' : 'medium',
                        'high',
                        'One measured callback dominates its hook wall time',
                        'The measured callback accounts for at least 60% of the hook wall time in this capture.',
                        $hook,
                        $source,
                        $wallMs,
                        array(
                            $this->evidence('hooks', 'callback_timing', $hook, $source, 'wall_ms', $wallMs),
                            array('section' => 'hooks', 'kind' => 'hook_execution', 'hook' => $hook, 'metric' => 'time_ms', 'value' => round($hookTime, 3)),
                            array('section' => 'hooks', 'kind' => 'ratio', 'hook' => $hook, 'metric' => 'callback_to_hook', 'value' => round($wallMs / $hookTime, 3)),
                        )
                    );
                    if (count($findings) >= self::MAX_FINDINGS) {
                        break 2;
                    }
                }
            }
        }

        if (count($findings) < self::MAX_FINDINGS) {
            $cacheFinding = $this->cacheReadCorrelation($capture);
            if ($cacheFinding !== null) {
                $findings[] = $cacheFinding;
            }
        }

        usort($findings, static function (array $a, array $b): int {
            $severity = array('high' => 3, 'medium' => 2, 'low' => 1);
            $confidence = array('high' => 3, 'medium' => 2, 'low' => 1);
            return array(
                $severity[(string) ($b['severity'] ?? 'low')] ?? 0,
                $confidence[(string) ($b['confidence'] ?? 'low')] ?? 0,
                (float) ($b['impact_ms'] ?? 0.0),
            ) <=> array(
                $severity[(string) ($a['severity'] ?? 'low')] ?? 0,
                $confidence[(string) ($a['confidence'] ?? 'low')] ?? 0,
                (float) ($a['impact_ms'] ?? 0.0),
            );
        });

        $findings = array_slice($findings, 0, self::MAX_FINDINGS);
        $byConfidence = array('high' => 0, 'medium' => 0, 'low' => 0);
        foreach ($findings as $finding) {
            $confidence = (string) ($finding['confidence'] ?? 'low');
            if (isset($byConfidence[$confidence])) {
                ++$byConfidence[$confidence];
            }
        }

        return array(
            'count' => count($findings),
            'truncated' => count($findings) >= self::MAX_FINDINGS,
            'by_confidence' => $byConfidence,
            'items' => $findings,
        );
    }

    private function cacheReadCorrelation(array $capture): ?array
    {
        $cache = is_array($capture['cache'] ?? null) ? (array) $capture['cache'] : array();
        $cacheIssue = '';
        foreach ((array) ($cache['opportunities'] ?? array()) as $opportunity) {
            if (!is_array($opportunity)) {
                continue;
            }
            $kind = (string) ($opportunity['kind'] ?? '');
            if (in_array($kind, array('object_cache_read_unavailable', 'object_cache_cross_request_miss', 'object_cache_persistence_failed'), true)) {
                $cacheIssue = $kind;
                break;
            }
        }
        if ($cacheIssue === '') {
            return null;
        }

        $patterns = is_array($capture['database']['patterns'] ?? null) ? (array) $capture['database']['patterns'] : array();
        $repeated = array();
        $queries = 0;
        $timeMs = 0.0;
        foreach ($patterns as $pattern) {
            if (!is_array($pattern)) {
                continue;
            }
            $query = strtolower((string) ($pattern['query'] ?? ''));
            $count = max(0, (int) ($pattern['count'] ?? 0));
            $patternMs = max(0.0, (float) ($pattern['time_ms'] ?? 0.0));
            if ($count < 10 || $patternMs < 10.0) {
                continue;
            }
            if (!str_contains($query, 'option_value') && !str_contains($query, 'sitemeta')) {
                continue;
            }
            $repeated[] = $pattern;
            $queries += $count;
            $timeMs += $patternMs;
        }
        if ($repeated === array()) {
            return null;
        }

        usort($repeated, static fn (array $a, array $b): int => (float) ($b['time_ms'] ?? 0.0) <=> (float) ($a['time_ms'] ?? 0.0));
        $top = $repeated[0];
        $source = is_array($top['source'] ?? null) ? (array) $top['source'] : array();

        return $this->finding(
            'cache_read_path_with_repeated_option_reads',
            $timeMs >= 100.0 ? 'high' : 'medium',
            'medium',
            'Object-cache read-path issue coincides with repeated option/site-meta reads',
            'The same capture shows an external object-cache read-path or cross-request miss and repeated option or site-meta query patterns. This supports investigating cache policy/configuration before treating the repeated reads as an isolated database problem; it does not by itself prove backend persistence failure.',
            (string) ($source['hook'] ?? ''),
            $source,
            round($timeMs, 3),
            array(
                array('section' => 'cache', 'kind' => 'opportunity', 'metric' => $cacheIssue, 'value' => true),
                array('section' => 'database', 'kind' => 'patterns', 'metric' => 'repeated_queries', 'value' => $queries),
                array('section' => 'database', 'kind' => 'patterns', 'metric' => 'time_ms_sum', 'value' => round($timeMs, 3)),
            )
        );
    }

    /** @param array<int,array<string,mixed>> $costs */
    private function matchingCallbackCost(array $costs, array $source, string $hook): array
    {
        $key = $this->sourceKey($source);
        foreach ($costs as $cost) {
            if (!is_array($cost)) {
                continue;
            }
            $costSource = is_array($cost['source'] ?? null) ? (array) $cost['source'] : array();
            if ((string) ($costSource['hook'] ?? '') === $hook && $this->sourceKey($costSource) === $key) {
                return $cost;
            }
        }
        return array();
    }

    private function sourceKey(array $source): string
    {
        return strtolower(implode('|', array(
            (string) ($source['component_type'] ?? ''),
            (string) ($source['component'] ?? ''),
            (string) ($source['file'] ?? ''),
            (string) ($source['line'] ?? 0),
            (string) ($source['callable'] ?? ''),
        )));
    }

    /** @return array<string,mixed> */
    private function finding(
        string $ruleId,
        string $severity,
        string $confidence,
        string $title,
        string $summary,
        string $hook,
        array $source,
        float $impactMs,
        array $evidence
    ): array {
        return array(
            'rule_id' => $ruleId,
            'severity' => $severity,
            'confidence' => $confidence,
            'title' => $title,
            'summary' => $summary,
            'hook' => $hook,
            'source' => $source,
            'impact_ms' => round(max(0.0, $impactMs), 3),
            'evidence' => array_slice($evidence, 0, 4),
        );
    }

    /** @return array<string,mixed> */
    private function evidence(
        string $section,
        string $kind,
        string $hook,
        array $source,
        string $metric,
        float $value
    ): array {
        return array(
            'section' => $section,
            'kind' => $kind,
            'hook' => $hook,
            'source' => $source,
            'metric' => $metric,
            'value' => round(max(0.0, $value), 3),
        );
    }
}
