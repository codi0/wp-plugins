<?php

declare(strict_types=1);

namespace {
    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int { return (int) ($GLOBALS['codi_mcp_test_wp_current_user_id'] ?? 0); }
    }
    if (!function_exists('get_userdata')) {
        function get_userdata(int $userId) {
            $user = $GLOBALS['codi_mcp_test_wp_users'][$userId] ?? null;
            return is_array($user) ? (object) array('ID' => $userId, 'display_name' => (string) ($user['display_name'] ?? '')) : false;
        }
    }
    if (!function_exists('is_user_member_of_blog')) {
        function is_user_member_of_blog(int $userId, int $siteId): bool { return isset($GLOBALS['codi_mcp_test_wp_users'][$userId]['sites'][$siteId]); }
    }
    if (!function_exists('get_taxonomy')) {
        function get_taxonomy(string $taxonomy) {
            $data = $GLOBALS['codi_mcp_test_wp_taxonomies'][$taxonomy] ?? null;
            if (!is_array($data)) { return false; }
            return (object) array(
                'name' => $taxonomy,
                'hierarchical' => (bool) ($data['hierarchical'] ?? false),
                'object_type' => array_values((array) ($data['object_type'] ?? array('post'))),
                'cap' => (object) array(
                    'manage_terms' => (string) ($data['manage_terms'] ?? 'manage_categories'),
                    'edit_terms' => (string) ($data['edit_terms'] ?? 'manage_categories'),
                    'delete_terms' => (string) ($data['delete_terms'] ?? 'manage_categories'),
                    'assign_terms' => (string) ($data['assign_terms'] ?? 'edit_posts'),
                ),
            );
        }
    }
    if (!function_exists('term_exists')) {
        function term_exists($term, string $taxonomy = '') {
            foreach ((array) (($GLOBALS['codi_mcp_test_wp_terms'] ?? array())[$taxonomy] ?? array()) as $row) {
                if ((int) ($row['term_id'] ?? 0) === (int) $term || (string) ($row['slug'] ?? '') === (string) $term) {
                    return array('term_id' => (int) $row['term_id'], 'term_taxonomy_id' => (int) $row['term_id']);
                }
            }
            return null;
        }
    }
    if (!function_exists('get_term')) {
        function get_term(int $termId, string $taxonomy = '') {
            $taxonomies = $taxonomy !== '' ? array($taxonomy) : array_keys((array) ($GLOBALS['codi_mcp_test_wp_terms'] ?? array()));
            foreach ($taxonomies as $tax) {
                foreach ((array) (($GLOBALS['codi_mcp_test_wp_terms'] ?? array())[$tax] ?? array()) as $row) {
                    if ((int) ($row['term_id'] ?? 0) === $termId) {
                        return (object) array(
                            'term_id' => $termId,
                            'taxonomy' => (string) $tax,
                            'name' => (string) ($row['name'] ?? ''),
                            'slug' => (string) ($row['slug'] ?? ''),
                            'description' => (string) ($row['description'] ?? ''),
                            'parent' => (int) ($row['parent'] ?? 0),
                        );
                    }
                }
            }
            return null;
        }
    }
    if (!function_exists('wp_insert_term')) {
        function wp_insert_term(string $name, string $taxonomy, array $args = array()) {
            $GLOBALS['codi_mcp_test_wp_terms'][$taxonomy] ??= array();
            $ids = array_map(static fn (array $row): int => (int) ($row['term_id'] ?? 0), $GLOBALS['codi_mcp_test_wp_terms'][$taxonomy]);
            $id = ($ids === array() ? 0 : max($ids)) + 1;
            $GLOBALS['codi_mcp_test_wp_terms'][$taxonomy][] = array(
                'term_id' => $id,
                'name' => $name,
                'slug' => (string) ($args['slug'] ?? strtolower(str_replace(' ', '-', $name))),
                'description' => (string) ($args['description'] ?? ''),
                'parent' => (int) ($args['parent'] ?? 0),
            );
            return array('term_id' => $id, 'term_taxonomy_id' => $id);
        }
    }
    if (!function_exists('wp_update_term')) {
        function wp_update_term(int $termId, string $taxonomy, array $args = array()) {
            foreach ($GLOBALS['codi_mcp_test_wp_terms'][$taxonomy] as &$row) {
                if ((int) ($row['term_id'] ?? 0) !== $termId) { continue; }
                foreach (array('name', 'slug', 'description', 'parent') as $field) {
                    if (array_key_exists($field, $args)) { $row[$field] = $args[$field]; }
                }
                return array('term_id' => $termId, 'term_taxonomy_id' => $termId);
            }
            return new \WP_Error('term_not_found', 'Term not found.');
        }
    }
    if (!function_exists('wp_delete_term')) {
        function wp_delete_term(int $termId, string $taxonomy) {
            foreach ((array) ($GLOBALS['codi_mcp_test_wp_terms'][$taxonomy] ?? array()) as $index => $row) {
                if ((int) ($row['term_id'] ?? 0) === $termId) {
                    array_splice($GLOBALS['codi_mcp_test_wp_terms'][$taxonomy], $index, 1);
                    return true;
                }
            }
            return false;
        }
    }
    if (!function_exists('wp_set_object_terms')) {
        function wp_set_object_terms(int $objectId, array $terms, string $taxonomy, bool $append = false) {
            $existing = (array) ($GLOBALS['codi_test_term_relationships'][$objectId][$taxonomy] ?? array());
            $next = $append ? array_values(array_unique(array_merge($existing, array_map('intval', $terms)))) : array_values(array_unique(array_map('intval', $terms)));
            $GLOBALS['codi_test_term_relationships'][$objectId][$taxonomy] = $next;
            return $next;
        }
    }
    if (!function_exists('wp_remove_object_terms')) {
        function wp_remove_object_terms(int $objectId, array $terms, string $taxonomy) {
            $existing = (array) ($GLOBALS['codi_test_term_relationships'][$objectId][$taxonomy] ?? array());
            $GLOBALS['codi_test_term_relationships'][$objectId][$taxonomy] = array_values(array_diff($existing, array_map('intval', $terms)));
            return true;
        }
    }
    if (!function_exists('wp_get_object_terms')) {
        function wp_get_object_terms(int $objectId, string $taxonomy, array $args = array()) {
            return array_values((array) ($GLOBALS['codi_test_term_relationships'][$objectId][$taxonomy] ?? array()));
        }
    }
    if (!function_exists('get_registered_meta_keys')) {
        function get_registered_meta_keys(string $objectType, string $objectSubtype = ''): array {
            return (array) ($GLOBALS['codi_test_registered_meta'][$objectType][$objectSubtype] ?? array());
        }
    }
    if (!function_exists('metadata_exists')) {
        function metadata_exists(string $type, int $id, string $key): bool { return array_key_exists($key, (array) ($GLOBALS['codi_test_metadata'][$type][$id] ?? array())); }
    }
    if (!function_exists('get_metadata')) {
        function get_metadata(string $type, int $id, string $key, bool $single = false) {
            if (!metadata_exists($type, $id, $key)) { return $single ? '' : array(); }
            $value = $GLOBALS['codi_test_metadata'][$type][$id][$key];
            return $single ? $value : (is_array($value) && array_is_list($value) ? $value : array($value));
        }
    }
    if (!function_exists('update_metadata')) {
        function update_metadata(string $type, int $id, string $key, $value) { $GLOBALS['codi_test_metadata'][$type][$id][$key] = $value; return true; }
    }
    if (!function_exists('delete_metadata')) {
        function delete_metadata(string $type, int $id, string $key) { $exists = metadata_exists($type, $id, $key); unset($GLOBALS['codi_test_metadata'][$type][$id][$key]); return $exists; }
    }
    if (!function_exists('add_metadata')) {
        function add_metadata(string $type, int $id, string $key, $value, bool $unique = false) {
            $current = (array) ($GLOBALS['codi_test_metadata'][$type][$id][$key] ?? array());
            $current[] = $value;
            $GLOBALS['codi_test_metadata'][$type][$id][$key] = $current;
            return count($current);
        }
    }
    if (!function_exists('wp_slash')) {
        function wp_slash($value) { return $value; }
    }
    if (!function_exists('rest_validate_value_from_schema')) {
        function rest_validate_value_from_schema($value, array $schema, string $param = '') {
            $type = (string) ($schema['type'] ?? '');
            $ok = match ($type) {
                'string' => is_string($value),
                'boolean' => is_bool($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'array' => is_array($value) && array_is_list($value),
                'object' => is_array($value) && !array_is_list($value),
                default => true,
            };
            return $ok ? true : new \WP_Error('invalid_type', 'Invalid schema type.');
        }
    }
    if (!function_exists('get_available_languages')) {
        function get_available_languages(): array { return array('en_GB'); }
    }
    if (!function_exists('get_locale')) {
        function get_locale(): string { return (string) get_option('WPLANG', 'en_US'); }
    }
    if (!function_exists('flush_rewrite_rules')) {
        function flush_rewrite_rules(bool $hard = true): void { $GLOBALS['codi_test_rewrite_flushes'][] = $hard; }
    }

    if (!function_exists('wp_strip_all_tags')) {
        function wp_strip_all_tags(string $text, bool $remove_breaks = false): string {
            $text = strip_tags($text);
            return $remove_breaks ? preg_replace('/[\r\n\t ]+/', ' ', $text) ?? $text : $text;
        }
    }

    final class CodiMcpTestTheme
    {
        public function __construct(private string $stylesheet, private array $data, private bool $block = true, private bool $broken = false) {}
        public function get(string $key): string { return (string) ($this->data[$key] ?? ''); }
        public function get_template(): string { return (string) ($this->data['Template'] ?? $this->stylesheet); }
        public function get_stylesheet(): string { return $this->stylesheet; }
        public function parent() { return null; }
        public function errors() { return $this->broken ? new \WP_Error('broken_theme', 'Broken theme.') : false; }
        public function is_block_theme(): bool { return $this->block; }
    }
    if (!function_exists('wp_get_themes')) {
        function wp_get_themes(array $args = array()): array {
            $themes = (array) ($GLOBALS['codi_test_themes'] ?? array());
            if (array_key_exists('errors', $args) && $args['errors'] !== null) {
                $wantErrors = (bool) $args['errors'];
                $themes = array_filter($themes, static function ($theme) use ($wantErrors): bool {
                    $errors = is_object($theme) && method_exists($theme, 'errors') ? $theme->errors() : false;
                    return is_wp_error($errors) === $wantErrors;
                });
            }
            if (($args['allowed'] ?? null) === true) {
                $allowed = array_fill_keys((array) ($GLOBALS['codi_test_allowed_themes'] ?? array()), true);
                return array_intersect_key($themes, $allowed);
            }
            return $themes;
        }
    }
    if (!function_exists('validate_theme_requirements')) {
        function validate_theme_requirements(string $stylesheet) { return true; }
    }
    if (!function_exists('switch_theme')) {
        function switch_theme(string $stylesheet): void { $GLOBALS['codi_mcp_test_wp_theme_stylesheet'] = $stylesheet; }
    }
}

