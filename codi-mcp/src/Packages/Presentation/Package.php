<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation;

use CodiMcp\Core\Abilities\AbilityMetadata;
use CodiMcp\Core\AbilityPackage;

final class Package implements AbilityPackage
{
    private ?PresentationManager $manager = null;

    public function key(): string { return 'presentation'; }
    public function label(): string { return 'Presentation'; }

    public function abilityNames(): array
    {
        return array_map(fn (string $slug): string => $this->abilityName($slug), array(
            'presentation-help',
            'presentation-find',
            'presentation-inspect',
            'presentation-preview',
            'presentation-verify',
            'presentation-commit',
            'presentation-history',
            'presentation-rollback',
        ));
    }

    public function registerCategories(): void
    {
        if (!function_exists('wp_register_ability_category')) { return; }
        wp_register_ability_category($this->category(), array(
            'label' => 'Codi MCP — Presentation',
            'description' => 'WordPress-managed Gutenberg composition and presentation with preview-bound native writes, verification, history, and rollback.',
        ));
    }

    public function registerAbilities(): void
    {
        if (!function_exists('wp_register_ability')) { return; }

        $this->registerReadOnly(
            'presentation-help',
            'Presentation help',
            'List Presentation operations; action returns the accepted input schema for one operation.',
            $this->helpInputSchema(),
            $this->helpOutputSchema(),
            fn ($input = array()) => $this->presentationHelp(is_array($input) ? $input : array()),
            fn () => $this->manager()->canInspect()
        );
        $this->registerReadOnly(
            'presentation-find',
            'Find presentation surfaces',
            'Find posts/pages, templates/parts, patterns, navigation, or Global Styles by route, search, or kind; returns refs.',
            $this->findInputSchema(),
            $this->findOutputSchema(),
            fn ($input = array()) => $this->manager()->find(is_array($input) ? $input : array()),
            fn () => $this->manager()->canInspect()
        );
        $this->registerReadOnly(
            'presentation-inspect',
            'Inspect presentation state',
            'Read a Presentation target; blocks is compact, raw is serialized state, full returns both.',
            $this->inspectInputSchema(),
            $this->inspectOutputSchema(),
            fn ($input = array()) => $this->manager()->inspect(is_array($input) ? $input : array()),
            fn () => $this->manager()->canInspect()
        );
        $this->registerMutation(
            'presentation-preview',
            'Preview presentation mutation',
            'Preview Presentation operations and return preview_id for presentation-commit. Use presentation-help(action=...) for accepted fields.',
            $this->previewInputSchema(),
            $this->previewOutputSchema(),
            fn ($input = array()) => $this->manager()->preview(is_array($input) ? $input : array()),
            fn ($input = array()) => $this->manager()->canPreview(is_array($input) ? $input : array()),
            false,
            false,
            $this->modelPreviewInputSchema()
        );
        $this->registerReadOnly(
            'presentation-verify',
            'Verify presentation state',
            'Check a target or preview for freshness, valid Gutenberg blocks, and required dependencies.',
            $this->verifyInputSchema(),
            $this->verifyOutputSchema(),
            fn ($input = array()) => $this->manager()->verify(is_array($input) ? $input : array()),
            fn ($input = array()) => $this->manager()->canVerify(is_array($input) ? $input : array())
        );
        $this->registerMutation(
            'presentation-commit',
            'Commit presentation preview',
            'Apply a preview_id if its base state is unchanged; records a rollback snapshot.',
            $this->strictObject(array('preview_id' => $this->previewIdSchema())),
            $this->commitOutputSchema(),
            fn ($input = array()) => $this->manager()->commit(is_array($input) ? $input : array()),
            fn ($input = array()) => $this->manager()->canCommit(is_array($input) ? $input : array()),
            true,
            false
        );
        $this->registerReadOnly(
            'presentation-history',
            'Presentation history',
            'List recent Presentation edits and transaction IDs for rollback.',
            $this->historyInputSchema(),
            $this->historyOutputSchema(),
            fn ($input = array()) => $this->manager()->history(is_array($input) ? $input : array()),
            fn () => $this->manager()->canInspect()
        );
        $this->registerMutation(
            'presentation-rollback',
            'Rollback presentation transaction',
            'Preview or apply reversal of a Presentation transaction; refuses if the target has since changed.',
            $this->rollbackInputSchema(),
            $this->rollbackOutputSchema(),
            fn ($input = array()) => $this->manager()->rollback(is_array($input) ? $input : array()),
            fn ($input = array()) => $this->manager()->canRollback(is_array($input) ? $input : array()),
            true,
            false
        );
    }

