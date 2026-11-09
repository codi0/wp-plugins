<?php

declare(strict_types=1);

namespace CodiMcpTest\Unit;

use CodiMcp\Packages\Database\DatabaseOptimizer;
use CodiMcpTest\Framework\TestCase;

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

final class DatabaseOptimizationTest extends TestCase
{
    public function test_index_mutation_is_ordered_bounded_and_idempotent(): void
    {
        global $wpdb;
        $previous = $wpdb ?? null;
        $wpdb = $this->database();

        try {
            $optimizer = new DatabaseOptimizer();
            $added = $optimizer->index(array(
                'action' => 'add',
                'table' => 'wp_6_bhi_organisations_users',
                'index' => 'user_org_lookup',
                'columns' => array('user_id', 'organisation_id'),
            ));

            $this->assertFalse($added instanceof \WP_Error);
            $this->assertTrue((bool) $added['changed']);
            $this->assertSame(array('user_id', 'organisation_id'), $added['after']['columns']);

            $repeated = $optimizer->index(array(
                'action' => 'add',
                'table' => 'wp_6_bhi_organisations_users',
                'index' => 'user_org_lookup',
                'columns' => array('user_id', 'organisation_id'),
            ));
            $this->assertFalse($repeated instanceof \WP_Error);
            $this->assertFalse((bool) $repeated['changed']);

            $wrongOrder = $optimizer->index(array(
                'action' => 'remove',
                'table' => 'wp_6_bhi_organisations_users',
                'index' => 'user_org_lookup',
                'columns' => array('organisation_id', 'user_id'),
            ));
            $this->assertTrue($wrongOrder instanceof \WP_Error);
            $this->assertSame('codi_mcp_db_index_conflict', $wrongOrder->get_error_code());

            $removed = $optimizer->index(array(
                'action' => 'remove',
                'table' => 'wp_6_bhi_organisations_users',
                'index' => 'user_org_lookup',
                'columns' => array('user_id', 'organisation_id'),
            ));
            $this->assertFalse($removed instanceof \WP_Error);
            $this->assertTrue((bool) $removed['changed']);
            $this->assertFalse((bool) $removed['after']['exists']);
        } finally {
            $wpdb = $previous;
        }
    }

    public function test_index_mutation_refuses_core_and_constraint_indexes(): void
    {
        global $wpdb;
        $previous = $wpdb ?? null;
        $wpdb = $this->database();

        try {
            $optimizer = new DatabaseOptimizer();

            $core = $optimizer->index(array(
                'action' => 'add',
                'table' => 'wp_6_posts',
                'index' => 'post_status_extra',
                'columns' => array('post_status'),
            ));
            $this->assertTrue($core instanceof \WP_Error);
            $this->assertSame('codi_mcp_db_index_core_table', $core->get_error_code());

            $unique = $optimizer->index(array(
                'action' => 'remove',
                'table' => 'wp_6_bhi_organisations_users',
                'index' => 'uniq_user',
                'columns' => array('user_id'),
            ));
            $this->assertTrue($unique instanceof \WP_Error);
            $this->assertSame('codi_mcp_db_index_constraint', $unique->get_error_code());
        } finally {
            $wpdb = $previous;
        }
    }

    public function test_option_autoload_inspection_never_returns_values_and_native_mutation_verifies_state(): void
    {
        global $wpdb;
        $previous = $wpdb ?? null;
        $wpdb = $this->database();

        try {
            $optimizer = new DatabaseOptimizer();
            $inspection = $optimizer->optionsAutoload(array(
                'autoload' => true,
                'min_bytes' => 1000,
                'page' => 1,
                'per_page' => 10,
            ));

            $this->assertFalse($inspection instanceof \WP_Error);
            $this->assertSame('large_unused', $inspection['items'][0]['option']);
            $encoded = json_encode($inspection);
            $this->assertFalse(str_contains((string) $encoded, 'must-not-leak'));

            $beforeValue = $wpdb->optionRows['large_unused']['value'];
            $changed = $optimizer->optionsAutoloadSet(array(
                'changes' => array(
                    array('option' => 'large_unused', 'autoload' => false),
                ),
            ));
            $this->assertFalse($changed instanceof \WP_Error);
            $this->assertSame(1, (int) $changed['changed']);
            $this->assertFalse((bool) $changed['items'][0]['after']);
            $this->assertSame($beforeValue, $wpdb->optionRows['large_unused']['value']);
        } finally {
            $wpdb = $previous;
        }
    }

