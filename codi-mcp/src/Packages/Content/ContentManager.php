<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Content;

final class ContentManager
{
    private const MAX_META_JSON_BYTES = 65536;

    public function canManageTerm($input, string $operation): bool
    {
        $input = is_array($input) ? $input : array();
        $taxonomy = $this->taxonomy((string) ($input['taxonomy'] ?? ''));
        if (!$taxonomy) {
            return false;
        }

        $capability = match ($operation) {
            'create' => (string) ($taxonomy->cap->manage_terms ?? 'manage_categories'),
            'update' => (string) ($taxonomy->cap->edit_terms ?? 'manage_categories'),
            'delete' => (string) ($taxonomy->cap->delete_terms ?? 'manage_categories'),
            default => '',
        };

        return $capability !== '' && current_user_can($capability);
    }

    public function canAssignTerms($input): bool
    {
        $input = is_array($input) ? $input : array();
        $postId = (int) ($input['post_id'] ?? 0);
        $post = $postId > 0 ? get_post($postId) : null;
        $taxonomy = $this->taxonomy((string) ($input['taxonomy'] ?? ''));
        if (!is_object($post) || !$taxonomy || !current_user_can('edit_post', $postId)) {
            return false;
        }
        if (!in_array((string) ($post->post_type ?? ''), (array) ($taxonomy->object_type ?? array()), true)) {
            return false;
        }

        $capability = (string) ($taxonomy->cap->assign_terms ?? 'edit_posts');
        return $capability !== '' && current_user_can($capability);
    }

    public function canListTerms($input): bool
    {
        $input = is_array($input) ? $input : array();
        $taxonomy = $this->taxonomy((string) ($input['taxonomy'] ?? ''));
        if (!$taxonomy) {
            return false;
        }
        foreach (array('assign_terms', 'edit_terms', 'manage_terms') as $key) {
            $capability = (string) ($taxonomy->cap->{$key} ?? '');
            if ($capability !== '' && current_user_can($capability)) {
                return true;
            }
        }
        return false;
    }

    public function canListAuthors($input): bool
    {
        $input = is_array($input) ? $input : array();
        $postType = trim((string) ($input['post_type'] ?? ''));
        $object = $postType !== '' && function_exists('get_post_type_object') ? get_post_type_object($postType) : null;
        $capability = is_object($object) ? (string) ($object->cap->edit_posts ?? '') : '';
        return $capability !== '' && current_user_can($capability);
    }

    public function canUpdatePostAttributes($input): bool
    {
        $input = is_array($input) ? $input : array();
        $postId = (int) ($input['post_id'] ?? 0);
        $post = $postId > 0 ? get_post($postId) : null;
        if (!is_object($post) || !current_user_can('edit_post', $postId)) {
            return false;
        }
        if (array_key_exists('sticky', $input)) {
            if ((string) ($post->post_type ?? '') !== 'post') {
                return false;
            }
            $postType = function_exists('get_post_type_object') ? get_post_type_object('post') : null;
            $capability = is_object($postType) ? (string) ($postType->cap->edit_others_posts ?? '') : '';
            if ($capability === '' || !current_user_can($capability)) {
                return false;
            }
        }
        return true;
    }

    public function canListPosts($input): bool
    {
        $input = is_array($input) ? $input : array();
        $postType = $this->contentPostTypeObject((string) ($input['post_type'] ?? ''));
        if ($postType instanceof \WP_Error) {
            return false;
        }
        $capability = (string) ($postType->cap->edit_posts ?? '');
        return $capability !== '' && current_user_can($capability);
    }

    public function canCreatePost($input): bool
    {
        $input = is_array($input) ? $input : array();
        $postType = $this->contentPostTypeObject((string) ($input['post_type'] ?? ''));
        if ($postType instanceof \WP_Error) {
            return false;
        }
        $capability = (string) ($postType->cap->create_posts ?? $postType->cap->edit_posts ?? '');
        return $capability !== '' && current_user_can($capability);
    }

    public function canEditPost($input): bool
    {
        $input = is_array($input) ? $input : array();
        $postId = (int) ($input['post_id'] ?? 0);
        $post = $postId > 0 ? get_post($postId) : null;
        return is_object($post)
            && !($this->contentPostTypeObject((string) ($post->post_type ?? '')) instanceof \WP_Error)
            && current_user_can('edit_post', $postId);
    }

    public function canUpdatePostStatus($input): bool
    {
        $input = is_array($input) ? $input : array();
        if (!$this->canEditPost($input)) {
            return false;
        }
        $status = strtolower(trim((string) ($input['status'] ?? '')));
        if (!in_array($status, array('draft', 'pending', 'publish', 'private', 'future'), true)) {
            return false;
        }
        if (in_array($status, array('publish', 'private', 'future'), true)) {
            $postId = (int) ($input['post_id'] ?? 0);
            $post = get_post($postId);
            $postType = is_object($post) && function_exists('get_post_type_object') ? get_post_type_object((string) ($post->post_type ?? '')) : null;
            $capability = is_object($postType) ? (string) ($postType->cap->publish_posts ?? '') : '';
            return $capability !== '' && current_user_can($capability);
        }
        return true;
    }

