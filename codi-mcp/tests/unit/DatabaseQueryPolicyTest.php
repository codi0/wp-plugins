<?php

declare(strict_types=1);

namespace CodiMcpTest\Unit;

use CodiMcp\Packages\Database\QueryPolicy;
use CodiMcp\Packages\Database\DatabaseInspector;
use CodiMcpTest\Framework\TestCase;

final class DatabaseQueryPolicyTest extends TestCase
{
    public function test_accepts_parameterized_select_and_explain(): void
    {
        $policy = new QueryPolicy();

        $select = $policy->validate(
            'SELECT ID, post_title FROM %i WHERE post_status = %s AND ID > %d ORDER BY ID DESC LIMIT %d',
            array('wp_posts', 'publish', 10, 25)
        );
        $this->assertFalse(is_wp_error($select));
        $this->assertSame('select', (string) ($select['statement'] ?? ''));
        $this->assertSame(array('i', 's', 'd', 'd'), $select['placeholder_types']);

        $functions = $policy->validate(
            'SELECT COUNT(*) AS total, REPLACE(post_title, %s, %s) AS normalized FROM %i GROUP BY post_type LIMIT %d',
            array('old', 'new', 'wp_posts', 25)
        );
        $this->assertFalse(is_wp_error($functions));

        $explain = $policy->validate(
            'EXPLAIN SELECT * FROM %i WHERE ID = %d',
            array('wp_posts', 42)
        );
        $this->assertFalse(is_wp_error($explain));
        $this->assertSame('explain', (string) ($explain['statement'] ?? ''));
    }

    public function test_rejects_embedded_values_and_side_effecting_sql(): void
    {
        $policy = new QueryPolicy();
        $cases = array(
            array('SELECT * FROM wp_posts WHERE post_status = \'publish\'', array(), 'codi_mcp_db_query_literal'),
            array('SELECT * FROM wp_posts WHERE ID = 7', array(), 'codi_mcp_db_query_numeric_literal'),
            array('DELETE FROM wp_posts WHERE ID = %d', array(7), 'codi_mcp_db_query_statement'),
            array('SELECT * FROM wp_posts -- hidden', array(), 'codi_mcp_db_query_comments'),
            array('SELECT * FROM wp_posts WHERE ID = %d', array(), 'codi_mcp_db_query_param_count'),
            array('SELECT SLEEP(%d)', array(1), 'codi_mcp_db_query_dangerous_function'),
            array('SELECT custom_side_effect()', array(), 'codi_mcp_db_query_function_not_allowed'),
            array('SELECT * FROM information_schema.tables', array(), 'codi_mcp_db_query_system_schema'),
            array('SELECT user_pass FROM wp_users', array(), 'codi_mcp_db_query_sensitive_identifier'),
            array('SELECT * FROM wp_posts FOR UPDATE', array(), 'codi_mcp_db_query_disallowed_keyword'),
            array('SELECT * FROM wp_posts; SELECT * FROM wp_options', array(), 'codi_mcp_db_query_multiple_statements'),
        );

        foreach ($cases as [$sql, $params, $expectedCode]) {
            $result = $policy->validate($sql, $params);
            $this->assertTrue(is_wp_error($result), $sql . ' should be rejected.');
            $this->assertSame($expectedCode, $result->code, $sql . ' rejection code mismatch.');
        }
    }