namespace CodiMcpTest\Unit {
    use CodiMcp\Packages\Content\ContentManager;
    use CodiMcp\Packages\Content\ContentInspector;
    use CodiMcp\Packages\Site\SiteManager;
    use CodiMcp\Packages\Themes\ThemeDeployment;
    use CodiMcp\Packages\Themes\ThemeInspection;
    use CodiMcpTest\Framework\TestCase;

    final class StructuredContentSiteTest extends TestCase
    {
        protected function setUp(): void
        {
            \codi_mcp_test_reset_environment();
            $GLOBALS['codi_mcp_test_wp_current_user_id'] = 7;
            $GLOBALS['codi_mcp_test_wp_users'][7]['sites'][1] = array_merge(
                (array) ($GLOBALS['codi_mcp_test_wp_users'][7]['sites'][1] ?? array()),
                array('manage_categories' => true, 'edit_post' => true, 'edit_post_meta' => true, 'edit_term_meta' => true, 'edit_others_posts' => true, 'edit_others_pages' => true, 'switch_themes' => true)
            );
            $GLOBALS['codi_mcp_test_wp_users'][8] = array(
                'user_login' => 'fixture-editor',
                'user_nicename' => 'fixture-editor',
                'display_name' => 'Fixture Editor',
                'sites' => array(1 => array('read' => true, 'edit_pages' => true)),
            );
            $GLOBALS['codi_mcp_test_wp_taxonomies'] = array(
                'topic' => array('label' => 'Topics', 'object_type' => array('page'), 'hierarchical' => true, 'assign_terms' => 'edit_pages'),
            );
            $GLOBALS['codi_mcp_test_wp_terms'] = array(
                'topic' => array(
                    array('term_id' => 1, 'name' => 'News', 'slug' => 'news', 'description' => '', 'parent' => 0),
                    array('term_id' => 2, 'name' => 'Featured', 'slug' => 'featured', 'description' => '', 'parent' => 0),
                ),
            );
            $GLOBALS['codi_test_term_relationships'] = array();
            $GLOBALS['codi_test_registered_meta'] = array(
                'post' => array(
                    'page' => array(
                        'subtitle' => array('type' => 'string', 'single' => true, 'show_in_rest' => true),
                        'private_key' => array('type' => 'string', 'single' => true, 'show_in_rest' => false),
                    ),
                ),
            );
            $GLOBALS['codi_test_metadata'] = array();
            $GLOBALS['codi_test_rewrite_flushes'] = array();
            $GLOBALS['codi_test_themes'] = array(
                'fixture-theme' => new \CodiMcpTestTheme('fixture-theme', array('Name' => 'Fixture', 'Version' => '1.0')),
                'allowed-theme' => new \CodiMcpTestTheme('allowed-theme', array('Name' => 'Allowed', 'Version' => '2.0')),
                'blocked-theme' => new \CodiMcpTestTheme('blocked-theme', array('Name' => 'Blocked', 'Version' => '3.0')),
            );
            $GLOBALS['codi_test_allowed_themes'] = array('fixture-theme', 'allowed-theme');
            update_option('blogname', 'Old title', false);
            update_option('blogdescription', 'Old tagline', false);
            update_option('show_on_front', 'posts', false);
            update_option('page_on_front', 0, false);
            update_option('page_for_posts', 0, false);
            update_option('timezone_string', 'UTC', false);
            update_option('permalink_structure', '', false);
            update_option('posts_per_page', 10, false);
            update_option('blog_public', 1, false);
            update_option('WPLANG', 'en_US', false);
            update_option('date_format', 'F j, Y', false);
            update_option('time_format', 'g:i a', false);
            update_option('start_of_week', 1, false);
        }

