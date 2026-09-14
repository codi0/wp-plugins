<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Content;

final class ContentInspector
{
    private const MAX_PER_PAGE = 100;

    public function canInspect(): bool
    {
        return function_exists('current_user_can') && current_user_can('manage_options');
    }

    public function postTypesList(array $input = array()): array
    {
        $rows = array();
        foreach ((array) get_post_types(array(), 'objects') as $postType) {
            if (!is_object($postType)) {
                continue;
            }
            $name = (string) ($postType->name ?? '');
            $rows[] = array(
                'name' => $name,
                'label' => (string) ($postType->label ?? ''),
                'description' => (string) ($postType->description ?? ''),
                'public' => !empty($postType->public),
                'show_ui' => !empty($postType->show_ui),
                'show_in_rest' => !empty($postType->show_in_rest),
                'rest_base' => (string) ($postType->rest_base ?? ''),
                'rest_namespace' => (string) ($postType->rest_namespace ?? ''),
                'hierarchical' => !empty($postType->hierarchical),
                'has_archive' => !empty($postType->has_archive),
                'supports' => array_values(array_map('strval', array_keys((array) get_all_post_type_supports($name)))),
                'taxonomies' => array_values(array_map('strval', (array) get_object_taxonomies($name, 'names'))),
            );
        }

        return $this->filterAndPage($rows, $input, array('name', 'label', 'description'));
    }

    public function taxonomiesList(array $input = array()): array
    {
        $rows = array();
        foreach ((array) get_taxonomies(array(), 'objects') as $taxonomy) {
            if (!is_object($taxonomy)) {
                continue;
            }
            $rows[] = array(
                'name' => (string) ($taxonomy->name ?? ''),
                'label' => (string) ($taxonomy->label ?? ''),
                'description' => (string) ($taxonomy->description ?? ''),
                'public' => !empty($taxonomy->public),
                'show_ui' => !empty($taxonomy->show_ui),
                'show_in_rest' => !empty($taxonomy->show_in_rest),
                'rest_base' => (string) ($taxonomy->rest_base ?? ''),
                'rest_namespace' => (string) ($taxonomy->rest_namespace ?? ''),
                'hierarchical' => !empty($taxonomy->hierarchical),
                'object_types' => array_values(array_map('strval', (array) ($taxonomy->object_type ?? array()))),
            );
        }

        return $this->filterAndPage($rows, $input, array('name', 'label', 'description'));
    }

