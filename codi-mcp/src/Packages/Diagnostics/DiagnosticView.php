<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Diagnostics;

final class DiagnosticView
{
    private const SUMMARY_TOP_ITEMS = 5;
    /** @return array<string,mixed>|\WP_Error */
    public function render(array $capture, string $section, array $input): array|\WP_Error
    {
        $data = is_array($capture[$section] ?? null) ? (array) $capture[$section] : null;
        if ($data === null) {
            return new \WP_Error('codi_mcp_diagnostic_section_unavailable', 'The requested diagnostic section is unavailable.');
        }

        if ($section === 'overview') {
            $data['top_offenders'] = $this->topOffenders($capture);
            return $data;
        }

        $detail = strtolower(trim((string) ($input['detail'] ?? 'summary')));
        if (!in_array($detail, array('full', 'summary', 'items'), true)) {
            return new \WP_Error('codi_mcp_diagnostic_detail_invalid', 'detail must be full, summary, or items.');
        }

        if ($detail === 'full') {
            if ($section === 'hooks' && array_key_exists('include_callbacks', $input) && !$input['include_callbacks']) {
                foreach ((array) ($data['items'] ?? array()) as $index => $row) {
                    if (is_array($row)) {
                        unset($row['callbacks']);
                        $data['items'][$index] = $row;
                    }
                }
            }
            return $data;
        }

        if ($detail === 'summary') {
            return $this->summary($section, $data, $capture, $input);
        }

        return $this->items($section, $data, $input);
    }

    /** @return array<string,mixed>|\WP_Error */
    private function summary(string $section, array $data, array $capture, array $input): array|\WP_Error
    {
        if ($section === 'database') {
            return array(
                'available' => (bool) ($data['available'] ?? false),
                'capture_scope' => (string) ($data['capture_scope'] ?? ''),
                'queries_before_capture' => (int) ($data['queries_before_capture'] ?? 0),
                'count' => (int) ($data['count'] ?? 0),
                'internal_queries_excluded' => (int) ($data['internal_queries_excluded'] ?? 0),
                'time_ms' => (float) ($data['time_ms'] ?? 0.0),
                'by_type' => array_slice((array) ($data['by_type'] ?? array()), 0, 10),
                'top_components' => array_slice((array) ($data['by_component'] ?? array()), 0, self::SUMMARY_TOP_ITEMS),
                'top_patterns' => array_slice((array) ($data['patterns'] ?? array()), 0, self::SUMMARY_TOP_ITEMS),
                'slowest' => array_slice((array) ($data['slowest'] ?? array()), 0, self::SUMMARY_TOP_ITEMS),
                'opportunities' => array_slice((array) ($data['opportunities'] ?? array()), 0, self::SUMMARY_TOP_ITEMS),
                'autoload' => is_array($data['autoload'] ?? null) ? array(
                    'available' => (bool) ($data['autoload']['available'] ?? false),
                    'total_count' => (int) ($data['autoload']['total_count'] ?? 0),
                    'total_bytes' => (int) ($data['autoload']['total_bytes'] ?? 0),
                    'truncated' => (bool) ($data['autoload']['truncated'] ?? false),
                ) : array(),
            );
        }

        if ($section === 'hooks') {
            $rows = $this->filterHookRows((array) ($data['items'] ?? array()), $input);
            $summary = array();
            foreach ($rows as $row) {
                $summary[] = array(
                    'hook' => (string) ($row['hook'] ?? ''),
                    'execution' => (array) ($row['execution'] ?? array()),
                    'callback_count' => (int) ($row['callback_count'] ?? count((array) ($row['callbacks'] ?? array()))),
                    'callbacks_returned' => (int) ($row['callbacks_returned'] ?? count((array) ($row['callbacks'] ?? array()))),
                    'callbacks_truncated' => (bool) ($row['callbacks_truncated'] ?? false),
                    'top_cost_sources' => array_slice((array) ($row['cost_sources'] ?? array()), 0, self::SUMMARY_TOP_ITEMS),
                    'callback_timing' => is_array($row['callback_timing'] ?? null) ? array(
                        'enabled' => (bool) ($row['callback_timing']['enabled'] ?? false),
                        'profiled_callbacks' => (int) ($row['callback_timing']['profiled_callbacks'] ?? 0),
                        'skipped_callbacks' => (int) ($row['callback_timing']['skipped_callbacks'] ?? 0),
                        'skipped_reference' => (int) ($row['callback_timing']['skipped_reference'] ?? 0),
                        'skipped_unreflectable' => (int) ($row['callback_timing']['skipped_unreflectable'] ?? 0),
                        'callbacks_omitted_by_limit' => (int) ($row['callback_timing']['callbacks_omitted_by_limit'] ?? 0),
                        'executions' => (int) ($row['callback_timing']['executions'] ?? 0),
                        'inclusive_wall_ms_sum' => (float) ($row['callback_timing']['inclusive_wall_ms_sum'] ?? 0.0),
                        'setup_ms' => (float) ($row['callback_timing']['setup_ms'] ?? 0.0),
                        'restore_ms' => (float) ($row['callback_timing']['restore_ms'] ?? 0.0),
                        'bookkeeping_ms' => (float) ($row['callback_timing']['bookkeeping_ms'] ?? 0.0),
                        'registry_changed' => (bool) ($row['callback_timing']['registry_changed'] ?? false),
                        'coverage_complete' => (bool) ($row['callback_timing']['coverage_complete'] ?? false),
                        'top_callbacks' => array_slice((array) ($row['callback_timing']['items'] ?? array()), 0, self::SUMMARY_TOP_ITEMS),
                    ) : array(),
                );
            }
            return array('items' => $summary, 'total' => count($summary));
        }

        if ($section === 'http') {
            $rows = $this->filterRows((array) ($data['items'] ?? array()), $input);
            return array(
                'count' => count($rows),
                'truncated' => (bool) ($data['truncated'] ?? false),
                'top_components' => $this->componentSummary($rows),
                'slowest' => array_slice($this->sortedRows($rows, 'duration_ms'), 0, self::SUMMARY_TOP_ITEMS),
                'opportunities' => array_slice((array) ($data['opportunities'] ?? array()), 0, self::SUMMARY_TOP_ITEMS),
            );
        }

        if ($section === 'timeline') {
            $rows = $this->filterRows((array) ($data['items'] ?? array()), $input);
            $types = array();
            foreach ($rows as $row) {
                $type = (string) ($row['type'] ?? 'unknown');
                $types[$type] = (int) ($types[$type] ?? 0) + 1;
            }
            arsort($types);
            return array(
                'count' => count($rows),
                'truncated' => (bool) ($data['truncated'] ?? false),
                'by_type' => $types,
                'slowest' => array_slice($this->sortedRows($rows, 'duration_ms'), 0, self::SUMMARY_TOP_ITEMS),
            );
        }

        if (isset($data['items']) && is_array($data['items'])) {
            $rows = $this->filterRows((array) $data['items'], $input);
            return array('count' => count($rows), 'truncated' => (bool) ($data['truncated'] ?? false));
        }

        return $data;
    }