    /** @return array<string,mixed>|\WP_Error */
    public function presentationHelp(array $input = array())
    {
        $help = $this->manager()->help();
        $action = strtolower(trim((string) ($input['action'] ?? '')));
        $search = strtolower(trim((string) ($input['search'] ?? '')));
        $targetKind = strtolower(trim((string) ($input['target_kind'] ?? '')));
        $operations = array();

        foreach ((array) ($help['operations'] ?? array()) as $operation) {
            if (!is_array($operation)) {
                continue;
            }
            $name = strtolower((string) ($operation['action'] ?? ''));
            $summary = strtolower((string) ($operation['summary'] ?? ''));
            $targets = array_values(array_map('strval', (array) ($operation['target_kinds'] ?? array())));
            if ($action !== '' && $name !== $action) {
                continue;
            }
            if ($search !== '' && !str_contains($name . ' ' . $summary, $search)) {
                continue;
            }
            if ($targetKind !== '' && !in_array($targetKind, $targets, true)) {
                continue;
            }

            $row = array(
                'action' => (string) ($operation['action'] ?? ''),
                'target_kinds' => $targets,
            );
            if ($action !== '' || $search !== '' || $targetKind !== '') {
                $row['summary'] = (string) ($operation['summary'] ?? '');
            }
            if ($action !== '') {
                $schema = $this->operationSchemaForAction($action);
                if ($schema === null) {
                    return new \WP_Error('codi_mcp_presentation_operation_not_found', 'The requested Presentation operation is unavailable.');
                }
                $row['input_schema'] = $schema;
            }
            $operations[] = $row;
        }

        if ($action !== '' && $operations === array()) {
            return new \WP_Error('codi_mcp_presentation_operation_not_found', 'The requested Presentation operation is unavailable.');
        }
        $help['operations'] = $operations;
        return $help;
    }

    private function operationSchemaForAction(string $action): ?array
    {
        foreach ((array) ($this->operationSchema()['oneOf'] ?? array()) as $schema) {
            if (!is_array($schema)) {
                continue;
            }
            $enum = (array) ($schema['properties']['action']['enum'] ?? array());
            if ($enum === array($action)) {
                return $schema;
            }
        }
        return null;
    }

    private function manager(): PresentationManager { return $this->manager ??= new PresentationManager(); }
    private function abilityName(string $slug): string { return rtrim(CODI_MCP_ABILITY_PREFIX, '/') . '/' . ltrim($slug, '/'); }
    private function category(): string { return rtrim(CODI_MCP_ABILITY_PREFIX, '/') . '-presentation'; }

    private function registerReadOnly(string $slug, string $label, string $description, array $input, array $output, callable $execute, callable $permission): void
    {
        wp_register_ability($this->abilityName($slug), array(
            'label' => $label,
            'description' => $description,
            'category' => $this->category(),
            'input_schema' => $input,
            'output_schema' => $output,
            'execute_callback' => $execute,
            'permission_callback' => $permission,
            'meta' => AbilityMetadata::owned($this->key(), true, false, true),
        ));
    }