    public function test_table_scope_is_current_site_and_shared_only(): void
    {
        $policy = new QueryPolicy();
        $tables = array('wp_posts', 'wp_options', 'wp_users', 'wp_2_posts', 'custom_table');
        $shared = array('wp_users' => true);

        $validation = $policy->validate('SELECT * FROM %i', array('wp_posts'));
        $this->assertFalse(is_wp_error($validation));
        $this->assertTrue(true === $policy->validateTableScope(
            $validation['sql'],
            array('wp_posts'),
            $validation['placeholder_types'],
            $tables,
            $shared,
            'wp_',
            'wp_'
        ));

        $sharedValidation = $policy->validate('SELECT * FROM %i', array('wp_users'));
        $this->assertFalse(is_wp_error($sharedValidation));
        $this->assertTrue(true === $policy->validateTableScope(
            $sharedValidation['sql'],
            array('wp_users'),
            $sharedValidation['placeholder_types'],
            $tables,
            $shared,
            'wp_',
            'wp_'
        ));

        $rawTable = $policy->validate('SELECT * FROM wp_posts', array());
        $this->assertFalse(is_wp_error($rawTable));
        $rawScope = $policy->validateTableScope(
            $rawTable['sql'],
            array(),
            $rawTable['placeholder_types'],
            $tables,
            $shared,
            'wp_',
            'wp_'
        );
        $this->assertTrue(is_wp_error($rawScope));
        $this->assertSame('codi_mcp_db_query_table_parameter', $rawScope->code);

        $commaJoin = $policy->validate('SELECT p.ID, o.option_id FROM %i p, %i o WHERE p.ID = o.option_id', array('wp_posts', 'wp_options'));
        $this->assertFalse(is_wp_error($commaJoin));
        $commaScope = $policy->validateTableScope(
            $commaJoin['sql'],
            array('wp_posts', 'wp_options'),
            $commaJoin['placeholder_types'],
            $tables,
            $shared,
            'wp_',
            'wp_'
        );
        $this->assertTrue(is_wp_error($commaScope));
        $this->assertSame('codi_mcp_db_query_comma_join', $commaScope->code);

        foreach (array('wp_2_posts', 'custom_table') as $table) {
            $blockedValidation = $policy->validate('SELECT * FROM %i', array($table));
            $this->assertFalse(is_wp_error($blockedValidation));
            $scope = $policy->validateTableScope(
                $blockedValidation['sql'],
                array($table),
                $blockedValidation['placeholder_types'],
                $tables,
                $shared,
                'wp_',
                'wp_'
            );
            $this->assertTrue(is_wp_error($scope), $table . ' should be outside query scope.');
            $this->assertSame('codi_mcp_db_query_table_scope', $scope->code);
        }
    }

    public function test_ast_validates_nested_table_sources_and_rejects_raw_nested_tables(): void
    {
        $policy = new QueryPolicy();
        $tables = array('wp_posts', 'wp_options');

        $nested = $policy->validate('SELECT ID FROM (SELECT ID FROM %i WHERE ID > %d) p', array('wp_posts', 10));
        $this->assertFalse(is_wp_error($nested));
        $this->assertTrue(true === $policy->validateTableScope(
            $nested['sql'],
            array('wp_posts', 10),
            $nested['placeholder_types'],
            $tables,
            array(),
            'wp_',
            'wp_'
        ));

        $rawNested = $policy->validate('SELECT ID FROM (SELECT ID FROM wp_posts) p', array());
        $this->assertFalse(is_wp_error($rawNested));
        $scope = $policy->validateTableScope($rawNested['sql'], array(), $rawNested['placeholder_types'], $tables, array(), 'wp_', 'wp_');
        $this->assertTrue(is_wp_error($scope));
        $this->assertSame('codi_mcp_db_query_table_parameter', $scope->code);

        $wildcardJoin = $policy->validate('SELECT * FROM %i p JOIN %i o ON o.option_id = p.ID', array('wp_posts', 'wp_options'));
        $this->assertTrue(is_wp_error($wildcardJoin));
        $this->assertSame('codi_mcp_db_query_wildcard_join', $wildcardJoin->code);
    }

    public function test_sensitive_key_value_columns_must_remain_redactable(): void
    {
        $policy = new QueryPolicy();

        $safe = $policy->validate(
            'SELECT option_name, option_value FROM %i WHERE autoload = %s',
            array('wp_options', 'yes')
        );
        $this->assertFalse(is_wp_error($safe));

        foreach (array(
            'SELECT option_value FROM %i',
            'SELECT option_name, option_value AS value FROM %i',
            'SELECT option_name, HEX(option_value) FROM %i',
            'SELECT meta_value FROM %i',
            'SELECT meta_key, meta_value AS value FROM %i',
        ) as $sql) {
            $result = $policy->validate($sql, array('wp_options'));
            $this->assertTrue(is_wp_error($result), $sql . ' should preserve the key/value redaction contract.');
            $this->assertSame('codi_mcp_db_query_sensitive_projection', $result->code);
        }
    }

