<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Content;

use CodiMcp\Core\Abilities\AbilityMetadata;
use CodiMcp\Core\AbilityPackage;

final class Package implements AbilityPackage
{
    private ?ContentInspector $inspector = null;
    private ?ContentManager $manager = null;

    public function key(): string
    {
        return 'content';
    }

    public function label(): string
    {
        return 'Content structures';
    }

    public function abilityNames(): array
    {
        return array(
            $this->abilityName('post-types-list'),
            $this->abilityName('taxonomies-list'),
            $this->abilityName('terms-list'),
            $this->abilityName('authors-list'),
            $this->abilityName('registered-blocks-list'),
            $this->abilityName('registered-meta-list'),
            $this->abilityName('posts-list'),
            $this->abilityName('registered-meta-get'),
            $this->abilityName('term-create'),
            $this->abilityName('term-update'),
            $this->abilityName('term-delete'),
            $this->abilityName('post-terms-update'),
            $this->abilityName('registered-meta-set'),
            $this->abilityName('registered-meta-delete'),
            $this->abilityName('post-author-set'),
            $this->abilityName('post-attributes-update'),
            $this->abilityName('post-create'),
            $this->abilityName('post-identity-update'),
            $this->abilityName('post-status-update'),
            $this->abilityName('post-featured-media-set'),
            $this->abilityName('post-duplicate'),
            $this->abilityName('post-trash'),
            $this->abilityName('post-restore'),
            $this->abilityName('post-delete'),
        );
    }

    public function registerCategories(): void
    {
        if (!function_exists('wp_register_ability_category')) {
            return;
        }

        wp_register_ability_category($this->category(), array(
            'label' => 'Codi MCP — Content structures',
            'description' => 'WordPress content-entity lifecycle and editorial state, plus bounded taxonomy relationships, author attribution, registered metadata, post attributes, and registered content structures.',
        ));
    }

