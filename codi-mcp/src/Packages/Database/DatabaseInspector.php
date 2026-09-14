<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Database;

final class DatabaseInspector
{
    private const MAX_PER_PAGE = 100;

    private ?QueryPolicy $queryPolicy = null;

    public function canInspect(): bool
    {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            return false;
        }

        return !function_exists('is_multisite') || !is_multisite() || (function_exists('is_super_admin') && is_super_admin());
    }

    public function tables(array $input = array())
    {
        global $wpdb;

        $statusRows = $wpdb->get_results('SHOW TABLE STATUS', ARRAY_A);
        if (!is_array($statusRows)) {
            return new \WP_Error('codi_mcp_db_tables_failed', 'Database table metadata could not be read.');
        }

        $search = strtolower(trim((string) ($input['search'] ?? '')));
        $relationship = (string) ($input['relationship'] ?? 'all');
        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($input['per_page'] ?? 50)));
        $shared = $this->sharedTables();

        $rows = array();
        foreach ($statusRows as $status) {
            $name = (string) ($status['Name'] ?? '');
            if ($name === '') {
                continue;
            }
            $relation = $this->relationship($name, $shared);
            if ($relationship !== 'all' && $relationship !== $relation) {
                continue;
            }
            if ($search !== '' && strpos(strtolower($name), $search) === false) {
                continue;
            }

            $dataLength = max(0, (int) ($status['Data_length'] ?? 0));
            $indexLength = max(0, (int) ($status['Index_length'] ?? 0));
            $rows[] = array(
                'name' => $name,
                'relationship' => $relation,
                'engine' => (string) ($status['Engine'] ?? ''),
                'approx_rows' => max(0, (int) ($status['Rows'] ?? 0)),
                'data_bytes' => $dataLength,
                'index_bytes' => $indexLength,
                'total_bytes' => $dataLength + $indexLength,
                'collation' => (string) ($status['Collation'] ?? ''),
            );
        }

        usort($rows, static fn (array $left, array $right): int => strnatcasecmp($left['name'], $right['name']));
        $total = count($rows);
        $items = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return array(
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'returned' => count($items),
        );
    }

    public function schema(array $input = array())
    {
        global $wpdb;

        $table = trim((string) ($input['table'] ?? ''));
        if ($table === '') {
            return new \WP_Error('codi_mcp_db_table_required', 'table is required.');
        }

        $names = $this->tableNames();
        if (is_wp_error($names)) {
            return $names;
        }
        if (!in_array($table, $names, true)) {
            return new \WP_Error('codi_mcp_db_table_not_found', 'Database table was not found.');
        }

        $quoted = '`' . str_replace('`', '``', $table) . '`';
        $columnsRaw = $wpdb->get_results('SHOW FULL COLUMNS FROM ' . $quoted, ARRAY_A);
        if (!is_array($columnsRaw)) {
            return new \WP_Error('codi_mcp_db_schema_failed', 'Database column metadata could not be read.');
        }
        $indexesRaw = $wpdb->get_results('SHOW INDEX FROM ' . $quoted, ARRAY_A);
        if (!is_array($indexesRaw)) {
            return new \WP_Error('codi_mcp_db_indexes_failed', 'Database index metadata could not be read.');
        }

        $columns = array();
        foreach ($columnsRaw as $column) {
            $hasDefault = array_key_exists('Default', $column) && $column['Default'] !== null;
            $columns[] = array(
                'name' => (string) ($column['Field'] ?? ''),
                'type' => (string) ($column['Type'] ?? ''),
                'nullable' => strtoupper((string) ($column['Null'] ?? '')) === 'YES',
                'key' => (string) ($column['Key'] ?? ''),
                'has_default' => $hasDefault,
                'default' => $hasDefault ? (string) $column['Default'] : '',
                'extra' => (string) ($column['Extra'] ?? ''),
                'collation' => (string) ($column['Collation'] ?? ''),
                'comment' => substr((string) ($column['Comment'] ?? ''), 0, 1000),
            );
        }

        $indexMap = array();
        foreach ($indexesRaw as $index) {
            $name = (string) ($index['Key_name'] ?? '');
            if ($name === '') {
                continue;
            }
            if (!isset($indexMap[$name])) {
                $indexMap[$name] = array(
                    'name' => $name,
                    'primary' => strtoupper($name) === 'PRIMARY',
                    'unique' => (int) ($index['Non_unique'] ?? 1) === 0,
                    'type' => (string) ($index['Index_type'] ?? ''),
                    'columns' => array(),
                );
            }
            $columnName = (string) ($index['Column_name'] ?? '');
            if ($columnName !== '') {
                $indexMap[$name]['columns'][(int) ($index['Seq_in_index'] ?? 0)] = $columnName;
            }
        }

        $indexes = array();
        $primaryKeys = array();
        foreach ($indexMap as $index) {
            ksort($index['columns']);
            $index['columns'] = array_values($index['columns']);
            if ($index['primary']) {
                $primaryKeys = $index['columns'];
            }
            $indexes[] = $index;
        }

        return array(
            'table' => $table,
            'relationship' => $this->relationship($table, $this->sharedTables()),
            'columns' => $columns,
            'indexes' => $indexes,
            'primary_keys' => $primaryKeys,
        );
    }

    public function query(array $input = array())
    {
        global $wpdb;

        $sql = trim((string) ($input['sql'] ?? ''));
        $params = is_array($input['params'] ?? null) ? array_values($input['params']) : array();
        $maxRows = min(QueryPolicy::MAX_ROWS, max(1, (int) ($input['max_rows'] ?? 100)));

        $validation = $this->queryPolicy()->validate($sql, $params);
        if (is_wp_error($validation)) {
            return $validation;
        }

        if (in_array('i', $validation['placeholder_types'], true)
            && method_exists($wpdb, 'has_cap')
            && !$wpdb->has_cap('identifier_placeholders')) {
            return new \WP_Error('codi_mcp_db_query_identifier_unsupported', 'This WordPress database driver does not support %i identifier placeholders.');
        }

        $tableNames = $this->tableNames();
        if (is_wp_error($tableNames)) {
            return $tableNames;
        }
        $scope = $this->queryPolicy()->validateTableScope(
            $validation['sql'],
            $params,
            $validation['placeholder_types'],
            $tableNames,
            $this->sharedTables(),
            (string) $wpdb->prefix,
            (string) $wpdb->base_prefix
        );
        if (is_wp_error($scope)) {
            return $scope;
        }

        $prepared = $validation['sql'];
        if ($validation['placeholder_types'] !== array() || strpos($prepared, '%%') !== false) {
            $prepared = $wpdb->prepare($prepared, $params);
            if (!is_string($prepared) || $prepared === '') {
                return new \WP_Error('codi_mcp_db_query_prepare_failed', 'The database query could not be prepared.');
            }
        }

        $executionSql = $prepared;
        if ($validation['statement'] === 'select') {
            $executionSql = 'SELECT * FROM (' . $prepared . ') AS codi_mcp_read LIMIT ' . (string) ($maxRows + 1);
        }

        $rows = $wpdb->get_results($executionSql, ARRAY_A);
        if (!is_array($rows) || trim((string) ($wpdb->last_error ?? '')) !== '') {
            return new \WP_Error('codi_mcp_db_query_failed', 'The read-only database query failed.');
        }

        $rowOverflow = count($rows) > $maxRows;
        if ($rowOverflow) {
            $rows = array_slice($rows, 0, $maxRows);
        }

        $sanitized = $this->queryPolicy()->sanitizeRows($rows);
        $boundedRows = array();
        $responseBytes = 0;
        $byteOverflow = false;
        foreach ($sanitized['rows'] as $row) {
            $encoded = function_exists('wp_json_encode') ? wp_json_encode($row) : json_encode($row);
            $rowBytes = is_string($encoded) ? strlen($encoded) : 0;
            if ($responseBytes + $rowBytes > QueryPolicy::MAX_RESPONSE_BYTES) {
                $byteOverflow = true;
                break;
            }
            $boundedRows[] = $row;
            $responseBytes += $rowBytes;
        }

        $columns = $boundedRows !== array() ? array_keys($boundedRows[0]) : array();

        return array(
            'statement' => (string) $validation['statement'],
            'columns' => array_values(array_map('strval', $columns)),
            'rows' => $boundedRows,
            'returned' => count($boundedRows),
            'truncated' => $rowOverflow || $byteOverflow,
            'response_bytes' => $responseBytes,
            'redacted_fields' => (int) $sanitized['redacted_fields'],
            'truncated_fields' => (int) $sanitized['truncated_fields'],
        );
    }

    private function queryPolicy(): QueryPolicy
    {
        return $this->queryPolicy ??= new QueryPolicy();
    }

    /** @return string[]|\WP_Error */
    private function tableNames()
    {
        global $wpdb;
        $names = $wpdb->get_col('SHOW TABLES');
        if (!is_array($names)) {
            return new \WP_Error('codi_mcp_db_tables_failed', 'Database table names could not be read.');
        }
        return array_values(array_map('strval', $names));
    }

    /** @return array<string,bool> */
    private function sharedTables(): array
    {
        global $wpdb;
        $tables = array();
        foreach (array('global', 'ms_global') as $scope) {
            foreach ((array) $wpdb->tables($scope, true) as $table) {
                $tables[(string) $table] = true;
            }
        }
        return $tables;
    }

    private function relationship(string $table, array $shared): string
    {
        global $wpdb;
        if (isset($shared[$table])) {
            return 'shared';
        }
        $prefix = (string) $wpdb->prefix;
        $basePrefix = (string) $wpdb->base_prefix;
        if ($prefix === $basePrefix && $basePrefix !== '' && preg_match('/^' . preg_quote($basePrefix, '/') . '[1-9][0-9]*_/', $table)) {
            return 'wordpress-other';
        }
        if ($prefix !== '' && strpos($table, $prefix) === 0) {
            return 'site';
        }
        if ($basePrefix !== '' && strpos($table, $basePrefix) === 0) {
            return 'wordpress-other';
        }
        return 'other';
    }
}