    public function test_option_autoload_bulk_failure_restores_prior_states(): void
    {
        global $wpdb;
        $previous = $wpdb ?? null;
        $wpdb = $this->database();
        $wpdb->failAutoloadFor = array('second_option');

        try {
            $before = array(
                'large_unused' => $wpdb->optionRows['large_unused']['autoload'],
                'second_option' => $wpdb->optionRows['second_option']['autoload'],
            );

            $result = (new DatabaseOptimizer())->optionsAutoloadSet(array(
                'changes' => array(
                    array('option' => 'large_unused', 'autoload' => false),
                    array('option' => 'second_option', 'autoload' => true),
                ),
            ));

            $this->assertTrue($result instanceof \WP_Error);
            $this->assertSame('codi_mcp_options_autoload_failed', $result->get_error_code());
            $this->assertSame($before['large_unused'], $wpdb->optionRows['large_unused']['autoload']);
            $this->assertSame($before['second_option'], $wpdb->optionRows['second_option']['autoload']);
        } finally {
            $wpdb = $previous;
        }
    }

    private function database(): object
    {
        return new class {
            public string $prefix = 'wp_6_';
            public string $base_prefix = 'wp_';
            public string $options = 'wp_6_options';
            public string $last_error = '';
            public string $last_query = '';
            public array $failAutoloadFor = array();
            public array $optionRows = array(
                'large_unused' => array('value' => 'must-not-leak-' . 'x', 'bytes' => 40000, 'autoload' => 'on'),
                'second_option' => array('value' => 'second-value', 'bytes' => 2000, 'autoload' => 'off'),
                'tiny_option' => array('value' => 'tiny', 'bytes' => 4, 'autoload' => 'on'),
            );
            public array $indexes = array(
                'wp_6_bhi_organisations_users' => array(
                    'uniq_user' => array('unique' => true, 'columns' => array('user_id')),
                ),
            );

            public function tables(string $scope, bool $prefix = true): array
            {
                if ($scope === 'blog') {
                    return array('wp_6_posts', 'wp_6_postmeta', 'wp_6_options', 'wp_6_terms', 'wp_6_term_taxonomy', 'wp_6_term_relationships', 'wp_6_comments', 'wp_6_commentmeta');
                }
                if ($scope === 'global') {
                    return array('wp_users', 'wp_usermeta');
                }
                return array();
            }

            public function get_col(string $sql): array
            {
                if (str_starts_with($sql, 'SHOW TABLES')) {
                    return array('wp_6_posts', 'wp_6_options', 'wp_6_bhi_organisations_users', 'wp_users');
                }
                return array();
            }

            public function get_results(string $sql, $format): array
            {
                $this->last_query = $sql;
                $this->last_error = '';

                if (preg_match('/^SHOW FULL COLUMNS FROM `([^`]+)`/', $sql, $matches)) {
                    $table = $matches[1];
                    if ($table === 'wp_6_bhi_organisations_users') {
                        return array(
                            $this->column('id', 'bigint unsigned', 'PRI'),
                            $this->column('user_id', 'bigint unsigned'),
                            $this->column('organisation_id', 'bigint unsigned'),
                            $this->column('status', 'varchar(32)'),
                        );
                    }
                    if ($table === 'wp_6_posts') {
                        return array($this->column('ID', 'bigint unsigned', 'PRI'), $this->column('post_status', 'varchar(20)'));
                    }
                    if ($table === 'wp_6_options') {
                        return array($this->column('option_name', 'varchar(191)', 'UNI'), $this->column('option_value', 'longtext'), $this->column('autoload', 'varchar(20)'));
                    }
                    return array();
                }

                if (preg_match('/^SHOW INDEX FROM `([^`]+)`/', $sql, $matches)) {
                    $table = $matches[1];
                    $rows = array();
                    foreach ((array) ($this->indexes[$table] ?? array()) as $name => $index) {
                        foreach ($index['columns'] as $offset => $column) {
                            $rows[] = array(
                                'Key_name' => $name,
                                'Non_unique' => !empty($index['unique']) ? 0 : 1,
                                'Seq_in_index' => $offset + 1,
                                'Column_name' => $column,
                                'Index_type' => 'BTREE',
                            );
                        }
                    }
                    return $rows;
                }

                if (str_contains($sql, 'COUNT(*) AS option_count')) {
                    $count = 0;
                    $bytes = 0;
                    foreach ($this->optionRows as $row) {
                        if ($this->autoloaded($row['autoload'])) {
                            $count++;
                            $bytes += (int) $row['bytes'];
                        }
                    }
                    return array(array('option_count' => $count, 'option_bytes' => $bytes));
                }

                if (str_contains($sql, 'SELECT option_name, autoload FROM')) {
                    $rows = array();
                    foreach ($this->optionRows as $name => $row) {
                        if (str_contains($sql, "'" . addslashes($name) . "'")) {
                            $rows[] = array('option_name' => $name, 'autoload' => $row['autoload']);
                        }
                    }
                    return $rows;
                }

                if (str_contains($sql, 'OCTET_LENGTH(option_value) AS option_bytes')) {
                    return $this->filteredOptionRows($sql);
                }

                return array();
            }

            public function get_var(string $sql)
            {
                return count($this->filteredOptionRows($sql));
            }

            public function prepare(string $query, array $args): string
            {
                $index = 0;
                return (string) preg_replace_callback('/%%|%[sd]/i', function (array $match) use ($args, &$index): string {
                    if ($match[0] === '%%') {
                        return '%';
                    }
                    $value = $args[$index++] ?? null;
                    return strtolower(substr($match[0], -1)) === 'd'
                        ? (string) (int) $value
                        : "'" . addslashes((string) $value) . "'";
                }, $query);
            }

            public function esc_like(string $value): string
            {
                return addcslashes($value, '_%\\');
            }

            public function query(string $sql)
            {
                $this->last_query = $sql;
                $this->last_error = '';
                if (preg_match('/^ALTER TABLE `([^`]+)` ADD INDEX `([^`]+)` \((.+)\)$/', $sql, $matches)) {
                    preg_match_all('/`([^`]+)`/', $matches[3], $columns);
                    $this->indexes[$matches[1]][$matches[2]] = array('unique' => false, 'columns' => array_values($columns[1]));
                    return 0;
                }
                if (preg_match('/^ALTER TABLE `([^`]+)` DROP INDEX `([^`]+)`$/', $sql, $matches)) {
                    unset($this->indexes[$matches[1]][$matches[2]]);
                    return 0;
                }
                $this->last_error = 'Unexpected query';
                return false;
            }

            public function set_option_autoload_values(array $options): array
            {
                $results = array();
                foreach ($options as $option => $autoload) {
                    if (!isset($this->optionRows[$option]) || in_array($option, $this->failAutoloadFor, true)) {
                        $results[$option] = false;
                        continue;
                    }
                    $desired = $autoload ? 'on' : 'off';
                    $results[$option] = $this->optionRows[$option]['autoload'] !== $desired;
                    $this->optionRows[$option]['autoload'] = $desired;
                }
                return $results;
            }

            private function column(string $field, string $type, string $key = ''): array
            {
                return array(
                    'Field' => $field,
                    'Type' => $type,
                    'Null' => 'NO',
                    'Key' => $key,
                    'Default' => null,
                    'Extra' => '',
                    'Collation' => '',
                    'Comment' => '',
                );
            }

            private function autoloaded(string $raw): bool
            {
                return in_array($raw, array('yes', 'on', 'auto-on', 'auto'), true);
            }

            private function filteredOptionRows(string $sql): array
            {
                $minBytes = preg_match('/OCTET_LENGTH\(option_value\) >= ([0-9]+)/', $sql, $matches) ? (int) $matches[1] : 0;
                $autoloadOnly = str_contains($sql, 'autoload IN (');
                $nonAutoloadOnly = str_contains($sql, 'autoload NOT IN (');
                $rows = array();
                foreach ($this->optionRows as $name => $row) {
                    if ((int) $row['bytes'] < $minBytes) {
                        continue;
                    }
                    $isAutoloaded = $this->autoloaded($row['autoload']);
                    if ($autoloadOnly && !$isAutoloaded) {
                        continue;
                    }
                    if ($nonAutoloadOnly && $isAutoloaded) {
                        continue;
                    }
                    $rows[] = array(
                        'option_name' => $name,
                        'autoload' => $row['autoload'],
                        'option_bytes' => (int) $row['bytes'],
                    );
                }
                usort($rows, static fn (array $a, array $b): int => array($b['option_bytes'], $a['option_name']) <=> array($a['option_bytes'], $b['option_name']));
                if (preg_match('/LIMIT ([0-9]+) OFFSET ([0-9]+)/', $sql, $matches)) {
                    $rows = array_slice($rows, (int) $matches[2], (int) $matches[1]);
                }
                return $rows;
            }
        };
    }
}