    /** @return array<string,mixed>|\WP_Error */
    private function items(string $section, array $data, array $input): array|\WP_Error
    {
        $collection = strtolower(trim((string) ($input['collection'] ?? '')));
        if ($section === 'database') {
            $collection = $collection !== '' ? $collection : 'patterns';
            $allowed = array('patterns', 'slowest', 'explains', 'by_component', 'by_caller', 'by_type', 'callback_costs', 'opportunities', 'autoload');
            if (!in_array($collection, $allowed, true)) {
                return new \WP_Error('codi_mcp_diagnostic_collection_invalid', 'The requested database collection is unavailable.');
            }
            $collectionRows = $collection === 'autoload'
                ? (array) ($data['autoload']['items'] ?? array())
                : (array) ($data[$collection] ?? array());
            $rows = $this->filterRows($collectionRows, $input);
            return $this->page($rows, $collection, $input);
        }

        if ($section === 'http') {
            $collection = $collection !== '' ? $collection : 'items';
            if (!in_array($collection, array('items', 'opportunities'), true)) {
                return new \WP_Error('codi_mcp_diagnostic_collection_invalid', 'The requested HTTP collection is unavailable.');
            }
            $rows = $collection === 'opportunities'
                ? (array) ($data['opportunities'] ?? array())
                : (array) ($data['items'] ?? array());
            return $this->page($this->filterRows($rows, $input), $collection, $input);
        }

        if ($section === 'hooks') {
            $collection = $collection !== '' ? $collection : 'items';
            if ($collection === 'cost_sources') {
                $rows = array();
                foreach ($this->filterHookRows((array) ($data['items'] ?? array()), $input) as $hookRow) {
                    foreach ((array) ($hookRow['cost_sources'] ?? array()) as $cost) {
                        if (!is_array($cost)) {
                            continue;
                        }
                        $cost['hook'] = (string) ($hookRow['hook'] ?? '');
                        $rows[] = $cost;
                    }
                }
                $rows = $this->filterRows($rows, $input);
                return $this->page($rows, 'cost_sources', $input);
            }
            if ($collection === 'callback_timings') {
                $rows = array();
                foreach ($this->filterHookRows((array) ($data['items'] ?? array()), $input) as $hookRow) {
                    foreach ((array) ($hookRow['callback_timing']['items'] ?? array()) as $timing) {
                        if (!is_array($timing)) {
                            continue;
                        }
                        $timing['hook'] = (string) ($hookRow['hook'] ?? '');
                        $rows[] = $timing;
                    }
                }
                return $this->page($this->filterRows($rows, $input), 'callback_timings', $input);
            }
            if ($collection !== 'items') {
                return new \WP_Error('codi_mcp_diagnostic_collection_invalid', 'The requested hooks collection is unavailable.');
            }
            $rows = $this->filterHookRows((array) ($data['items'] ?? array()), $input);
            if (array_key_exists('include_callbacks', $input) && !$input['include_callbacks']) {
                foreach ($rows as $index => $row) {
                    unset($row['callbacks']);
                    $rows[$index] = $row;
                }
            }
            return $this->page($rows, 'items', $input);
        }

        if (isset($data['items']) && is_array($data['items'])) {
            return $this->page($this->filterRows((array) $data['items'], $input), 'items', $input);
        }

        return new \WP_Error('codi_mcp_diagnostic_collection_invalid', 'This diagnostic section does not expose a paginated item collection.');
    }