        public function test_post_term_updates_require_existing_terms_and_registered_taxonomy(): void
        {
            $manager = new ContentManager();
            $input = array('post_id' => 101, 'taxonomy' => 'topic', 'mode' => 'set', 'term_ids' => array(1, 2));
            $this->assertTrue($manager->canAssignTerms($input));
            $result = $manager->updatePostTerms($input);
            $this->assertSame(array(1, 2), $result['term_ids']);

            $bad = $manager->updatePostTerms(array('post_id' => 101, 'taxonomy' => 'topic', 'mode' => 'add', 'term_ids' => array(999)));
            $this->assertTrue(is_wp_error($bad));
            $this->assertSame(array(1, 2), $GLOBALS['codi_test_term_relationships'][101]['topic']);
        }

        public function test_terms_and_authors_discovery_are_content_owned_and_capability_scoped(): void
        {
            $manager = new ContentManager();
            $inspector = new ContentInspector();

            $termInput = array('taxonomy' => 'topic', 'page' => 1, 'per_page' => 10);
            $this->assertTrue($manager->canListTerms($termInput));
            $terms = $inspector->termsList($termInput);
            $this->assertSame(2, $terms['total']);
            $this->assertSame(array(1, 2), array_map(static fn (array $row): int => (int) $row['term_id'], $terms['items']));

            $authorInput = array('post_type' => 'page', 'page' => 1, 'per_page' => 10);
            $this->assertTrue($manager->canListAuthors($authorInput));
            $authors = $inspector->authorsList($authorInput);
            $this->assertSame(2, $authors['total']);
            $ids = array_map(static fn (array $row): int => (int) $row['user_id'], $authors['items']);
            sort($ids);
            $this->assertSame(array(7, 8), $ids);
            $this->assertFalse(array_key_exists('user_login', $authors['items'][0]));

            unset($GLOBALS['codi_mcp_test_wp_users'][7]['sites'][1]['edit_others_pages']);
            $ownOnly = $inspector->authorsList($authorInput);
            $this->assertSame(1, $ownOnly['total']);
            $this->assertSame(7, $ownOnly['items'][0]['user_id']);
        }