    public function test_inspector_prepares_bounds_and_redacts_query_results(): void
    {
        if (!defined('ARRAY_A')) {
            define('ARRAY_A', 'ARRAY_A');
        }

        global $wpdb;
        $previous = $wpdb ?? null;
        $wpdb = new class {
            public string $prefix = 'wp_';
            public string $base_prefix = 'wp_';
            public string $last_error = '';
            public string $last_query = '';

            public function has_cap(string $capability): bool
            {
                return $capability === 'identifier_placeholders';
            }

            public function tables(string $scope, bool $prefix = true): array
            {
                return $scope === 'global' ? array('wp_users', 'wp_usermeta') : array('wp_blogs');
            }

            public function get_col(string $sql): array
            {
                return array('wp_posts', 'wp_options', 'wp_users', 'wp_usermeta', 'wp_blogs', 'wp_2_posts');
            }

            public function prepare(string $query, array $args): string
            {
                $index = 0;
                return (string) preg_replace_callback('/%%|%[sdfi]/i', function (array $match) use ($args, &$index): string {
                    if ($match[0] === '%%') {
                        return '%';
                    }
                    $value = $args[$index++] ?? null;
                    return match (strtolower(substr($match[0], -1))) {
                        'i' => '`' . str_replace('`', '``', (string) $value) . '`',
                        'd' => (string) (int) $value,
                        'f' => (string) (float) $value,
                        default => "'" . addslashes((string) $value) . "'",
                    };
                }, $query);
            }

            public function get_results(string $sql, $format): array
            {
                $this->last_query = $sql;
                $this->last_error = '';
                return array(
                    array('option_name' => 'api_key', 'option_value' => 'must-not-leak'),
                    array('option_name' => 'siteurl', 'option_value' => 'https://example.test'),
                );
            }
        };

        try {
            $result = (new DatabaseInspector())->query(array(
                'sql' => 'SELECT option_name, option_value FROM %i WHERE autoload = %s ORDER BY option_name',
                'params' => array('wp_options', 'yes'),
                'max_rows' => 1,
            ));

            $this->assertFalse(is_wp_error($result));
            $this->assertSame(1, $result['returned']);
            $this->assertTrue((bool) $result['truncated']);
            $this->assertSame('[REDACTED]', $result['rows'][0]['option_value']);
            $this->assertTrue(strpos($wpdb->last_query, 'LIMIT 2') !== false, 'Server-side hard row limit was not applied.');
        } finally {
            $wpdb = $previous;
        }
    }

    public function test_redacts_credential_fields_and_sensitive_key_value_rows(): void
    {
        $policy = new QueryPolicy();
        $result = $policy->sanitizeRows(array(
            array('option_name' => 'api_key', 'option_value' => 'top-secret-value', 'autoload' => 'yes'),
            array('user_pass' => '$P$B123456789012345678901234567890', 'display_name' => 'Alice'),
            array('access_token' => 'abc123', 'status' => 'active'),
            array('meta_key' => 'ordinary_setting', 'meta_value' => 'ordinary-value'),
        ));

        $this->assertSame('[REDACTED]', $result['rows'][0]['option_value']);
        $this->assertSame('yes', $result['rows'][0]['autoload']);
        $this->assertSame('[REDACTED]', $result['rows'][1]['user_pass']);
        $this->assertSame('[REDACTED]', $result['rows'][2]['access_token']);
        $this->assertSame('ordinary-value', $result['rows'][3]['meta_value']);
        $this->assertTrue($result['redacted_fields'] >= 3);
    }
}