    private function registerMutation(string $slug, string $label, string $description, array $input, array $output, callable $execute, callable $permission, bool $destructive, bool $idempotent, ?array $modelInput = null): void
    {
        $meta = AbilityMetadata::owned($this->key(), false, $destructive, $idempotent);
        if ($modelInput !== null) {
            $meta['codi_mcp']['model_input_schema'] = $modelInput;
        }
        wp_register_ability($this->abilityName($slug), array(
            'label' => $label,
            'description' => $description,
            'category' => $this->category(),
            'input_schema' => $input,
            'output_schema' => $output,
            'execute_callback' => $execute,
            'permission_callback' => $permission,
            'meta' => $meta,
        ));
    }

    private function emptySchema(): array { return array('type' => 'object', 'additionalProperties' => false, 'properties' => array()); }
    private function refSchema(): array { return array('type' => 'string', 'minLength' => 20, 'maxLength' => 800, 'pattern' => '^codi:presentation:v1:(?:post:[a-z0-9_-]+:[1-9][0-9]*|(?:template|template_part):[A-Za-z0-9_-]+)$'); }
    private function previewIdSchema(): array { return array('type' => 'string', 'minLength' => 32, 'maxLength' => 32, 'pattern' => '^[a-f0-9]{32}$'); }
    private function transactionIdSchema(): array { return array('type' => 'string', 'minLength' => 28, 'maxLength' => 28, 'pattern' => '^ptx_[a-f0-9]{24}$'); }
    private function strictObject(array $properties): array { return array('type' => 'object', 'additionalProperties' => false, 'properties' => $properties, 'required' => array_keys($properties)); }
    private function optionalObject(array $properties, array $required = array()): array { return array('type' => 'object', 'additionalProperties' => false, 'properties' => $properties, 'required' => $required); }
    private function actionSchema(string $action): array { return array('type' => 'string', 'enum' => array($action)); }
    private function stringArray(): array { return array('type' => 'array', 'items' => array('type' => 'string')); }

    private function surfaceSchema(bool $native = false): array
    {
        $properties = array(
            'ref' => array('type' => 'string'),
            'kind' => array('type' => 'string'),
            'post_id' => array('type' => 'integer'),
            'post_type' => array('type' => 'string'),
            'title' => array('type' => 'string'),
            'slug' => array('type' => 'string'),
            'status' => array('type' => 'string'),
            'route_url' => array('type' => 'string'),
            'source_kind' => array('type' => 'string'),
            'original_source' => array('type' => 'string'),
            'customized' => array('type' => 'boolean'),
            'template_id' => array('type' => 'string'),
            'theme' => array('type' => 'string'),
            'area' => array('type' => 'string'),
            'checksum' => array('type' => 'string'),
        );
        if ($native) {
            $properties['content'] = array('type' => 'string');
            $properties['template'] = array('type' => 'string');
            $properties['global_styles'] = array('type' => 'object', 'additionalProperties' => true);
            $properties['sync_status'] = array('type' => 'string');
        }
        return $this->strictObject($properties);
    }

    private function helpInputSchema(): array
    {
        return $this->optionalObject(array(
            'action' => array('type' => 'string', 'maxLength' => 100, 'description' => 'Exact action; returns its input schema.'),
            'search' => array('type' => 'string', 'maxLength' => 100),
            'target_kind' => array('type' => 'string', 'enum' => array('content', 'template', 'template_part', 'pattern', 'navigation', 'global_styles')),
        ));
    }

    private function inspectInputSchema(): array
    {
        return $this->optionalObject(array(
            'ref' => $this->refSchema(),
            'detail' => array('type' => 'string', 'enum' => array('blocks', 'raw', 'full'), 'default' => 'blocks'),
        ), array('ref'));
    }