        public function test_post_attributes_update_is_narrow_typed_and_cycle_safe(): void
        {
            $GLOBALS['codi_mcp_test_wp_posts'][1][] = (object) array(
                'ID' => 120,
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_name' => 'parent-page',
                'post_title' => 'Parent Page',
                'post_excerpt' => '',
                'post_content' => '',
                'post_parent' => 0,
                'menu_order' => 0,
                'comment_status' => 'closed',
                'ping_status' => 'closed',
            );
            $GLOBALS['codi_mcp_test_wp_posts'][1][] = (object) array(
                'ID' => 121,
                'post_type' => 'post',
                'post_status' => 'publish',
                'post_name' => 'news-post',
                'post_title' => 'News Post',
                'post_excerpt' => '',
                'post_content' => '',
                'post_parent' => 0,
                'menu_order' => 0,
                'comment_status' => 'open',
                'ping_status' => 'open',
            );

            $manager = new ContentManager();
            $input = array(
                'post_id' => 101,
                'parent_id' => 120,
                'menu_order' => 4,
                'excerpt' => 'Structured summary',
                'comment_status' => 'open',
            );
            $this->assertTrue($manager->canUpdatePostAttributes($input));
            $result = $manager->updatePostAttributes($input);
            $this->assertTrue($result['updated']);
            $this->assertSame(120, $result['parent_id']);
            $this->assertSame(4, $result['menu_order']);
            $this->assertSame('Structured summary', $result['excerpt']);
            $this->assertSame('open', $result['comment_status']);

            $cycle = $manager->updatePostAttributes(array('post_id' => 120, 'parent_id' => 101));
            $this->assertTrue(is_wp_error($cycle));
            $unsupported = $manager->updatePostAttributes(array('post_id' => 101, 'ping_status' => 'open'));
            $this->assertTrue(is_wp_error($unsupported));

            $sticky = $manager->updatePostAttributes(array('post_id' => 121, 'sticky' => true));
            $this->assertTrue($sticky['sticky']);
            $this->assertTrue(is_sticky(121));
        }

