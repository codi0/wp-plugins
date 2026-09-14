<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation;

final class OperationEngine
{
    public function __construct(
        private ?PresentationRepository $repository = null,
        private ?BlockEditor $blocks = null
    ) {
        $this->repository ??= new PresentationRepository();
        $this->blocks ??= new BlockEditor();
    }

    /** @param array<string,mixed>|null $before @param array<int,array<string,mixed>> $operations @return array<string,mixed> */
    public function apply(?array $before, array $operations): array
    {
        if ($operations === array()) {
            throw new \InvalidArgumentException('presentation-preview requires at least one operation.');
        }
        if (count($operations) > 50) {
            throw new \InvalidArgumentException('A Presentation preview may contain at most 50 operations.');
        }

        $state = $before;
        foreach ($operations as $index => $operation) {
            if (!is_array($operation)) {
                throw new \InvalidArgumentException('Every Presentation operation must be an object.');
            }
            $action = strtolower(trim((string) ($operation['action'] ?? '')));
            if (!OperationCatalog::has($action)) {
                throw new \InvalidArgumentException(sprintf('Unsupported Presentation operation [%s].', $action));
            }
            if (!is_array($state) && 'object.create' !== $action) {
                throw new \InvalidArgumentException('Only object.create can start a preview without an existing ref.');
            }
            if (is_array($state) && !empty($state['_delete'])) {
                throw new \InvalidArgumentException('object.delete must be the final operation in a preview.');
            }

            if ('object.create' === $action) {
                if (is_array($state)) {
                    throw new \InvalidArgumentException('object.create requires a preview without an existing ref.');
                }
                $state = $this->createState($operation);
                continue;
            }
            if (!is_array($state)) {
                throw new \LogicException('Presentation operation state is unavailable.');
            }
            $this->assertActionTarget($action, (string) ($state['kind'] ?? ''));

            switch ($action) {
                case 'object.duplicate':
                    $state = $this->duplicateState($state, $operation);
                    break;
                case 'object.delete':
                    if (!in_array((string) ($state['kind'] ?? ''), array('pattern', 'navigation', 'template', 'template_part'), true)) {
                        throw new \InvalidArgumentException('object.delete only supports Presentation-owned patterns, navigation, templates, and template parts.');
                    }
                    if (in_array((string) ($state['kind'] ?? ''), array('template', 'template_part'), true) && 'custom' !== (string) ($state['source'] ?? '')) {
                        throw new \InvalidArgumentException('Presentation cannot delete a theme/plugin source template. Create or remove a WordPress customization here; change source through Themes/Plugins.');
                    }
                    $state['_delete'] = true;
                    break;
                case 'blocks.insert_copy':
                    $source = $this->repository->snapshotByRef($this->requiredString($operation, 'source_ref'));
                    if ('global_styles' === (string) ($source['kind'] ?? '')) {
                        throw new \InvalidArgumentException('blocks.insert_copy cannot copy from Global Styles.');
                    }
                    $block = $this->blocks->blockAtPath((string) ($source['content'] ?? ''), $this->requiredString($operation, 'source_path'));
                    $state['content'] = $this->blocks->insertExisting(
                        (string) ($state['content'] ?? ''),
                        trim((string) ($operation['parent_path'] ?? '')),
                        max(0, (int) ($operation['index'] ?? PHP_INT_MAX)),
                        $block
                    );
                    break;
                case 'blocks.remove':
                    $state['content'] = $this->blocks->remove((string) ($state['content'] ?? ''), $this->requiredString($operation, 'path'));
                    break;
                case 'blocks.move':
                    $path = $this->requiredString($operation, 'path');
                    $state['content'] = $this->blocks->move(
                        (string) ($state['content'] ?? ''),
                        $path,
                        array_key_exists('to_parent_path', $operation) ? trim((string) $operation['to_parent_path']) : $this->parentPath($path),
                        max(0, (int) ($operation['to_index'] ?? 0))
                    );
                    break;
                case 'blocks.update_presentation':
                    $presentationChanges = array();
                    foreach (array('text_color','background_color','font_size','line_height','margin_top','margin_bottom','padding_top','padding_right','padding_bottom','padding_left') as $field) {
                        if (array_key_exists($field, $operation)) { $presentationChanges[$field] = $operation[$field]; }
                    }
                    if ($presentationChanges === array()) { throw new \InvalidArgumentException('blocks.update_presentation requires at least one presentation field to change.'); }
                    $state['content'] = $this->blocks->updatePresentation((string) ($state['content'] ?? ''), $this->requiredString($operation, 'path'), $presentationChanges);
                    break;
                case 'blocks.insert_group':
                    $groupAttributes = array();
                    foreach (array('tag_name' => 'tagName', 'align' => 'align', 'anchor' => 'anchor', 'class_name' => 'className', 'background_color' => 'backgroundColor', 'text_color' => 'textColor') as $field => $attribute) {
                        if (array_key_exists($field, $operation) && '' !== trim((string) $operation[$field])) {
                            $groupAttributes[$attribute] = (string) $operation[$field];
                        }
                    }
                    $state['content'] = $this->blocks->insertGroup(
                        (string) ($state['content'] ?? ''),
                        max(0, (int) ($operation['index'] ?? PHP_INT_MAX)),
                        trim((string) ($operation['parent_path'] ?? '')),
                        $groupAttributes
                    );
                    break;
                case 'blocks.insert_columns':
                    $columnsState = array();
                    if (array_key_exists('vertical_alignment', $operation)) { $columnsState['vertical_alignment'] = (string) $operation['vertical_alignment']; }
                    if (array_key_exists('is_stacked_on_mobile', $operation)) { $columnsState['is_stacked_on_mobile'] = (bool) $operation['is_stacked_on_mobile']; }
                    $state['content'] = $this->blocks->insertColumns(
                        (string) ($state['content'] ?? ''),
                        max(0, (int) ($operation['index'] ?? PHP_INT_MAX)),
                        trim((string) ($operation['parent_path'] ?? '')),
                        max(1, min(6, (int) ($operation['count'] ?? 2))),
                        $columnsState
                    );
                    break;
                case 'blocks.update_column':
                    $changes = array();
                    foreach (array('width','vertical_alignment') as $field) { if (array_key_exists($field, $operation)) { $changes[$field] = $operation[$field]; } }
                    if ($changes === array()) { throw new \InvalidArgumentException('blocks.update_column requires width and/or vertical_alignment.'); }
                    $state['content'] = $this->blocks->updateColumn((string) ($state['content'] ?? ''), $this->requiredString($operation, 'path'), $changes);
                    break;
                case 'blocks.insert_cover':
                    $state['content'] = $this->blocks->insertCover(
                        (string) ($state['content'] ?? ''),
                        max(0, (int) ($operation['index'] ?? PHP_INT_MAX)),
                        trim((string) ($operation['parent_path'] ?? '')),
                        $this->coverOperationState($operation)
                    );
                    break;
                case 'blocks.update_cover':
                    $changes = $this->coverOperationState($operation);
                    if ($changes === array()) { throw new \InvalidArgumentException('blocks.update_cover requires at least one supported Cover field to change.'); }
                    $state['content'] = $this->blocks->updateCover((string) ($state['content'] ?? ''), $this->requiredString($operation, 'path'), $changes);
                    break;
                case 'blocks.insert_gallery':
                    $galleryState = array();
                    foreach (array('columns','image_crop','caption','align','anchor','class_name','background_color','gradient') as $field) {
                        if (array_key_exists($field, $operation)) { $galleryState[$field] = $operation[$field]; }
                    }
                    $state['content'] = $this->blocks->insertGallery(
                        (string) ($state['content'] ?? ''),
                        max(0, (int) ($operation['index'] ?? PHP_INT_MAX)),
                        trim((string) ($operation['parent_path'] ?? '')),
                        $galleryState
                    );
                    break;
                case 'blocks.update_gallery':
                    $galleryChanges = array();
                    foreach (array('columns','image_crop','caption','align','anchor','class_name','background_color','gradient') as $field) {
                        if (array_key_exists($field, $operation)) { $galleryChanges[$field] = $operation[$field]; }
                    }
                    if ($galleryChanges === array()) { throw new \InvalidArgumentException('blocks.update_gallery requires at least one supported Gallery field to change.'); }
                    $state['content'] = $this->blocks->updateGallery((string) ($state['content'] ?? ''), $this->requiredString($operation, 'path'), $galleryChanges);
                    break;
                case 'query.insert':
                    $state['content'] = $this->blocks->insertQueryLoop(
                        (string) ($state['content'] ?? ''),
                        max(0, (int) ($operation['index'] ?? PHP_INT_MAX)),
                        trim((string) ($operation['parent_path'] ?? '')),
                        $this->queryOperationState($operation)
                    );
                    break;
                case 'query.update':
                    $queryChanges = $this->queryOperationState($operation);
                    if ($queryChanges === array()) { throw new \InvalidArgumentException('query.update requires at least one query parameter to change.'); }
                    $state['content'] = $this->blocks->updateQueryLoop((string) ($state['content'] ?? ''), $this->requiredString($operation, 'path'), $queryChanges);
                    break;
                case 'navigation.insert_entry':
                    $navigationState = $this->navigationOperationState($operation);
                    $navigationState['item_type'] = strtolower(trim((string) ($operation['item_type'] ?? 'link')));
                    $state['content'] = $this->blocks->insertNavigationItem(
                        (string) ($state['content'] ?? ''),
                        max(0, (int) ($operation['index'] ?? PHP_INT_MAX)),
                        trim((string) ($operation['parent_path'] ?? '')),
                        $navigationState
                    );
                    break;
                case 'navigation.update_entry':
                    $navigationChanges = $this->navigationOperationState($operation);
                    if ($navigationChanges === array()) { throw new \InvalidArgumentException('navigation.update_entry requires at least one semantic field to change.'); }
                    $state['content'] = $this->blocks->updateNavigationItem((string) ($state['content'] ?? ''), $this->requiredString($operation, 'path'), $navigationChanges);
                    break;
                case 'content.insert_list':
                    $state['content'] = $this->blocks->insertList(
                        (string) ($state['content'] ?? ''),
                        max(0, (int) ($operation['index'] ?? PHP_INT_MAX)),
                        trim((string) ($operation['parent_path'] ?? '')),
                        is_array($operation['items'] ?? null) ? array_values($operation['items']) : array(),
                        (bool) ($operation['ordered'] ?? false)
                    );
                    break;
                case 'content.insert_text':
                    $state['content'] = $this->blocks->insertTextBlock(
                        (string) ($state['content'] ?? ''),
                        max(0, (int) ($operation['index'] ?? PHP_INT_MAX)),
                        $this->requiredString($operation, 'type'),
                        (string) ($operation['text'] ?? ''),
                        (int) ($operation['level'] ?? 2),
                        trim((string) ($operation['parent_path'] ?? ''))
                    );
                    break;
                case 'content.update_text':
                    $state['content'] = $this->blocks->updateText((string) ($state['content'] ?? ''), $this->requiredString($operation, 'path'), (string) ($operation['text'] ?? ''));
                    break;
                case 'content.insert_button':
                    $state['content'] = $this->blocks->insertButton(
                        (string) ($state['content'] ?? ''),
                        max(0, (int) ($operation['index'] ?? PHP_INT_MAX)),
                        trim((string) ($operation['parent_path'] ?? '')),
                        array(
                            'text' => $this->requiredString($operation, 'text'),
                            'url' => (string) ($operation['url'] ?? ''),
                            'title' => (string) ($operation['title'] ?? ''),
                            'link_target' => (string) ($operation['link_target'] ?? ''),
                            'rel' => (string) ($operation['rel'] ?? ''),
                            'tag_name' => (string) ($operation['tag_name'] ?? ''),
                            'type' => (string) ($operation['type'] ?? ''),
                        )
                    );
                    break;
                case 'content.update_button':
                    $changes = array();
                    foreach (array('text','url','title','link_target','rel','tag_name','type') as $field) {
                        if (array_key_exists($field, $operation)) { $changes[$field] = $operation[$field]; }
                    }
                    if ($changes === array()) {
                        throw new \InvalidArgumentException('content.update_button requires at least one semantic button field to change.');
                    }
                    $state['content'] = $this->blocks->updateButton((string) ($state['content'] ?? ''), $this->requiredString($operation, 'path'), $changes);
                    break;
                case 'content.insert_image':
                    $state['content'] = $this->blocks->insertImage(
                        (string) ($state['content'] ?? ''),
                        max(0, (int) ($operation['index'] ?? PHP_INT_MAX)),
                        trim((string) ($operation['parent_path'] ?? '')),
                        $this->imageOperationState($operation, true)
                    );
                    break;
                case 'content.update_image':
                    $changes = $this->imageOperationState($operation, false);
                    unset($changes['path']);
                    if ($changes === array()) { throw new \InvalidArgumentException('content.update_image requires at least one semantic image field to change.'); }
                    $state['content'] = $this->blocks->updateImage((string) ($state['content'] ?? ''), $this->requiredString($operation, 'path'), $changes);
                    break;
                case 'presentation.set_template':
                    if ('content' !== (string) ($state['kind'] ?? '')) {
                        throw new \InvalidArgumentException('Template assignment only applies to content entities.');
                    }
                    $template = $this->requiredString($operation, 'template');
                    $this->repository->assertTemplateAssignmentAvailable($state, $template);
                    $state['template'] = $template;
                    break;
                case 'presentation.clear_template':
                    if ('content' !== (string) ($state['kind'] ?? '')) {
                        throw new \InvalidArgumentException('Template assignment only applies to content entities.');
                    }
                    $state['template'] = '';
                    break;
                case 'styles.update':
                    $state['global_styles'] = $this->updatedGlobalStyles((array) ($state['global_styles'] ?? array()), $operation);
                    break;
                default:
                    throw new \InvalidArgumentException(sprintf('Presentation operation [%s] is not executable.', $action));
            }
        }

        if (!is_array($state)) {
            throw new \LogicException('Presentation preview produced no state.');
        }
        if (is_array($before)
            && in_array((string) ($state['kind'] ?? ''), array('template', 'template_part'), true)
            && empty($state['_delete'])
            && 'custom' !== (string) ($before['source'] ?? '')
            && (string) ($before['content'] ?? '') !== (string) ($state['content'] ?? '')) {
            $state['origin'] = (string) (($before['origin'] ?? '') ?: ($before['source'] ?? ''));
            $state['source'] = 'custom';
        }
        $state['checksum'] = $this->repository->checksumForState($state);
        return $state;
    }

