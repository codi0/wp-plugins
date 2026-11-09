<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Diagnostics;

final class DiagnosticComparator
{
    private const MAX_CAPTURES = 10;
    private const MAX_RECURRING = 20;

    private DiagnosticStore $store;

    public function __construct(?DiagnosticStore $store = null)
    {
        $this->store = $store ?? new DiagnosticStore();
    }

    public function compare(array $input): array|\WP_Error
    {
        $ids = is_array($input['diagnostic_ids'] ?? null)
            ? array_values(array_unique(array_map(static fn ($id): string => strtolower(trim((string) $id)), $input['diagnostic_ids'])))
            : array();

        if (count($ids) < 2 || count($ids) > self::MAX_CAPTURES) {
            return new \WP_Error('codi_mcp_diagnostic_compare_ids', 'diagnostic_ids must contain 2 to 10 unique recent diagnostic IDs.');
        }

        $captures = array();
        foreach ($ids as $id) {
            if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
                return new \WP_Error('codi_mcp_diagnostic_compare_id_invalid', 'One or more diagnostic IDs are invalid.');
            }
            $capture = $this->store->get($id);
            if ($capture === false) {
                return new \WP_Error('codi_mcp_diagnostic_not_found', 'One or more diagnostic captures were not found or have expired.');
            }
            $captures[$id] = $capture;
        }

        $reference = $this->identity(reset($captures));
        foreach ($captures as $capture) {
            if ($this->identity($capture) !== $reference) {
                return new \WP_Error(
                    'codi_mcp_diagnostic_not_comparable',
                    'Diagnostic comparison requires the same target, method, request type, effective user, site, and diagnostic configuration.'
                );
            }
        }

        $detail = strtolower(trim((string) ($input['detail'] ?? 'summary')));
        $full = $detail === 'full';
        $components = $this->recurringComponents($captures);
        $http = $this->recurringHttp($captures);
        $opportunities = $this->recurringOpportunities($captures);