        public function test_registered_meta_is_limited_to_rest_exposed_registered_keys(): void
        {
            $manager = new ContentManager();
            $target = array('object_type' => 'post', 'object_id' => 101, 'key' => 'subtitle');
            $this->assertTrue($manager->canEditRegisteredMeta($target));
            $set = $manager->setRegisteredMeta($target + array('value_json' => '"Hello"'));
            $this->assertSame('"Hello"', $set['value_json']);
            $this->assertSame('Hello', $GLOBALS['codi_test_metadata']['post'][101]['subtitle']);

            $private = array('object_type' => 'post', 'object_id' => 101, 'key' => 'private_key');
            $this->assertFalse($manager->canEditRegisteredMeta($private));
            $this->assertTrue(is_wp_error($manager->setRegisteredMeta($private + array('value_json' => '"secret"'))));
        }

        public function test_content_lifecycle_permissions_and_listing_preserve_object_capabilities(): void
        {
            $manager = new ContentManager();
            $GLOBALS['codi_mcp_test_meta_capabilities']['edit_post'][101] = true;
            $GLOBALS['codi_mcp_test_meta_capabilities']['delete_post'][101] = false;

            $this->assertTrue($manager->canEditPost(array('post_id' => 101)));
            $this->assertFalse($manager->canDeletePost(array('post_id' => 101)), 'Edit permission must not substitute for delete permission.');

            unset($GLOBALS['codi_mcp_test_wp_users'][7]['sites'][1]['publish_pages']);
            $this->assertTrue($manager->canUpdatePostStatus(array('post_id' => 101, 'status' => 'draft')));
            $this->assertFalse($manager->canUpdatePostStatus(array('post_id' => 101, 'status' => 'publish')), 'Publishing must require the post type publish capability.');

            $GLOBALS['codi_mcp_test_public_post_types']['article'] = array(
                'public' => true,
                'show_ui' => true,
                'show_in_rest' => true,
                'edit_cap' => 'edit_articles',
                'create_cap' => 'create_articles',
                'publish_cap' => 'publish_articles',
            );
            $GLOBALS['codi_mcp_test_wp_users'][7]['sites'][1]['edit_articles'] = true;
            $GLOBALS['codi_mcp_test_wp_posts'][1][] = (object) array(
                'ID' => 130,
                'post_type' => 'article',
                'post_status' => 'draft',
                'post_name' => 'article-one',
                'post_title' => 'Article One',
                'post_excerpt' => '',
                'post_content' => '',
                'post_parent' => 0,
            );
            $this->assertTrue($manager->canEditPost(array('post_id' => 130)));
            $this->assertFalse($manager->canDuplicatePost(array('post_id' => 130)), 'Duplication must require the post type create capability.');
            $GLOBALS['codi_mcp_test_wp_users'][7]['sites'][1]['create_articles'] = true;
            $this->assertTrue($manager->canDuplicatePost(array('post_id' => 130)));

            $GLOBALS['codi_mcp_test_wp_posts'][1][] = (object) array(
                'ID' => 140,
                'post_type' => 'page',
                'post_status' => 'private',
                'post_name' => 'hidden-page',
                'post_title' => 'Hidden Page',
                'post_excerpt' => '',
                'post_content' => '',
                'post_parent' => 0,
            );
            $GLOBALS['codi_mcp_test_meta_capabilities']['edit_post'][140] = false;
            $listed = (new ContentInspector())->postsList(array('post_type' => 'page', 'page' => 1, 'per_page' => 100));
            $this->assertFalse(in_array(140, array_map(static fn (array $row): int => (int) $row['post_id'], $listed['items']), true));
        }