    /** @param array<string,mixed> $operation @return array<string,mixed> */
    private function createState(array $operation): array
    {
        $kind = strtolower(trim((string) ($operation['kind'] ?? '')));
        if (!in_array($kind, array('pattern', 'navigation', 'template', 'template_part'), true)) {
            throw new \InvalidArgumentException('object.create kind must be pattern, navigation, template, or template_part.');
        }
        $title = $this->requiredString($operation, 'title');
        $slug = $this->normalizedSlug($title, (string) ($operation['slug'] ?? ''));
        if (array_key_exists('content', $operation) && '' !== trim((string) $operation['content'])) {
            throw new \InvalidArgumentException('object.create starts empty. Populate it through explicit codec-backed operations such as content.insert_text or by copying an intact root-safe block with blocks.insert_copy.');
        }
        $content = '';
        $postType = $this->repository->postTypeForKind($kind);
        $state = array(
            'ref' => '',
            'kind' => $kind,
            'post_id' => 0,
            'post_type' => $postType,
            'title' => $title,
            'slug' => $slug,
            'status' => 'publish',
            'content' => $content,
            'template' => '',
            'global_styles' => array(),
            'route_url' => '',
            'theme' => function_exists('get_stylesheet') ? (string) get_stylesheet() : '',
            'area' => trim((string) ($operation['area'] ?? '')),
            'sync_status' => 'pattern' === $kind ? trim((string) ($operation['sync_status'] ?? '')) : '',
            'template_id' => '',
            'source' => in_array($kind, array('template', 'template_part'), true) ? 'custom' : '',
            'origin' => '',
            'has_theme_file' => false,
            '_create' => true,
        );
        if ('template_part' === $kind && '' === $state['area']) {
            throw new \InvalidArgumentException('Creating a template_part requires area.');
        }
        if ('pattern' === $kind) {
            if ('' === $state['sync_status']) {
                $state['sync_status'] = 'synced';
            }
            if (!in_array($state['sync_status'], array('synced', 'unsynced'), true)) {
                throw new \InvalidArgumentException('Pattern sync_status must be synced or unsynced.');
            }
        } elseif ('' !== trim((string) ($operation['sync_status'] ?? ''))) {
            throw new \InvalidArgumentException('sync_status is only valid when creating a pattern.');
        }
        if (in_array($kind, array('template', 'template_part'), true)) {
            $state['template_id'] = (string) $state['theme'] . '//' . $slug;
            $state['ref'] = $this->repository->refForUnifiedTemplate($kind, (string) $state['template_id']);
        }
        $this->repository->assertObjectSlugAvailable($kind, $slug, (string) ($state['theme'] ?? ''));
        return $state;
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $operation @return array<string,mixed> */
    private function duplicateState(array $state, array $operation): array
    {
        if (!in_array((string) ($state['kind'] ?? ''), array('pattern', 'navigation', 'template', 'template_part'), true)) {
            throw new \InvalidArgumentException('object.duplicate only supports Presentation-owned patterns, navigation, templates, and template parts.');
        }
        $copy = $state;
        $copy['ref'] = '';
        $copy['post_id'] = 0;
        $copy['template_id'] = '';
        if (in_array((string) ($copy['kind'] ?? ''), array('template', 'template_part'), true)) {
            $copy['source'] = 'custom';
            $copy['origin'] = '';
            $copy['has_theme_file'] = false;
        }
        $copy['title'] = trim((string) ($operation['title'] ?? '')) ?: ((string) ($state['title'] ?? '') . ' Copy');
        $copy['slug'] = $this->normalizedSlug((string) $copy['title'], (string) ($operation['slug'] ?? ''));
        if (in_array((string) ($copy['kind'] ?? ''), array('template', 'template_part'), true)) {
            $copy['template_id'] = (string) ($copy['theme'] ?? '') . '//' . (string) $copy['slug'];
            $copy['ref'] = $this->repository->refForUnifiedTemplate((string) $copy['kind'], (string) $copy['template_id']);
        }
        $this->repository->assertObjectSlugAvailable((string) $copy['kind'], (string) $copy['slug'], (string) ($copy['theme'] ?? ''));
        $copy['_duplicate'] = true;
        unset($copy['_create'], $copy['_delete']);
        return $copy;
    }

    private function assertActionTarget(string $action, string $kind): void
    {
        $spec = OperationCatalog::all()[$action] ?? null;
        $targets = is_array($spec) ? array_values((array) ($spec['targets'] ?? array())) : array();
        if ($targets !== array() && !in_array($kind, $targets, true)) {
            throw new \InvalidArgumentException(sprintf('Operation [%s] does not support target kind [%s].', $action, $kind));
        }
    }

    /** @param array<string,mixed> $styles @param array<string,mixed> $operation @return array<string,mixed> */
    private function updatedGlobalStyles(array $styles, array $operation): array
    {
        $hasChange = false;
        foreach (array('settings', 'styles') as $key) {
            if (!array_key_exists($key, $operation)) {
                continue;
            }
            if (!is_array($operation[$key])) {
                throw new \InvalidArgumentException(sprintf('styles.update %s must be an object.', $key));
            }
            $this->assertSafeTree($operation[$key], 0);
            if ($this->containsRecursiveKey($operation[$key], 'css')) {
                throw new \InvalidArgumentException('styles.update does not accept custom CSS. Source CSS belongs in Themes/Plugins; native custom-CSS support requires its own validated semantic path.');
            }
            $current = is_array($styles[$key] ?? null) ? $styles[$key] : array();
            $styles[$key] = $this->mergeRecursive($current, $operation[$key]);
            $hasChange = true;
        }
        if (!$hasChange) {
            throw new \InvalidArgumentException('styles.update requires settings and/or styles.');
        }
        if (!class_exists('WP_Theme_JSON') || !method_exists('WP_Theme_JSON', 'remove_insecure_properties')) {
            throw new \RuntimeException('WordPress Theme JSON sanitization is unavailable.');
        }
        $styles['version'] = defined('WP_Theme_JSON::LATEST_SCHEMA') ? (int) constant('WP_Theme_JSON::LATEST_SCHEMA') : (isset($styles['version']) && is_numeric($styles['version']) ? (int) $styles['version'] : 3);
        unset($styles['isGlobalStylesUserThemeJSON']);
        $styles = \WP_Theme_JSON::remove_insecure_properties($styles, 'custom');
        if (!is_array($styles)) {
            throw new \RuntimeException('WordPress rejected the proposed Global Styles structure.');
        }
        $styles['version'] = defined('WP_Theme_JSON::LATEST_SCHEMA') ? (int) constant('WP_Theme_JSON::LATEST_SCHEMA') : (int) ($styles['version'] ?? 3);
        $styles['isGlobalStylesUserThemeJSON'] = true;
        return $styles;
    }

    /** @param array<string,mixed> $base @param array<string,mixed> $changes @return array<string,mixed> */
    private function mergeRecursive(array $base, array $changes): array
    {
        foreach ($changes as $key => $value) {
            if (is_array($value) && is_array($base[$key] ?? null) && !$this->isList($value) && !$this->isList((array) $base[$key])) {
                $base[$key] = $this->mergeRecursive((array) $base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    /** @param array<mixed> $value */
    private function containsRecursiveKey(array $value, string $needle): bool
    {
        foreach ($value as $key => $item) {
            if (strtolower((string) $key) === strtolower($needle)) {
                return true;
            }
            if (is_array($item) && $this->containsRecursiveKey($item, $needle)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<mixed> $value */
    private function assertSafeTree(array $value, int $depth): void
    {
        if ($depth > 16) {
            throw new \InvalidArgumentException('Presentation JSON trees may not exceed 16 levels.');
        }
        if (count($value) > 1000) {
            throw new \InvalidArgumentException('Presentation JSON objects may not contain more than 1000 entries at one level.');
        }
        foreach ($value as $item) {
            if (is_array($item)) {
                $this->assertSafeTree($item, $depth + 1);
                continue;
            }
            if (!is_null($item) && !is_scalar($item)) {
                throw new \InvalidArgumentException('Presentation JSON values must be scalar, null, array, or object-like arrays.');
            }
        }
    }

    /** @param array<string,mixed> $operation @return array<string,mixed> */
    private function coverOperationState(array $operation): array
    {
        $state = array();
        foreach (array('url','alt','overlay_color','custom_overlay_color','gradient','custom_gradient','content_position','min_height_unit','tag_name','size_slug','text_color','font_size','align','anchor','class_name') as $field) {
            if (array_key_exists($field, $operation)) { $state[$field] = $operation[$field]; }
        }
        foreach (array('id','dim_ratio','min_height') as $field) { if (array_key_exists($field, $operation)) { $state[$field] = $operation[$field]; } }
        foreach (array('use_featured_image','has_parallax','is_repeated','is_dark') as $field) { if (array_key_exists($field, $operation)) { $state[$field] = (bool) $operation[$field]; } }
        $hasX = array_key_exists('focal_x', $operation); $hasY = array_key_exists('focal_y', $operation);
        if ($hasX xor $hasY) { throw new \InvalidArgumentException('Cover focal_x and focal_y must be supplied together.'); }
        if ($hasX && $hasY) { $state['focal_point'] = array('x' => (float) $operation['focal_x'], 'y' => (float) $operation['focal_y']); }
        return $state;
    }

    /** @param array<string,mixed> $operation @return array<string,mixed> */
    private function imageOperationState(array $operation, bool $requireUrl): array
    {
        $state = array();
        foreach (array('url','alt','caption','title','href','rel','link_class','link_target','width','height','aspect_ratio','scale','size_slug','link_destination','align') as $field) {
            if (array_key_exists($field, $operation)) { $state[$field] = $operation[$field]; }
        }
        if (array_key_exists('id', $operation)) { $state['id'] = (int) $operation['id']; }
        if (array_key_exists('is_decorative', $operation)) { $state['is_decorative'] = (bool) $operation['is_decorative']; }
        $hasX = array_key_exists('focal_x', $operation);
        $hasY = array_key_exists('focal_y', $operation);
        if ($hasX xor $hasY) { throw new \InvalidArgumentException('Image focal_x and focal_y must be supplied together.'); }
        if ($hasX && $hasY) { $state['focal_point'] = array('x' => (float) $operation['focal_x'], 'y' => (float) $operation['focal_y']); }
        if ($requireUrl && '' === trim((string) ($state['url'] ?? ''))) { throw new \InvalidArgumentException('content.insert_image requires url.'); }
        return $state;
    }

    /** @param array<string,mixed> $operation @return array<string,mixed> */
    private function queryOperationState(array $operation): array
    {
        $state = array();
        foreach (array('per_page','post_type','order','order_by','offset','search','inherit','sticky') as $field) {
            if (array_key_exists($field, $operation)) { $state[$field] = $operation[$field]; }
        }
        return $state;
    }

    /** @param array<string,mixed> $operation @return array<string,mixed> */
    private function navigationOperationState(array $operation): array
    {
        $state = array();
        foreach (array('label','url','rel','title','description','opens_in_new_tab') as $field) {
            if (array_key_exists($field, $operation)) { $state[$field] = $operation[$field]; }
        }
        return $state;
    }

    private function normalizedSlug(string $title, string $requested): string
    {
        $slug = trim($requested);
        if ('' === $slug && function_exists('sanitize_title')) {
            $slug = (string) sanitize_title($title);
        }
        if ('' === $slug) {
            $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
        }
        if ('' === $slug || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug)) {
            throw new \InvalidArgumentException('Presentation object slug must resolve to a non-empty lowercase WordPress-safe slug.');
        }
        return $slug;
    }

    private function requiredString(array $operation, string $field): string
    {
        if (!array_key_exists($field, $operation)) {
            throw new \InvalidArgumentException(sprintf('%s is required.', $field));
        }
        $value = (string) $operation[$field];
        if ('' === trim($value) && !in_array($field, array('content', 'text'), true)) {
            throw new \InvalidArgumentException(sprintf('%s cannot be empty.', $field));
        }
        return $value;
    }

    private function parentPath(string $path): string
    {
        $segments = explode('.', trim($path));
        array_pop($segments);
        return implode('.', $segments);
    }

    /** @param array<mixed> $value */
    private function isList(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }
}