    public function registerAbilities(): void
    {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        $input = $this->pagedSearchInputSchema();
        $this->registerReadOnly(
            'post-types-list',
            'List registered post types',
            'List registered WordPress post types with REST, hierarchy, archive, support, and taxonomy information.',
            $input,
            $this->pagedOutputSchema($this->postTypeSchema()),
            [$this, 'postTypesList']
        );
        $this->registerReadOnly(
            'taxonomies-list',
            'List registered taxonomies',
            'List registered WordPress taxonomies with object associations, REST exposure, hierarchy, and UI/public state.',
            $input,
            $this->pagedOutputSchema($this->taxonomySchema()),
            [$this, 'taxonomiesList']
        );
        $this->registerReadOnly(
            'terms-list',
            'List taxonomy terms',
            'Find existing taxonomy terms and IDs before assigning terms to posts.',
            $this->termsListInputSchema(),
            $this->pagedOutputSchema($this->termListSchema()),
            [$this, 'termsList'],
            [$this, 'canListTerms']
        );
        $this->registerReadOnly(
            'authors-list',
            'List assignable authors',
            'List users permitted to author the selected post type.',
            $this->authorsListInputSchema(),
            $this->pagedOutputSchema($this->authorSchema()),
            [$this, 'authorsList'],
            [$this, 'canListAuthors']
        );
        $this->registerReadOnly(
            'registered-blocks-list',
            'List registered block types',
            'List Gutenberg block types compactly by default; detail=schema includes attributes, supports, and asset handles.',
            $this->registeredBlocksInputSchema(),
            $this->pagedOutputSchema($this->blockSchema()),
            [$this, 'registeredBlocksList']
        );

        $this->registerReadOnly(
            'registered-meta-list',
            'List registered metadata',
            'List registered metadata keys and REST schemas for an object type/subtype; excludes stored values.',
            $this->registeredMetaInputSchema(),
            $this->pagedOutputSchema($this->registeredMetaSchema()),
            [$this, 'registeredMetaList']
        );

        $this->registerReadOnly(
            'posts-list',
            'List content entities',
            'Find posts, pages, or custom post types by type/search. Use returned IDs with Content tools; Presentation edits body blocks.',
            $this->postsListInputSchema(),
            $this->postsListOutputSchema(),
            [$this, 'postsList'],
            [$this, 'canListPosts']
        );

        $this->registerReadOnly(
            'registered-meta-get',
            'Read registered metadata value',
            'Read one registered, REST-exposed post or term metadata value.',
            $this->registeredMetaTargetInputSchema(),
            $this->registeredMetaValueSchema(),
            [$this, 'registeredMetaGet'],
            [$this, 'canReadRegisteredMeta']
        );

        $this->registerMutation('term-create', 'Create taxonomy term', 'Create one term in an existing taxonomy using that taxonomy\'s manage_terms capability.', $this->termCreateInputSchema(), $this->termSchema(), [$this, 'termCreate'], [$this, 'canCreateTerm'], false, false);
        $this->registerMutation('term-update', 'Update taxonomy term', 'Update one exact existing taxonomy term using that taxonomy\'s edit_terms capability.', $this->termUpdateInputSchema(), $this->termSchema(), [$this, 'termUpdate'], [$this, 'canUpdateTerm'], true, true);
        $this->registerMutation('term-delete', 'Delete taxonomy term', 'Delete one exact existing taxonomy term using that taxonomy\'s delete_terms capability.', $this->termDeleteInputSchema(), $this->strictObject(array('taxonomy' => array('type' => 'string'), 'term_id' => array('type' => 'integer'), 'deleted' => array('type' => 'boolean'))), [$this, 'termDelete'], [$this, 'canDeleteTerm'], true, false);
        $this->registerMutation('post-terms-update', 'Update post taxonomy terms', 'Set, add, or remove existing term IDs on a post. Never creates terms; taxonomy must be registered for the post type.', $this->postTermsInputSchema(), $this->postTermsOutputSchema(), [$this, 'postTermsUpdate'], [$this, 'canAssignTerms'], true, true);
        $this->registerMutation('registered-meta-set', 'Set registered metadata', 'Set one registered, REST-exposed post or term metadata value from JSON.', $this->registeredMetaSetInputSchema(), $this->registeredMetaValueSchema(), [$this, 'registeredMetaSet'], [$this, 'canEditRegisteredMeta'], true, true);
        $this->registerMutation('registered-meta-delete', 'Delete registered metadata', 'Delete one registered, REST-exposed post or term metadata value.', $this->registeredMetaTargetInputSchema(), $this->strictObject(array('object_type' => array('type' => 'string'), 'object_id' => array('type' => 'integer'), 'key' => array('type' => 'string'), 'deleted' => array('type' => 'boolean'))), [$this, 'registeredMetaDelete'], [$this, 'canEditRegisteredMeta'], true, true);
        $this->registerMutation('post-author-set', 'Set post author', 'Assign a permitted site user as a post/page author.', $this->strictObject(array('post_id' => array('type' => 'integer', 'minimum' => 1), 'author_id' => array('type' => 'integer', 'minimum' => 1))), $this->strictObject(array('post_id' => array('type' => 'integer'), 'author_id' => array('type' => 'integer'), 'updated' => array('type' => 'boolean'))), [$this, 'postAuthorSet'], [$this, 'canSetAuthor'], true, true);
        $this->registerMutation('post-attributes-update', 'Update structured post attributes', 'Update parent, menu order, excerpt, comments/pings, or sticky status. Use Presentation for body blocks.', $this->postAttributesInputSchema(), $this->postAttributesOutputSchema(), [$this, 'postAttributesUpdate'], [$this, 'canUpdatePostAttributes'], true, true);
        $this->registerMutation('post-create', 'Create content entity', 'Create a draft post, page, or custom post type. Use Presentation to add block content.', $this->postCreateInputSchema(), $this->postEntitySchema(), [$this, 'postCreate'], [$this, 'canCreatePost'], false, false);
        $this->registerMutation('post-identity-update', 'Update content identity', 'Update a post/page title or slug. Use Presentation for body blocks.', $this->postIdentityInputSchema(), $this->postEntitySchema(), [$this, 'postIdentityUpdate'], [$this, 'canEditPost'], true, true);
        $this->registerMutation('post-status-update', 'Update publication status', 'Change post/page publication status. Scheduling future publication requires publish_at.', $this->postStatusInputSchema(), $this->postEntitySchema(), [$this, 'postStatusUpdate'], [$this, 'canUpdatePostStatus'], true, true);
        $this->registerMutation('post-featured-media-set', 'Set featured media', 'Set or clear featured attachment for a post; attachment_id=0 clears it.', $this->strictObject(array('post_id' => array('type' => 'integer', 'minimum' => 1), 'attachment_id' => array('type' => 'integer', 'minimum' => 0))), $this->strictObject(array('post_id' => array('type' => 'integer'), 'attachment_id' => array('type' => 'integer'), 'updated' => array('type' => 'boolean'))), [$this, 'postFeaturedMediaSet'], [$this, 'canEditPost'], true, true);
        $this->registerMutation('post-duplicate', 'Duplicate content entity', 'Copy a post/page to a new draft, including its current body. Use Presentation for subsequent block edits.', $this->postDuplicateInputSchema(), $this->postEntitySchema(), [$this, 'postDuplicate'], [$this, 'canDuplicatePost'], false, false);
        $this->registerMutation('post-trash', 'Trash content entity', 'Move one Content-owned post entity to WordPress trash.', $this->strictObject(array('post_id' => array('type' => 'integer', 'minimum' => 1))), $this->strictObject(array('post_id' => array('type' => 'integer'), 'trashed' => array('type' => 'boolean'))), [$this, 'postTrash'], [$this, 'canDeletePost'], true, true);
        $this->registerMutation('post-restore', 'Restore content entity', 'Restore one Content-owned post entity from WordPress trash.', $this->strictObject(array('post_id' => array('type' => 'integer', 'minimum' => 1))), $this->postEntitySchema(), [$this, 'postRestore'], [$this, 'canDeletePost'], true, true);
        $this->registerMutation('post-delete', 'Delete content entity', 'Permanently delete a post/page. Attachments are managed separately by Media.', $this->strictObject(array('post_id' => array('type' => 'integer', 'minimum' => 1))), $this->strictObject(array('post_id' => array('type' => 'integer'), 'deleted' => array('type' => 'boolean'))), [$this, 'postDelete'], [$this, 'canDeletePost'], true, false);
    }

