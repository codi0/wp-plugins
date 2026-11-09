<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Database;

use CodiMcp\Core\Abilities\AbilityMetadata;
use CodiMcp\Core\AbilityPackage;

final class Package implements AbilityPackage
{
    private ?DatabaseInspector $inspector = null;
    private ?DatabaseOptimizer $optimizer = null;

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
            $this->abilityName('db-index'),
            $this->abilityName('options-autoload'),
            $this->abilityName('options-autoload-set'),
        );
    }

    public function registerCategories(): void
    {
        if (!function_exists('wp_register_ability_category')) {
            return;
        }

        wp_register_ability_category($this->category(), array(
            'label' => 'Codi MCP — Database',
            'description' => 'Read-only database inspection plus narrowly bounded index and WordPress option-autoload optimization abilities.',
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
            'List accessible database tables, scope, approximate row counts, and storage sizes.',
            $this->tablesInputSchema(),
            $this->tablesOutputSchema(),
            [$this, 'tables']
        );

        $this->registerReadOnly(
            'db-schema',
            'Inspect database table schema',
            'Inspect one table\'s columns, keys, and indexes; returns no row data.',
            $this->schemaInputSchema(),
            $this->schemaOutputSchema(),
            [$this, 'schema']
        );

        $this->registerReadOnly(
            'db-query',
            'Run bounded read-only database query',
            'Run read-only SELECT or EXPLAIN SELECT on existing current-DB tables. Use %s/%d/%f for values and %i for identifiers with matching params. Rejects raw literals/comments, writes, locks, system schemas, and protected data.',
            $this->queryInputSchema(),
            $this->queryOutputSchema(),
            [$this, 'query']
        );

        $this->registerMutation(
            'db-index',
            'Add or remove database index',
            'Add or remove one non-unique index on a current-site non-Core table. Multi-column order is significant; removal requires the expected columns.',
            $this->indexInputSchema(),
            $this->indexOutputSchema(),
            [$this, 'index'],
            [$this, 'canOptimize']
        );

        $this->registerReadOnly(
            'options-autoload',
            'Inspect option autoload metadata',
            'Inspect current-site option names, serialized byte sizes, and autoload states without returning option values.',
            $this->optionsAutoloadInputSchema(),
            $this->optionsAutoloadOutputSchema(),
            [$this, 'optionsAutoload']
        );

        $this->registerMutation(
            'options-autoload-set',
            'Set option autoload states',
            'Change autoload state for bounded existing current-site options; values are never changed.',
            $this->optionsAutoloadSetInputSchema(),
            $this->optionsAutoloadSetOutputSchema(),
            [$this, 'optionsAutoloadSet'],
            [$this, 'canOptimize']
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

    public function index($input = array())
    {
        return $this->optimizer()->index(is_array($input) ? $input : array());
    }

    public function optionsAutoload($input = array())
    {
        return $this->optimizer()->optionsAutoload(is_array($input) ? $input : array());
    }

    public function optionsAutoloadSet($input = array())
    {
        return $this->optimizer()->optionsAutoloadSet(is_array($input) ? $input : array());
    }

    public function canInspect(): bool
    {
        return $this->inspector()->canInspect();
    }

    public function canOptimize(): bool
    {
        return $this->optimizer()->canManage();
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

    private function registerMutation(string $slug, string $label, string $description, array $inputSchema, array $outputSchema, callable $callback, callable $permissionCallback): void
    {
        wp_register_ability($this->abilityName($slug), array(
            'label' => $label,
            'description' => $description,
            'category' => $this->category(),
            'input_schema' => $inputSchema,
            'output_schema' => $outputSchema,
            'execute_callback' => $callback,
            'permission_callback' => $permissionCallback,
            'meta' => AbilityMetadata::owned($this->key(), false, true, true),
        ));
    }

    private function inspector(): DatabaseInspector
    {
        return $this->inspector ??= new DatabaseInspector();
    }

    private function optimizer(): DatabaseOptimizer
    {
        return $this->optimizer ??= new DatabaseOptimizer();
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



    private function indexInputSchema(): array
    {
        return $this->strictObject(array(
            'action' => array('type' => 'string', 'enum' => array('add', 'remove')),
            'table' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'pattern' => '^[A-Za-z0-9_$-]+$'),
            'index' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'pattern' => '^[A-Za-z0-9_$-]+$'),
            'columns' => array(
                'type' => 'array',
                'minItems' => 1,
                'maxItems' => DatabaseOptimizer::MAX_INDEX_COLUMNS,
                'uniqueItems' => true,
                'items' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'pattern' => '^[A-Za-z0-9_$-]+$'),
            ),
        ));
    }

    private function indexOutputSchema(): array
    {
        $state = $this->strictObject(array(
            'exists' => array('type' => 'boolean'),
            'primary' => array('type' => 'boolean'),
            'unique' => array('type' => 'boolean'),
            'columns' => array('type' => 'array', 'items' => array('type' => 'string')),
        ));
        return $this->strictObject(array(
            'action' => array('type' => 'string', 'enum' => array('add', 'remove')),
            'table' => array('type' => 'string'),
            'index' => array('type' => 'string'),
            'columns' => array('type' => 'array', 'items' => array('type' => 'string')),
            'changed' => array('type' => 'boolean'),
            'before' => $state,
            'after' => $state,
        ));
    }

    private function optionsAutoloadInputSchema(): array
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'search' => array('type' => 'string', 'maxLength' => 191),
                'autoload' => array('type' => 'boolean'),
                'min_bytes' => array('type' => 'integer', 'minimum' => 0),
                'page' => array('type' => 'integer', 'minimum' => 1),
                'per_page' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100),
            ),
        );
    }

    private function optionsAutoloadOutputSchema(): array
    {
        $item = $this->strictObject(array(
            'option' => array('type' => 'string'),
            'bytes' => array('type' => 'integer'),
            'autoload' => array('type' => 'boolean'),
            'raw_autoload' => array('type' => 'string'),
        ));
        return $this->strictObject(array(
            'total_autoload_count' => array('type' => 'integer'),
            'total_autoload_bytes' => array('type' => 'integer'),
            'items' => array('type' => 'array', 'items' => $item),
            'total' => array('type' => 'integer'),
            'page' => array('type' => 'integer'),
            'per_page' => array('type' => 'integer'),
            'returned' => array('type' => 'integer'),
        ));
    }

    private function optionsAutoloadSetInputSchema(): array
    {
        $change = $this->strictObject(array(
            'option' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 191),
            'autoload' => array('type' => 'boolean'),
        ));
        return $this->strictObject(array(
            'changes' => array('type' => 'array', 'minItems' => 1, 'maxItems' => DatabaseOptimizer::MAX_OPTION_CHANGES, 'items' => $change),
        ));
    }

    private function optionsAutoloadSetOutputSchema(): array
    {
        $item = $this->strictObject(array(
            'option' => array('type' => 'string'),
            'before' => array('type' => 'boolean'),
            'after' => array('type' => 'boolean'),
            'changed' => array('type' => 'boolean'),
        ));
        return $this->strictObject(array(
            'changed' => array('type' => 'integer'),
            'items' => array('type' => 'array', 'items' => $item),
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
