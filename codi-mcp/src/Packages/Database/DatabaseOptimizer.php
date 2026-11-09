<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Database;

final class DatabaseOptimizer
{
    public const MAX_INDEX_COLUMNS = 8;
    public const MAX_OPTION_CHANGES = 50;
    private const MAX_PER_PAGE = 100;

    private ?DatabaseInspector $inspector = null;

    public function canManage(): bool
    {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            return false;
        }

        return !function_exists('is_multisite') || !is_multisite() || (function_exists('is_super_admin') && is_super_admin());
    }

    public function index(array $input)
    {
        global $wpdb;

        $action = strtolower(trim((string) ($input['action'] ?? '')));
        $table = trim((string) ($input['table'] ?? ''));
        $index = trim((string) ($input['index'] ?? ''));
        $columns = is_array($input['columns'] ?? null) ? array_values(array_map('strval', $input['columns'])) : array();

        if (!in_array($action, array('add', 'remove'), true)) {
            return new \WP_Error('codi_mcp_db_index_action', 'action must be add or remove.');
        }
        foreach (array('table' => $table, 'index' => $index) as $field => $identifier) {
            $error = $this->validateIdentifier($identifier, $field);
            if ($error instanceof \WP_Error) {
                return $error;
            }
        }
        if (strtoupper($index) === 'PRIMARY') {
            return new \WP_Error('codi_mcp_db_index_primary', 'Primary-key changes are not supported by this optimization ability.');
        }
        if ($columns === array() || count($columns) > self::MAX_INDEX_COLUMNS || count(array_unique($columns)) !== count($columns)) {
            return new \WP_Error('codi_mcp_db_index_columns', 'columns must contain 1 to 8 unique ordered column names.');
        }
        foreach ($columns as $column) {
            $error = $this->validateIdentifier($column, 'column');
            if ($error instanceof \WP_Error) {
                return $error;
            }
        }

        $schema = $this->inspector()->schema(array('table' => $table));
        if ($schema instanceof \WP_Error) {
            return $schema;
        }
        if (($schema['relationship'] ?? '') !== 'site') {
            return new \WP_Error('codi_mcp_db_index_scope', 'Index changes are limited to tables belonging to the current site.');
        }
        if ($this->isCoreSiteTable($table)) {
            return new \WP_Error('codi_mcp_db_index_core_table', 'WordPress Core table indexes are not mutation targets for this optimization ability.');
        }

        $knownColumns = array();
        foreach ((array) ($schema['columns'] ?? array()) as $column) {
            if (is_array($column)) {
                $knownColumns[(string) ($column['name'] ?? '')] = true;
            }
        }
        foreach ($columns as $column) {
            if (!isset($knownColumns[$column])) {
                return new \WP_Error('codi_mcp_db_index_column_not_found', 'One or more requested index columns do not exist on the table.');
            }
        }

        $before = $this->indexState($schema, $index);
        if ($action === 'add') {
            if ($before['exists']) {
                if ($before['primary'] || $before['unique'] || $before['columns'] !== $columns) {
                    return new \WP_Error('codi_mcp_db_index_conflict', 'An index with this name already exists with different semantics.');
                }
                return $this->indexResult($action, $table, $index, $columns, false, $before, $before);
            }
            foreach ((array) ($schema['indexes'] ?? array()) as $existing) {
                if (!is_array($existing) || !empty($existing['primary']) || !empty($existing['unique'])) {
                    continue;
                }
                if (array_values(array_map('strval', (array) ($existing['columns'] ?? array()))) === $columns) {
                    return new \WP_Error('codi_mcp_db_index_equivalent_exists', 'An equivalent non-unique index already exists on these ordered columns.');
                }
            }
        } else {
            if (!$before['exists']) {
                return $this->indexResult($action, $table, $index, $columns, false, $before, $before);
            }
            if ($before['primary'] || $before['unique']) {
                return new \WP_Error('codi_mcp_db_index_constraint', 'Primary and unique indexes are not removable through this optimization ability.');
            }
            if ($before['columns'] !== $columns) {
                return new \WP_Error('codi_mcp_db_index_conflict', 'The current index columns do not match the expected ordered columns.');
            }
        }

        $quotedTable = $this->quoteIdentifier($table);
        $quotedIndex = $this->quoteIdentifier($index);
        if ($action === 'add') {
            $quotedColumns = array_map(fn (string $column): string => $this->quoteIdentifier($column), $columns);
            $sql = 'ALTER TABLE ' . $quotedTable . ' ADD INDEX ' . $quotedIndex . ' (' . implode(', ', $quotedColumns) . ')';
        } else {
            $sql = 'ALTER TABLE ' . $quotedTable . ' DROP INDEX ' . $quotedIndex;
        }

        if (!is_object($wpdb) || !method_exists($wpdb, 'query')) {
            return new \WP_Error('codi_mcp_db_index_unavailable', 'The WordPress database driver cannot execute index changes.');
        }
        $wpdb->last_error = '';
        $result = $wpdb->query($sql);
        if ($result === false || trim((string) ($wpdb->last_error ?? '')) !== '') {
            return new \WP_Error('codi_mcp_db_index_failed', 'The database index change failed.');
        }

        $afterSchema = $this->inspector()->schema(array('table' => $table));
        if ($afterSchema instanceof \WP_Error) {
            return new \WP_Error('codi_mcp_db_index_verify_failed', 'The index change completed but the resulting schema could not be verified.');
        }
        $after = $this->indexState($afterSchema, $index);
        if ($action === 'add' && (!$after['exists'] || $after['primary'] || $after['unique'] || $after['columns'] !== $columns)) {
            return new \WP_Error('codi_mcp_db_index_verify_failed', 'The added index does not match the requested definition.');
        }
        if ($action === 'remove' && $after['exists']) {
            return new \WP_Error('codi_mcp_db_index_verify_failed', 'The removed index is still present after the database change.');
        }

        return $this->indexResult($action, $table, $index, $columns, true, $before, $after);
    }

    public function optionsAutoload(array $input): array|\WP_Error
    {
        global $wpdb;

        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results') || !method_exists($wpdb, 'get_var')) {
            return new \WP_Error('codi_mcp_options_autoload_unavailable', 'The WordPress database driver cannot inspect option autoload metadata.');
        }
        $table = (string) ($wpdb->options ?? '');
        if ($table === '') {
            return new \WP_Error('codi_mcp_options_autoload_unavailable', 'The current site options table is unavailable.');
        }

        $search = trim((string) ($input['search'] ?? ''));
        $filterAutoload = array_key_exists('autoload', $input) ? (bool) $input['autoload'] : null;
        $minBytes = max(0, (int) ($input['min_bytes'] ?? 0));
        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($input['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        $autoloadValues = $this->autoloadValues();
        $autoloadList = implode(', ', array_map(fn (string $value): string => "'" . str_replace("'", "''", $value) . "'", $autoloadValues));
        $quotedTable = $this->quoteIdentifier($table);

        $summaryRows = $wpdb->get_results(
            'SELECT COUNT(*) AS option_count, COALESCE(SUM(OCTET_LENGTH(option_value)), 0) AS option_bytes FROM '
            . $quotedTable . ' WHERE autoload IN (' . $autoloadList . ')',
            defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A'
        );
        $summary = is_array($summaryRows) && isset($summaryRows[0]) && is_array($summaryRows[0]) ? $summaryRows[0] : array();

        $where = array('OCTET_LENGTH(option_value) >= %d');
        $args = array($minBytes);
        if ($search !== '') {
            $where[] = 'option_name LIKE %s';
            $args[] = '%' . $this->escapeLike($search) . '%';
        }
        if ($filterAutoload !== null) {
            $where[] = $filterAutoload
                ? 'autoload IN (' . $autoloadList . ')'
                : 'autoload NOT IN (' . $autoloadList . ')';
        }
        $whereSql = implode(' AND ', $where);

        $countPrepared = $this->prepare('SELECT COUNT(*) FROM ' . $quotedTable . ' WHERE ' . $whereSql, $args);
        $rowsPrepared = $this->prepare(
            'SELECT option_name, autoload, OCTET_LENGTH(option_value) AS option_bytes FROM ' . $quotedTable
            . ' WHERE ' . $whereSql . ' ORDER BY option_bytes DESC, option_name ASC LIMIT %d OFFSET %d',
            array_merge($args, array($perPage, $offset))
        );
        if ($countPrepared instanceof \WP_Error || $rowsPrepared instanceof \WP_Error) {
            return new \WP_Error('codi_mcp_options_autoload_prepare_failed', 'Option autoload inspection could not be prepared safely.');
        }

        $total = (int) $wpdb->get_var($countPrepared);
        $rows = $wpdb->get_results($rowsPrepared, defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
        if (!is_array($rows) || trim((string) ($wpdb->last_error ?? '')) !== '') {
            return new \WP_Error('codi_mcp_options_autoload_failed', 'Option autoload metadata could not be read.');
        }

        $items = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $raw = (string) ($row['autoload'] ?? '');
            $items[] = array(
                'option' => (string) ($row['option_name'] ?? ''),
                'bytes' => max(0, (int) ($row['option_bytes'] ?? 0)),
                'autoload' => in_array($raw, $autoloadValues, true),
                'raw_autoload' => $raw,
            );
        }

        return array(
            'total_autoload_count' => max(0, (int) ($summary['option_count'] ?? 0)),
            'total_autoload_bytes' => max(0, (int) ($summary['option_bytes'] ?? 0)),
            'items' => $items,
            'total' => max(0, $total),
            'page' => $page,
            'per_page' => $perPage,
            'returned' => count($items),
        );
    }

    public function optionsAutoloadSet(array $input): array|\WP_Error
    {
        $changes = is_array($input['changes'] ?? null) ? array_values($input['changes']) : array();
        if ($changes === array() || count($changes) > self::MAX_OPTION_CHANGES) {
            return new \WP_Error('codi_mcp_options_autoload_changes', 'changes must contain 1 to 50 option autoload changes.');
        }
        if (!function_exists('wp_set_option_autoload_values')) {
            return new \WP_Error('codi_mcp_options_autoload_unavailable', 'This WordPress version does not provide the native option autoload mutation API.');
        }

        $requested = array();
        foreach ($changes as $change) {
            if (!is_array($change)) {
                return new \WP_Error('codi_mcp_options_autoload_change', 'Each change must be an object.');
            }
            $option = trim((string) ($change['option'] ?? ''));
            if ($option === '' || strlen($option) > 191) {
                return new \WP_Error('codi_mcp_options_autoload_option', 'Each option name must contain 1 to 191 characters.');
            }
            if (isset($requested[$option])) {
                return new \WP_Error('codi_mcp_options_autoload_duplicate', 'Each option may appear only once per request.');
            }
            if (in_array($option, array('alloptions', 'notoptions'), true)
                || str_starts_with($option, '_transient_')
                || str_starts_with($option, '_site_transient_')) {
                return new \WP_Error('codi_mcp_options_autoload_protected', 'Protected WordPress options and transients are not mutation targets for this optimization ability.');
            }
            if (!array_key_exists('autoload', $change) || !is_bool($change['autoload'])) {
                return new \WP_Error('codi_mcp_options_autoload_value', 'Each change requires a boolean autoload value.');
            }
            $requested[$option] = (bool) $change['autoload'];
        }

        $before = $this->optionAutoloadStates(array_keys($requested));
        if ($before instanceof \WP_Error) {
            return $before;
        }
        foreach (array_keys($requested) as $option) {
            if (!array_key_exists($option, $before)) {
                return new \WP_Error('codi_mcp_options_autoload_not_found', 'One or more requested options do not exist on the current site.');
            }
        }

        wp_set_option_autoload_values($requested);
        $after = $this->optionAutoloadStates(array_keys($requested));
        if ($after instanceof \WP_Error) {
            $this->restoreOptionAutoloadStates($before);
            return new \WP_Error('codi_mcp_options_autoload_verify_failed', 'The autoload change completed but its result could not be verified.');
        }

        foreach ($requested as $option => $autoload) {
            if (!array_key_exists($option, $after) || $after[$option] !== $autoload) {
                $rolledBack = $this->restoreOptionAutoloadStates($before);
                return new \WP_Error(
                    $rolledBack ? 'codi_mcp_options_autoload_failed' : 'codi_mcp_options_autoload_recovery_required',
                    $rolledBack
                        ? 'One or more autoload changes did not persist; prior autoload states were restored.'
                        : 'One or more autoload changes did not persist and prior autoload states could not be fully restored.'
                );
            }
        }

        $items = array();
        $changed = 0;
        foreach ($requested as $option => $autoload) {
            $didChange = $before[$option] !== $after[$option];
            if ($didChange) {
                $changed++;
            }
            $items[] = array(
                'option' => $option,
                'before' => $before[$option],
                'after' => $after[$option],
                'changed' => $didChange,
            );
        }
        return array('changed' => $changed, 'items' => $items);
    }

    private function inspector(): DatabaseInspector
    {
        return $this->inspector ??= new DatabaseInspector();
    }

    private function validateIdentifier(string $identifier, string $field): bool|\WP_Error
    {
        if ($identifier === '' || strlen($identifier) > 64 || preg_match('/^[A-Za-z0-9_$-]+$/', $identifier) !== 1) {
            return new \WP_Error('codi_mcp_db_index_identifier', $field . ' must be a simple database identifier of at most 64 characters.');
        }
        return true;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function isCoreSiteTable(string $table): bool
    {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'tables')) {
            return true;
        }
        return in_array($table, array_values(array_map('strval', (array) $wpdb->tables('blog', true))), true);
    }

    /** @return array{exists:bool,primary:bool,unique:bool,columns:array<int,string>} */
    private function indexState(array $schema, string $name): array
    {
        foreach ((array) ($schema['indexes'] ?? array()) as $index) {
            if (!is_array($index) || (string) ($index['name'] ?? '') !== $name) {
                continue;
            }
            return array(
                'exists' => true,
                'primary' => (bool) ($index['primary'] ?? false),
                'unique' => (bool) ($index['unique'] ?? false),
                'columns' => array_values(array_map('strval', (array) ($index['columns'] ?? array()))),
            );
        }
        return array('exists' => false, 'primary' => false, 'unique' => false, 'columns' => array());
    }

    private function indexResult(string $action, string $table, string $index, array $columns, bool $changed, array $before, array $after): array
    {
        return array(
            'action' => $action,
            'table' => $table,
            'index' => $index,
            'columns' => $columns,
            'changed' => $changed,
            'before' => $before,
            'after' => $after,
        );
    }

    /** @return string[] */
    private function autoloadValues(): array
    {
        $values = function_exists('wp_autoload_values_to_autoload')
            ? wp_autoload_values_to_autoload()
            : array('yes', 'on', 'auto-on', 'auto');
        return array_values(array_unique(array_filter(array_map('strval', (array) $values), 'strlen')));
    }

    private function escapeLike(string $value): string
    {
        global $wpdb;
        return is_object($wpdb) && method_exists($wpdb, 'esc_like') ? (string) $wpdb->esc_like($value) : addcslashes($value, '_%\\');
    }

    private function prepare(string $sql, array $args): string|\WP_Error
    {
        global $wpdb;
        if ($args === array()) {
            return $sql;
        }
        if (!is_object($wpdb) || !method_exists($wpdb, 'prepare')) {
            return new \WP_Error('codi_mcp_db_prepare_unavailable', 'The WordPress database driver cannot prepare this operation.');
        }
        $prepared = $wpdb->prepare($sql, $args);
        return is_string($prepared) && $prepared !== ''
            ? $prepared
            : new \WP_Error('codi_mcp_db_prepare_failed', 'The database operation could not be prepared.');
    }

    /** @param string[] $options @return array<string,bool>|\WP_Error */
    private function optionAutoloadStates(array $options): array|\WP_Error
    {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            return new \WP_Error('codi_mcp_options_autoload_unavailable', 'The WordPress database driver cannot inspect option autoload states.');
        }
        $table = (string) ($wpdb->options ?? '');
        if ($table === '') {
            return new \WP_Error('codi_mcp_options_autoload_unavailable', 'The current site options table is unavailable.');
        }
        $placeholders = implode(', ', array_fill(0, count($options), '%s'));
        $prepared = $this->prepare(
            'SELECT option_name, autoload FROM ' . $this->quoteIdentifier($table) . ' WHERE option_name IN (' . $placeholders . ')',
            $options
        );
        if ($prepared instanceof \WP_Error) {
            return $prepared;
        }
        $rows = $wpdb->get_results($prepared, defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
        if (!is_array($rows) || trim((string) ($wpdb->last_error ?? '')) !== '') {
            return new \WP_Error('codi_mcp_options_autoload_failed', 'Option autoload states could not be read.');
        }
        $autoloadValues = $this->autoloadValues();
        $states = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = (string) ($row['option_name'] ?? '');
            if ($name !== '') {
                $states[$name] = in_array((string) ($row['autoload'] ?? ''), $autoloadValues, true);
            }
        }
        return $states;
    }

    /** @param array<string,bool> $states */
    private function restoreOptionAutoloadStates(array $states): bool
    {
        if (!function_exists('wp_set_option_autoload_values')) {
            return false;
        }
        wp_set_option_autoload_values($states);
        $verified = $this->optionAutoloadStates(array_keys($states));
        if ($verified instanceof \WP_Error) {
            return false;
        }
        foreach ($states as $option => $autoload) {
            if (!array_key_exists($option, $verified) || $verified[$option] !== $autoload) {
                return false;
            }
        }
        return true;
    }
}