    public function postTypesList($input = array())
    {
        return $this->inspector()->postTypesList(is_array($input) ? $input : array());
    }

    public function taxonomiesList($input = array())
    {
        return $this->inspector()->taxonomiesList(is_array($input) ? $input : array());
    }

    public function termsList($input = array())
    {
        return $this->inspector()->termsList(is_array($input) ? $input : array());
    }

    public function authorsList($input = array())
    {
        return $this->inspector()->authorsList(is_array($input) ? $input : array());
    }

    public function registeredBlocksList($input = array())
    {
        return $this->inspector()->registeredBlocksList(is_array($input) ? $input : array());
    }

    public function registeredMetaList($input = array())
    {
        return $this->inspector()->registeredMetaList(is_array($input) ? $input : array());
    }

    public function postsList($input = array()) { return $this->inspector()->postsList(is_array($input) ? $input : array()); }

    public function registeredMetaGet($input = array()) { return $this->manager()->getRegisteredMeta(is_array($input) ? $input : array()); }
    public function termCreate($input = array()) { return $this->manager()->createTerm(is_array($input) ? $input : array()); }
    public function termUpdate($input = array()) { return $this->manager()->updateTerm(is_array($input) ? $input : array()); }
    public function termDelete($input = array()) { return $this->manager()->deleteTerm(is_array($input) ? $input : array()); }
    public function postTermsUpdate($input = array()) { return $this->manager()->updatePostTerms(is_array($input) ? $input : array()); }
    public function registeredMetaSet($input = array()) { return $this->manager()->setRegisteredMeta(is_array($input) ? $input : array()); }
    public function registeredMetaDelete($input = array()) { return $this->manager()->deleteRegisteredMeta(is_array($input) ? $input : array()); }
    public function postAuthorSet($input = array()) { return $this->manager()->setPostAuthor(is_array($input) ? $input : array()); }
    public function postAttributesUpdate($input = array()) { return $this->manager()->updatePostAttributes(is_array($input) ? $input : array()); }
    public function postCreate($input = array()) { return $this->manager()->createPost(is_array($input) ? $input : array()); }
    public function postIdentityUpdate($input = array()) { return $this->manager()->updatePostIdentity(is_array($input) ? $input : array()); }
    public function postStatusUpdate($input = array()) { return $this->manager()->updatePostStatus(is_array($input) ? $input : array()); }
    public function postFeaturedMediaSet($input = array()) { return $this->manager()->setFeaturedMedia(is_array($input) ? $input : array()); }
    public function postDuplicate($input = array()) { return $this->manager()->duplicatePost(is_array($input) ? $input : array()); }
    public function postTrash($input = array()) { return $this->manager()->trashPost(is_array($input) ? $input : array()); }
    public function postRestore($input = array()) { return $this->manager()->restorePost(is_array($input) ? $input : array()); }
    public function postDelete($input = array()) { return $this->manager()->deletePost(is_array($input) ? $input : array()); }