    private function helpOutputSchema(): array
    {
        $operation = array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'action' => array('type' => 'string'),
                'summary' => array('type' => 'string'),
                'target_kinds' => $this->stringArray(),
                'input_schema' => array('type' => 'object', 'additionalProperties' => true),
            ),
            'required' => array('action', 'target_kinds'),
        );
        return $this->strictObject(array(
            'package' => array('type' => 'string'),
            'workflow' => $this->stringArray(),
            'owned_state' => $this->stringArray(),
            'external_owners' => $this->stringArray(),
            'classic_theme_support' => array('type' => 'string'),
            'block_safety' => $this->stringArray(),
            'operations' => array('type' => 'array', 'items' => $operation),
        ));
    }

    private function findInputSchema(): array
    {
        return $this->optionalObject(array(
            'query' => array('type' => 'string', 'maxLength' => 500),
            'route' => array('type' => 'string', 'maxLength' => 2048),
            'kinds' => array('type' => 'array', 'maxItems' => 6, 'uniqueItems' => true, 'items' => array('type' => 'string', 'enum' => array('content', 'template', 'template_part', 'pattern', 'navigation', 'global_styles'))),
            'post_type' => array('type' => 'string', 'maxLength' => 80, 'pattern' => '^[a-z0-9_-]+$'),
            'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 50),
        ));
    }

    private function findOutputSchema(): array
    {
        return $this->strictObject(array('items' => array('type' => 'array', 'items' => $this->surfaceSchema(false)), 'returned' => array('type' => 'integer')));
    }

    private function blockSummarySchema(): array
    {
        return $this->strictObject(array(
            'path' => array('type' => 'string'),
            'name' => array('type' => 'string'),
            'attrs' => array('type' => 'object', 'additionalProperties' => true),
            'text' => array('type' => 'string'),
            'registered' => array('type' => 'boolean'),
            'dynamic' => array('type' => 'boolean'),
            'mutation_support' => array('type' => 'string', 'enum' => array('structural_only', 'php_save_codec')),
            'codec_capabilities' => array('type' => 'array', 'items' => array('type' => 'string')),
        ));
    }

    private function dependenciesSchema(): array
    {
        return $this->strictObject(array(
            'media_ids' => array('type' => 'array', 'items' => array('type' => 'integer')),
            'navigation_ids' => array('type' => 'array', 'items' => array('type' => 'integer')),
            'pattern_ids' => array('type' => 'array', 'items' => array('type' => 'integer')),
            'template_part_slugs' => $this->stringArray(),
            'query_paths' => $this->stringArray(),
        ));
    }

    private function inspectOutputSchema(): array
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'surface' => $this->surfaceSchema(false),
                'content' => array('type' => 'string'),
                'template' => array('type' => 'string'),
                'global_styles' => array('type' => 'object', 'additionalProperties' => true),
                'sync_status' => array('type' => 'string'),
                'blocks' => array('type' => 'array', 'items' => $this->blockSummarySchema()),
                'dependencies' => $this->dependenciesSchema(),
            ),
            'required' => array('surface', 'template', 'global_styles', 'sync_status'),
        );
    }

    private function operationSchema(): array
    {
        $path = array('type' => 'string', 'maxLength' => 200, 'pattern' => '^[0-9]+(?:\\.[0-9]+)*$');
        $parentPath = array('type' => 'string', 'maxLength' => 200, 'pattern' => '^(?:[0-9]+(?:\\.[0-9]+)*)?$');
        $index = array('type' => 'integer', 'minimum' => 0, 'maximum' => 10000);
        $jsonObject = array('type' => 'object', 'additionalProperties' => true, 'maxProperties' => 1000);
        $imageFields = array(
            'id' => array('type' => 'integer', 'minimum' => 1),
            'url' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 4000),
            'alt' => array('type' => 'string', 'maxLength' => 5000),
            'caption' => array('type' => 'string', 'maxLength' => 100000),
            'title' => array('type' => 'string', 'maxLength' => 1000),
            'href' => array('type' => 'string', 'maxLength' => 4000),
            'rel' => array('type' => 'string', 'maxLength' => 500),
            'link_class' => array('type' => 'string', 'maxLength' => 500),
            'link_target' => array('type' => 'string', 'enum' => array('', '_self','_blank','_parent','_top')),
            'width' => array('type' => 'string', 'maxLength' => 100),
            'height' => array('type' => 'string', 'maxLength' => 100),
            'aspect_ratio' => array('type' => 'string', 'maxLength' => 100),
            'scale' => array('type' => 'string', 'enum' => array('cover','contain')),
            'size_slug' => array('type' => 'string', 'maxLength' => 100),
            'link_destination' => array('type' => 'string', 'maxLength' => 100),
            'is_decorative' => array('type' => 'boolean'),
            'align' => array('type' => 'string', 'enum' => array('none','left','center','right','wide','full')),
            'focal_x' => array('type' => 'number', 'minimum' => 0, 'maximum' => 1),
            'focal_y' => array('type' => 'number', 'minimum' => 0, 'maximum' => 1),
        );
        $coverFields = array(
            'url' => array('type' => 'string', 'maxLength' => 4000),
            'id' => array('type' => 'integer', 'minimum' => 1),
            'alt' => array('type' => 'string', 'maxLength' => 5000),
            'use_featured_image' => array('type' => 'boolean'),
            'has_parallax' => array('type' => 'boolean'),
            'is_repeated' => array('type' => 'boolean'),
            'dim_ratio' => array('type' => 'integer', 'minimum' => 0, 'maximum' => 100),
            'overlay_color' => array('type' => 'string', 'maxLength' => 100),
            'custom_overlay_color' => array('type' => 'string', 'maxLength' => 100),
            'gradient' => array('type' => 'string', 'maxLength' => 100),
            'custom_gradient' => array('type' => 'string', 'maxLength' => 2000),
            'content_position' => array('type' => 'string', 'enum' => array('center','center center','top left','top center','top right','center left','center right','bottom left','bottom center','bottom right')),
            'is_dark' => array('type' => 'boolean'),
            'min_height' => array('type' => 'number', 'minimum' => 0, 'maximum' => 10000),
            'min_height_unit' => array('type' => 'string', 'enum' => array('px','%','vh','vw','svh','lvh','dvh','em','rem')),
            'tag_name' => array('type' => 'string', 'enum' => array('div','section','main','aside','header','footer','article')),
            'size_slug' => array('type' => 'string', 'maxLength' => 100),
            'text_color' => array('type' => 'string', 'maxLength' => 100),
            'font_size' => array('type' => 'string', 'maxLength' => 100),
            'align' => array('type' => 'string', 'enum' => array('wide','full','left','center','right')),
            'anchor' => array('type' => 'string', 'maxLength' => 255),
            'class_name' => array('type' => 'string', 'maxLength' => 500),
            'focal_x' => array('type' => 'number', 'minimum' => 0, 'maximum' => 1),
            'focal_y' => array('type' => 'number', 'minimum' => 0, 'maximum' => 1),
        );

        $galleryFields = array(
            'columns' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 8),
            'image_crop' => array('type' => 'boolean'),
            'caption' => array('type' => 'string', 'maxLength' => 100000),
            'align' => array('type' => 'string', 'enum' => array('','left','center','right','wide','full')),
            'anchor' => array('type' => 'string', 'maxLength' => 255),
            'class_name' => array('type' => 'string', 'maxLength' => 500),
            'background_color' => array('type' => 'string', 'maxLength' => 100),
            'gradient' => array('type' => 'string', 'maxLength' => 100),
        );
        $queryFields = array(
            'per_page' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100),
            'post_type' => array('type' => 'string', 'pattern' => '^[a-z0-9_-]{1,64}$'),
            'order' => array('type' => 'string', 'enum' => array('asc','desc')),
            'order_by' => array('type' => 'string', 'enum' => array('date','title','modified','menu_order','author','comment_count','rand')),
            'offset' => array('type' => 'integer', 'minimum' => 0, 'maximum' => 10000),
            'search' => array('type' => 'string', 'maxLength' => 500),
            'inherit' => array('type' => 'boolean'),
            'sticky' => array('type' => 'string', 'enum' => array('','only','exclude')),
        );
        $navigationFields = array(
            'label' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 1000),
            'url' => array('type' => 'string', 'maxLength' => 4000),
            'rel' => array('type' => 'string', 'maxLength' => 500),
            'title' => array('type' => 'string', 'maxLength' => 1000),
            'description' => array('type' => 'string', 'maxLength' => 5000),
            'opens_in_new_tab' => array('type' => 'boolean'),
        );


        return array('type' => 'object', 'oneOf' => array(
            $this->strictObject(array('action' => $this->actionSchema('blocks.insert_copy'), 'source_ref' => $this->refSchema(), 'source_path' => $path, 'parent_path' => $parentPath, 'index' => $index)),
            $this->strictObject(array('action' => $this->actionSchema('blocks.remove'), 'path' => $path)),
            $this->strictObject(array('action' => $this->actionSchema('blocks.move'), 'path' => $path, 'to_parent_path' => $parentPath, 'to_index' => $index)),
            $this->optionalObject(array('action' => $this->actionSchema('blocks.update_presentation'), 'path' => $path, 'text_color' => array('type' => 'string', 'maxLength' => 100), 'background_color' => array('type' => 'string', 'maxLength' => 100), 'font_size' => array('type' => 'string', 'maxLength' => 100), 'line_height' => array('type' => array('number', 'string'), 'oneOf' => array(array('type' => 'number', 'minimum' => 0.5, 'maximum' => 4), array('type' => 'string', 'enum' => array('')))), 'margin_top' => array('type' => 'string', 'maxLength' => 100), 'margin_bottom' => array('type' => 'string', 'maxLength' => 100), 'padding_top' => array('type' => 'string', 'maxLength' => 100), 'padding_right' => array('type' => 'string', 'maxLength' => 100), 'padding_bottom' => array('type' => 'string', 'maxLength' => 100), 'padding_left' => array('type' => 'string', 'maxLength' => 100)), array('action','path')),
            $this->optionalObject(array('action' => $this->actionSchema('blocks.insert_group'), 'parent_path' => $parentPath, 'index' => $index, 'tag_name' => array('type' => 'string', 'enum' => array('div','section','main','aside','header','footer','article')), 'align' => array('type' => 'string', 'enum' => array('wide','full')), 'anchor' => array('type' => 'string', 'maxLength' => 255), 'class_name' => array('type' => 'string', 'maxLength' => 500), 'background_color' => array('type' => 'string', 'maxLength' => 100), 'text_color' => array('type' => 'string', 'maxLength' => 100)), array('action')),
            $this->optionalObject(array('action' => $this->actionSchema('blocks.insert_columns'), 'parent_path' => $parentPath, 'index' => $index, 'count' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 6), 'vertical_alignment' => array('type' => 'string', 'enum' => array('top','center','bottom','stretch')), 'is_stacked_on_mobile' => array('type' => 'boolean')), array('action')),
            $this->optionalObject(array('action' => $this->actionSchema('blocks.update_column'), 'path' => $path, 'width' => array('type' => 'string', 'maxLength' => 100), 'vertical_alignment' => array('type' => 'string', 'enum' => array('','top','center','bottom','stretch'))), array('action','path')),
            $this->optionalObject(array_merge(array('action' => $this->actionSchema('blocks.insert_cover'), 'parent_path' => $parentPath, 'index' => $index), $coverFields), array('action')),
            $this->optionalObject(array_merge(array('action' => $this->actionSchema('blocks.update_cover'), 'path' => $path), $coverFields), array('action','path')),
            $this->optionalObject(array_merge(array('action' => $this->actionSchema('blocks.insert_gallery'), 'parent_path' => $parentPath, 'index' => $index), $galleryFields), array('action')),
            $this->optionalObject(array_merge(array('action' => $this->actionSchema('blocks.update_gallery'), 'path' => $path), $galleryFields), array('action','path')),
            $this->optionalObject(array_merge(array('action' => $this->actionSchema('query.insert'), 'parent_path' => $parentPath, 'index' => $index), $queryFields), array('action')),
            $this->optionalObject(array_merge(array('action' => $this->actionSchema('query.update'), 'path' => $path), $queryFields), array('action','path')),
            $this->optionalObject(array_merge(array('action' => $this->actionSchema('navigation.insert_entry'), 'item_type' => array('type' => 'string', 'enum' => array('link','submenu')), 'parent_path' => $parentPath, 'index' => $index), $navigationFields), array('action','item_type','label')),
            $this->optionalObject(array_merge(array('action' => $this->actionSchema('navigation.update_entry'), 'path' => $path), $navigationFields), array('action','path')),
            $this->optionalObject(array('action' => $this->actionSchema('content.insert_list'), 'items' => array('type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'items' => array('type' => 'string', 'maxLength' => 100000)), 'ordered' => array('type' => 'boolean'), 'parent_path' => $parentPath, 'index' => $index), array('action','items')),
            $this->optionalObject(array('action' => $this->actionSchema('content.insert_text'), 'type' => array('type' => 'string', 'enum' => array('paragraph', 'heading')), 'text' => array('type' => 'string', 'maxLength' => 100000), 'level' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 6), 'parent_path' => $parentPath, 'index' => $index), array('action', 'type', 'text')),
            $this->strictObject(array('action' => $this->actionSchema('content.update_text'), 'path' => $path, 'text' => array('type' => 'string', 'maxLength' => 100000))),
            $this->optionalObject(array('action' => $this->actionSchema('content.insert_button'), 'text' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 100000), 'url' => array('type' => 'string', 'maxLength' => 4000), 'title' => array('type' => 'string', 'maxLength' => 1000), 'link_target' => array('type' => 'string', 'enum' => array('_self','_blank','_parent','_top')), 'rel' => array('type' => 'string', 'maxLength' => 500), 'tag_name' => array('type' => 'string', 'enum' => array('a','button')), 'type' => array('type' => 'string', 'maxLength' => 100), 'parent_path' => $parentPath, 'index' => $index), array('action', 'text')),
            $this->optionalObject(array('action' => $this->actionSchema('content.update_button'), 'path' => $path, 'text' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 100000), 'url' => array('type' => 'string', 'maxLength' => 4000), 'title' => array('type' => 'string', 'maxLength' => 1000), 'link_target' => array('type' => 'string', 'enum' => array('_self','_blank','_parent','_top')), 'rel' => array('type' => 'string', 'maxLength' => 500), 'tag_name' => array('type' => 'string', 'enum' => array('a','button')), 'type' => array('type' => 'string', 'maxLength' => 100)), array('action', 'path')),
            $this->optionalObject(array_merge(array('action' => $this->actionSchema('content.insert_image'), 'parent_path' => $parentPath, 'index' => $index), $imageFields), array('action','url')),
            $this->optionalObject(array_merge(array('action' => $this->actionSchema('content.update_image'), 'path' => $path), $imageFields), array('action','path')),
            $this->strictObject(array('action' => $this->actionSchema('presentation.set_template'), 'template' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 255))),
            $this->strictObject(array('action' => $this->actionSchema('presentation.clear_template'))),
            $this->optionalObject(array('action' => $this->actionSchema('styles.update'), 'settings' => $jsonObject, 'styles' => $jsonObject), array('action')),
            $this->optionalObject(array('action' => $this->actionSchema('object.create'), 'kind' => array('type' => 'string', 'enum' => array('pattern', 'navigation', 'template', 'template_part')), 'title' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 500), 'slug' => array('type' => 'string', 'maxLength' => 200), 'area' => array('type' => 'string', 'maxLength' => 100), 'sync_status' => array('type' => 'string', 'enum' => array('synced', 'unsynced'))), array('action', 'kind', 'title')),
            $this->optionalObject(array('action' => $this->actionSchema('object.duplicate'), 'title' => array('type' => 'string', 'maxLength' => 500), 'slug' => array('type' => 'string', 'maxLength' => 200)), array('action')),
            $this->strictObject(array('action' => $this->actionSchema('object.delete'))),
        ));
    }

    private function previewInputSchema(): array
    {
        return $this->optionalObject(array(
            'ref' => $this->refSchema(),
            'operations' => array('type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'items' => $this->operationSchema()),
        ), array('operations'));
    }

    private function modelPreviewInputSchema(): array
    {
        return $this->optionalObject(array(
            'ref' => $this->refSchema(),
            'operations' => array(
                'type' => 'array',
                'minItems' => 1,
                'maxItems' => 50,
                'items' => array(
                    'type' => 'object',
                    'additionalProperties' => true,
                    'properties' => array(
                        'action' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'description' => 'Use presentation-help(action=...) for accepted fields.'),
                    ),
                    'required' => array('action'),
                ),
            ),
        ), array('operations'));
    }

    private function previewOutputSchema(): array
    {
        return $this->strictObject(array(
            'preview_id' => array('type' => 'string'),
            'ref' => array('type' => 'string'),
            'target_kind' => array('type' => 'string'),
            'operation_count' => array('type' => 'integer'),
            'before_checksum' => array('type' => 'string'),
            'after_checksum' => array('type' => 'string'),
            'changes' => $this->stringArray(),
            'proposed_state' => $this->surfaceSchema(true),
            'expires_at' => array('type' => 'string'),
        ));
    }

    private function verifyInputSchema(): array
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array('preview_id' => $this->previewIdSchema(), 'ref' => $this->refSchema(), 'frontend' => array('type' => 'boolean')),
            'oneOf' => array(array('type' => 'object', 'required' => array('preview_id')), array('type' => 'object', 'required' => array('ref'))),
        );
    }

    private function verifyOutputSchema(): array
    {
        $check = $this->strictObject(array('code' => array('type' => 'string'), 'ok' => array('type' => 'boolean'), 'message' => array('type' => 'string')));
        return $this->strictObject(array(
            'subject' => array('type' => 'string'),
            'ref' => array('type' => 'string'),
            'preview_id' => array('type' => 'string'),
            'valid' => array('type' => 'boolean'),
            'checksum' => array('type' => 'string'),
            'checks' => array('type' => 'array', 'items' => $check),
        ));
    }

    private function commitOutputSchema(): array
    {
        return $this->strictObject(array(
            'transaction_id' => array('type' => 'string'),
            'preview_id' => array('type' => 'string'),
            'ref' => array('type' => 'string'),
            'status' => array('type' => 'string'),
            'before_checksum' => array('type' => 'string'),
            'after_checksum' => array('type' => 'string'),
            'committed_at' => array('type' => 'string'),
            'rollback_supported' => array('type' => 'boolean'),
        ));
    }

    private function historyInputSchema(): array
    {
        return $this->optionalObject(array('ref' => $this->refSchema(), 'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 50)));
    }

    private function historyOutputSchema(): array
    {
        $item = $this->strictObject(array(
            'transaction_id' => array('type' => 'string'),
            'kind' => array('type' => 'string'),
            'ref' => array('type' => 'string'),
            'committed_at' => array('type' => 'string'),
            'actions' => $this->stringArray(),
            'changes' => $this->stringArray(),
            'rolled_back_at' => array('type' => 'string'),
            'rolled_back_by' => array('type' => 'string'),
        ));
        return $this->strictObject(array('items' => array('type' => 'array', 'items' => $item), 'returned' => array('type' => 'integer')));
    }

    private function rollbackInputSchema(): array
    {
        return $this->optionalObject(array('transaction_id' => $this->transactionIdSchema(), 'mode' => array('type' => 'string', 'enum' => array('preview', 'apply'))), array('transaction_id'));
    }

    private function rollbackOutputSchema(): array
    {
        return $this->optionalObject(array(
            'mode' => array('type' => 'string'),
            'transaction_id' => array('type' => 'string'),
            'can_apply' => array('type' => 'boolean'),
            'impact' => array('type' => 'string'),
            'result_ref' => array('type' => 'string'),
            'rollback_transaction_id' => array('type' => 'string'),
            'status' => array('type' => 'string'),
        ), array('mode', 'transaction_id', 'impact', 'result_ref'));
    }
}