    public function canDuplicatePost($input): bool
    {
        $input = is_array($input) ? $input : array();
        if (!$this->canEditPost($input)) {
            return false;
        }
        $postId = (int) ($input['post_id'] ?? 0);
        $post = get_post($postId);
        $postType = is_object($post) && function_exists('get_post_type_object') ? get_post_type_object((string) ($post->post_type ?? '')) : null;
        if (!is_object($postType)) {
            return false;
        }
        $capability = (string) ($postType->cap->create_posts ?? $postType->cap->edit_posts ?? '');
        return $capability !== '' && current_user_can($capability);
    }

    public function canDeletePost($input): bool
    {
        $input = is_array($input) ? $input : array();
        $postId = (int) ($input['post_id'] ?? 0);
        $post = $postId > 0 ? get_post($postId) : null;
        return is_object($post)
            && !($this->contentPostTypeObject((string) ($post->post_type ?? '')) instanceof \WP_Error)
            && current_user_can('delete_post', $postId);
    }

    public function canReadRegisteredMeta($input): bool
    {
        $resolved = $this->registeredMetaDefinition(is_array($input) ? $input : array());
        if (is_wp_error($resolved)) {
            return false;
        }
        return $this->canEditMetaObject($resolved['object_type'], $resolved['object_id'], $resolved['key']);
    }

    public function canEditRegisteredMeta($input): bool
    {
        return $this->canReadRegisteredMeta($input);
    }

    public function canSetAuthor($input): bool
    {
        $input = is_array($input) ? $input : array();
        $postId = (int) ($input['post_id'] ?? 0);
        $authorId = (int) ($input['author_id'] ?? 0);
        $post = $postId > 0 ? get_post($postId) : null;
        if (!is_object($post) || $authorId <= 0 || !current_user_can('edit_post', $postId)) {
            return false;
        }

        $postType = function_exists('get_post_type_object') ? get_post_type_object((string) ($post->post_type ?? 'post')) : null;
        $othersCap = is_object($postType) ? (string) ($postType->cap->edit_others_posts ?? '') : '';
        $currentUserId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($authorId !== $currentUserId && ($othersCap === '' || !current_user_can($othersCap))) {
            return false;
        }

        $author = function_exists('get_userdata') ? get_userdata($authorId) : null;
        if (!is_object($author)) {
            return false;
        }
        if (function_exists('is_multisite') && is_multisite() && function_exists('is_user_member_of_blog') && !is_user_member_of_blog($authorId, get_current_blog_id())) {
            return false;
        }

        $editPostsCap = is_object($postType) ? (string) ($postType->cap->edit_posts ?? 'edit_posts') : 'edit_posts';
        return function_exists('user_can') && user_can($author, $editPostsCap);
    }