    /** @return array<string,mixed> */
    private function topOffenders(array $capture): array
    {
        $database = is_array($capture['database'] ?? null) ? (array) $capture['database'] : array();
        $cache = is_array($capture['cache'] ?? null) ? (array) $capture['cache'] : array();
        $hooks = is_array($capture['hooks']['items'] ?? null) ? (array) $capture['hooks']['items'] : array();
        $hookRows = array();
        $sourceRows = array();
        $callbackRows = array();
        foreach ($hooks as $row) {
            if (!is_array($row)) {
                continue;
            }
            $execution = (array) ($row['execution'] ?? array());
            $hookRows[] = array(
                'hook' => (string) ($row['hook'] ?? ''),
                'queries' => (int) ($execution['queries'] ?? 0),
                'time_ms' => (float) ($execution['time_ms'] ?? 0.0),
                'http_requests' => (int) ($execution['http_requests'] ?? 0),
            );
            foreach ((array) ($row['cost_sources'] ?? array()) as $source) {
                if (!is_array($source)) {
                    continue;
                }
                $source['hook'] = (string) ($row['hook'] ?? '');
                $sourceRows[] = $source;
            }
            foreach ((array) ($row['callback_timing']['items'] ?? array()) as $callback) {
                if (!is_array($callback) || (string) ($callback['status'] ?? '') !== 'profiled') {
                    continue;
                }
                $callback['hook'] = (string) ($row['hook'] ?? '');
                $callbackRows[] = $callback;
            }
        }
        usort($hookRows, static fn (array $a, array $b): int => array((int) $b['queries'], (float) $b['time_ms']) <=> array((int) $a['queries'], (float) $a['time_ms']));
        usort($sourceRows, static fn (array $a, array $b): int => array((int) ($b['queries'] ?? 0), (float) ($b['query_time_ms'] ?? 0.0)) <=> array((int) ($a['queries'] ?? 0), (float) ($a['query_time_ms'] ?? 0.0)));
        usort($callbackRows, static fn (array $a, array $b): int => array((float) ($b['wall_ms'] ?? 0.0), (float) ($b['max_ms'] ?? 0.0)) <=> array((float) ($a['wall_ms'] ?? 0.0), (float) ($a['max_ms'] ?? 0.0)));
        return array(
            'database_components' => array_slice((array) ($database['by_component'] ?? array()), 0, 5),
            'query_patterns' => array_slice((array) ($database['patterns'] ?? array()), 0, 5),
            'slow_queries' => array_slice((array) ($database['slowest'] ?? array()), 0, 5),
            'database_opportunities' => array_slice((array) ($database['opportunities'] ?? array()), 0, 5),
            'cache_opportunities' => array_slice((array) ($cache['opportunities'] ?? array()), 0, 5),
            'hooks' => array_slice($hookRows, 0, 5),
            'hook_sources' => array_slice($sourceRows, 0, 5),
            'hook_callbacks' => array_slice($callbackRows, 0, 5),
            'correlations' => array_slice((array) ($capture['correlations']['items'] ?? array()), 0, 5),
        );
    }

