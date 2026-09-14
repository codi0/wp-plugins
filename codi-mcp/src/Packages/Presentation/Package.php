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
            'Describe Presentation ownership boundaries, workflow, safe mutation catalogue, and intentionally unsupported classic/source-level presentation paths.',
            $this->emptySchema(),
            $this->helpOutputSchema(),
            fn () => $this->manager()->help(),
            fn () => $this->manager()->canInspect()
        );
        $this->registerReadOnly(
            'presentation-find',
            'Find presentation surfaces',
            'Find WordPress-managed presentation/composition surfaces by route, search text, post type, or Presentation kind. Returns stable Presentation refs; it is not an alternate Content/Media/Site discovery API.',
            $this->findInputSchema(),
            $this->findOutputSchema(),
            fn ($input = array()) => $this->manager()->find(is_array($input) ? $input : array()),
            fn () => $this->manager()->canInspect()
        );
        $this->registerReadOnly(
            'presentation-inspect',
            'Inspect presentation state',
            'Inspect the exact native WordPress composition/presentation state behind one Presentation ref, including Gutenberg structure, native template/global-style state, and presentation dependencies.',
            $this->strictObject(array('ref' => $this->refSchema())),
            $this->inspectOutputSchema(),
            fn ($input = array()) => $this->manager()->inspect(is_array($input) ? $input : array()),
            fn () => $this->manager()->canInspect()
        );
        $this->registerMutation(
            'presentation-preview',
            'Preview presentation mutation',
            'Construct an immutable proposed Presentation change without writing WordPress. Static Gutenberg blocks are fail-closed: no generic attribute setter exists, and unsupported block internals require an explicit block-specific codec.',
            $this->previewInputSchema(),
            $this->previewOutputSchema(),
            fn ($input = array()) => $this->manager()->preview(is_array($input) ? $input : array()),
            fn ($input = array()) => $this->manager()->canPreview(is_array($input) ? $input : array()),
            false,
            false
        );
        $this->registerReadOnly(
            'presentation-verify',
            'Verify presentation state',
            'Verify either current native Presentation state or a proposed preview. Preview verification includes freshness, Gutenberg structural safety, registered-block checks, and presentation dependencies.',
            $this->verifyInputSchema(),
            $this->verifyOutputSchema(),
            fn ($input = array()) => $this->manager()->verify(is_array($input) ? $input : array()),
            fn ($input = array()) => $this->manager()->canVerify(is_array($input) ? $input : array())
        );
        $this->registerMutation(
            'presentation-commit',
            'Commit presentation preview',
            'Apply one previously reviewed Presentation preview only if its native base state is unchanged. Commit writes the proposed native WordPress state and records a rollback snapshot.',
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
            'List bounded Presentation transaction summaries. History stores operational rollback snapshots only; it is not a frontend presentation source of truth.',
            $this->historyInputSchema(),
            $this->historyOutputSchema(),
            fn ($input = array()) => $this->manager()->history(is_array($input) ? $input : array()),
            fn () => $this->manager()->canInspect()
        );
        $this->registerMutation(
            'presentation-rollback',
            'Rollback presentation transaction',
            'Preview or apply reversal of one committed Presentation transaction, refusing rollback when the native target has diverged since that transaction.',
            $this->rollbackInputSchema(),
            $this->rollbackOutputSchema(),
            fn ($input = array()) => $this->manager()->rollback(is_array($input) ? $input : array()),
            fn ($input = array()) => $this->manager()->canRollback(is_array($input) ? $input : array()),
            true,
            false
        );
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

    private function registerMutation(string $slug, string $label, string $description, array $input, array $output, callable $execute, callable $permission, bool $destructive, bool $idempotent): void
    {
        wp_register_ability($this->abilityName($slug), array(
            'label' => $label,
            'description' => $description,
            'category' => $this->category(),
            'input_schema' => $input,
            'output_schema' => $output,
            'execute_callback' => $execute,
            'permission_callback' => $permission,
            'meta' => AbilityMetadata::owned($this->key(), false, $destructive, $idempotent),
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

    private function helpOutputSchema(): array
    {
        $operation = $this->strictObject(array('action' => array('type' => 'string'), 'summary' => array('type' => 'string'), 'target_kinds' => $this->stringArray()));
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
        return $this->strictObject(array(
            'surface' => $this->surfaceSchema(false),
            'content' => array('type' => 'string'),
            'template' => array('type' => 'string'),
            'global_styles' => array('type' => 'object', 'additionalProperties' => true),
            'sync_status' => array('type' => 'string'),
            'blocks' => array('type' => 'array', 'items' => $this->blockSummarySchema()),
            'dependencies' => $this->dependenciesSchema(),
        ));
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