    public function termsList(array $input = array())
    {
        $taxonomy = trim((string) ($input['taxonomy'] ?? ''));
        $taxonomyObject = function_exists('get_taxonomy') ? get_taxonomy($taxonomy) : null;
        if (!is_object($taxonomyObject)) {
            return new \WP_Error('codi_mcp_taxonomy_not_found', 'The requested taxonomy is not registered.');
        }

        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($input['per_page'] ?? 50)));
        $args = array(
            'taxonomy' => $taxonomy,
            'hide_empty' => !empty($input['hide_empty']),
            'orderby' => 'name',
            'order' => 'ASC',
        );
        $search = trim((string) ($input['search'] ?? ''));
        if ($search !== '') {
            $args['search'] = $search;
        }
        if (array_key_exists('parent', $input)) {
            $parent = max(0, (int) $input['parent']);
            if ($parent > 0 && empty($taxonomyObject->hierarchical)) {
                return new \WP_Error('codi_mcp_parent_term_invalid', 'parent filtering is only valid for hierarchical taxonomies.');
            }
            $args['parent'] = $parent;
        }

        $countArgs = $args;
        $countArgs['fields'] = 'count';
        $countArgs['number'] = 0;
        $countArgs['offset'] = 0;
        $totalResult = get_terms($countArgs);
        if (is_wp_error($totalResult)) {
            return $totalResult;
        }
        $total = is_numeric($totalResult) ? (int) $totalResult : count((array) $totalResult);

        $args['number'] = $perPage;
        $args['offset'] = ($page - 1) * $perPage;
        $terms = get_terms($args);
        if (is_wp_error($terms)) {
            return $terms;
        }

        $rows = array();
        foreach (array_slice((array) $terms, 0, $perPage) as $term) {
            if (!is_object($term)) {
                continue;
            }
            $description = (string) ($term->description ?? '');
            $descriptionTruncated = strlen($description) > 2000;
            if ($descriptionTruncated) {
                $description = substr($description, 0, 2000);
            }
            $rows[] = array(
                'term_id' => (int) ($term->term_id ?? 0),
                'taxonomy' => (string) ($term->taxonomy ?? $taxonomy),
                'name' => (string) ($term->name ?? ''),
                'slug' => (string) ($term->slug ?? ''),
                'description' => $description,
                'description_truncated' => $descriptionTruncated,
                'parent' => (int) ($term->parent ?? 0),
                'count' => (int) ($term->count ?? 0),
            );
        }

        return array(
            'items' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'returned' => count($rows),
        );
    }

    public function authorsList(array $input = array())
    {
        $postType = trim((string) ($input['post_type'] ?? ''));
        $postTypeObject = function_exists('get_post_type_object') ? get_post_type_object($postType) : null;
        if (!is_object($postTypeObject)) {
            return new \WP_Error('codi_mcp_post_type_not_found', 'The requested post type is not registered.');
        }
        $editPostsCap = (string) ($postTypeObject->cap->edit_posts ?? 'edit_posts');
        $editOthersCap = (string) ($postTypeObject->cap->edit_others_posts ?? '');
        $canAssignOthers = $editOthersCap !== '' && function_exists('current_user_can') && current_user_can($editOthersCap);
        $currentUserId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($input['per_page'] ?? 50)));
        $search = trim((string) ($input['search'] ?? ''));
        $siteId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;

        $queryArgs = array(
            'blog_id' => $siteId,
            'capability' => $editPostsCap,
            'number' => $perPage,
            'offset' => ($page - 1) * $perPage,
            'orderby' => 'display_name',
            'order' => 'ASC',
        );
        if (!$canAssignOthers) {
            $queryArgs['include'] = array($currentUserId);
        }
        if ($search !== '') {
            $queryArgs['search'] = '*' . $search . '*';
            $queryArgs['search_columns'] = array('display_name', 'user_nicename');
        }

        if (class_exists('WP_User_Query')) {
            $query = new \WP_User_Query($queryArgs);
            $users = (array) $query->get_results();
            $total = (int) $query->get_total();
        } else {
            $scanArgs = $queryArgs;
            $scanArgs['number'] = 500;
            $scanArgs['offset'] = 0;
            $users = array_values(array_filter((array) get_users($scanArgs), function ($user) use ($editPostsCap, $search, $siteId, $canAssignOthers, $currentUserId): bool {
                if (!is_object($user) || !function_exists('user_can') || !user_can($user, $editPostsCap)) {
                    return false;
                }
                $userId = (int) ($user->ID ?? 0);
                if (!$canAssignOthers && $userId !== $currentUserId) {
                    return false;
                }
                if (function_exists('is_multisite') && is_multisite() && function_exists('is_user_member_of_blog') && !is_user_member_of_blog($userId, $siteId)) {
                    return false;
                }
                if ($search === '') {
                    return true;
                }
                $haystack = strtolower((string) ($user->display_name ?? '') . ' ' . (string) ($user->user_nicename ?? ''));
                return str_contains($haystack, strtolower($search));
            }));
            usort($users, static fn ($left, $right): int => strnatcasecmp((string) ($left->display_name ?? ''), (string) ($right->display_name ?? '')));
            $total = count($users);
            $users = array_slice($users, ($page - 1) * $perPage, $perPage);
        }

        $rows = array();
        foreach ($users as $user) {
            if (!is_object($user)) {
                continue;
            }
            $userId = (int) ($user->ID ?? 0);
            if (!$canAssignOthers && $userId !== $currentUserId) {
                continue;
            }
            if ($userId < 1 || (function_exists('user_can') && !user_can($user, $editPostsCap))) {
                continue;
            }
            $rows[] = array(
                'user_id' => $userId,
                'display_name' => (string) ($user->display_name ?? ''),
                'slug' => (string) ($user->user_nicename ?? ''),
                'avatar_url' => function_exists('get_avatar_url') ? (string) get_avatar_url($userId) : '',
                'author_url' => function_exists('get_author_posts_url') ? (string) get_author_posts_url($userId) : '',
            );
        }

        return array(
            'items' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'returned' => count($rows),
        );
    }

    public function registeredBlocksList(array $input = array()): array
    {
        if (!class_exists('WP_Block_Type_Registry')) {
            return $this->page(array(), 1, 50);
        }

        $registry = \WP_Block_Type_Registry::get_instance();
        $rows = array();
        foreach ((array) $registry->get_all_registered() as $blockType) {
            if (!is_object($blockType)) {
                continue;
            }

            $name = (string) ($blockType->name ?? '');
            $parts = explode('/', $name, 2);
            $attributes = array();
            foreach ((array) ($blockType->attributes ?? array()) as $attributeName => $definition) {
                $definition = is_array($definition) ? $definition : array();
                $attributes[] = array(
                    'name' => (string) $attributeName,
                    'type' => is_string($definition['type'] ?? null) ? $definition['type'] : '',
                    'source' => is_string($definition['source'] ?? null) ? $definition['source'] : '',
                    'selector' => is_string($definition['selector'] ?? null) ? $definition['selector'] : '',
                    'has_default' => array_key_exists('default', $definition),
                    'default_json' => array_key_exists('default', $definition) ? $this->boundedJson($definition['default'], 2000) : '',
                    'enum_json' => array_key_exists('enum', $definition) ? $this->boundedJson($definition['enum'], 2000) : '',
                );
            }

            $rows[] = array(
                'name' => $name,
                'namespace' => (string) ($parts[0] ?? ''),
                'title' => (string) ($blockType->title ?? ''),
                'api_version' => (int) ($blockType->api_version ?? 1),
                'parent' => $this->stringList($blockType->parent ?? array()),
                'ancestor' => $this->stringList($blockType->ancestor ?? array()),
                'attributes' => $attributes,
                'supports_json' => $this->boundedJson($blockType->supports ?? array(), 10000),
                'dynamic' => is_callable($blockType->render_callback ?? null),
                'editor_scripts' => $this->publicPropertyList($blockType, 'editor_script_handles'),
                'scripts' => $this->publicPropertyList($blockType, 'script_handles'),
                'view_scripts' => $this->publicPropertyList($blockType, 'view_script_handles'),
                'view_script_modules' => $this->publicPropertyList($blockType, 'view_script_module_ids'),
                'editor_styles' => $this->publicPropertyList($blockType, 'editor_style_handles'),
                'styles' => $this->publicPropertyList($blockType, 'style_handles'),
                'view_styles' => $this->publicPropertyList($blockType, 'view_style_handles'),
                'source' => $this->blockSource($blockType, $name),
            );
        }

        return $this->filterAndPage($rows, $input, array('name', 'namespace', 'title'));
    }

    public function registeredMetaList(array $input = array()): array
    {
        if (!function_exists('get_registered_meta_keys')) {
            return $this->page(array(), 1, 50);
        }

        $objectType = trim((string) ($input['object_type'] ?? ''));
        $allowedTypes = array('post', 'term', 'comment', 'user', 'blog');
        if (!in_array($objectType, $allowedTypes, true)) {
            return new \WP_Error('codi_mcp_meta_object_type_invalid', 'object_type must be post, term, comment, user, or blog.');
        }

        $objectSubtype = trim((string) ($input['object_subtype'] ?? ''));
        $definitions = array();
        foreach ((array) get_registered_meta_keys($objectType, '') as $key => $args) {
            $definitions[(string) $key] = array('args' => is_array($args) ? $args : array(), 'subtype' => '');
        }
        if ($objectSubtype !== '') {
            foreach ((array) get_registered_meta_keys($objectType, $objectSubtype) as $key => $args) {
                $definitions[(string) $key] = array('args' => is_array($args) ? $args : array(), 'subtype' => $objectSubtype);
            }
        }

        $rows = array();
        foreach ($definitions as $key => $definition) {
            $args = $definition['args'];
            $showInRest = $args['show_in_rest'] ?? false;
            $restSchema = is_array($showInRest) && is_array($showInRest['schema'] ?? null)
                ? $this->safeRestSchema($showInRest['schema'])
                : array();
            $rows[] = array(
                'key' => (string) $key,
                'object_type' => $objectType,
                'object_subtype' => (string) $definition['subtype'],
                'type' => is_string($args['type'] ?? null) ? $args['type'] : 'string',
                'label' => is_string($args['label'] ?? null) ? $args['label'] : '',
                'description' => is_string($args['description'] ?? null) ? $args['description'] : '',
                'single' => !empty($args['single']),
                'show_in_rest' => $showInRest === true || is_array($showInRest),
                'rest_schema_json' => $restSchema !== array() ? $this->boundedJson($restSchema, 4000) : '',
                'sanitize_callback_present' => is_callable($args['sanitize_callback'] ?? null),
                'auth_callback_present' => is_callable($args['auth_callback'] ?? null),
                'revisions_enabled' => !empty($args['revisions_enabled']),
            );
        }

        $search = strtolower(trim((string) ($input['search'] ?? '')));
        if ($search !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($search): bool {
                return strpos(strtolower($row['key'] . ' ' . $row['label'] . ' ' . $row['description']), $search) !== false;
            }));
        }
        usort($rows, static fn (array $left, array $right): int => strnatcasecmp($left['key'], $right['key']));

        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($input['per_page'] ?? 50)));
        return $this->page($rows, $page, $perPage);
    }

    /** @return array<string,mixed> */
    public function postsList(array $input = array()): array
    {
        $postType = trim((string) ($input['post_type'] ?? ''));
        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($input['per_page'] ?? 20)));
        $args = array(
            'post_type' => array($postType),
            'post_status' => array('draft', 'publish', 'private', 'future', 'pending'),
            'perm' => 'editable',
            'posts_per_page' => $perPage,
            'offset' => ($page - 1) * $perPage,
        );
        if (trim((string) ($input['search'] ?? '')) !== '') {
            $args['s'] = trim((string) $input['search']);
        }
        $items = array();
        foreach ((array) (function_exists('get_posts') ? get_posts($args) : array()) as $post) {
            $postId = is_object($post) ? (int) ($post->ID ?? 0) : 0;
            if (!is_object($post) || (string) ($post->post_type ?? '') !== $postType || $postId < 1 || !current_user_can('edit_post', $postId)) {
                continue;
            }
            $items[] = array(
                'post_id' => $postId,
                'post_type' => $postType,
                'title' => (string) ($post->post_title ?? ''),
                'slug' => (string) ($post->post_name ?? ''),
                'status' => (string) ($post->post_status ?? ''),
                'author_id' => (int) ($post->post_author ?? 0),
                'parent_id' => (int) ($post->post_parent ?? 0),
            );
        }
        return array('items' => $items, 'page' => $page, 'per_page' => $perPage, 'returned' => count($items));
    }

    private function filterAndPage(array $rows, array $input, array $searchFields): array
    {
        $search = strtolower(trim((string) ($input['search'] ?? '')));
        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($input['per_page'] ?? 50)));

        if ($search !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($search, $searchFields): bool {
                foreach ($searchFields as $field) {
                    if (strpos(strtolower((string) ($row[$field] ?? '')), $search) !== false) {
                        return true;
                    }
                }
                return false;
            }));
        }

        usort($rows, static fn (array $left, array $right): int => strnatcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? '')));
        return $this->page($rows, $page, $perPage);
    }

    private function page(array $rows, int $page, int $perPage): array
    {
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

    private function stringList($value): array
    {
        if (is_string($value) && $value !== '') {
            return array($value);
        }
        return array_values(array_filter(array_map('strval', (array) $value), static fn (string $item): bool => $item !== ''));
    }

    private function publicPropertyList(object $object, string $property): array
    {
        $vars = get_object_vars($object);
        return $this->stringList($vars[$property] ?? array());
    }

    private function blockSource(object $blockType, string $name): string
    {
        if (strpos($name, 'core/') === 0) {
            return 'core';
        }

        $vars = get_object_vars($blockType);
        $file = is_string($vars['file'] ?? null) ? wp_normalize_path($vars['file']) : '';
        if ($file === '') {
            return '';
        }

        $pluginDir = defined('WP_PLUGIN_DIR') ? trailingslashit(wp_normalize_path(WP_PLUGIN_DIR)) : '';
        if ($pluginDir !== '' && strpos($file, $pluginDir) === 0) {
            $relative = substr($file, strlen($pluginDir));
            $parts = explode('/', ltrim($relative, '/'));
            return !empty($parts[0]) ? 'plugin:' . $parts[0] : 'plugin';
        }

        $themeRoot = function_exists('get_theme_root') ? trailingslashit(wp_normalize_path(get_theme_root())) : '';
        if ($themeRoot !== '' && strpos($file, $themeRoot) === 0) {
            $relative = substr($file, strlen($themeRoot));
            $parts = explode('/', ltrim($relative, '/'));
            return !empty($parts[0]) ? 'theme:' . $parts[0] : 'theme';
        }

        return '';
    }

    private function safeRestSchema(array $schema, int $depth = 0): array
    {
        if ($depth >= 4) {
            return array();
        }

        $result = array();
        foreach (array('type', 'description', 'format', 'pattern', 'minimum', 'maximum', 'minLength', 'maxLength', 'minItems', 'maxItems', 'additionalProperties') as $key) {
            if (array_key_exists($key, $schema) && (is_scalar($schema[$key]) || $schema[$key] === null)) {
                $result[$key] = $schema[$key];
            }
        }
        if (isset($schema['required']) && is_array($schema['required'])) {
            $result['required'] = array_values(array_filter(array_map('strval', $schema['required']), static fn (string $key): bool => $key !== ''));
        }
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            $properties = array();
            foreach ($schema['properties'] as $key => $definition) {
                if (is_array($definition)) {
                    $properties[(string) $key] = $this->safeRestSchema($definition, $depth + 1);
                }
            }
            if ($properties !== array()) {
                $result['properties'] = $properties;
            }
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            $result['items'] = $this->safeRestSchema($schema['items'], $depth + 1);
        }
        foreach (array('oneOf', 'anyOf', 'allOf') as $key) {
            if (!isset($schema[$key]) || !is_array($schema[$key])) {
                continue;
            }
            $variants = array();
            foreach (array_slice($schema[$key], 0, 20) as $definition) {
                if (is_array($definition)) {
                    $variants[] = $this->safeRestSchema($definition, $depth + 1);
                }
            }
            if ($variants !== array()) {
                $result[$key] = $variants;
            }
        }

        return $result;
    }

    private function boundedJson($value, int $maxBytes): string
    {
        $json = function_exists('wp_json_encode') ? wp_json_encode($value) : json_encode($value);
        $json = is_string($json) ? $json : '';
        return strlen($json) > $maxBytes ? substr($json, 0, $maxBytes) . '…' : $json;
    }
}