    public function canInspect(): bool
    {
        return $this->inspector()->canInspect();
    }

    public function canReadRegisteredMeta($input = array()): bool { return $this->manager()->canReadRegisteredMeta($input); }
    public function canEditRegisteredMeta($input = array()): bool { return $this->manager()->canEditRegisteredMeta($input); }
    public function canCreateTerm($input = array()): bool { return $this->manager()->canManageTerm($input, 'create'); }
    public function canUpdateTerm($input = array()): bool { return $this->manager()->canManageTerm($input, 'update'); }
    public function canDeleteTerm($input = array()): bool { return $this->manager()->canManageTerm($input, 'delete'); }
    public function canAssignTerms($input = array()): bool { return $this->manager()->canAssignTerms($input); }
    public function canSetAuthor($input = array()): bool { return $this->manager()->canSetAuthor($input); }
    public function canListTerms($input = array()): bool { return $this->manager()->canListTerms($input); }
    public function canListAuthors($input = array()): bool { return $this->manager()->canListAuthors($input); }
    public function canUpdatePostAttributes($input = array()): bool { return $this->manager()->canUpdatePostAttributes($input); }
    public function canListPosts($input = array()): bool { return $this->manager()->canListPosts($input); }
    public function canCreatePost($input = array()): bool { return $this->manager()->canCreatePost($input); }
    public function canEditPost($input = array()): bool { return $this->manager()->canEditPost($input); }
    public function canUpdatePostStatus($input = array()): bool { return $this->manager()->canUpdatePostStatus($input); }
    public function canDuplicatePost($input = array()): bool { return $this->manager()->canDuplicatePost($input); }
    public function canDeletePost($input = array()): bool { return $this->manager()->canDeletePost($input); }

    private function registerReadOnly(string $slug, string $label, string $description, array $inputSchema, array $outputSchema, callable $callback, ?callable $permissionCallback = null): void
    {
        wp_register_ability($this->abilityName($slug), array(
            'label' => $label,
            'description' => $description,
            'category' => $this->category(),
            'input_schema' => $inputSchema,
            'output_schema' => $outputSchema,
            'execute_callback' => $callback,
            'permission_callback' => $permissionCallback ?? [$this, 'canInspect'],
            'meta' => $this->readOnlyMeta(),
        ));
    }