        public function test_site_config_update_is_typed_and_soft_flushes_permalink_changes(): void
        {
            $manager = new SiteManager();
            $result = $manager->updateConfig(array(
                'site_title' => 'New title',
                'timezone' => 'Europe/London',
                'locale' => 'en_GB',
                'permalink_structure' => '/%postname%/',
            ));
            $this->assertSame('New title', $result['config']['site_title']);
            $this->assertSame('Europe/London', $result['config']['timezone']);
            $this->assertSame('/%postname%/', $result['config']['permalink_structure']);
            $this->assertSame(array(false), $GLOBALS['codi_test_rewrite_flushes']);

            $invalid = $manager->updateConfig(array('front_page_mode' => 'page', 'page_on_front' => 0));
            $this->assertTrue(is_wp_error($invalid));
        }

        public function test_site_config_update_compensates_partial_option_write_failure(): void
        {
            $manager = new SiteManager();
            $GLOBALS['codi_mcp_test_wp_option_write_failures']['blogdescription'] = 1;
            $result = $manager->updateConfig(array(
                'site_title' => 'Should roll back',
                'tagline' => 'Will fail',
                'permalink_structure' => '/%year%/%postname%/',
            ));

            $this->assertTrue(is_wp_error($result));
            $this->assertSame('codi_mcp_site_config_write_failed', (string) ($result->code ?? ''));
            $config = $manager->config();
            $this->assertSame('Old title', $config['site_title']);
            $this->assertSame('Old tagline', $config['tagline']);
            $this->assertSame('', $config['permalink_structure']);
            $this->assertSame(array(), $GLOBALS['codi_test_rewrite_flushes'], 'Failed site configuration must not flush rewrite rules.');
        }

        public function test_theme_inspection_includes_healthy_installed_themes(): void
        {
            $result = (new ThemeInspection())->list(array('page' => 1, 'per_page' => 100));
            $this->assertSame(3, (int) ($result['total'] ?? 0));
            $stylesheets = array_map(static fn (array $theme): string => (string) ($theme['stylesheet'] ?? ''), (array) ($result['items'] ?? array()));
            sort($stylesheets);
            $this->assertSame(array('allowed-theme', 'blocked-theme', 'fixture-theme'), $stylesheets);
        }

        public function test_theme_activation_respects_current_site_multisite_allowance(): void
        {
            $manager = new ThemeDeployment(20 * 1024 * 1024, 256 * 1024);
            $this->assertTrue($manager->canActivateThemes());
            $blocked = $manager->activate(array('stylesheet' => 'blocked-theme'));
            $this->assertTrue(is_wp_error($blocked));
            $this->assertSame('fixture-theme', get_stylesheet());

            $allowed = $manager->activate(array('stylesheet' => 'allowed-theme'));
            $this->assertSame(true, $allowed['active']);
            $this->assertSame('allowed-theme', get_stylesheet());
        }
    }
}