        $intermittentHttp = $this->intermittentHttp($captures);
        $intermittentOpportunities = $this->intermittentOpportunities($captures);
        return array(
            'diagnostic_ids' => array_keys($captures),
            'capture_count' => count($captures),
            'identity' => $reference,
            'metrics' => $this->metrics($captures),
            'recurring' => array(
                'components' => $full ? $components : array_slice($components, 0, 5),
                'query_patterns' => $full ? $this->recurringQueryPatterns($captures) : array(),
                'http_requests' => $full ? $http : array_slice($http, 0, 5),
                'opportunities' => $full ? $opportunities : array_slice($opportunities, 0, 10),
            ),
            'intermittent' => array(
                'http_requests' => $full ? $intermittentHttp : array_slice($intermittentHttp, 0, 5),
                'opportunities' => $full ? $intermittentOpportunities : array_slice($intermittentOpportunities, 0, 10),
            ),
        );
    }

    private function identity(array $capture): array
    {
        $request = is_array($capture['overview']['request'] ?? null) ? $capture['overview']['request'] : array();
        return array(
            'target' => (string) ($request['url'] ?? ''),
            'method' => (string) ($request['method'] ?? ''),
            'type' => (string) ($request['type'] ?? ''),
            'effective_user_id' => max(0, (int) ($request['user_id'] ?? 0)),
            'site_id' => max(0, (int) ($request['site_id'] ?? 0)),
            'profile' => (string) ($capture['overview']['diagnostic']['profile'] ?? 'none'),
            'configuration_fingerprint' => (string) ($capture['overview']['diagnostic']['configuration_fingerprint'] ?? ''),
        );
    }

    private function metrics(array $captures): array
    {
        $series = array(
            'duration_ms' => array(),
            'queries' => array(),
            'query_time_ms' => array(),
            'state_changes' => array(),
            'http_requests' => array(),
        );

        foreach ($captures as $capture) {
            $request = (array) ($capture['overview']['request'] ?? array());
            $summary = (array) ($capture['overview']['summary'] ?? array());
            $series['duration_ms'][] = (float) ($request['duration_ms'] ?? 0.0);
            $series['queries'][] = (float) ($summary['queries'] ?? 0);
            $series['query_time_ms'][] = (float) ($summary['query_time_ms'] ?? 0.0);
            $series['state_changes'][] = (float) ($summary['state_changes'] ?? 0);
            $series['http_requests'][] = (float) ($summary['http_requests'] ?? 0);
        }

        $result = array();
        foreach ($series as $name => $values) {
            $result[$name] = $this->stats($values);
        }
        return $result;
    }

    private function recurringComponents(array $captures): array
    {
        $rows = array();
        foreach ($captures as $capture) {
            foreach ((array) ($capture['database']['by_component'] ?? array()) as $component) {
                if (!is_array($component)) {
                    continue;
                }
                $name = (string) ($component['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $key = strtolower($name);
                $rows[$key] ??= array(
                    'name' => $name,
                    'observed_in' => 0,
                    'queries' => 0,
                    'time_ms' => 0.0,
                );
                $rows[$key]['observed_in']++;
                $rows[$key]['queries'] += max(0, (int) ($component['count'] ?? 0));
                $rows[$key]['time_ms'] += max(0.0, (float) ($component['time_ms'] ?? 0.0));
            }
        }
        return $this->finalizeRecurring($rows);
    }

    private function recurringQueryPatterns(array $captures): array
    {
        $rows = array();
        foreach ($captures as $capture) {
            foreach ((array) ($capture['database']['patterns'] ?? array()) as $pattern) {
                if (!is_array($pattern)) {
                    continue;
                }
                $query = (string) ($pattern['query'] ?? '');
                $source = is_array($pattern['source'] ?? null) ? $pattern['source'] : array();
                if ($query === '') {
                    continue;
                }
                $key = hash('sha256', $query . '|' . (string) ($source['component'] ?? '') . '|' . (string) ($source['callable'] ?? ''));
                $rows[$key] ??= array(
                    'query' => $query,
                    'source' => $source,
                    'observed_in' => 0,
                    'executions' => 0,
                    'time_ms' => 0.0,
                );
                $rows[$key]['observed_in']++;
                $rows[$key]['executions'] += max(0, (int) ($pattern['count'] ?? 0));
                $rows[$key]['time_ms'] += max(0.0, (float) ($pattern['time_ms'] ?? 0.0));
            }
        }
        return $this->finalizeRecurring($rows);
    }

    private function recurringHttp(array $captures): array
    {
        return $this->finalizeRecurring($this->httpEvidence($captures));
    }

    private function intermittentHttp(array $captures): array
    {
        $rows = array_values(array_filter(
            $this->httpEvidence($captures),
            static fn (array $row): bool => (int) ($row['observed_in'] ?? 0) === 1
                && ((float) ($row['time_ms'] ?? 0.0) >= 250.0 || (int) ($row['failures'] ?? 0) > 0)
        ));
        usort($rows, static fn (array $a, array $b): int => array((float) ($b['time_ms'] ?? 0.0), (int) ($b['failures'] ?? 0)) <=> array((float) ($a['time_ms'] ?? 0.0), (int) ($a['failures'] ?? 0)));
        return array_slice($rows, 0, self::MAX_RECURRING);
    }

    private function httpEvidence(array $captures): array
    {
        $rows = array();
        foreach ($captures as $diagnosticId => $capture) {
            $seen = array();
            foreach ((array) ($capture['http']['items'] ?? array()) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $source = is_array($item['source'] ?? null) ? $item['source'] : array();
                $key = hash('sha256',
                    (string) ($item['method'] ?? '') . '|'
                    . (string) ($item['url'] ?? '') . '|'
                    . (string) ($item['ajax_action'] ?? '') . '|'
                    . (string) ($source['component'] ?? '') . '|'
                    . (string) ($source['callable'] ?? '')
                );
                $rows[$key] ??= array(
                    'method' => (string) ($item['method'] ?? ''),
                    'url' => (string) ($item['url'] ?? ''),
                    'ajax_action' => (string) ($item['ajax_action'] ?? ''),
                    'source' => $source,
                    'observed_in' => 0,
                    'requests' => 0,
                    'time_ms' => 0.0,
                    'failures' => 0,
                    'diagnostic_ids' => array(),
                );
                if (!isset($seen[$key])) {
                    $rows[$key]['observed_in']++;
                    $rows[$key]['diagnostic_ids'][] = (string) $diagnosticId;
                    $seen[$key] = true;
                }
                $rows[$key]['requests']++;
                $rows[$key]['time_ms'] += max(0.0, (float) ($item['duration_ms'] ?? 0.0));
                if ((string) ($item['error_code'] ?? '') !== '' || (int) ($item['status'] ?? 0) >= 500) {
                    $rows[$key]['failures']++;
                }
            }
        }
        return array_values($rows);
    }

    private function recurringOpportunities(array $captures): array
    {
        return $this->finalizeRecurring($this->opportunityEvidence($captures), false);
    }

    private function intermittentOpportunities(array $captures): array
    {
        $rows = array_values(array_filter(
            $this->opportunityEvidence($captures),
            static fn (array $row): bool => (int) ($row['observed_in'] ?? 0) === 1
        ));
        $rank = array('high' => 3, 'medium' => 2, 'low' => 1);
        usort($rows, static function (array $a, array $b) use ($rank): int {
            $confidence = ($rank[(string) ($b['confidence'] ?? '')] ?? 0) <=> ($rank[(string) ($a['confidence'] ?? '')] ?? 0);
            return $confidence !== 0 ? $confidence : ((float) ($b['time_ms'] ?? 0.0) <=> (float) ($a['time_ms'] ?? 0.0));
        });
        return array_slice($rows, 0, self::MAX_RECURRING);
    }

    private function opportunityEvidence(array $captures): array
    {
        $rows = array();
        foreach ($captures as $diagnosticId => $capture) {
            $items = array_merge(
                (array) ($capture['database']['opportunities'] ?? array()),
                (array) ($capture['http']['opportunities'] ?? array()),
                (array) ($capture['cache']['opportunities'] ?? array())
            );
            $seen = array();
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $source = is_array($item['source'] ?? null) ? $item['source'] : array();
                $key = hash('sha256',
                    (string) ($item['kind'] ?? '') . '|'
                    . (string) ($item['query'] ?? '') . '|'
                    . (string) ($item['option'] ?? '') . '|'
                    . (string) ($item['url'] ?? '') . '|'
                    . (string) ($item['ajax_action'] ?? '') . '|'
                    . (string) ($source['component'] ?? '') . '|'
                    . (string) ($source['callable'] ?? '')
                );
                $rows[$key] ??= array(
                    'kind' => (string) ($item['kind'] ?? ''),
                    'confidence' => (string) ($item['confidence'] ?? ''),
                    'query' => (string) ($item['query'] ?? ''),
                    'option' => (string) ($item['option'] ?? ''),
                    'url' => (string) ($item['url'] ?? ''),
                    'ajax_action' => (string) ($item['ajax_action'] ?? ''),
                    'source' => $source,
                    'observed_in' => 0,
                    'time_ms' => max(0.0, (float) ($item['time_ms'] ?? $item['total_ms'] ?? $item['duration_ms'] ?? 0.0)),
                    'diagnostic_ids' => array(),
                );
                if (!isset($seen[$key])) {
                    $rows[$key]['observed_in']++;
                    $rows[$key]['diagnostic_ids'][] = (string) $diagnosticId;
                    $seen[$key] = true;
                }
            }
        }
        return array_values($rows);
    }

    private function finalizeRecurring(array $rows, bool $sortByTime = true): array
    {
        $rows = array_values(array_filter($rows, static fn (array $row): bool => (int) ($row['observed_in'] ?? 0) >= 2));
        usort($rows, static function (array $a, array $b) use ($sortByTime): int {
            $primary = (int) ($b['observed_in'] ?? 0) <=> (int) ($a['observed_in'] ?? 0);
            if ($primary !== 0) {
                return $primary;
            }
            if ($sortByTime) {
                return (float) ($b['time_ms'] ?? 0.0) <=> (float) ($a['time_ms'] ?? 0.0);
            }
            return strcmp((string) ($a['kind'] ?? ''), (string) ($b['kind'] ?? ''));
        });
        return array_slice($rows, 0, self::MAX_RECURRING);
    }

    private function stats(array $values): array
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);
        if ($count === 0) {
            return array('min' => 0.0, 'median' => 0.0, 'max' => 0.0);
        }
        $middle = intdiv($count, 2);
        $median = $count % 2 === 1
            ? (float) $values[$middle]
            : ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;

        return array(
            'min' => round((float) $values[0], 3),
            'median' => round($median, 3),
            'max' => round((float) $values[$count - 1], 3),
        );
    }
}