    private function registerMutation(string $slug, string $label, string $description, array $inputSchema, array $outputSchema, callable $callback, callable $permissionCallback, bool $destructive, bool $idempotent): void
    {
        wp_register_ability($this->abilityName($slug), array(
            'label' => $label,
            'description' => $description,
            'category' => $this->category(),
            'input_schema' => $inputSchema,
            'output_schema' => $outputSchema,
            'execute_callback' => $callback,
            'permission_callback' => $permissionCallback,
            'meta' => AbilityMetadata::owned($this->key(), false, $destructive, $idempotent),
        ));
    }

    private function inspector(): ContentInspector
    {
        return $this->inspector ??= new ContentInspector();
    }

    private function manager(): ContentManager
    {
        return $this->manager ??= new ContentManager();
    }

    private function abilityName(string $slug): string
    {
        return rtrim(CODI_MCP_ABILITY_PREFIX, '/') . '/' . ltrim($slug, '/');
    }

    private function category(): string
    {
        return rtrim(CODI_MCP_ABILITY_PREFIX, '/') . '-content';
    }

    private function readOnlyMeta(): array
    {
        return AbilityMetadata::owned($this->key(), true, false, true);
    }

    private function pagedSearchInputSchema(): array
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'search' => array('type' => 'string', 'maxLength' => 200),
                'page' => array('type' => 'integer', 'minimum' => 1),
                'per_page' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100),
            ),
        );
    }

    private function registeredBlocksInputSchema(): array
    {
        $schema = $this->pagedSearchInputSchema();
        $schema['properties']['detail'] = array(
            'type' => 'string',
            'enum' => array('summary', 'schema'),
            'default' => 'summary',
        );
        return $schema;
    }

    private function pagedOutputSchema(array $itemSchema): array
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'items' => array('type' => 'array', 'items' => $itemSchema),
                'total' => array('type' => 'integer'),
                'page' => array('type' => 'integer'),
                'per_page' => array('type' => 'integer'),
                'returned' => array('type' => 'integer'),
            ),
            'required' => array('items', 'total', 'page', 'per_page', 'returned'),
        );
    }

    private function postTypeSchema(): array
    {
        $properties = array(
            'name' => array('type' => 'string'),
            'label' => array('type' => 'string'),
            'description' => array('type' => 'string'),
            'public' => array('type' => 'boolean'),
            'show_ui' => array('type' => 'boolean'),
            'show_in_rest' => array('type' => 'boolean'),
            'rest_base' => array('type' => 'string'),
            'rest_namespace' => array('type' => 'string'),
            'hierarchical' => array('type' => 'boolean'),
            'has_archive' => array('type' => 'boolean'),
            'supports' => $this->stringArraySchema(),
            'taxonomies' => $this->stringArraySchema(),
        );
        return $this->strictObject($properties);
    }

    private function taxonomySchema(): array
    {
        $properties = array(
            'name' => array('type' => 'string'),
            'label' => array('type' => 'string'),
            'description' => array('type' => 'string'),
            'public' => array('type' => 'boolean'),
            'show_ui' => array('type' => 'boolean'),
            'show_in_rest' => array('type' => 'boolean'),
            'rest_base' => array('type' => 'string'),
            'rest_namespace' => array('type' => 'string'),
            'hierarchical' => array('type' => 'boolean'),
            'object_types' => $this->stringArraySchema(),
        );
        return $this->strictObject($properties);
    }

    private function termsListInputSchema(): array
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'taxonomy' => $this->taxonomyFieldSchema(),
                'search' => array('type' => 'string', 'maxLength' => 200),
                'hide_empty' => array('type' => 'boolean'),
                'parent' => array('type' => 'integer', 'minimum' => 0),
                'page' => array('type' => 'integer', 'minimum' => 1),
                'per_page' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100),
            ),
            'required' => array('taxonomy'),
        );
    }

    private function termListSchema(): array
    {
        return $this->strictObject(array(
            'term_id' => array('type' => 'integer'),
            'taxonomy' => array('type' => 'string'),
            'name' => array('type' => 'string'),
            'slug' => array('type' => 'string'),
            'description' => array('type' => 'string'),
            'description_truncated' => array('type' => 'boolean'),
            'parent' => array('type' => 'integer'),
            'count' => array('type' => 'integer'),
        ));
    }

    private function authorsListInputSchema(): array
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'post_type' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 20, 'pattern' => '^[a-z0-9_-]+$'),
                'search' => array('type' => 'string', 'maxLength' => 200),
                'page' => array('type' => 'integer', 'minimum' => 1),
                'per_page' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100),
            ),
            'required' => array('post_type'),
        );
    }

    private function authorSchema(): array
    {
        return $this->strictObject(array(
            'user_id' => array('type' => 'integer'),
            'display_name' => array('type' => 'string'),
            'slug' => array('type' => 'string'),
            'avatar_url' => array('type' => 'string'),
            'author_url' => array('type' => 'string'),
        ));
    }

    private function blockSchema(): array
    {
        $attributeProperties = array(
            'name' => array('type' => 'string'),
            'type' => array('type' => 'string'),
            'source' => array('type' => 'string'),
            'selector' => array('type' => 'string'),
            'has_default' => array('type' => 'boolean'),
            'default_json' => array('type' => 'string'),
            'enum_json' => array('type' => 'string'),
        );
        $properties = array(
            'name' => array('type' => 'string'),
            'namespace' => array('type' => 'string'),
            'title' => array('type' => 'string'),
            'api_version' => array('type' => 'integer'),
            'dynamic' => array('type' => 'boolean'),
            'source' => array('type' => 'string'),
            'parent' => $this->stringArraySchema(),
            'ancestor' => $this->stringArraySchema(),
            'attributes' => array('type' => 'array', 'items' => $this->strictObject($attributeProperties)),
            'supports_json' => array('type' => 'string'),
            'editor_scripts' => $this->stringArraySchema(),
            'scripts' => $this->stringArraySchema(),
            'view_scripts' => $this->stringArraySchema(),
            'view_script_modules' => $this->stringArraySchema(),
            'editor_styles' => $this->stringArraySchema(),
            'styles' => $this->stringArraySchema(),
            'view_styles' => $this->stringArraySchema(),
        );
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => $properties,
            'required' => array('name', 'namespace', 'title', 'api_version', 'dynamic', 'source'),
        );
    }

    private function registeredMetaInputSchema(): array
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'object_type' => array('type' => 'string', 'enum' => array('post', 'term', 'comment', 'user', 'blog')),
                'object_subtype' => array('type' => 'string', 'maxLength' => 100),
                'search' => array('type' => 'string', 'maxLength' => 200),
                'page' => array('type' => 'integer', 'minimum' => 1),
                'per_page' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100),
            ),
            'required' => array('object_type'),
        );
    }

    private function registeredMetaSchema(): array
    {
        return $this->strictObject(array(
            'key' => array('type' => 'string'),
            'object_type' => array('type' => 'string'),
            'object_subtype' => array('type' => 'string'),
            'type' => array('type' => 'string'),
            'label' => array('type' => 'string'),
            'description' => array('type' => 'string'),
            'single' => array('type' => 'boolean'),
            'show_in_rest' => array('type' => 'boolean'),
            'rest_schema_json' => array('type' => 'string'),
            'sanitize_callback_present' => array('type' => 'boolean'),
            'auth_callback_present' => array('type' => 'boolean'),
            'revisions_enabled' => array('type' => 'boolean'),
        ));
    }

    private function taxonomyFieldSchema(): array
    {
        return array('type' => 'string', 'minLength' => 1, 'maxLength' => 32, 'pattern' => '^[a-z0-9_-]+$');
    }

    private function termCreateInputSchema(): array
    {
        return array('type' => 'object', 'additionalProperties' => false, 'properties' => array(
            'taxonomy' => $this->taxonomyFieldSchema(),
            'name' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 200),
            'slug' => array('type' => 'string', 'maxLength' => 200),
            'description' => array('type' => 'string', 'maxLength' => 20000),
            'parent' => array('type' => 'integer', 'minimum' => 0),
        ), 'required' => array('taxonomy', 'name'));
    }

    private function termUpdateInputSchema(): array
    {
        return array('type' => 'object', 'additionalProperties' => false, 'properties' => array(
            'taxonomy' => $this->taxonomyFieldSchema(),
            'term_id' => array('type' => 'integer', 'minimum' => 1),
            'name' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 200),
            'slug' => array('type' => 'string', 'maxLength' => 200),
            'description' => array('type' => 'string', 'maxLength' => 20000),
            'parent' => array('type' => 'integer', 'minimum' => 0),
        ), 'required' => array('taxonomy', 'term_id'));
    }

    private function termDeleteInputSchema(): array
    {
        return $this->strictObject(array('taxonomy' => $this->taxonomyFieldSchema(), 'term_id' => array('type' => 'integer', 'minimum' => 1)));
    }

    private function termSchema(): array
    {
        return $this->strictObject(array(
            'term_id' => array('type' => 'integer'),
            'taxonomy' => array('type' => 'string'),
            'name' => array('type' => 'string'),
            'slug' => array('type' => 'string'),
            'description' => array('type' => 'string'),
            'parent' => array('type' => 'integer'),
        ));
    }

    private function postTermsInputSchema(): array
    {
        return $this->strictObject(array(
            'post_id' => array('type' => 'integer', 'minimum' => 1),
            'taxonomy' => $this->taxonomyFieldSchema(),
            'mode' => array('type' => 'string', 'enum' => array('set', 'add', 'remove')),
            'term_ids' => array('type' => 'array', 'maxItems' => 100, 'items' => array('type' => 'integer', 'minimum' => 1)),
        ));
    }

    private function postTermsOutputSchema(): array
    {
        return $this->strictObject(array(
            'post_id' => array('type' => 'integer'),
            'taxonomy' => array('type' => 'string'),
            'mode' => array('type' => 'string'),
            'term_ids' => array('type' => 'array', 'items' => array('type' => 'integer')),
        ));
    }

    private function postAttributesInputSchema(): array
    {
        $properties = array(
            'post_id' => array('type' => 'integer', 'minimum' => 1),
            'parent_id' => array('type' => 'integer', 'minimum' => 0),
            'menu_order' => array('type' => 'integer', 'minimum' => -2147483648, 'maximum' => 2147483647),
            'excerpt' => array('type' => 'string', 'maxLength' => 50000),
            'comment_status' => array('type' => 'string', 'enum' => array('open', 'closed')),
            'ping_status' => array('type' => 'string', 'enum' => array('open', 'closed')),
            'sticky' => array('type' => 'boolean'),
        );
        $anyOf = array();
        foreach (array('parent_id', 'menu_order', 'excerpt', 'comment_status', 'ping_status', 'sticky') as $field) {
            $anyOf[] = array('type' => 'object', 'required' => array($field), 'additionalProperties' => true);
        }
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => $properties,
            'required' => array('post_id'),
            'anyOf' => $anyOf,
        );
    }

    private function postAttributesOutputSchema(): array
    {
        return $this->strictObject(array(
            'post_id' => array('type' => 'integer'),
            'post_type' => array('type' => 'string'),
            'parent_id' => array('type' => 'integer'),
            'menu_order' => array('type' => 'integer'),
            'excerpt' => array('type' => 'string'),
            'comment_status' => array('type' => 'string'),
            'ping_status' => array('type' => 'string'),
            'sticky' => array('type' => 'boolean'),
            'updated' => array('type' => 'boolean'),
            'updated_fields' => array('type' => 'array', 'items' => array('type' => 'string')),
        ));
    }

    private function registeredMetaTargetInputSchema(): array
    {
        return $this->strictObject(array(
            'object_type' => array('type' => 'string', 'enum' => array('post', 'term')),
            'object_id' => array('type' => 'integer', 'minimum' => 1),
            'key' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 255),
        ));
    }

    private function registeredMetaSetInputSchema(): array
    {
        return $this->strictObject(array(
            'object_type' => array('type' => 'string', 'enum' => array('post', 'term')),
            'object_id' => array('type' => 'integer', 'minimum' => 1),
            'key' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 255),
            'value_json' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 65536),
        ));
    }

    private function registeredMetaValueSchema(): array
    {
        return $this->strictObject(array(
            'object_type' => array('type' => 'string'),
            'object_id' => array('type' => 'integer'),
            'key' => array('type' => 'string'),
            'single' => array('type' => 'boolean'),
            'type' => array('type' => 'string'),
            'exists' => array('type' => 'boolean'),
            'value_json' => array('type' => 'string'),
            'truncated' => array('type' => 'boolean'),
        ));
    }

    private function postsListInputSchema(): array
    {
        return $this->strictObjectOptional(array(
            'post_type' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 100),
            'search' => array('type' => 'string', 'maxLength' => 500),
            'page' => array('type' => 'integer', 'minimum' => 1),
            'per_page' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100),
        ), array('post_type'));
    }

    private function postsListOutputSchema(): array
    {
        return $this->strictObject(array('items' => array('type' => 'array', 'items' => $this->postEntitySchema()), 'page' => array('type' => 'integer'), 'per_page' => array('type' => 'integer'), 'returned' => array('type' => 'integer')));
    }

    private function postCreateInputSchema(): array
    {
        return $this->strictObjectOptional(array('post_type' => array('type' => 'string', 'minLength' => 1), 'title' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 500), 'slug' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 200)), array('post_type', 'title'));
    }

    private function postIdentityInputSchema(): array
    {
        return array('type' => 'object', 'additionalProperties' => false, 'properties' => array('post_id' => array('type' => 'integer', 'minimum' => 1), 'title' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 500), 'slug' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 200)), 'required' => array('post_id'), 'anyOf' => array(array('type' => 'object', 'required' => array('title')), array('type' => 'object', 'required' => array('slug'))));
    }

    private function postStatusInputSchema(): array
    {
        return $this->strictObjectOptional(array('post_id' => array('type' => 'integer', 'minimum' => 1), 'status' => array('type' => 'string', 'enum' => array('draft', 'pending', 'publish', 'private', 'future')), 'publish_at' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 100)), array('post_id', 'status'));
    }

    private function postDuplicateInputSchema(): array
    {
        return $this->strictObjectOptional(array('post_id' => array('type' => 'integer', 'minimum' => 1), 'title' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 500), 'slug' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 200)), array('post_id'));
    }

    private function postEntitySchema(): array
    {
        return $this->strictObject(array('post_id' => array('type' => 'integer'), 'post_type' => array('type' => 'string'), 'title' => array('type' => 'string'), 'slug' => array('type' => 'string'), 'status' => array('type' => 'string'), 'author_id' => array('type' => 'integer'), 'parent_id' => array('type' => 'integer')));
    }

    private function strictObjectOptional(array $properties, array $required): array
    {
        return array('type' => 'object', 'additionalProperties' => false, 'properties' => $properties, 'required' => $required);
    }

    private function strictObject(array $properties): array
    {
        return array('type' => 'object', 'additionalProperties' => false, 'properties' => $properties, 'required' => array_keys($properties));
    }

    private function stringArraySchema(): array
    {
        return array('type' => 'array', 'items' => array('type' => 'string'));
    }
}