    /** @param array<int,mixed> $rows @return array<int,array<string,mixed>> */
    private function filterHookRows(array $rows, array $input): array
    {
        $hook = strtolower(trim((string) ($input['hook'] ?? '')));
        $filtered = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($hook !== '' && !str_contains(strtolower((string) ($row['hook'] ?? '')), $hook)) {
                continue;
            }
            $filtered[] = $row;
        }
        return $filtered;
    }

    /** @param array<int,mixed> $rows @return array<int,array<string,mixed>> */
    private function filterRows(array $rows, array $input): array
    {
        $component = strtolower(trim((string) ($input['component'] ?? '')));
        $hook = strtolower(trim((string) ($input['hook'] ?? '')));
        $queryType = strtolower(trim((string) ($input['query_type'] ?? '')));
        $minimumMs = max(0.0, (float) ($input['minimum_ms'] ?? 0.0));
        $filtered = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $source = is_array($row['source'] ?? null) ? (array) $row['source'] : array();
            $rowComponent = strtolower((string) ($row['component'] ?? $source['component'] ?? $row['name'] ?? ''));
            if ($component !== '' && !str_contains($rowComponent, $component)) {
                continue;
            }
            $rowHook = strtolower((string) ($row['hook'] ?? $source['hook'] ?? ''));
            if ($hook !== '' && !str_contains($rowHook, $hook)) {
                continue;
            }
            if ($queryType !== '') {
                $type = strtolower((string) ($row['name'] ?? ''));
                if (isset($row['query'])) {
                    $type = strtolower((string) strtok(ltrim((string) $row['query']), " \t\r\n"));
                }
                if ($type !== $queryType) {
                    continue;
                }
            }
            $time = isset($row['time_ms'])
                ? (float) $row['time_ms']
                : (isset($row['duration_ms']) ? (float) $row['duration_ms'] : (float) ($row['wall_ms'] ?? 0.0));
            if ($minimumMs > 0.0 && $time < $minimumMs) {
                continue;
            }
            $filtered[] = $row;
        }
        $sort = strtolower(trim((string) ($input['sort'] ?? '')));
        return $sort !== '' ? $this->sortedRows($filtered, $sort) : $filtered;
    }

    /** @param array<int,array<string,mixed>> $rows @return array<string,mixed> */
    private function page(array $rows, string $collection, array $input): array
    {
        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($input['per_page'] ?? 25)));
        $total = count($rows);
        return array(
            'collection' => $collection,
            'items' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'returned' => min($perPage, max(0, $total - (($page - 1) * $perPage))),
        );
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private function sortedRows(array $rows, string $field): array
    {
        $allowed = array('count', 'time_ms', 'duration_ms', 'wall_ms', 'max_ms', 'exclusive_profiled_ms', 'executions', 'offset_ms', 'queries', 'query_time_ms', 'http_requests', 'state_changes');
        if (!in_array($field, $allowed, true)) {
            return $rows;
        }
        usort($rows, static fn (array $a, array $b): int => ((float) ($b[$field] ?? 0)) <=> ((float) ($a[$field] ?? 0)));
        return $rows;
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private function componentSummary(array $rows): array
    {
        $components = array();
        foreach ($rows as $row) {
            $source = is_array($row['source'] ?? null) ? (array) $row['source'] : array();
            $component = (string) ($source['component'] ?? $source['component_type'] ?? 'unknown');
            if (!isset($components[$component])) {
                $components[$component] = array('name' => $component, 'count' => 0, 'time_ms' => 0.0);
            }
            $components[$component]['count']++;
            $components[$component]['time_ms'] += (float) ($row['duration_ms'] ?? 0.0);
        }
        $items = array_values($components);
        usort($items, static fn (array $a, array $b): int => array((int) $b['count'], (float) $b['time_ms']) <=> array((int) $a['count'], (float) $a['time_ms']));
        foreach ($items as &$item) {
            $item['time_ms'] = round((float) $item['time_ms'], 3);
        }
        unset($item);
        return array_slice($items, 0, self::SUMMARY_TOP_ITEMS);
    }
}