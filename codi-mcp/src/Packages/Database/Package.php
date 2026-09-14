<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Database;

use CodiMcp\Core\Abilities\AbilityMetadata;
use CodiMcp\Core\AbilityPackage;

final class Package implements AbilityPackage
{
    private ?DatabaseInspector $inspector = null;

    public function key(): string
    {
        return 'database';
    }

    public function label(): string
    {
        return 'Database';
    }

    public function abilityNames(): array
    {
        return array(
            $this->abilityName('db-tables'),
            $this->abilityName('db-schema'),
            $this->abilityName('db-query'),
        );
    }

    public function registerCategories(): void
    {
        if (!function_exists('wp_register_ability_category')) {
            return;
        }

        wp_register_ability_category($this->category(), array(
            'label' => 'Codi MCP — Database',
            'description' => 'Read-only database metadata and bounded parameterized row-data inspection. Database writes are not provided.',
        ));
    }

    public function registerAbilities(): void
    {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        $this->registerReadOnly(
            'db-tables',
            'List database tables',
            'List tables visible to the WordPress database connection with prefix relationship, approximate row count, storage engine, collation, and size metadata. Multisite access requires a super administrator.',
            $this->tablesInputSchema(),
            $this->tablesOutputSchema(),
            [$this, 'tables']
        );

        $this->registerReadOnly(
            'db-schema',
            'Inspect database table schema',
            'Inspect columns, primary keys, and indexes for one existing database table. This reads schema metadata only and never returns table row data.',
            $this->schemaInputSchema(),
            $this->schemaOutputSchema(),
            [$this, 'schema']
        );

        $this->registerReadOnly(
            'db-query',
            'Run bounded read-only database query',
            'Run one SELECT or EXPLAIN SELECT query. Values must use unquoted %s, %d, or %f placeholders and direct table names must use %i, all with matching params. Comments, embedded quoted/numeric literals, implicit comma joins, writes, locking clauses, unknown or dangerous functions, system schemas, protected credential fields, and tables outside the current-site/shared WordPress scope are rejected. Results are row/byte bounded and credential-like values are redacted.',
            $this->queryInputSchema(),
            $this->queryOutputSchema(),
            [$this, 'query']
        );
    }

    public function tables($input = array())
    {
        return $this->inspector()->tables(is_array($input) ? $input : array());
    }

    public function schema($input = array())
    {
        return $this->inspector()->schema(is_array($input) ? $input : array());
    }

    public function query($input = array())
    {
        return $this->inspector()->query(is_array($input) ? $input : array());
    }

    public function canInspect(): bool
    {
        return $this->inspector()->canInspect();
    }

    private function registerReadOnly(string $slug, string $label, string $description, array $inputSchema, array $outputSchema, callable $callback): void
    {
        wp_register_ability($this->abilityName($slug), array(
            'label' => $label,
            'description' => $description,
            'category' => $this->category(),
            'input_schema' => $inputSchema,
            'output_schema' => $outputSchema,
            'execute_callback' => $callback,
            'permission_callback' => [$this, 'canInspect'],
            'meta' => AbilityMetadata::owned($this->key(), true, false, true),

        ));
    }

    private function inspector(): DatabaseInspector
    {
        return $this->inspector ??= new DatabaseInspector();
    }

    private function abilityName(string $slug): string
    {
        return rtrim(CODI_MCP_ABILITY_PREFIX, '/') . '/' . ltrim($slug, '/');
    }

    private function category(): string
    {
        return rtrim(CODI_MCP_ABILITY_PREFIX, '/') . '-database';
    }

    private function tablesInputSchema(): array
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'search' => array('type' => 'string', 'maxLength' => 200),
                'relationship' => array('type' => 'string', 'enum' => array('all', 'site', 'shared', 'wordpress-other', 'other')),
                'page' => array('type' => 'integer', 'minimum' => 1),
                'per_page' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100),
            ),
        );
    }

    private function tablesOutputSchema(): array
    {
        $item = $this->strictObject(array(
            'name' => array('type' => 'string'),
            'relationship' => array('type' => 'string', 'enum' => array('site', 'shared', 'wordpress-other', 'other')),
            'engine' => array('type' => 'string'),
            'approx_rows' => array('type' => 'integer'),
            'data_bytes' => array('type' => 'integer'),
            'index_bytes' => array('type' => 'integer'),
            'total_bytes' => array('type' => 'integer'),
            'collation' => array('type' => 'string'),
        ));

        return $this->strictObject(array(
            'items' => array('type' => 'array', 'items' => $item),
            'total' => array('type' => 'integer'),
            'page' => array('type' => 'integer'),
            'per_page' => array('type' => 'integer'),
            'returned' => array('type' => 'integer'),
        ));
    }

    private function schemaInputSchema(): array
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'table' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 255),
            ),
            'required' => array('table'),
        );
    }

    private function schemaOutputSchema(): array
    {
        $column = $this->strictObject(array(
            'name' => array('type' => 'string'),
            'type' => array('type' => 'string'),
            'nullable' => array('type' => 'boolean'),
            'key' => array('type' => 'string'),
            'has_default' => array('type' => 'boolean'),
            'default' => array('type' => 'string'),
            'extra' => array('type' => 'string'),
            'collation' => array('type' => 'string'),
            'comment' => array('type' => 'string'),
        ));
        $index = $this->strictObject(array(
            'name' => array('type' => 'string'),
            'primary' => array('type' => 'boolean'),
            'unique' => array('type' => 'boolean'),
            'type' => array('type' => 'string'),
            'columns' => array('type' => 'array', 'items' => array('type' => 'string')),
        ));

        return $this->strictObject(array(
            'table' => array('type' => 'string'),
            'relationship' => array('type' => 'string', 'enum' => array('site', 'shared', 'wordpress-other', 'other')),
            'columns' => array('type' => 'array', 'items' => $column),
            'indexes' => array('type' => 'array', 'items' => $index),
            'primary_keys' => array('type' => 'array', 'items' => array('type' => 'string')),
        ));
    }

    private function queryInputSchema(): array
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'sql' => array('type' => 'string', 'minLength' => 1, 'maxLength' => QueryPolicy::MAX_SQL_BYTES),
                'params' => array(
                    'type' => 'array',
                    'maxItems' => QueryPolicy::MAX_PARAMS,
                    'items' => array('type' => array('string', 'number', 'boolean')),
                ),
                'max_rows' => array('type' => 'integer', 'minimum' => 1, 'maximum' => QueryPolicy::MAX_ROWS),
            ),
            'required' => array('sql', 'params'),
        );
    }

    private function queryOutputSchema(): array
    {
        $row = array(
            'type' => 'object',
            'additionalProperties' => array('type' => array('string', 'null')),
        );

        return $this->strictObject(array(
            'statement' => array('type' => 'string', 'enum' => array('select', 'explain')),
            'columns' => array('type' => 'array', 'items' => array('type' => 'string')),
            'rows' => array('type' => 'array', 'items' => $row),
            'returned' => array('type' => 'integer'),
            'truncated' => array('type' => 'boolean'),
            'response_bytes' => array('type' => 'integer'),
            'redacted_fields' => array('type' => 'integer'),
            'truncated_fields' => array('type' => 'integer'),
        ));
    }

    private function strictObject(array $properties): array
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => $properties,
            'required' => array_keys($properties),
        );
    }
}