    public function createTerm(array $input)
    {
        $taxonomy = trim((string) ($input['taxonomy'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        if ($taxonomy === '' || $name === '') {
            return new \WP_Error('codi_mcp_term_input_invalid', 'taxonomy and name are required.');
        }

        $args = array();
        foreach (array('slug', 'description') as $field) {
            if (array_key_exists($field, $input)) {
                $args[$field] = (string) $input[$field];
            }
        }
        if (array_key_exists('parent', $input)) {
            $parent = max(0, (int) $input['parent']);
            $taxonomyObject = $this->taxonomy($taxonomy);
            if ($parent > 0 && (!is_object($taxonomyObject) || empty($taxonomyObject->hierarchical))) {
                return new \WP_Error('codi_mcp_parent_term_invalid', 'parent is only valid for hierarchical taxonomies.');
            }
            if ($parent > 0 && !$this->termExists($parent, $taxonomy)) {
                return new \WP_Error('codi_mcp_parent_term_not_found', 'The requested parent term does not exist in this taxonomy.');
            }
            $args['parent'] = $parent;
        }

        $result = wp_insert_term($name, $taxonomy, $args);
        if (is_wp_error($result)) {
            return $result;
        }
        return $this->termResult((int) ($result['term_id'] ?? 0), $taxonomy);
    }

    public function updateTerm(array $input)
    {
        $taxonomy = trim((string) ($input['taxonomy'] ?? ''));
        $termId = (int) ($input['term_id'] ?? 0);
        if ($termId <= 0 || !$this->termExists($termId, $taxonomy)) {
            return new \WP_Error('codi_mcp_term_not_found', 'The requested term was not found in this taxonomy.');
        }

        $args = array();
        foreach (array('name', 'slug', 'description') as $field) {
            if (array_key_exists($field, $input)) {
                $args[$field] = (string) $input[$field];
            }
        }
        if (array_key_exists('parent', $input)) {
            $parent = max(0, (int) $input['parent']);
            $taxonomyObject = $this->taxonomy($taxonomy);
            if ($parent > 0 && (!is_object($taxonomyObject) || empty($taxonomyObject->hierarchical))) {
                return new \WP_Error('codi_mcp_parent_term_invalid', 'parent is only valid for hierarchical taxonomies.');
            }
            if ($parent === $termId) {
                return new \WP_Error('codi_mcp_parent_term_invalid', 'A term cannot be its own parent.');
            }
            if ($parent > 0 && !$this->termExists($parent, $taxonomy)) {
                return new \WP_Error('codi_mcp_parent_term_not_found', 'The requested parent term does not exist in this taxonomy.');
            }
            $args['parent'] = $parent;
        }
        if ($args === array()) {
            return new \WP_Error('codi_mcp_term_update_empty', 'At least one term field must be supplied.');
        }

        $result = wp_update_term($termId, $taxonomy, $args);
        if (is_wp_error($result)) {
            return $result;
        }
        return $this->termResult($termId, $taxonomy);
    }

    public function deleteTerm(array $input)
    {
        $taxonomy = trim((string) ($input['taxonomy'] ?? ''));
        $termId = (int) ($input['term_id'] ?? 0);
        if ($termId <= 0 || !$this->termExists($termId, $taxonomy)) {
            return new \WP_Error('codi_mcp_term_not_found', 'The requested term was not found in this taxonomy.');
        }

        $result = wp_delete_term($termId, $taxonomy);
        if (is_wp_error($result)) {
            return $result;
        }
        if ($result === false) {
            return new \WP_Error('codi_mcp_term_delete_failed', 'WordPress could not delete the requested term.');
        }

        return array('taxonomy' => $taxonomy, 'term_id' => $termId, 'deleted' => true);
    }

    public function updatePostTerms(array $input)
    {
        $postId = (int) ($input['post_id'] ?? 0);
        $taxonomy = trim((string) ($input['taxonomy'] ?? ''));
        $mode = (string) ($input['mode'] ?? 'set');
        $termIds = array_values(array_unique(array_map('intval', (array) ($input['term_ids'] ?? array()))));
        $termIds = array_values(array_filter($termIds, static fn (int $id): bool => $id > 0));

        foreach ($termIds as $termId) {
            if (!$this->termExists($termId, $taxonomy)) {
                return new \WP_Error('codi_mcp_term_not_found', 'Every term_id must identify an existing term in the selected taxonomy.');
            }
        }

        if ($mode === 'remove') {
            $result = $termIds === array() ? true : wp_remove_object_terms($postId, $termIds, $taxonomy);
        } else {
            $result = wp_set_object_terms($postId, $termIds, $taxonomy, $mode === 'add');
        }
        if (is_wp_error($result)) {
            return $result;
        }

        $assigned = wp_get_object_terms($postId, $taxonomy, array('fields' => 'ids'));
        if (is_wp_error($assigned)) {
            return $assigned;
        }
        $assigned = array_values(array_map('intval', (array) $assigned));
        sort($assigned);

        return array(
            'post_id' => $postId,
            'taxonomy' => $taxonomy,
            'mode' => $mode,
            'term_ids' => $assigned,
        );
    }

    public function getRegisteredMeta(array $input)
    {
        $resolved = $this->registeredMetaDefinition($input);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $value = get_metadata($resolved['object_type'], $resolved['object_id'], $resolved['key'], $resolved['single']);
        $exists = metadata_exists($resolved['object_type'], $resolved['object_id'], $resolved['key']);

        return $this->metaResult($resolved, $value, $exists);
    }

    public function setRegisteredMeta(array $input)
    {
        $resolved = $this->registeredMetaDefinition($input);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        try {
            $value = json_decode((string) ($input['value_json'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return new \WP_Error('codi_mcp_meta_json_invalid', 'value_json must contain valid JSON.');
        }

        $validation = $this->validateMetaValue($resolved, $value);
        if (is_wp_error($validation)) {
            return $validation;
        }

        if ($resolved['single']) {
            $result = update_metadata($resolved['object_type'], $resolved['object_id'], $resolved['key'], function_exists('wp_slash') ? wp_slash($value) : $value);
            if ($result === false && !metadata_exists($resolved['object_type'], $resolved['object_id'], $resolved['key'])) {
                return new \WP_Error('codi_mcp_meta_update_failed', 'WordPress could not update the registered metadata value.');
            }
        } else {
            if (!is_array($value) || !array_is_list($value)) {
                return new \WP_Error('codi_mcp_meta_value_invalid', 'A non-single registered metadata field must be set with a JSON array of values.');
            }
            $previous = (array) get_metadata($resolved['object_type'], $resolved['object_id'], $resolved['key'], false);
            delete_metadata($resolved['object_type'], $resolved['object_id'], $resolved['key']);
            foreach ($value as $item) {
                $stored = add_metadata($resolved['object_type'], $resolved['object_id'], $resolved['key'], function_exists('wp_slash') ? wp_slash($item) : $item, false);
                if ($stored === false) {
                    delete_metadata($resolved['object_type'], $resolved['object_id'], $resolved['key']);
                    foreach ($previous as $oldItem) {
                        add_metadata($resolved['object_type'], $resolved['object_id'], $resolved['key'], function_exists('wp_slash') ? wp_slash($oldItem) : $oldItem, false);
                    }
                    return new \WP_Error('codi_mcp_meta_update_failed', 'WordPress could not replace all values for the registered metadata field. The previous values were restored.');
                }
            }
        }

        return $this->getRegisteredMeta($input);
    }

    public function deleteRegisteredMeta(array $input)
    {
        $resolved = $this->registeredMetaDefinition($input);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $existed = metadata_exists($resolved['object_type'], $resolved['object_id'], $resolved['key']);
        if ($existed && !delete_metadata($resolved['object_type'], $resolved['object_id'], $resolved['key'])) {
            return new \WP_Error('codi_mcp_meta_delete_failed', 'WordPress could not delete the registered metadata field.');
        }

        return array(
            'object_type' => $resolved['object_type'],
            'object_id' => $resolved['object_id'],
            'key' => $resolved['key'],
            'deleted' => $existed,
        );
    }

    public function setPostAuthor(array $input)
    {
        $postId = (int) ($input['post_id'] ?? 0);
        $authorId = (int) ($input['author_id'] ?? 0);
        $result = wp_update_post(array('ID' => $postId, 'post_author' => $authorId), true);
        if (is_wp_error($result)) {
            return $result;
        }
        if ((int) $result <= 0) {
            return new \WP_Error('codi_mcp_author_update_failed', 'WordPress could not update the post author.');
        }

        return array('post_id' => $postId, 'author_id' => $authorId, 'updated' => true);
    }

    public function updatePostAttributes(array $input)
    {
        $postId = (int) ($input['post_id'] ?? 0);
        $post = $postId > 0 ? get_post($postId) : null;
        if (!is_object($post)) {
            return new \WP_Error('codi_mcp_post_not_found', 'The requested post object does not exist.');
        }
        $postTypeName = (string) ($post->post_type ?? '');
        $postType = function_exists('get_post_type_object') ? get_post_type_object($postTypeName) : null;
        if (!is_object($postType)) {
            return new \WP_Error('codi_mcp_post_type_not_found', 'The requested post type is not registered.');
        }

        $supplied = array_values(array_intersect(array_keys($input), array('parent_id', 'menu_order', 'excerpt', 'comment_status', 'ping_status', 'sticky')));
        if ($supplied === array()) {
            return new \WP_Error('codi_mcp_post_attributes_empty', 'At least one supported post attribute must be supplied.');
        }

        $payload = array('ID' => $postId);
        $updatedFields = array();
        $hierarchical = !empty($postType->hierarchical) || $postTypeName === 'page';

        if (array_key_exists('parent_id', $input)) {
            if (!$hierarchical) {
                return new \WP_Error('codi_mcp_post_parent_unsupported', 'parent_id is only supported for hierarchical post types.');
            }
            $parentId = max(0, (int) $input['parent_id']);
            if ($parentId === $postId) {
                return new \WP_Error('codi_mcp_post_parent_invalid', 'A post cannot be its own parent.');
            }
            if ($parentId > 0) {
                $parent = get_post($parentId);
                if (!is_object($parent) || (string) ($parent->post_type ?? '') !== $postTypeName) {
                    return new \WP_Error('codi_mcp_post_parent_invalid', 'parent_id must identify an existing post of the same post type.');
                }
                if (in_array((string) ($parent->post_status ?? ''), array('trash', 'auto-draft'), true)) {
                    return new \WP_Error('codi_mcp_post_parent_invalid', 'parent_id cannot identify a trashed or auto-draft post.');
                }
                if (!current_user_can('edit_post', $parentId)) {
                    return new \WP_Error('codi_mcp_post_parent_forbidden', 'The current user cannot edit the requested parent post.');
                }
                if ($this->wouldCreateParentCycle($postId, $parentId)) {
                    return new \WP_Error('codi_mcp_post_parent_cycle', 'The requested parent would create a hierarchy cycle.');
                }
            }
            if ((int) ($post->post_parent ?? 0) !== $parentId) {
                $payload['post_parent'] = $parentId;
                $updatedFields[] = 'parent_id';
            }
        }

        if (array_key_exists('menu_order', $input)) {
            if (!$hierarchical && !$this->supportsPostFeature($postTypeName, 'page-attributes')) {
                return new \WP_Error('codi_mcp_menu_order_unsupported', 'menu_order is only supported for hierarchical or page-attributes post types.');
            }
            $menuOrder = (int) $input['menu_order'];
            if ((int) ($post->menu_order ?? 0) !== $menuOrder) {
                $payload['menu_order'] = $menuOrder;
                $updatedFields[] = 'menu_order';
            }
        }

        if (array_key_exists('excerpt', $input)) {
            if (!$this->supportsPostFeature($postTypeName, 'excerpt')) {
                return new \WP_Error('codi_mcp_excerpt_unsupported', 'excerpt is not supported by this post type.');
            }
            $excerpt = (string) $input['excerpt'];
            if ((string) ($post->post_excerpt ?? '') !== $excerpt) {
                $payload['post_excerpt'] = $excerpt;
                $updatedFields[] = 'excerpt';
            }
        }

        foreach (array('comment_status' => 'comments', 'ping_status' => 'trackbacks') as $field => $feature) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            if (!$this->supportsPostFeature($postTypeName, $feature)) {
                return new \WP_Error('codi_mcp_post_attribute_unsupported', sprintf('%s is not supported by this post type.', $field));
            }
            $value = (string) $input[$field];
            if (!in_array($value, array('open', 'closed'), true)) {
                return new \WP_Error('codi_mcp_post_attribute_invalid', sprintf('%s must be open or closed.', $field));
            }
            if ((string) ($post->{$field} ?? '') !== $value) {
                $payload[$field] = $value;
                $updatedFields[] = $field;
            }
        }

        $stickyChanged = false;
        $sticky = function_exists('is_sticky') ? is_sticky($postId) : false;
        if (array_key_exists('sticky', $input)) {
            if ($postTypeName !== 'post') {
                return new \WP_Error('codi_mcp_sticky_unsupported', 'sticky is only supported for the built-in post type.');
            }
            $stickyPostType = function_exists('get_post_type_object') ? get_post_type_object('post') : null;
            $stickyCapability = is_object($stickyPostType) ? (string) ($stickyPostType->cap->edit_others_posts ?? '') : '';
            if ($stickyCapability === '' || !current_user_can($stickyCapability)) {
                return new \WP_Error('codi_mcp_sticky_forbidden', 'The current user cannot change sticky-post state.');
            }
            if (!function_exists('stick_post') || !function_exists('unstick_post')) {
                return new \WP_Error('codi_mcp_sticky_unavailable', 'WordPress sticky-post functions are unavailable.');
            }
            $requestedSticky = (bool) $input['sticky'];
            $stickyChanged = $sticky !== $requestedSticky;
            if ($stickyChanged) {
                $updatedFields[] = 'sticky';
            }
        }

        if (count($payload) > 1) {
            $result = wp_update_post($payload, true);
            if (is_wp_error($result)) {
                return $result;
            }
            if ((int) $result <= 0) {
                return new \WP_Error('codi_mcp_post_attributes_update_failed', 'WordPress could not update the requested post attributes.');
            }
        }

        if ($stickyChanged) {
            if ((bool) $input['sticky']) {
                stick_post($postId);
            } else {
                unstick_post($postId);
            }
        }

        $updated = get_post($postId);
        return $this->postAttributesResult(is_object($updated) ? $updated : $post, $updatedFields);
    }

    /** @return array<string,mixed>|\WP_Error */
    public function createPost(array $input): array|\WP_Error
    {
        $postTypeName = trim((string) ($input['post_type'] ?? ''));
        $postType = $this->contentPostTypeObject($postTypeName);
        if ($postType instanceof \WP_Error) {
            return $postType;
        }
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            return new \WP_Error('codi_mcp_post_title_required', 'title is required.');
        }
        $payload = array('post_type' => $postTypeName, 'post_title' => $title, 'post_status' => 'draft', 'post_content' => '');
        if (array_key_exists('slug', $input)) {
            $payload['post_name'] = (string) $input['slug'];
        }
        $id = wp_insert_post($payload, true);
        if (is_wp_error($id)) {
            return $id;
        }
        if ((int) $id < 1) {
            return new \WP_Error('codi_mcp_post_create_failed', 'WordPress could not create the content entity.');
        }
        return $this->postEntityResult(get_post((int) $id));
    }

    /** @return array<string,mixed>|\WP_Error */
    public function updatePostIdentity(array $input): array|\WP_Error
    {
        $postId = (int) ($input['post_id'] ?? 0);
        $post = $postId > 0 ? get_post($postId) : null;
        if (!is_object($post) || $this->contentPostTypeObject((string) ($post->post_type ?? '')) instanceof \WP_Error) {
            return new \WP_Error('codi_mcp_post_not_found', 'The requested content entity does not exist.');
        }
        $payload = array('ID' => $postId);
        if (array_key_exists('title', $input)) {
            $title = trim((string) $input['title']);
            if ($title === '') {
                return new \WP_Error('codi_mcp_post_title_required', 'title cannot be empty.');
            }
            $payload['post_title'] = $title;
        }
        if (array_key_exists('slug', $input)) {
            $slug = trim((string) $input['slug']);
            if ($slug === '') {
                return new \WP_Error('codi_mcp_post_slug_required', 'slug cannot be empty.');
            }
            $payload['post_name'] = $slug;
        }
        if (count($payload) === 1) {
            return new \WP_Error('codi_mcp_post_identity_empty', 'title or slug is required.');
        }
        $updated = wp_update_post($payload, true);
        if (is_wp_error($updated)) {
            return $updated;
        }
        return $this->postEntityResult(get_post($postId));
    }

    /** @return array<string,mixed>|\WP_Error */
    public function updatePostStatus(array $input): array|\WP_Error
    {
        $postId = (int) ($input['post_id'] ?? 0);
        $post = $postId > 0 ? get_post($postId) : null;
        if (!is_object($post) || $this->contentPostTypeObject((string) ($post->post_type ?? '')) instanceof \WP_Error) {
            return new \WP_Error('codi_mcp_post_not_found', 'The requested content entity does not exist.');
        }
        $status = strtolower(trim((string) ($input['status'] ?? '')));
        if (!in_array($status, array('draft', 'publish', 'private', 'pending', 'future'), true)) {
            return new \WP_Error('codi_mcp_post_status_invalid', 'status must be draft, publish, private, pending, or future.');
        }
        $payload = array('ID' => $postId, 'post_status' => $status);
        if ($status === 'future') {
            $publishAt = trim((string) ($input['publish_at'] ?? ''));
            if ($publishAt === '') {
                return new \WP_Error('codi_mcp_publish_at_required', 'publish_at is required when status is future.');
            }
            try {
                $date = new \DateTimeImmutable($publishAt);
            } catch (\Throwable) {
                return new \WP_Error('codi_mcp_publish_at_invalid', 'publish_at must be a valid date/time.');
            }
            $payload['post_date'] = $date->format('Y-m-d H:i:s');
            $payload['post_date_gmt'] = $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } elseif (array_key_exists('publish_at', $input)) {
            return new \WP_Error('codi_mcp_publish_at_invalid', 'publish_at is only valid when status is future.');
        }
        $updated = wp_update_post($payload, true);
        if (is_wp_error($updated)) {
            return $updated;
        }
        return $this->postEntityResult(get_post($postId));
    }

    /** @return array<string,mixed>|\WP_Error */
    public function setFeaturedMedia(array $input): array|\WP_Error
    {
        $postId = (int) ($input['post_id'] ?? 0);
        $attachmentId = max(0, (int) ($input['attachment_id'] ?? 0));
        $post = $postId > 0 ? get_post($postId) : null;
        if (!is_object($post) || $this->contentPostTypeObject((string) ($post->post_type ?? '')) instanceof \WP_Error) {
            return new \WP_Error('codi_mcp_post_not_found', 'The requested content entity does not exist.');
        }
        if ($attachmentId > 0) {
            $attachment = get_post($attachmentId);
            if (!is_object($attachment) || (string) ($attachment->post_type ?? '') !== 'attachment') {
                return new \WP_Error('codi_mcp_featured_media_invalid', 'attachment_id must identify an existing media attachment.');
            }
            $ok = function_exists('set_post_thumbnail') ? set_post_thumbnail($postId, $attachmentId) : update_post_meta($postId, '_thumbnail_id', $attachmentId);
            if (!$ok && (int) get_post_meta($postId, '_thumbnail_id', true) !== $attachmentId) {
                return new \WP_Error('codi_mcp_featured_media_failed', 'WordPress could not set featured media.');
            }
        } else {
            if (function_exists('delete_post_thumbnail')) {
                delete_post_thumbnail($postId);
            } elseif (function_exists('delete_post_meta')) {
                delete_post_meta($postId, '_thumbnail_id');
            }
        }
        return array('post_id' => $postId, 'attachment_id' => $attachmentId, 'updated' => true);
    }

    /** @return array<string,mixed>|\WP_Error */
    public function duplicatePost(array $input): array|\WP_Error
    {
        $postId = (int) ($input['post_id'] ?? 0);
        $post = $postId > 0 ? get_post($postId) : null;
        if (!is_object($post) || $this->contentPostTypeObject((string) ($post->post_type ?? '')) instanceof \WP_Error) {
            return new \WP_Error('codi_mcp_post_not_found', 'The requested content entity does not exist.');
        }
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            $title = (string) ($post->post_title ?? 'Copy') . ' Copy';
        }
        $payload = array(
            'post_type' => (string) $post->post_type,
            'post_title' => $title,
            'post_status' => 'draft',
            'post_content' => (string) ($post->post_content ?? ''),
            'post_excerpt' => (string) ($post->post_excerpt ?? ''),
            'post_parent' => (int) ($post->post_parent ?? 0),
            'menu_order' => (int) ($post->menu_order ?? 0),
        );
        if (array_key_exists('slug', $input)) {
            $payload['post_name'] = (string) $input['slug'];
        }
        $id = wp_insert_post($payload, true);
        if (is_wp_error($id)) {
            return $id;
        }
        return $this->postEntityResult(get_post((int) $id));
    }

    /** @return array<string,mixed>|\WP_Error */
    public function trashPost(array $input): array|\WP_Error
    {
        $postId = (int) ($input['post_id'] ?? 0);
        $post = $postId > 0 ? get_post($postId) : null;
        if (!is_object($post) || $this->contentPostTypeObject((string) ($post->post_type ?? '')) instanceof \WP_Error) {
            return new \WP_Error('codi_mcp_post_not_found', 'The requested content entity does not exist.');
        }
        $result = function_exists('wp_trash_post') ? wp_trash_post($postId) : wp_update_post(array('ID' => $postId, 'post_status' => 'trash'), true);
        if (is_wp_error($result) || !$result) {
            return is_wp_error($result) ? $result : new \WP_Error('codi_mcp_post_trash_failed', 'WordPress could not trash the content entity.');
        }
        return array('post_id' => $postId, 'trashed' => true);
    }

    /** @return array<string,mixed>|\WP_Error */
    public function restorePost(array $input): array|\WP_Error
    {
        $postId = (int) ($input['post_id'] ?? 0);
        $post = $postId > 0 ? get_post($postId) : null;
        if (!is_object($post) || $this->contentPostTypeObject((string) ($post->post_type ?? '')) instanceof \WP_Error) {
            return new \WP_Error('codi_mcp_post_not_found', 'The requested content entity does not exist.');
        }
        if ((string) ($post->post_status ?? '') !== 'trash') {
            return new \WP_Error('codi_mcp_post_not_trashed', 'The requested content entity is not in trash.');
        }
        $result = function_exists('wp_untrash_post') ? wp_untrash_post($postId) : wp_update_post(array('ID' => $postId, 'post_status' => 'draft'), true);
        if (is_wp_error($result) || !$result) {
            return is_wp_error($result) ? $result : new \WP_Error('codi_mcp_post_restore_failed', 'WordPress could not restore the content entity.');
        }
        return $this->postEntityResult(get_post($postId));
    }

    /** @return array<string,mixed>|\WP_Error */
    public function deletePost(array $input): array|\WP_Error
    {
        $postId = (int) ($input['post_id'] ?? 0);
        $post = $postId > 0 ? get_post($postId) : null;
        if (!is_object($post) || $this->contentPostTypeObject((string) ($post->post_type ?? '')) instanceof \WP_Error) {
            return new \WP_Error('codi_mcp_post_not_found', 'The requested content entity does not exist.');
        }
        if (!function_exists('wp_delete_post')) {
            return new \WP_Error('codi_mcp_post_delete_unavailable', 'WordPress post deletion support is unavailable.');
        }
        $deleted = wp_delete_post($postId, true);
        if (!$deleted) {
            return new \WP_Error('codi_mcp_post_delete_failed', 'WordPress could not permanently delete the content entity.');
        }
        return array('post_id' => $postId, 'deleted' => true);
    }

    /** @return object|\WP_Error */
    private function contentPostTypeObject(string $postType): object
    {
        $postType = trim($postType);
        $excluded = array('attachment', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_block', 'wp_global_styles', 'revision', 'nav_menu_item');
        if ($postType === '' || in_array($postType, $excluded, true)) {
            return new \WP_Error('codi_mcp_post_type_out_of_scope', 'The requested post type is owned by another Codi package or is not an editable content entity.');
        }
        $object = function_exists('get_post_type_object') ? get_post_type_object($postType) : null;
        if (!is_object($object)) {
            return new \WP_Error('codi_mcp_post_type_not_found', 'The requested post type is not registered.');
        }
        return $object;
    }

    /** @return array<string,mixed>|\WP_Error */
    private function postEntityResult($post): array|\WP_Error
    {
        if (!is_object($post)) {
            return new \WP_Error('codi_mcp_post_not_found', 'WordPress did not return the content entity after mutation.');
        }
        return array(
            'post_id' => (int) ($post->ID ?? 0),
            'post_type' => (string) ($post->post_type ?? ''),
            'title' => (string) ($post->post_title ?? ''),
            'slug' => (string) ($post->post_name ?? ''),
            'status' => (string) ($post->post_status ?? ''),
            'author_id' => (int) ($post->post_author ?? 0),
            'parent_id' => (int) ($post->post_parent ?? 0),
        );
    }

    private function supportsPostFeature(string $postType, string $feature): bool
    {
        if (!function_exists('post_type_supports')) {
            return true;
        }
        return post_type_supports($postType, $feature);
    }

    private function wouldCreateParentCycle(int $postId, int $parentId): bool
    {
        $seen = array();
        $current = $parentId;
        for ($depth = 0; $depth < 100 && $current > 0; $depth++) {
            if ($current === $postId || isset($seen[$current])) {
                return true;
            }
            $seen[$current] = true;
            $parent = get_post($current);
            if (!is_object($parent)) {
                return false;
            }
            $current = (int) ($parent->post_parent ?? 0);
        }
        return $current > 0;
    }

    private function postAttributesResult(object $post, array $updatedFields): array
    {
        $postId = (int) ($post->ID ?? 0);
        return array(
            'post_id' => $postId,
            'post_type' => (string) ($post->post_type ?? ''),
            'parent_id' => (int) ($post->post_parent ?? 0),
            'menu_order' => (int) ($post->menu_order ?? 0),
            'excerpt' => (string) ($post->post_excerpt ?? ''),
            'comment_status' => (string) ($post->comment_status ?? ''),
            'ping_status' => (string) ($post->ping_status ?? ''),
            'sticky' => function_exists('is_sticky') ? (bool) is_sticky($postId) : false,
            'updated' => $updatedFields !== array(),
            'updated_fields' => array_values($updatedFields),
        );
    }

    private function taxonomy(string $taxonomy): ?object
    {
        $taxonomy = trim($taxonomy);
        if ($taxonomy === '' || !function_exists('get_taxonomy')) {
            return null;
        }
        $object = get_taxonomy($taxonomy);
        return is_object($object) ? $object : null;
    }

    private function termExists(int $termId, string $taxonomy): bool
    {
        $exists = function_exists('term_exists') ? term_exists($termId, $taxonomy) : null;
        return $exists !== null && $exists !== 0 && $exists !== false;
    }

    private function termResult(int $termId, string $taxonomy)
    {
        $term = get_term($termId, $taxonomy);
        if (is_wp_error($term)) {
            return $term;
        }
        if (!is_object($term)) {
            return new \WP_Error('codi_mcp_term_not_found', 'WordPress did not return the requested term after mutation.');
        }
        return array(
            'term_id' => (int) ($term->term_id ?? $termId),
            'taxonomy' => $taxonomy,
            'name' => (string) ($term->name ?? ''),
            'slug' => (string) ($term->slug ?? ''),
            'description' => (string) ($term->description ?? ''),
            'parent' => (int) ($term->parent ?? 0),
        );
    }

    private function registeredMetaDefinition(array $input)
    {
        $objectType = trim((string) ($input['object_type'] ?? ''));
        if (!in_array($objectType, array('post', 'term'), true)) {
            return new \WP_Error('codi_mcp_meta_object_type_invalid', 'Registered metadata editing is limited to post and term objects.');
        }
        $objectId = (int) ($input['object_id'] ?? 0);
        $key = trim((string) ($input['key'] ?? ''));
        if ($objectId <= 0 || $key === '') {
            return new \WP_Error('codi_mcp_meta_input_invalid', 'object_id and key are required.');
        }

        if ($objectType === 'post') {
            $object = get_post($objectId);
            if (!is_object($object)) {
                return new \WP_Error('codi_mcp_meta_object_not_found', 'The requested post object does not exist.');
            }
            $subtype = (string) ($object->post_type ?? '');
        } else {
            $object = get_term($objectId);
            if (is_wp_error($object) || !is_object($object)) {
                return new \WP_Error('codi_mcp_meta_object_not_found', 'The requested term object does not exist.');
            }
            $subtype = (string) ($object->taxonomy ?? '');
        }

        $definition = null;
        if ($subtype !== '') {
            $definitions = (array) get_registered_meta_keys($objectType, $subtype);
            $definition = isset($definitions[$key]) && is_array($definitions[$key]) ? $definitions[$key] : null;
        }
        if ($definition === null) {
            $definitions = (array) get_registered_meta_keys($objectType, '');
            $definition = isset($definitions[$key]) && is_array($definitions[$key]) ? $definitions[$key] : null;
        }
        if ($definition === null) {
            return new \WP_Error('codi_mcp_meta_not_registered', 'The requested metadata key is not registered for this object.');
        }

        $showInRest = $definition['show_in_rest'] ?? false;
        if ($showInRest !== true && !is_array($showInRest)) {
            return new \WP_Error('codi_mcp_meta_not_exposed', 'Only registered metadata explicitly exposed through WordPress REST metadata can be read or changed.');
        }

        return array(
            'object_type' => $objectType,
            'object_id' => $objectId,
            'object_subtype' => $subtype,
            'key' => $key,
            'single' => !empty($definition['single']),
            'type' => is_string($definition['type'] ?? null) ? $definition['type'] : 'string',
            'definition' => $definition,
        );
    }

    private function canEditMetaObject(string $objectType, int $objectId, string $key): bool
    {
        if ($objectType === 'post') {
            return current_user_can('edit_post', $objectId) && current_user_can('edit_post_meta', $objectId, $key);
        }
        if ($objectType === 'term') {
            $term = get_term($objectId);
            if (is_wp_error($term) || !is_object($term)) {
                return false;
            }
            $taxonomy = $this->taxonomy((string) ($term->taxonomy ?? ''));
            $cap = is_object($taxonomy) ? (string) ($taxonomy->cap->edit_terms ?? 'manage_categories') : '';
            return $cap !== '' && current_user_can($cap) && current_user_can('edit_term_meta', $objectId, $key);
        }
        return false;
    }

    private function validateMetaValue(array $resolved, $value)
    {
        $definition = $resolved['definition'];
        $showInRest = $definition['show_in_rest'] ?? false;
        $itemSchema = is_array($showInRest) && is_array($showInRest['schema'] ?? null)
            ? $showInRest['schema']
            : array('type' => $resolved['type']);
        $schema = $resolved['single'] ? $itemSchema : array('type' => 'array', 'items' => $itemSchema);

        if (function_exists('rest_validate_value_from_schema')) {
            $valid = rest_validate_value_from_schema($value, $schema, 'value');
            if (is_wp_error($valid)) {
                return new \WP_Error('codi_mcp_meta_value_invalid', $valid->get_error_message());
            }
            if ($valid !== true) {
                return new \WP_Error('codi_mcp_meta_value_invalid', 'The supplied value does not match the registered REST metadata schema.');
            }
        } elseif (!$this->matchesType($value, $resolved['single'] ? $resolved['type'] : 'array')) {
            return new \WP_Error('codi_mcp_meta_value_invalid', 'The supplied value does not match the registered metadata type.');
        }

        return true;
    }

    private function matchesType($value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'boolean' => is_bool($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && !array_is_list($value),
            default => false,
        };
    }

    private function metaResult(array $resolved, $value, bool $exists): array
    {
        $json = function_exists('wp_json_encode') ? wp_json_encode($value) : json_encode($value);
        $json = is_string($json) ? $json : 'null';
        if (strlen($json) > self::MAX_META_JSON_BYTES) {
            return array(
                'object_type' => $resolved['object_type'],
                'object_id' => $resolved['object_id'],
                'key' => $resolved['key'],
                'single' => $resolved['single'],
                'type' => $resolved['type'],
                'exists' => $exists,
                'value_json' => '',
                'truncated' => true,
            );
        }
        return array(
            'object_type' => $resolved['object_type'],
            'object_id' => $resolved['object_id'],
            'key' => $resolved['key'],
            'single' => $resolved['single'],
            'type' => $resolved['type'],
            'exists' => $exists,
            'value_json' => $json,
            'truncated' => false,
        );
    }
}
