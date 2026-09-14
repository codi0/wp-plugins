<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation;

final class PresentationRepository
{
    private const PRESENTATION_POST_TYPES = array(
        'wp_template' => 'template',
        'wp_template_part' => 'template_part',
        'wp_navigation' => 'navigation',
        'wp_block' => 'pattern',
        'wp_global_styles' => 'global_styles',
    );

    public function __construct(
        private ?RefCodec $refs = null,
        private ?BlockTemplateGateway $templates = null,
        private ?GlobalStylesGateway $globalStyles = null
    ) {
        $this->refs ??= new RefCodec();
        $this->templates ??= new BlockTemplateGateway();
        $this->globalStyles ??= new GlobalStylesGateway();
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function find(array $input): array
    {
        $limit = max(1, min(50, (int) ($input['limit'] ?? 20)));
        $route = trim((string) ($input['route'] ?? ''));
        if ('' !== $route) {
            $postId = $this->postIdForRoute($route);
            if ($postId < 1) {
                return array('items' => array(), 'returned' => 0);
            }
            $post = get_post($postId);
            if (!$this->isPostObject($post) || !$this->isAllowedPostType((string) $post->post_type)) {
                return array('items' => array(), 'returned' => 0);
            }
            $snapshot = $this->snapshotFromPost($post);
            if (!$this->canInspectSnapshot($snapshot)) {
                return array('items' => array(), 'returned' => 0);
            }
            $snapshot['checksum'] = $this->checksumForState($snapshot);
            return array('items' => array($this->surfaceFromSnapshot($snapshot, false)), 'returned' => 1);
        }

        $postTypes = $this->postTypesForFind((array) ($input['kinds'] ?? array()), trim((string) ($input['post_type'] ?? '')));
        $search = trim((string) ($input['query'] ?? ''));
        $items = array();
        if ($postTypes !== array() && function_exists('get_posts')) {
            $args = array(
                'post_type' => $postTypes,
                'post_status' => array('publish', 'draft', 'pending', 'private', 'future', 'inherit'),
                'posts_per_page' => $limit,
                'orderby' => 'modified',
                'order' => 'DESC',
                'suppress_filters' => false,
            );
            if ('' !== $search) {
                $args['s'] = $search;
            }
            foreach ((array) get_posts($args) as $post) {
                if (!$this->isPostObject($post) || !$this->isAllowedPostType((string) $post->post_type)) {
                    continue;
                }
                if ($this->isThemeScopedType((string) $post->post_type) && !$this->belongsToCurrentTheme((int) $post->ID)) {
                    continue;
                }
                $snapshot = $this->snapshotFromPost($post);
                if (!$this->canInspectSnapshot($snapshot)) {
                    continue;
                }
                $snapshot['checksum'] = $this->checksumForState($snapshot);
                $items[] = $this->surfaceFromSnapshot($snapshot, false);
                if (count($items) >= $limit) {
                    break;
                }
            }
        }

        foreach ($this->templateSurfacesForFind((array) ($input['kinds'] ?? array()), trim((string) ($input['post_type'] ?? '')), $search, $limit - count($items)) as $surface) {
            $items[] = $surface;
            if (count($items) >= $limit) {
                break;
            }
        }

        return array('items' => $items, 'returned' => count($items));
    }

    /** @return array<string,mixed> */
    public function inspect(string $ref): array
    {
        $snapshot = $this->snapshotByRef($ref);
        return $this->surfaceFromSnapshot($snapshot, true);
    }

    /** @return array<string,mixed> */
    public function snapshotByRef(string $ref): array
    {
        $decoded = $this->refs->decode($ref);
        if ('template' === (string) ($decoded['type'] ?? '')) {
            $template = $this->templates->get((string) $decoded['template_type'], (string) $decoded['template_id']);
            $snapshot = $this->snapshotFromTemplate($template);
            $snapshot['checksum'] = $this->checksumForState($snapshot);
            return $snapshot;
        }

        $post = function_exists('get_post') ? get_post((int) $decoded['post_id']) : null;
        if (!$this->isPostObject($post) || (string) $post->post_type !== (string) $decoded['post_type']) {
            throw new \InvalidArgumentException('Presentation target no longer exists.');
        }
        if (!$this->isAllowedPostType((string) $post->post_type)) {
            throw new \InvalidArgumentException('The referenced WordPress object is not Presentation-owned or composition-editable.');
        }
        if ($this->isThemeScopedType((string) $post->post_type) && !$this->belongsToCurrentTheme((int) $post->ID)) {
            throw new \InvalidArgumentException('The referenced presentation object belongs to a different theme.');
        }

        $snapshot = $this->snapshotFromPost($post);
        $snapshot['checksum'] = $this->checksumForState($snapshot);
        return $snapshot;
    }

    /** @param array<string,mixed> $state */
    public function checksumForState(array $state): string
    {
        $kind = (string) ($state['kind'] ?? '');
        $payload = array(
            'kind' => $kind,
            'post_type' => (string) ($state['post_type'] ?? ''),
            'post_id' => in_array($kind, array('template', 'template_part'), true) ? 0 : (int) ($state['post_id'] ?? 0),
            'content' => 'global_styles' === $kind ? '' : (string) ($state['content'] ?? ''),
            'global_styles' => 'global_styles' === $kind ? ($state['global_styles'] ?? array()) : array(),
            'template' => 'content' === $kind ? (string) ($state['template'] ?? '') : '',
            'template_id' => in_array($kind, array('template', 'template_part'), true) ? (string) ($state['template_id'] ?? '') : '',
            'source' => in_array($kind, array('template', 'template_part'), true) ? (string) ($state['source'] ?? '') : '',
            'origin' => in_array($kind, array('template', 'template_part'), true) ? (string) ($state['origin'] ?? '') : '',
        );
        if ('content' !== $kind && 'global_styles' !== $kind) {
            $payload['title'] = (string) ($state['title'] ?? '');
            $payload['slug'] = (string) ($state['slug'] ?? '');
            $payload['theme'] = (string) ($state['theme'] ?? '');
            $payload['area'] = (string) ($state['area'] ?? '');
            $payload['sync_status'] = (string) ($state['sync_status'] ?? '');
        }
        $payload = $this->sortRecursive($payload);
        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string,mixed>|null $before @param array<string,mixed> $after @return array<string,mixed>|null */
    public function persist(?array $before, array $after): ?array
    {
        if (!empty($after['_delete'])) {
            if (!is_array($before)) {
                throw new \InvalidArgumentException('Cannot delete a missing Presentation target.');
            }
            $kind = (string) ($before['kind'] ?? '');
            if (in_array($kind, array('template', 'template_part'), true)) {
                $templateType = 'template_part' === $kind ? 'wp_template_part' : 'wp_template';
                $fallback = $this->templates->deleteCustomization($templateType, (string) ($before['template_id'] ?? ''));
                if (!is_object($fallback)) {
                    return null;
                }
                $snapshot = $this->snapshotFromTemplate($fallback);
                $snapshot['checksum'] = $this->checksumForState($snapshot);
                return $snapshot;
            }
            $this->deleteSnapshot($before);
            return null;
        }
        if (!empty($after['_create']) || !empty($after['_duplicate'])) {
            return $this->createFromState($after);
        }
        if (!is_array($before)) {
            throw new \InvalidArgumentException('A native target is required for this Presentation mutation.');
        }

        $kind = (string) ($after['kind'] ?? '');
        if (in_array($kind, array('template', 'template_part'), true)) {
            $templateType = 'template_part' === $kind ? 'wp_template_part' : 'wp_template';
            $templateId = trim((string) ($before['template_id'] ?? ''));
            if ('' === $templateId) {
                throw new \InvalidArgumentException('Presentation template target has no unified WordPress template ID.');
            }
            $template = $this->templates->update($templateType, $templateId, array(
                'content' => (string) ($after['content'] ?? ''),
            ));
            $snapshot = $this->snapshotFromTemplate($template);
            $snapshot['checksum'] = $this->checksumForState($snapshot);
            return $snapshot;
        }

        $postId = (int) ($before['post_id'] ?? 0);
        if ($postId < 1) {
            throw new \InvalidArgumentException('Presentation target has no native post ID.');
        }
        if ('global_styles' === $kind) {
            $post = $this->globalStyles->update($postId, (array) ($after['global_styles'] ?? array()));
            $snapshot = $this->snapshotFromPost($post);
            $snapshot['checksum'] = $this->checksumForState($snapshot);
            return $snapshot;
        }
        $payload = array('ID' => $postId, 'post_content' => (string) ($after['content'] ?? ''));
        $result = wp_update_post($payload, true);
        if (function_exists('is_wp_error') && is_wp_error($result)) {
            throw new \RuntimeException('WordPress rejected the Presentation update: ' . $result->get_error_message());
        }
        if ((int) $result < 1) {
            throw new \RuntimeException('WordPress did not persist the Presentation update.');
        }

        if ('content' === $kind && (string) ($before['template'] ?? '') !== (string) ($after['template'] ?? '')) {
            $template = trim((string) ($after['template'] ?? ''));
            if ('' === $template || 'default' === $template) {
                if (function_exists('delete_post_meta')) {
                    delete_post_meta($postId, '_wp_page_template');
                }
            } elseif (function_exists('update_post_meta')) {
                update_post_meta($postId, '_wp_page_template', $template);
            }
        }

        return $this->snapshotByRef((string) $before['ref']);
    }

    /** @param array<string,mixed> $snapshot */
    public function deleteSnapshot(array $snapshot): void
    {
        $kind = (string) ($snapshot['kind'] ?? '');
        if (in_array($kind, array('template', 'template_part'), true)) {
            $templateType = 'template_part' === $kind ? 'wp_template_part' : 'wp_template';
            $templateId = trim((string) ($snapshot['template_id'] ?? ''));
            $this->templates->deleteCustomization($templateType, $templateId);
            return;
        }
        $postId = (int) ($snapshot['post_id'] ?? 0);
        if ($postId < 1 || !function_exists('wp_delete_post')) {
            throw new \RuntimeException('WordPress post deletion is unavailable for this Presentation object.');
        }
        $deleted = wp_delete_post($postId, true);
        if (!$deleted || (function_exists('is_wp_error') && is_wp_error($deleted))) {
            throw new \RuntimeException('WordPress failed to delete the Presentation object.');
        }
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public function restoreSnapshot(array $snapshot, bool $requireMissing = false): array
    {
        $kind = (string) ($snapshot['kind'] ?? '');
        if (in_array($kind, array('template', 'template_part'), true)) {
            return $this->restoreTemplateSnapshot($snapshot);
        }
        $postId = (int) ($snapshot['post_id'] ?? 0);
        $existing = $postId > 0 && function_exists('get_post') ? get_post($postId) : null;
        if ($requireMissing && $this->isPostObject($existing)) {
            throw new \RuntimeException('Presentation rollback cannot restore the deleted object because its original native post ID is no longer vacant.');
        }
        if ('content' === (string) ($snapshot['kind'] ?? '')) {
            if (!$this->isPostObject($existing)) {
                throw new \RuntimeException('Presentation cannot recreate a Content-owned post during rollback. Restore the content entity through Content first.');
            }
            $result = wp_update_post(array('ID' => $postId, 'post_content' => (string) ($snapshot['content'] ?? '')), true);
            if (function_exists('is_wp_error') && is_wp_error($result)) {
                throw new \RuntimeException('WordPress failed to restore Presentation-owned block content: ' . $result->get_error_message());
            }
            $template = trim((string) ($snapshot['template'] ?? ''));
            if ('' === $template || 'default' === $template) {
                if (function_exists('delete_post_meta')) {
                    delete_post_meta($postId, '_wp_page_template');
                }
            } elseif (function_exists('update_post_meta')) {
                update_post_meta($postId, '_wp_page_template', $template);
            }
            return $this->snapshotByRef((string) $snapshot['ref']);
        }
        if ('global_styles' === (string) ($snapshot['kind'] ?? '')) {
            if (!$this->isPostObject($existing)) {
                throw new \RuntimeException('Presentation cannot recreate a missing native Global Styles record during rollback.');
            }
            $post = $this->globalStyles->update($postId, (array) ($snapshot['global_styles'] ?? array()));
            $restored = $this->snapshotFromPost($post);
            $restored['checksum'] = $this->checksumForState($restored);
            return $restored;
        }
        $content = (string) ($snapshot['content'] ?? '');
        $payload = array(
            'post_type' => (string) ($snapshot['post_type'] ?? 'post'),
            'post_status' => (string) ($snapshot['status'] ?? 'publish'),
            'post_title' => (string) ($snapshot['title'] ?? ''),
            'post_name' => (string) ($snapshot['slug'] ?? ''),
            'post_content' => $content,
        );
        if ($this->isPostObject($existing)) {
            $payload['ID'] = $postId;
            $result = wp_update_post($payload, true);
        } else {
            if ($postId > 0) {
                $payload['import_id'] = $postId;
            }
            $result = wp_insert_post($payload, true);
        }
        if (function_exists('is_wp_error') && is_wp_error($result)) {
            throw new \RuntimeException('WordPress failed to restore the Presentation snapshot: ' . $result->get_error_message());
        }
        $restoredId = (int) $result;
        if ($restoredId < 1) {
            throw new \RuntimeException('WordPress failed to restore the Presentation snapshot.');
        }
        if (!$this->isPostObject($existing) && $postId > 0 && $restoredId !== $postId) {
            if (function_exists('wp_delete_post')) {
                wp_delete_post($restoredId, true);
            }
            throw new \RuntimeException('WordPress could not restore the Presentation object with its original native post ID.');
        }
        $this->restoreNativeRelationships($restoredId, $snapshot);
        return $this->snapshotByRef($this->refs->encode((string) $snapshot['post_type'], $restoredId));
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function createFromState(array $state): array
    {
        $kind = (string) ($state['kind'] ?? '');
        $postType = $this->postTypeForKind($kind);
        if ('' === $postType || 'wp_global_styles' === $postType || 'content' === $kind) {
            throw new \InvalidArgumentException('object.create/object.duplicate only supports WordPress-managed patterns, navigation, templates, and template parts.');
        }
        $title = trim((string) ($state['title'] ?? ''));
        if ('' === $title) {
            throw new \InvalidArgumentException('A title is required when creating a Presentation object.');
        }
        $slug = trim((string) ($state['slug'] ?? ''));
        if ('' === $slug && function_exists('sanitize_title')) {
            $slug = (string) sanitize_title($title);
        }
        if (in_array($kind, array('template', 'template_part'), true)) {
            $templateType = 'template_part' === $kind ? 'wp_template_part' : 'wp_template';
            $template = $this->templates->create($templateType, array(
                'title' => $title,
                'slug' => $slug,
                'content' => (string) ($state['content'] ?? ''),
                'theme' => (string) ($state['theme'] ?? ''),
                'area' => (string) ($state['area'] ?? ''),
            ));
            $snapshot = $this->snapshotFromTemplate($template);
            $snapshot['checksum'] = $this->checksumForState($snapshot);
            return $snapshot;
        }
        $payload = array(
            'post_type' => $postType,
            'post_status' => 'publish',
            'post_title' => $title,
            'post_name' => $slug,
            'post_content' => (string) ($state['content'] ?? ''),
        );
        $result = wp_insert_post($payload, true);
        if (function_exists('is_wp_error') && is_wp_error($result)) {
            throw new \RuntimeException('WordPress rejected Presentation object creation: ' . $result->get_error_message());
        }
        $postId = (int) $result;
        if ($postId < 1) {
            throw new \RuntimeException('WordPress failed to create the Presentation object.');
        }
        $this->restoreNativeRelationships($postId, $state);
        return $this->snapshotByRef($this->refs->encode($postType, $postId));
    }

    /** @param array<string,mixed> $snapshot */
    private function restoreNativeRelationships(int $postId, array $snapshot): void
    {
        $postType = (string) ($snapshot['post_type'] ?? '');
        if ('wp_block' === $postType) {
            $sync = trim((string) ($snapshot['sync_status'] ?? ''));
            if ('' === $sync || 'synced' === $sync) {
                if (function_exists('delete_post_meta')) {
                    delete_post_meta($postId, 'wp_pattern_sync_status');
                } elseif (function_exists('update_post_meta')) {
                    update_post_meta($postId, 'wp_pattern_sync_status', '');
                }
            } elseif (in_array($sync, array('unsynced', 'partial'), true)) {
                if (!function_exists('update_post_meta')) {
                    throw new \RuntimeException('WordPress pattern sync metadata is unavailable.');
                }
                update_post_meta($postId, 'wp_pattern_sync_status', $sync);
            } else {
                throw new \InvalidArgumentException('Unsupported WordPress pattern sync status.');
            }
        }
        if ('content' === (string) ($snapshot['kind'] ?? '') && function_exists('update_post_meta')) {
            $template = trim((string) ($snapshot['template'] ?? ''));
            if ('' !== $template && 'default' !== $template) {
                update_post_meta($postId, '_wp_page_template', $template);
            }
        }
    }

    /** @return array<string,mixed> */
    public function surfaceFromSnapshot(array $snapshot, bool $includeNative = false): array
    {
        $surface = array(
            'ref' => (string) ($snapshot['ref'] ?? ''),
            'kind' => (string) ($snapshot['kind'] ?? ''),
            'post_id' => (int) ($snapshot['post_id'] ?? 0),
            'post_type' => (string) ($snapshot['post_type'] ?? ''),
            'title' => (string) ($snapshot['title'] ?? ''),
            'slug' => (string) ($snapshot['slug'] ?? ''),
            'status' => (string) ($snapshot['status'] ?? ''),
            'route_url' => (string) ($snapshot['route_url'] ?? ''),
            'source_kind' => (string) (($snapshot['source'] ?? '') ?: 'db'),
            'original_source' => (string) (($snapshot['origin'] ?? '') ?: ($snapshot['source'] ?? 'db')),
            'customized' => in_array((string) ($snapshot['kind'] ?? ''), array('template', 'template_part'), true) && 'custom' === (string) ($snapshot['source'] ?? ''),
            'template_id' => (string) ($snapshot['template_id'] ?? ''),
            'theme' => (string) ($snapshot['theme'] ?? ''),
            'area' => (string) ($snapshot['area'] ?? ''),
            'checksum' => (string) ($snapshot['checksum'] ?? $this->checksumForState($snapshot)),
        );
        if ($includeNative) {
            $surface['content'] = (string) ($snapshot['content'] ?? '');
            $surface['template'] = (string) ($snapshot['template'] ?? '');
            $surface['global_styles'] = is_array($snapshot['global_styles'] ?? null) ? $snapshot['global_styles'] : array();
            $surface['sync_status'] = (string) ($snapshot['sync_status'] ?? '');
        }
        return $surface;
    }

    /** @param array<string,mixed> $snapshot */
    public function assertDeletedSnapshotIdentityAvailable(array $snapshot): void
    {
        if (in_array((string) ($snapshot['kind'] ?? ''), array('template', 'template_part'), true)) {
            return;
        }
        $postId = (int) ($snapshot['post_id'] ?? 0);
        if ($postId < 1) {
            throw new \RuntimeException('Deleted Presentation snapshot has no native post ID to restore.');
        }
        $existing = function_exists('get_post') ? get_post($postId) : null;
        if ($this->isPostObject($existing)) {
            throw new \RuntimeException('Presentation rollback refused because the deleted object\'s original native post ID is no longer vacant.');
        }
    }

    public function canInspect(): bool
    {
        return !function_exists('current_user_can') || current_user_can('edit_posts') || current_user_can('edit_theme_options');
    }

    /** @param array<string,mixed> $snapshot */
    public function canInspectSnapshot(array $snapshot): bool
    {
        if (!function_exists('current_user_can')) {
            return true;
        }
        $postType = (string) ($snapshot['post_type'] ?? '');
        if ('wp_global_styles' === $postType || in_array($postType, array('wp_template', 'wp_template_part', 'wp_navigation'), true)) {
            return current_user_can('edit_theme_options') || current_user_can('edit_posts');
        }
        $postId = (int) ($snapshot['post_id'] ?? 0);
        return $postId > 0 && (current_user_can('read_post', $postId) || current_user_can('edit_post', $postId));
    }

    /** @param array<string,mixed> $snapshot */
    public function canEditSnapshot(array $snapshot): bool
    {
        if (!function_exists('current_user_can')) {
            return true;
        }
        $postId = (int) ($snapshot['post_id'] ?? 0);
        $postType = (string) ($snapshot['post_type'] ?? '');
        if ('wp_global_styles' === $postType || in_array($postType, array('wp_template', 'wp_template_part', 'wp_navigation'), true)) {
            return current_user_can('edit_theme_options');
        }
        return $postId > 0 && current_user_can('edit_post', $postId);
    }

    /** @param array<string,mixed> $snapshot */
    public function canDeleteSnapshot(array $snapshot): bool
    {
        if (!function_exists('current_user_can')) {
            return true;
        }
        $postId = (int) ($snapshot['post_id'] ?? 0);
        $postType = (string) ($snapshot['post_type'] ?? '');
        if (in_array($postType, array('wp_template', 'wp_template_part', 'wp_navigation'), true)) {
            return current_user_can('edit_theme_options');
        }
        return $postId > 0 && current_user_can('delete_post', $postId);
    }

    public function canCreateKind(string $kind): bool
    {
        if (!function_exists('current_user_can')) {
            return true;
        }
        $postType = $this->postTypeForKind($kind);
        if (in_array($postType, array('wp_template', 'wp_template_part', 'wp_navigation'), true)) {
            return current_user_can('edit_theme_options');
        }
        if (function_exists('get_post_type_object')) {
            $object = get_post_type_object($postType);
            $capability = is_object($object) ? (string) ($object->cap->create_posts ?? '') : '';
            if ('' !== $capability) {
                return current_user_can($capability);
            }
        }
        return current_user_can('edit_posts');
    }

    /** @param array<string,mixed> $snapshot */
    public function assertTemplateAssignmentAvailable(array $snapshot, string $templateSlug): void
    {
        if ('content' !== (string) ($snapshot['kind'] ?? '')) {
            throw new \InvalidArgumentException('Template assignment only applies to Content-owned post entities.');
        }
        $templateSlug = trim($templateSlug);
        if ('' === $templateSlug || 'default' === $templateSlug) {
            throw new \InvalidArgumentException('Use presentation.clear_template to remove an explicit template assignment.');
        }
        $postType = (string) ($snapshot['post_type'] ?? '');
        $available = array();
        if (function_exists('get_block_templates')) {
            foreach ((array) get_block_templates(array('post_type' => $postType), 'wp_template') as $template) {
                if (is_object($template) && '' !== trim((string) ($template->slug ?? ''))) {
                    $available[] = (string) $template->slug;
                }
            }
        }
        if (function_exists('wp_get_theme')) {
            $theme = wp_get_theme();
            if (is_object($theme) && method_exists($theme, 'get_page_templates')) {
                $classic = $theme->get_page_templates((int) ($snapshot['post_id'] ?? 0), $postType);
                if (is_array($classic)) {
                    $available = array_merge($available, array_map('strval', array_keys($classic)));
                }
            }
        }
        $available = array_values(array_unique(array_filter(array_map('trim', $available))));
        if (!in_array($templateSlug, $available, true)) {
            throw new \InvalidArgumentException(sprintf('Template [%s] is not currently available to post type [%s].', $templateSlug, $postType));
        }
    }

    public function refForUnifiedTemplate(string $kind, string $templateId): string
    {
        return $this->refs->encodeTemplate('template_part' === $kind ? 'wp_template_part' : 'wp_template', $templateId);
    }

    public function assertObjectSlugAvailable(string $kind, string $slug, string $theme = ''): void
    {
        $kind = strtolower(trim($kind));
        $slug = trim($slug);
        if ('' === $slug) {
            throw new \InvalidArgumentException('Presentation object slug cannot be empty.');
        }
        if (in_array($kind, array('template', 'template_part'), true)) {
            $theme = trim($theme);
            if ('' === $theme && function_exists('get_stylesheet')) {
                $theme = (string) get_stylesheet();
            }
            if ('' === $theme) {
                throw new \RuntimeException('Cannot resolve the active theme for template identity.');
            }
            $templateType = 'template_part' === $kind ? 'wp_template_part' : 'wp_template';
            if (function_exists('get_block_template') && is_object(get_block_template($theme . '//' . $slug, $templateType))) {
                throw new \InvalidArgumentException(sprintf('A %s with slug [%s] already exists for the active theme.', $kind, $slug));
            }
            return;
        }
        $postType = $this->postTypeForKind($kind);
        if ('' === $postType) {
            throw new \InvalidArgumentException(sprintf('Presentation cannot create object kind [%s].', $kind));
        }
        if (function_exists('get_page_by_path')) {
            $output = defined('OBJECT') ? constant('OBJECT') : 'OBJECT';
            $existing = get_page_by_path($slug, $output, $postType);
            if (is_object($existing)) {
                throw new \InvalidArgumentException(sprintf('A %s with slug [%s] already exists.', $kind, $slug));
            }
        }
    }

    /** @param array<string,mixed> $attemptedAfter @return array<string,mixed>|null */
    public function locateCreatedSnapshot(array $attemptedAfter): ?array
    {
        $kind = (string) ($attemptedAfter['kind'] ?? '');
        if (in_array($kind, array('template', 'template_part'), true)) {
            $ref = trim((string) ($attemptedAfter['ref'] ?? ''));
            if ('' === $ref) {
                $templateId = trim((string) ($attemptedAfter['template_id'] ?? ''));
                if ('' !== $templateId) {
                    $ref = $this->refForUnifiedTemplate($kind, $templateId);
                }
            }
            if ('' !== $ref) {
                try {
                    return $this->snapshotByRef($ref);
                } catch (\Throwable) {
                    return null;
                }
            }
            return null;
        }

        $postType = (string) ($attemptedAfter['post_type'] ?? '');
        $slug = trim((string) ($attemptedAfter['slug'] ?? ''));
        if ('' === $postType || '' === $slug) {
            return null;
        }
        $post = null;
        if (function_exists('get_page_by_path')) {
            $output = defined('OBJECT') ? constant('OBJECT') : 'OBJECT';
            $candidate = get_page_by_path($slug, $output, $postType);
            $post = is_object($candidate) ? $candidate : null;
        }
        if (!is_object($post) && function_exists('get_posts')) {
            foreach ((array) get_posts(array(
                'post_type' => array($postType),
                'post_status' => array('publish', 'draft', 'pending', 'private', 'future', 'inherit'),
                'posts_per_page' => 50,
                'suppress_filters' => false,
            )) as $candidate) {
                if (is_object($candidate) && (string) ($candidate->post_name ?? '') === $slug) {
                    $post = $candidate;
                    break;
                }
            }
        }
        if (!$this->isPostObject($post) || (string) $post->post_type !== $postType) {
            return null;
        }
        $snapshot = $this->snapshotFromPost($post);
        $snapshot['checksum'] = $this->checksumForState($snapshot);
        return $snapshot;
    }

    public function kindForPostType(string $postType): string
    {
        return self::PRESENTATION_POST_TYPES[$postType] ?? 'content';
    }

    public function postTypeForKind(string $kind): string
    {
        $kind = strtolower(trim($kind));
        foreach (self::PRESENTATION_POST_TYPES as $postType => $mappedKind) {
            if ($kind === $mappedKind) {
                return $postType;
            }
        }
        return '';
    }

    public function isAllowedPostType(string $postType): bool
    {
        if (isset(self::PRESENTATION_POST_TYPES[$postType])) {
            return true;
        }
        if ('attachment' === $postType || '' === $postType) {
            return false;
        }
        if (function_exists('post_type_supports')) {
            return (bool) post_type_supports($postType, 'editor');
        }
        return in_array($postType, array('page', 'post'), true);
    }

    /** @param object $post @return array<string,mixed> */
    private function snapshotFromPost(object $post): array
    {
        $postType = (string) ($post->post_type ?? '');
        $kind = $this->kindForPostType($postType);
        $postId = (int) ($post->ID ?? 0);
        $syncStatus = '';
        if ('wp_block' === $postType && function_exists('get_post_meta')) {
            $syncStatus = trim((string) get_post_meta($postId, 'wp_pattern_sync_status', true));
            if ('' === $syncStatus) {
                $syncStatus = 'synced';
            }
        }
        $snapshot = array(
            'ref' => $this->refs->encode($postType, $postId),
            'kind' => $kind,
            'post_id' => $postId,
            'post_type' => $postType,
            'title' => (string) ($post->post_title ?? ''),
            'slug' => (string) ($post->post_name ?? ''),
            'status' => (string) ($post->post_status ?? ''),
            'content' => 'global_styles' === $kind ? '' : (string) ($post->post_content ?? ''),
            'template' => 'content' === $kind ? $this->templateForPost($postId) : '',
            'global_styles' => 'global_styles' === $kind ? $this->decodeGlobalStyles((string) ($post->post_content ?? '')) : array(),
            'route_url' => 'content' === $kind && function_exists('get_permalink') ? (string) (get_permalink($postId) ?: '') : '',
            'theme' => $this->taxonomyTermSlug($postId, 'wp_theme'),
            'area' => 'wp_template_part' === $postType ? $this->taxonomyTermSlug($postId, 'wp_template_part_area') : '',
            'sync_status' => $syncStatus,
        );
        return $snapshot;
    }

    /** @param object $post @return array<string,mixed> */
    private function surfaceFromPost(object $post): array
    {
        $snapshot = $this->snapshotFromPost($post);
        $snapshot['checksum'] = $this->checksumForState($snapshot);
        return $this->surfaceFromSnapshot($snapshot, false);
    }

    /** @param array<int,mixed> $kinds @return array<int,array<string,mixed>> */
    private function templateSurfacesForFind(array $kinds, string $requestedPostType, string $search, int $remaining): array
    {
        if ($remaining < 1) {
            return array();
        }
        $normalized = array_values(array_unique(array_filter(array_map(static fn ($kind): string => strtolower(trim((string) $kind)), $kinds))));
        $types = array();
        if ('' !== $requestedPostType) {
            if ('wp_template' === $requestedPostType) {
                $normalized = array('template');
            } elseif ('wp_template_part' === $requestedPostType) {
                $normalized = array('template_part');
            } else {
                return array();
            }
        }
        if ($normalized === array() || in_array('template', $normalized, true)) {
            $types[] = 'wp_template';
        }
        if ($normalized === array() || in_array('template_part', $normalized, true)) {
            $types[] = 'wp_template_part';
        }
        $items = array();
        foreach ($types as $templateType) {
            foreach ($this->templates->list($templateType) as $template) {
                $haystack = strtolower(trim((string) ($template->title ?? '') . ' ' . (string) ($template->slug ?? '') . ' ' . (string) ($template->description ?? '')));
                if ('' !== $search && !str_contains($haystack, strtolower($search))) {
                    continue;
                }
                $snapshot = $this->snapshotFromTemplate($template);
                if (!$this->canInspectSnapshot($snapshot)) {
                    continue;
                }
                $snapshot['checksum'] = $this->checksumForState($snapshot);
                $items[] = $this->surfaceFromSnapshot($snapshot, false);
                if (count($items) >= $remaining) {
                    return $items;
                }
            }
        }
        return $items;
    }

    /** @return array<string,mixed> */
    private function snapshotFromTemplate(object $template): array
    {
        $templateType = (string) ($template->type ?? '');
        $kind = 'wp_template_part' === $templateType ? 'template_part' : 'template';
        $templateId = trim((string) ($template->id ?? ''));
        if ('' === $templateId) {
            throw new \RuntimeException('WordPress returned a template without a unified ID.');
        }
        return array(
            'ref' => $this->refs->encodeTemplate($templateType, $templateId),
            'kind' => $kind,
            'post_id' => (int) ($template->wp_id ?? 0),
            'post_type' => $templateType,
            'template_id' => $templateId,
            'title' => (string) ($template->title ?? ''),
            'slug' => (string) ($template->slug ?? ''),
            'status' => (string) ($template->status ?? 'publish'),
            'content' => (string) ($template->content ?? ''),
            'template' => '',
            'global_styles' => array(),
            'route_url' => '',
            'theme' => (string) ($template->theme ?? ''),
            'area' => 'wp_template_part' === $templateType ? (string) ($template->area ?? '') : '',
            'sync_status' => '',
            'source' => (string) ($template->source ?? ''),
            'origin' => (string) ($template->origin ?? ''),
            'has_theme_file' => (bool) ($template->has_theme_file ?? false),
        );
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function restoreTemplateSnapshot(array $snapshot): array
    {
        $kind = (string) ($snapshot['kind'] ?? '');
        $templateType = 'template_part' === $kind ? 'wp_template_part' : 'wp_template';
        $templateId = trim((string) ($snapshot['template_id'] ?? ''));
        if ('' === $templateId) {
            throw new \RuntimeException('Cannot restore a template snapshot without its unified template ID.');
        }
        if ('custom' !== (string) ($snapshot['source'] ?? '')) {
            $current = $this->templates->get($templateType, $templateId);
            if ($this->templates->sourceIsCustom($current)) {
                $fallback = $this->templates->deleteCustomization($templateType, $templateId);
                if (!is_object($fallback)) {
                    throw new \RuntimeException('Template source disappeared while restoring the pre-customization state.');
                }
                $current = $fallback;
            }
            $restored = $this->snapshotFromTemplate($current);
            $restored['checksum'] = $this->checksumForState($restored);
            return $restored;
        }
        try {
            $template = $this->templates->update($templateType, $templateId, array('content' => (string) ($snapshot['content'] ?? ''), 'title' => (string) ($snapshot['title'] ?? ''), 'area' => (string) ($snapshot['area'] ?? '')));
        } catch (\InvalidArgumentException) {
            $template = $this->templates->create($templateType, array('slug' => (string) ($snapshot['slug'] ?? ''), 'content' => (string) ($snapshot['content'] ?? ''), 'title' => (string) ($snapshot['title'] ?? ''), 'theme' => (string) ($snapshot['theme'] ?? ''), 'area' => (string) ($snapshot['area'] ?? '')));
        }
        $restored = $this->snapshotFromTemplate($template);
        $restored['checksum'] = $this->checksumForState($restored);
        return $restored;
    }

    /** @param array<int,mixed> $kinds @return string[] */
    private function postTypesForFind(array $kinds, string $requestedPostType): array
    {
        if ('' !== $requestedPostType) {
            if (in_array($requestedPostType, array('wp_template', 'wp_template_part'), true)) {
                return array();
            }
            return $this->isAllowedPostType($requestedPostType) ? array($requestedPostType) : array();
        }
        $normalized = array_values(array_unique(array_filter(array_map(static fn ($kind): string => strtolower(trim((string) $kind)), $kinds))));
        $includeContent = $normalized === array() || in_array('content', $normalized, true);
        $types = array();
        if ($includeContent) {
            $types = array_merge($types, $this->editorPostTypes());
        }
        foreach ($normalized as $kind) {
            $postType = $this->postTypeForKind($kind);
            if ('' !== $postType && !in_array($postType, array('wp_template', 'wp_template_part'), true)) {
                $types[] = $postType;
            }
        }
        if ($normalized === array()) {
            $types = array_merge($types, array('wp_navigation', 'wp_block', 'wp_global_styles'));
        }
        return array_values(array_unique(array_filter($types, [$this, 'isAllowedPostType'])));
    }

    /** @return string[] */
    private function editorPostTypes(): array
    {
        $types = array('page', 'post');
        if (function_exists('get_post_types')) {
            $registered = get_post_types(array('show_ui' => true), 'names');
            if (is_array($registered)) {
                $types = array_keys($registered) === range(0, count($registered) - 1) ? array_values($registered) : array_keys($registered);
            }
        }
        return array_values(array_filter(array_unique(array_map('strval', $types)), function (string $postType): bool {
            return !isset(self::PRESENTATION_POST_TYPES[$postType]) && $this->isAllowedPostType($postType);
        }));
    }

    private function postIdForRoute(string $route): int
    {
        if (!function_exists('url_to_postid')) {
            return 0;
        }
        $url = $route;
        if (!preg_match('#^https?://#i', $route) && function_exists('home_url')) {
            $url = (string) home_url('/' . ltrim($route, '/'));
        }
        return (int) url_to_postid($url);
    }

    private function templateForPost(int $postId): string
    {
        if (function_exists('get_page_template_slug')) {
            $template = get_page_template_slug($postId);
            if (is_string($template)) {
                return $template;
            }
        }
        if (function_exists('get_post_meta')) {
            return (string) get_post_meta($postId, '_wp_page_template', true);
        }
        return '';
    }

    /** @return array<string,mixed> */
    private function decodeGlobalStyles(string $content): array
    {
        if ('' === trim($content)) {
            return array('version' => 3, 'isGlobalStylesUserThemeJSON' => true, 'settings' => array(), 'styles' => array());
        }
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('The native wp_global_styles record contains invalid JSON.');
        }
        return $decoded;
    }


    private function taxonomyTermSlug(int $postId, string $taxonomy): string
    {
        if (!function_exists('get_the_terms')) {
            return '';
        }
        $terms = get_the_terms($postId, $taxonomy);
        if (!is_array($terms) || $terms === array()) {
            return '';
        }
        $term = reset($terms);
        return is_object($term) ? (string) ($term->slug ?? $term->name ?? '') : '';
    }

    private function belongsToCurrentTheme(int $postId): bool
    {
        if (!function_exists('get_stylesheet')) {
            return true;
        }
        $theme = $this->taxonomyTermSlug($postId, 'wp_theme');
        return '' === $theme || $theme === (string) get_stylesheet();
    }

    private function isThemeScopedType(string $postType): bool
    {
        return in_array($postType, array('wp_template', 'wp_template_part', 'wp_global_styles'), true);
    }

    private function isPostObject(mixed $post): bool
    {
        return is_object($post) && isset($post->ID, $post->post_type);
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function sortRecursive(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursive($item);
            }
        }
        if (array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }
        return $value;
    }
}
