<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
}

if (!defined('CODI_MCP_TEST_PLUGIN_DIR')) {
    define('CODI_MCP_TEST_PLUGIN_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

if (!defined('CODI_MCP_TEST_NOW')) {
    define('CODI_MCP_TEST_NOW', '2026-03-17T12:00:00Z');
}

if (!defined('CODI_MCP_TEST_RUN_ID')) {
    $runId = trim((string) getenv('CODI_MCP_TEST_RUN_ID'));
    if ('' === $runId) {
        $runId = 'standalone';
    }
    define('CODI_MCP_TEST_RUN_ID', preg_replace('/[^a-zA-Z0-9_.-]/', '-', $runId) ?: 'standalone');
}

if (!function_exists('codi_mcp_test_temp_dir')) {
    function codi_mcp_test_temp_dir(): string
    {
        static $resolved = null;
        if (is_string($resolved) && '' !== $resolved) {
            return $resolved;
        }

        $candidates = array(
            getenv('CODI_MCP_TEST_TEMP_DIR') ?: '',
            getenv('TMPDIR') ?: '',
            getenv('TEMP') ?: '',
            getenv('TMP') ?: '',
            sys_get_temp_dir(),
            __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '.codi-mcp-test-temp',
        );

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || '' === trim($candidate)) {
                continue;
            }
            $directory = rtrim($candidate, DIRECTORY_SEPARATOR);
            if ('' !== $directory && (is_dir($directory) || @mkdir($directory, 0775, true)) && is_writable($directory)) {
                $resolved = $directory;
                putenv('CODI_MCP_TEST_TEMP_DIR=' . $resolved);
                return $resolved;
            }
        }

        $resolved = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        putenv('CODI_MCP_TEST_TEMP_DIR=' . $resolved);
        return $resolved;
    }
}

if (!defined('CODI_MCP_TEST_ERROR_LOG')) {
    define('CODI_MCP_TEST_ERROR_LOG', codi_mcp_test_temp_dir() . DIRECTORY_SEPARATOR . 'codi-mcp-test-tests-error-' . CODI_MCP_TEST_RUN_ID . '.log');
}

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
if (!getenv('CODI_MCP_TEST_VERBOSE_LOGS')) {
    @ini_set('error_log', CODI_MCP_TEST_ERROR_LOG);
}

$GLOBALS['codi_mcp_test_wp_multisite'] ??= true;
$GLOBALS['codi_mcp_test_wp_current_blog_id'] ??= 1;
$GLOBALS['codi_mcp_test_wp_blog_stack'] ??= array();
$GLOBALS['codi_mcp_test_wp_current_user_id'] ??= 0;
$GLOBALS['codi_mcp_test_wp_users'] ??= array();
$GLOBALS['codi_mcp_test_wp_sites'] ??= array(
    1 => (object) array('blog_id' => 1, 'blogname' => 'Primary Site', 'domain' => 'example.test', 'path' => '/', 'home' => 'https://example.test/'),
    2 => (object) array('blog_id' => 2, 'blogname' => 'Docs Site', 'domain' => 'docs.example.test', 'path' => '/', 'home' => 'https://docs.example.test/'),
);
$GLOBALS['codi_mcp_test_wp_posts'] ??= array();
$GLOBALS['codi_mcp_test_wp_post_meta'] ??= array();

if (!function_exists('codi_mcp_test_default_theme_fixture_root')) {
    function codi_mcp_test_default_theme_fixture_root(): string
    {
        return codi_mcp_test_temp_dir() . DIRECTORY_SEPARATOR . 'codi-mcp-test-theme-fixtures-' . CODI_MCP_TEST_RUN_ID . '-' . getmypid();
    }
}

$GLOBALS['codi_mcp_test_wp_theme_root'] ??= codi_mcp_test_default_theme_fixture_root();
$GLOBALS['codi_mcp_test_wp_theme_stylesheet'] ??= 'fixture-theme';
$GLOBALS['codi_mcp_test_wp_site_stylesheets'] ??= array();
$GLOBALS['codi_mcp_test_wp_options'] ??= array();
$GLOBALS['codi_mcp_test_wp_site_options'] ??= array();
$GLOBALS['codi_mcp_test_filters'] ??= array();


if (!function_exists('codi_mcp_test_remove_directory_tree')) {
    function codi_mcp_test_remove_directory_tree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = @scandir($path);
        if (!is_array($entries)) {
            @rmdir($path);
            return;
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $entryPath = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($entryPath)) {
                codi_mcp_test_remove_directory_tree($entryPath);
                continue;
            }

            @unlink($entryPath);
        }

        @rmdir($path);
    }
}

if (!function_exists('codi_mcp_test_reset_theme_fixture_files')) {
    function codi_mcp_test_reset_theme_fixture_files(): void
    {
        $root = rtrim((string) ($GLOBALS['codi_mcp_test_wp_theme_root'] ?? ''), DIRECTORY_SEPARATOR);
        if ('' !== $root) {
            codi_mcp_test_remove_directory_tree($root);
        }

        $themeRoot = $root . DIRECTORY_SEPARATOR . (string) ($GLOBALS['codi_mcp_test_wp_theme_stylesheet'] ?? 'fixture-theme');
        @mkdir($themeRoot . DIRECTORY_SEPARATOR . 'templates', 0777, true);
        @mkdir($themeRoot . DIRECTORY_SEPARATOR . 'parts', 0777, true);
        @mkdir($themeRoot . DIRECTORY_SEPARATOR . 'patterns', 0777, true);

        file_put_contents($themeRoot . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'archive.html', '<!-- wp:template-part {"slug":"site-header"} /--><main><p>Archive template body.</p></main>');
        file_put_contents($themeRoot . DIRECTORY_SEPARATOR . 'parts' . DIRECTORY_SEPARATOR . 'footer.html', '<!-- wp:group --><footer><p>Footer content</p></footer><!-- /wp:group -->');
        file_put_contents($themeRoot . DIRECTORY_SEPARATOR . 'patterns' . DIRECTORY_SEPARATOR . 'cta-block.php', "<?php
/**
 * Title: CTA Block
 * Slug: fixture-theme/cta-block
 */
?>
<!-- wp:group --><section><h2>Call to action</h2></section><!-- /wp:group -->");
        file_put_contents($themeRoot . DIRECTORY_SEPARATOR . 'theme.json', json_encode(array(
            'version' => 2,
            'settings' => array('color' => array('palette' => array(array('slug' => 'primary', 'color' => '#1d4ed8', 'name' => 'Primary')))),
            'styles' => array('color' => array('text' => '#0f172a')),
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

if (!function_exists('codi_mcp_test_seed_default_runtime_fixtures')) {
    function codi_mcp_test_seed_default_runtime_fixtures(): void
    {
        $GLOBALS['codi_mcp_test_wp_current_user_id'] = 7;
        $GLOBALS['codi_mcp_test_wp_users'][7] = array(
            'user_login' => 'fixture-admin',
            'display_name' => 'Fixture Admin',
            'sites' => array(
                1 => array(
                    'read' => true,
                    'edit_pages' => true,
                    'edit_posts' => true,
                    'edit_theme_options' => true,
                    'upload_files' => true,
                    'manage_options' => true,
                    'publish_pages' => true,
                    'publish_posts' => true,
                    'delete_pages' => true,
                    'delete_posts' => true,
                    'read_private_pages' => true,
                    'read_private_posts' => true,
                ),
                2 => array(
                    'read' => true,
                    'edit_posts' => true,
                ),
            ),
        );

        $GLOBALS['codi_mcp_test_wp_posts'][1] = array(
            (object) array(
                'ID' => 101,
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_name' => 'home',
                'post_title' => 'Home',
                'post_excerpt' => 'Landing page with hero, feature grid, and CTA.',
                'post_content' => '<!-- wp:pattern {"slug":"hero-banner"} /--><p>Welcome to Codi AI.</p>',
                'post_modified_gmt' => '2026-03-17 12:00:00',
            ),
            (object) array(
                'ID' => 102,
                'post_type' => 'wp_template',
                'post_status' => 'publish',
                'post_name' => 'front-page',
                'post_title' => 'Front Page Template',
                'post_excerpt' => 'Global shell that owns the header and footer.',
                'post_content' => '<!-- wp:template-part {"slug":"site-header"} /-->',
                'post_modified_gmt' => '2026-03-17 12:00:00',
            ),
            (object) array(
                'ID' => 103,
                'post_type' => 'wp_template_part',
                'post_status' => 'publish',
                'post_name' => 'site-header',
                'post_title' => 'Site Header',
                'post_excerpt' => 'Header part with logo and primary navigation.',
                'post_content' => '<!-- wp:navigation {"ref":104} /--><!-- wp:image {"id":108} /-->',
                'post_modified_gmt' => '2026-03-17 12:00:00',
            ),
            (object) array(
                'ID' => 104,
                'post_type' => 'wp_navigation',
                'post_status' => 'publish',
                'post_name' => 'primary-nav',
                'post_title' => 'Primary Navigation',
                'post_excerpt' => 'Main navigation used in the site header.',
                'post_content' => '<!-- wp:navigation-link {"label":"Home","url":"/"} -->Home<!-- /wp:navigation-link --><!-- wp:navigation-link {"label":"Docs","url":"/docs"} -->Docs<!-- /wp:navigation-link --><!-- wp:navigation-link {"label":"Pricing","url":"/pricing"} -->Pricing<!-- /wp:navigation-link -->',
                'post_modified_gmt' => '2026-03-17 12:00:00',
            ),
            (object) array(
                'ID' => 105,
                'post_type' => 'wp_block',
                'post_status' => 'publish',
                'post_name' => 'hero-banner',
                'post_title' => 'Hero Banner',
                'post_excerpt' => 'Reusable hero pattern used on the front page.',
                'post_content' => '<section><h1>Build faster</h1></section>',
                'post_modified_gmt' => '2026-03-17 12:00:00',
            ),
            (object) array(
                'ID' => 106,
                'post_type' => 'wp_global_styles',
                'post_status' => 'publish',
                'post_name' => 'global-styles',
                'post_title' => 'Global Styles',
                'post_excerpt' => 'Theme tokens and custom CSS for the primary site.',
                'post_content' => '',
                'post_modified_gmt' => '2026-03-17 12:00:00',
            ),
            (object) array(
                'ID' => 107,
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'post_name' => 'hero-illustration',
                'post_title' => 'Hero Illustration',
                'post_excerpt' => 'Homepage hero artwork',
                'post_content' => '',
                'post_parent' => 101,
                'post_mime_type' => 'image/webp',
                'post_modified_gmt' => '2026-03-17 12:00:00',
            ),
            (object) array(
                'ID' => 108,
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'post_name' => 'site-logo',
                'post_title' => 'Site Logo',
                'post_excerpt' => 'Header logo',
                'post_content' => '',
                'post_parent' => 103,
                'post_mime_type' => 'image/svg+xml',
                'post_modified_gmt' => '2026-03-17 12:00:00',
            ),
        );

        $GLOBALS['codi_mcp_test_wp_posts'][2] = array(
            (object) array(
                'ID' => 201,
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_name' => 'docs-home',
                'post_title' => 'Docs Home',
                'post_excerpt' => 'Documentation landing page for the docs site.',
                'post_content' => '<p>Everything you need to get started.</p>',
                'post_modified_gmt' => '2026-03-17 12:00:00',
            ),
            (object) array(
                'ID' => 202,
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'post_name' => 'docs-diagram',
                'post_title' => 'Docs Diagram',
                'post_excerpt' => 'Architecture diagram for the docs site.',
                'post_content' => '',
                'post_parent' => 201,
                'post_mime_type' => 'image/png',
                'post_modified_gmt' => '2026-03-17 12:00:00',
            ),
        );

        $GLOBALS['codi_mcp_test_wp_post_meta'][101]['_wp_page_template'] = 'front-page.html';
        $GLOBALS['codi_mcp_test_wp_post_meta'][101]['codi_mcp_test_global_style_post_id'] = 106;
        $GLOBALS['codi_mcp_test_wp_post_meta'][101]['codi_mcp_test_script_handles'] = array('theme-home');
        $GLOBALS['codi_mcp_test_wp_post_meta'][101]['codi_mcp_test_asset_bundles'] = array('landing-core');
        $GLOBALS['codi_mcp_test_wp_post_meta'][106]['codi_mcp_test_css'] = '.hero{padding:48px;}';
        $GLOBALS['codi_mcp_test_wp_post_meta'][106]['codi_mcp_test_theme_tokens'] = array(
            'color.primary' => '#1d4ed8',
            'spacing.hero' => '48px',
        );
$GLOBALS['codi_mcp_test_wp_post_meta'][106]['codi_mcp_test_theme_json'] = array(
            'version' => 2,
            'settings' => array(
                'color' => array(
                    'palette' => array(
                        array('slug' => 'primary', 'color' => '#1d4ed8', 'name' => 'Primary'),
                        array('slug' => 'accent', 'color' => '#f97316', 'name' => 'Accent'),
                    ),
                ),
                'spacing' => array(
                    'spacingSizes' => array(
                        array('slug' => 'hero', 'size' => '48px', 'name' => 'Hero'),
                    ),
                ),
                'typography' => array(
                    'fontSizes' => array(
                        array('slug' => 'body', 'size' => '16px', 'name' => 'Body'),
                    ),
                    'fontFamilies' => array(
                        array('slug' => 'sans', 'fontFamily' => 'Inter, sans-serif', 'name' => 'Sans'),
                    ),
                ),
            ),
            'styles' => array(
                'color' => array('background' => '#ffffff', 'text' => '#0f172a'),
            ),
        );
        $GLOBALS['codi_mcp_test_wp_post_meta'][106]['codi_mcp_test_script_handles'] = array('theme-global');
        $GLOBALS['codi_mcp_test_wp_post_meta'][106]['codi_mcp_test_asset_bundles'] = array('global-core');
        $GLOBALS['codi_mcp_test_wp_post_meta'][107]['_wp_attachment_image_alt'] = 'Illustration of a team building with AI blocks.';
        $GLOBALS['codi_mcp_test_wp_post_meta'][107]['codi_mcp_test_source_url'] = 'https://example.test/uploads/hero-illustration.webp';
        $GLOBALS['codi_mcp_test_wp_post_meta'][108]['_wp_attachment_image_alt'] = 'Codi MCP wordmark';
        $GLOBALS['codi_mcp_test_wp_post_meta'][108]['codi_mcp_test_source_url'] = 'https://example.test/uploads/site-logo.svg';
        $GLOBALS['codi_mcp_test_wp_post_meta'][202]['_wp_attachment_image_alt'] = 'System architecture diagram';
        $GLOBALS['codi_mcp_test_wp_post_meta'][202]['codi_mcp_test_source_url'] = 'https://docs.example.test/uploads/docs-diagram.png';
    }
}

if (!function_exists('codi_mcp_test_reset_environment')) {
    function codi_mcp_test_reset_environment(): void
    {
        $GLOBALS['codi_mcp_test_actions'] = array();
        $GLOBALS['codi_mcp_test_action_counts'] = array();
        $GLOBALS['codi_mcp_test_cron_events'] = array();
        $GLOBALS['codi_mcp_test_deactivation_hooks'] = array();
        $GLOBALS['codi_mcp_test_current_filter_stack'] = array();
        $GLOBALS['codi_mcp_test_registered_abilities'] = array();
        $GLOBALS['codi_mcp_test_registered_ability_categories'] = array();
        $GLOBALS['codi_mcp_test_wp_register_ability_results'] = array();
        $GLOBALS['codi_mcp_test_rest_routes'] = array();
        $GLOBALS['codi_mcp_test_rest_server'] = null;
        $GLOBALS['codi_mcp_test_filters'] = array();
        $GLOBALS['codi_mcp_test_enqueued_scripts'] = array();
        $GLOBALS['codi_mcp_test_inline_scripts'] = array();
        $GLOBALS['codi_mcp_test_wp_remote_get_calls'] = array();
        $GLOBALS['codi_mcp_test_wp_remote_get_responses'] = array();
        $GLOBALS['codi_mcp_test_wp_remote_post_calls'] = array();
        $GLOBALS['codi_mcp_test_wp_remote_post_responses'] = array();
        $GLOBALS['codi_mcp_test_wp_valid_auth_cookie_user_id'] = 0;
        $existingUsers = is_array($GLOBALS['codi_mcp_test_wp_users'] ?? null) ? $GLOBALS['codi_mcp_test_wp_users'] : array();
        $GLOBALS['codi_mcp_test_wp_current_user_id'] = 0;
        $GLOBALS['codi_mcp_test_wp_current_blog_id'] = 1;
        $GLOBALS['codi_mcp_test_wp_blog_stack'] = array();
        $GLOBALS['codi_mcp_test_wp_posts'] = array();
        $GLOBALS['codi_mcp_test_wp_post_meta'] = array();
        $GLOBALS['codi_mcp_test_wp_options'] = array();
        $GLOBALS['codi_mcp_test_wp_transients'] = array();
        $GLOBALS['codi_mcp_test_set_transient_failures'] = 0;
        $GLOBALS['codi_mcp_test_delete_transient_failures'] = 0;
        $GLOBALS['codi_mcp_test_wp_option_write_failures'] = array();
        $GLOBALS['codi_mcp_test_wp_option_delete_failures'] = array();
        $GLOBALS['codi_mcp_test_wp_update_post_failures'] = array();
        $GLOBALS['codi_mcp_test_meta_capabilities'] = array();
        $GLOBALS['codi_mcp_test_wp_site_options'] = array();
        $GLOBALS['codi_mcp_test_wp_site_option_write_failures'] = array();
        $GLOBALS['codi_mcp_test_wp_site_option_delete_failures'] = array();
        $GLOBALS['codi_mcp_presentation_block_templates'] = array();
        $GLOBALS['codi_mcp_presentation_block_template_sources'] = array();
        $GLOBALS['codi_mcp_test_transaction_journal'] = array();
        $GLOBALS['codi_mcp_test_transaction_sequence'] = 0;
        $GLOBALS['codi_mcp_test_oauth_state_table'] = array();
        $GLOBALS['codi_mcp_test_admin_pages'] = array();
        $GLOBALS['codi_mcp_test_network_admin_pages'] = array();
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['PHP_AUTH_USER']);
        $GLOBALS['codi_mcp_test_wp_theme_root'] = codi_mcp_test_default_theme_fixture_root();
        codi_mcp_test_reset_theme_fixture_files();
        codi_mcp_test_seed_default_runtime_fixtures();
        if (function_exists('codi_mcp_presentation_reset_test_doubles')) {
            codi_mcp_presentation_reset_test_doubles();
        }
        $GLOBALS['codi_mcp_test_wp_users'] = array_replace($GLOBALS['codi_mcp_test_wp_users'], $existingUsers);
    }
}

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path(string $file): string
    {
        return dirname($file) . DIRECTORY_SEPARATOR;
    }
}

if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url(string $file): string
    {
        return 'https://example.test/plugins/' . basename(dirname($file)) . '/';
    }
}

if (!function_exists('__return_true')) {
    function __return_true(): bool
    {
        return true;
    }
}

spl_autoload_register(static function (string $className): void {
    $prefix = 'CodiMcp\\';
    if (strpos($className, $prefix) !== 0) {
        return;
    }

    $relative = substr($className, strlen($prefix));
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});


if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook)
    {
        foreach ((array) ($GLOBALS['codi_mcp_test_cron_events'] ?? array()) as $event) {
            if ((string) ($event['hook'] ?? '') === $hook) {
                return (int) ($event['timestamp'] ?? 0);
            }
        }
        return false;
    }
}

if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = array())
    {
        $GLOBALS['codi_mcp_test_cron_events'][] = array(
            'timestamp' => $timestamp,
            'recurrence' => $recurrence,
            'hook' => $hook,
            'args' => $args,
        );
        return true;
    }
}

if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook(string $hook): int
    {
        $events = (array) ($GLOBALS['codi_mcp_test_cron_events'] ?? array());
        $kept = array();
        $removed = 0;
        foreach ($events as $event) {
            if ((string) ($event['hook'] ?? '') === $hook) {
                ++$removed;
                continue;
            }
            $kept[] = $event;
        }
        $GLOBALS['codi_mcp_test_cron_events'] = $kept;
        return $removed;
    }
}

if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook(string $file, $callback): void
    {
        $GLOBALS['codi_mcp_test_deactivation_hooks'][$file] = $callback;
    }
}

if (!function_exists('add_action')) {
    $GLOBALS['codi_mcp_test_actions'] = array();

    function add_action(string $hook, $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        $GLOBALS['codi_mcp_test_actions'][$hook][] = array(
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $acceptedArgs,
        );
    }
}

if (!function_exists('do_action')) {
    function do_action(string $hook, ...$args): void
    {
        $GLOBALS['codi_mcp_test_action_counts'][$hook] = (int) ($GLOBALS['codi_mcp_test_action_counts'][$hook] ?? 0) + 1;
        $callbacks = $GLOBALS['codi_mcp_test_actions'][$hook] ?? array();
        usort($callbacks, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);
        $GLOBALS['codi_mcp_test_current_filter_stack'][] = $hook;
        try {
            foreach ($callbacks as $registration) {
                $accepted = (int) ($registration['accepted_args'] ?? 1);
                $invokeArgs = array_slice($args, 0, max(0, $accepted));
                call_user_func_array($registration['callback'], $invokeArgs);
            }
        } finally {
            array_pop($GLOBALS['codi_mcp_test_current_filter_stack']);
        }
    }
}

if (!function_exists('did_action')) {
    function did_action(string $hook): int
    {
        return (int) ($GLOBALS['codi_mcp_test_action_counts'][$hook] ?? 0);
    }
}

if (!function_exists('doing_action')) {
    function doing_action(?string $hook = null): bool
    {
        $stack = (array) ($GLOBALS['codi_mcp_test_current_filter_stack'] ?? array());
        if (null === $hook || '' === $hook) {
            return $stack !== array();
        }

        return in_array($hook, $stack, true);
    }
}

if (!function_exists('current_filter')) {
    function current_filter(): ?string
    {
        $stack = (array) ($GLOBALS['codi_mcp_test_current_filter_stack'] ?? array());
        if ($stack === array()) {
            return null;
        }

        return end($stack) ?: null;
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook, $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        $GLOBALS['codi_mcp_test_filters'][$hook][] = array(
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $acceptedArgs,
        );
    }
}

if (!function_exists('remove_filter')) {
    function remove_filter(string $hook, $callback, int $priority = 10): bool
    {
        $callbacks = (array) ($GLOBALS['codi_mcp_test_filters'][$hook] ?? array());
        foreach ($callbacks as $index => $registration) {
            if ((int) ($registration['priority'] ?? 10) === $priority && ($registration['callback'] ?? null) === $callback) {
                unset($callbacks[$index]);
                $GLOBALS['codi_mcp_test_filters'][$hook] = array_values($callbacks);
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, $value, ...$args)
    {
        $callbacks = $GLOBALS['codi_mcp_test_filters'][$hook] ?? array();
        usort($callbacks, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);
        foreach ($callbacks as $registration) {
            $accepted = (int) ($registration['accepted_args'] ?? 1);
            $invokeArgs = array_merge(array($value), array_slice($args, 0, max(0, $accepted - 1)));
            $value = call_user_func_array($registration['callback'], $invokeArgs);
        }

        return $value;
    }
}

if (!function_exists('wp_register_ability')) {
    $GLOBALS['codi_mcp_test_registered_abilities'] = array();

    function wp_register_ability(string $abilityName, array $args = array())
    {
        if (function_exists('apply_filters')) {
            $args = apply_filters('wp_register_ability_args', $args, $abilityName);
        }
        $GLOBALS['codi_mcp_test_registered_abilities'][$abilityName] = $args;
        $queued = $GLOBALS['codi_mcp_test_wp_register_ability_results'] ?? array();
        if (is_array($queued) && $queued !== array()) {
            return array_shift($GLOBALS['codi_mcp_test_wp_register_ability_results']);
        }

        return true;
    }
}

if (!class_exists('WP_Ability')) {
    class WP_Ability
    {
        protected string $name;
        protected array $descriptor;

        public function __construct(string $name, array $descriptor)
        {
            $this->name = $name;
            $this->descriptor = $this->prepare_properties($descriptor);
        }

        protected function prepare_properties(array $descriptor): array
        {
            return $descriptor;
        }

        public function get_name(): string
        {
            return $this->name;
        }

        public function get_label(): string
        {
            return (string) ($this->descriptor['label'] ?? $this->name);
        }

        public function get_description(): string
        {
            return (string) ($this->descriptor['description'] ?? '');
        }

        public function get_category(): string
        {
            return (string) ($this->descriptor['category'] ?? '');
        }

        public function get_meta(): array
        {
            return is_array($this->descriptor['meta'] ?? null) ? $this->descriptor['meta'] : array();
        }

        public function get_input_schema(): array
        {
            return is_array($this->descriptor['input_schema'] ?? null) ? $this->descriptor['input_schema'] : array();
        }

        public function get_output_schema(): array
        {
            return is_array($this->descriptor['output_schema'] ?? null) ? $this->descriptor['output_schema'] : array();
        }

        protected function do_execute($input = null)
        {
            $callback = $this->descriptor['execute_callback'] ?? null;
            if (!is_callable($callback)) {
                return new WP_Error('ability_invalid_execute_callback', 'Invalid execute callback.', array('status' => 500));
            }
            return $this->get_input_schema() === array() ? $callback() : $callback($input);
        }

        protected function validate_output($output)
        {
            return true;
        }

        public function execute($input = null)
        {
            $permission = $this->descriptor['permission_callback'] ?? null;
            if (!is_callable($permission)) {
                return new WP_Error('ability_invalid_permission_callback', 'Invalid permission callback.', array('status' => 500));
            }
            $allowed = $this->get_input_schema() === array() ? $permission() : $permission($input);
            if ($allowed instanceof WP_Error) {
                return $allowed;
            }
            if (!$allowed) {
                return new WP_Error('ability_permission_denied', 'Permission denied.', array('status' => 403));
            }

            do_action('wp_before_execute_ability', $this->name, $input, $this);
            $result = $this->do_execute($input);
            if ($result instanceof WP_Error) {
                return $result;
            }
            $valid = $this->validate_output($result);
            if ($valid instanceof WP_Error) {
                return $valid;
            }
            do_action('wp_after_execute_ability', $this->name, $input, $result, $this);
            return $result;
        }
    }
}

if (!class_exists('CodiMcpTestAbility')) {
    final class CodiMcpTestAbility extends WP_Ability
    {
    }
}

if (!function_exists('wp_get_abilities')) {
    function wp_get_abilities(): array
    {
        $abilities = array();
        foreach ((array) ($GLOBALS['codi_mcp_test_registered_abilities'] ?? array()) as $name => $descriptor) {
            if (!is_string($name) || !is_array($descriptor)) {
                continue;
            }
            $className = 'CodiMcpTestAbility';
            $candidate = $descriptor['ability_class'] ?? '';
            if (is_string($candidate) && $candidate !== '' && class_exists($candidate) && is_subclass_of($candidate, WP_Ability::class)) {
                $className = $candidate;
            }
            $abilities[$name] = new $className($name, $descriptor);
        }
        return $abilities;
    }
}

if (!function_exists('wp_register_ability_category')) {
    $GLOBALS['codi_mcp_test_registered_ability_categories'] = array();

    function wp_register_ability_category(string $categoryName, array $args = array())
    {
        $GLOBALS['codi_mcp_test_registered_ability_categories'][$categoryName] = $args;
        return true;
    }
}

if (!function_exists('register_rest_route')) {
    $GLOBALS['codi_mcp_test_rest_routes'] = array();

    function register_rest_route(string $namespace, string $route, array $args = array()): void
    {
        $GLOBALS['codi_mcp_test_rest_routes'][$namespace . $route] = $args;
    }
}

if (!function_exists('rest_get_server')) {
    function rest_get_server()
    {
        return $GLOBALS['codi_mcp_test_rest_server'] ?? null;
    }
}

if (!function_exists('wp_register_script')) {
    $GLOBALS['codi_mcp_test_registered_scripts'] = array();

    function wp_register_script(string $handle, string $src = '', array $deps = array(), $ver = false, bool $inFooter = false): bool
    {
        $GLOBALS['codi_mcp_test_registered_scripts'][$handle] = array(
            'src' => $src,
            'deps' => $deps,
            'ver' => $ver,
            'in_footer' => $inFooter,
        );

        return true;
    }
}

if (!function_exists('wp_enqueue_script')) {
    $GLOBALS['codi_mcp_test_enqueued_scripts'] = array();

    function wp_enqueue_script(string $handle): bool
    {
        $GLOBALS['codi_mcp_test_enqueued_scripts'][] = $handle;
        return true;
    }
}

if (!function_exists('wp_add_inline_script')) {
    $GLOBALS['codi_mcp_test_inline_scripts'] = array();

    function wp_add_inline_script(string $handle, string $data, string $position = 'after'): bool
    {
        $GLOBALS['codi_mcp_test_inline_scripts'][$handle][] = array(
            'data' => $data,
            'position' => $position,
        );

        return true;
    }
}

if (!function_exists('register_block_type')) {
    $GLOBALS['codi_mcp_test_registered_block_types'] = array();

    function register_block_type(string $blockName, array $args = array()): array
    {
        $GLOBALS['codi_mcp_test_registered_block_types'][$blockName] = $args;
        return $args;
    }
}

if (!function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        return (bool) ($GLOBALS['codi_mcp_test_wp_multisite'] ?? false);
    }
}

if (!function_exists('get_current_blog_id')) {
    function get_current_blog_id(): int
    {
        return (int) ($GLOBALS['codi_mcp_test_wp_current_blog_id'] ?? 1);
    }
}

if (!function_exists('get_current_network_id')) {
    function get_current_network_id(): int
    {
        return 1;
    }
}

if (!function_exists('switch_to_blog')) {
    function switch_to_blog(int $blogId): void
    {
        $GLOBALS['codi_mcp_test_wp_blog_stack'][] = get_current_blog_id();
        $GLOBALS['codi_mcp_test_wp_current_blog_id'] = $blogId;
    }
}

if (!function_exists('restore_current_blog')) {
    function restore_current_blog(): void
    {
        $previous = array_pop($GLOBALS['codi_mcp_test_wp_blog_stack']);
        $GLOBALS['codi_mcp_test_wp_current_blog_id'] = null === $previous ? 1 : (int) $previous;
    }
}

if (!function_exists('get_blog_details')) {
    function get_blog_details(int $siteId): ?object
    {
        return $GLOBALS['codi_mcp_test_wp_sites'][$siteId] ?? null;
    }
}

if (!function_exists('get_sites')) {
    function get_sites(array $args = array()): array
    {
        $sites = array_values((array) ($GLOBALS['codi_mcp_test_wp_sites'] ?? array()));
        if (!empty($args['fields']) && 'ids' === $args['fields']) {
            return array_values(array_map(static fn ($site): int => (int) ($site->blog_id ?? 0), $sites));
        }
        return $sites;
    }
}

if (!function_exists('get_blogs_of_user')) {
    function get_blogs_of_user(int $userId): array
    {
        $user = $GLOBALS['codi_mcp_test_wp_users'][$userId] ?? null;
        $siteIds = array_keys((array) ($user['sites'] ?? array()));
        $blogs = array();
        foreach ($siteIds as $siteId) {
            $blogs[$siteId] = (object) array('userblog_id' => (int) $siteId, 'blog_id' => (int) $siteId);
        }

        return $blogs;
    }
}

if (!function_exists('home_url')) {
    function home_url(string $path = '/'): string
    {
        $site = get_blog_details(get_current_blog_id());
        $base = $site?->home ?? 'https://example.test/';
        return rtrim((string) $base, '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('rest_url')) {
    function rest_url(string $path = ''): string
    {
        return rtrim(home_url('/wp-json/'), '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return rtrim(home_url('/wp-admin/'), '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('network_admin_url')) {
    function network_admin_url(string $path = ''): string
    {
        return rtrim(home_url('/wp-admin/network/'), '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('add_menu_page')) {
    function add_menu_page(string $pageTitle, string $menuTitle, string $capability, string $menuSlug, $callback = '', string $iconUrl = '', $position = null): string
    {
        $GLOBALS['codi_mcp_test_admin_pages'][$menuSlug] = array(
            'page_title' => $pageTitle,
            'menu_title' => $menuTitle,
            'capability' => $capability,
            'callback' => $callback,
        );

        return $menuSlug;
    }
}

if (!function_exists('add_submenu_page')) {
    function add_submenu_page(string $parentSlug, string $pageTitle, string $menuTitle, string $capability, string $menuSlug, $callback = ''): string
    {
        $GLOBALS['codi_mcp_test_network_admin_pages'][$menuSlug] = array(
            'parent_slug' => $parentSlug,
            'page_title' => $pageTitle,
            'menu_title' => $menuTitle,
            'capability' => $capability,
            'callback' => $callback,
        );

        return $menuSlug;
    }
}

if (!function_exists('add_management_page')) {
    $GLOBALS['codi_mcp_test_admin_pages'] = array();

    function add_management_page(string $pageTitle, string $menuTitle, string $capability, string $menuSlug, $callback = ''): string
    {
        $GLOBALS['codi_mcp_test_admin_pages'][$menuSlug] = array(
            'page_title' => $pageTitle,
            'menu_title' => $menuTitle,
            'capability' => $capability,
            'callback' => $callback,
        );

        return $menuSlug;
    }
}

if (!function_exists('plugin_basename')) {
    function plugin_basename(string $file): string
    {
        return basename(dirname($file)) . '/' . basename($file);
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = ''): string
    {
        return $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = ''): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return $url;
    }
}

if (!function_exists('wp_die')) {
    function wp_die(string $message = ''): void
    {
        throw new RuntimeException($message);
    }
}


if (!function_exists('wp_set_current_user')) {
    function wp_set_current_user(int $userId): void
    {
        $GLOBALS['codi_mcp_test_wp_current_user_id'] = $userId;
    }
}

if (!function_exists('wp_validate_auth_cookie')) {
    function wp_validate_auth_cookie(string $cookie = '', string $scheme = ''): int
    {
        return (int) ($GLOBALS['codi_mcp_test_wp_valid_auth_cookie_user_id'] ?? 0);
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action = '-1'): string
    {
        return hash('sha256', $action . '|nonce|' . (string) ($GLOBALS['codi_mcp_test_wp_current_user_id'] ?? 0));
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce(string $nonce, string $action = '-1'): bool
    {
        return hash_equals(wp_create_nonce($action), $nonce);
    }
}

if (!function_exists('wp_remote_get')) {
    $GLOBALS['codi_mcp_test_wp_remote_get_calls'] = array();
    $GLOBALS['codi_mcp_test_wp_remote_get_responses'] = array();

    function wp_remote_get(string $url, array $args = array())
    {
        $GLOBALS['codi_mcp_test_wp_remote_get_calls'][] = array('url' => $url, 'args' => $args);
        $queued = $GLOBALS['codi_mcp_test_wp_remote_get_responses'][$url] ?? null;
        if (is_callable($queued)) {
            return $queued($url, $args);
        }
        if (is_array($queued) || $queued instanceof WP_Error) {
            return $queued;
        }

        return array('body' => '');
    }
}

if (!function_exists('wp_remote_post')) {
    $GLOBALS['codi_mcp_test_wp_remote_post_calls'] = array();
    $GLOBALS['codi_mcp_test_wp_remote_post_responses'] = array();

    function wp_remote_post(string $url, array $args = array())
    {
        $GLOBALS['codi_mcp_test_wp_remote_post_calls'][] = array('url' => $url, 'args' => $args);
        $queued = $GLOBALS['codi_mcp_test_wp_remote_post_responses'][$url] ?? null;
        if (is_callable($queued)) {
            return $queued($url, $args);
        }
        if (is_array($queued) || $queued instanceof WP_Error) {
            return $queued;
        }

        return array('response' => array('code' => 200), 'body' => '{}');
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response): string
    {
        if (is_array($response)) {
            return (string) ($response['body'] ?? '');
        }

        return is_object($response) && isset($response->body) ? (string) $response->body : '';
    }
}


if (!function_exists('wp_login_url')) {
    function wp_login_url(string $redirect = ''): string
    {
        $url = home_url('wp-login.php');
        if ('' !== trim($redirect)) {
            $url .= '?redirect_to=' . rawurlencode($redirect);
        }
        return $url;
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(public string $code = 'error', public string $message = '', public mixed $data = null)
        {
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_data(): mixed
        {
            return $this->data;
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public function __construct(private $data = null, private int $status = 200, private array $headers = array())
        {
        }

        public function get_data()
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }

        public function get_headers(): array
        {
            return $this->headers;
        }
    }
}

if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool
    {
        return (int) (wp_get_current_user()->ID ?? 0) > 0;
    }
}

if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user(): object
    {
        $userId = (int) ($GLOBALS['codi_mcp_test_wp_current_user_id'] ?? 0);
        $user = $GLOBALS['codi_mcp_test_wp_users'][$userId] ?? null;
        if (null === $user) {
            return (object) array('ID' => 0, 'user_login' => '');
        }

        return (object) array(
            'ID' => $userId,
            'user_login' => $user['user_login'] ?? ('user-' . $userId),
            'display_name' => $user['display_name'] ?? ('User ' . $userId),
        );
    }
}

if (!function_exists('user_can_for_site')) {
    function user_can_for_site(int $userId, int $siteId, string $capability): bool
    {
        return !empty($GLOBALS['codi_mcp_test_wp_users'][$userId]['sites'][$siteId][$capability]);
    }
}

if (!function_exists('user_can')) {
    function user_can($userOrId, string $capability): bool
    {
        $userId = is_object($userOrId) ? (int) ($userOrId->ID ?? 0) : (int) $userOrId;
        return user_can_for_site($userId, get_current_blog_id(), $capability);
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability, ...$args): bool
    {
        $userId = (int) ($GLOBALS['codi_mcp_test_wp_current_user_id'] ?? 0);
        if (in_array($capability, array('edit_post', 'delete_post', 'read_post'), true) && isset($args[0])) {
            $postId = (int) $args[0];
            $overrides = (array) ($GLOBALS['codi_mcp_test_meta_capabilities'][$capability] ?? array());
            if (array_key_exists($postId, $overrides)) {
                return (bool) $overrides[$postId];
            }
            $post = get_post($postId);
            if (!is_object($post)) {
                return false;
            }
            $postType = (string) ($post->post_type ?? 'post');
            if ('edit_post' === $capability) {
                $base = 'page' === $postType ? 'edit_pages' : ('attachment' === $postType ? 'upload_files' : 'edit_posts');
            } elseif ('delete_post' === $capability) {
                $base = 'page' === $postType ? 'delete_pages' : ('attachment' === $postType ? 'upload_files' : 'delete_posts');
            } elseif ('private' === (string) ($post->post_status ?? '')) {
                $base = 'page' === $postType ? 'read_private_pages' : ('attachment' === $postType ? 'upload_files' : 'read_private_posts');
            } else {
                $base = 'attachment' === $postType ? 'upload_files' : 'read';
            }
            return user_can_for_site($userId, get_current_blog_id(), $base);
        }
        return user_can_for_site($userId, get_current_blog_id(), $capability);
    }
}

if (!function_exists('wp_timezone_string')) {
    function wp_timezone_string(): string
    {
        return 'UTC';
    }
}

if (!function_exists('wp_timezone')) {
    function wp_timezone(): \DateTimeZone
    {
        return new \DateTimeZone(wp_timezone_string());
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta(int $postId, string $key, $value): void
    {
        $GLOBALS['codi_mcp_test_wp_post_meta'][$postId][$key] = $value;
    }
}

if (!function_exists('delete_post_meta')) {
    function delete_post_meta(int $postId, string $key): void
    {
        if (isset($GLOBALS['codi_mcp_test_wp_post_meta'][$postId]) && array_key_exists($key, $GLOBALS['codi_mcp_test_wp_post_meta'][$postId])) {
            unset($GLOBALS['codi_mcp_test_wp_post_meta'][$postId][$key]);
        }
    }
}

if (!function_exists('delete_post_meta_by_key')) {
    function delete_post_meta_by_key(string $key): void
    {
        foreach ((array) ($GLOBALS['codi_mcp_test_wp_post_meta'] ?? array()) as $postId => $meta) {
            if (!is_array($meta) || !array_key_exists($key, $meta)) {
                continue;
            }
            unset($GLOBALS['codi_mcp_test_wp_post_meta'][$postId][$key]);
        }
    }
}

if (!function_exists('set_transient')) {
    function set_transient(string $key, mixed $value, int $expiration = 0): bool
    {
        $failures = (int) ($GLOBALS['codi_mcp_test_set_transient_failures'] ?? 0);
        if ($failures > 0) {
            $GLOBALS['codi_mcp_test_set_transient_failures'] = $failures - 1;
            return false;
        }
        $GLOBALS['codi_mcp_test_wp_transients'][$key] = array(
            'value' => $value,
            'expires' => $expiration > 0 ? time() + $expiration : 0,
        );
        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient(string $key): mixed
    {
        $entry = $GLOBALS['codi_mcp_test_wp_transients'][$key] ?? null;
        if (!is_array($entry)) {
            return false;
        }
        $expires = (int) ($entry['expires'] ?? 0);
        if ($expires > 0 && $expires < time()) {
            unset($GLOBALS['codi_mcp_test_wp_transients'][$key]);
            return false;
        }
        return $entry['value'] ?? false;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $key): bool
    {
        $failures = (int) ($GLOBALS['codi_mcp_test_delete_transient_failures'] ?? 0);
        if ($failures > 0) {
            $GLOBALS['codi_mcp_test_delete_transient_failures'] = $failures - 1;
            return false;
        }
        $exists = array_key_exists($key, (array) ($GLOBALS['codi_mcp_test_wp_transients'] ?? array()));
        unset($GLOBALS['codi_mcp_test_wp_transients'][$key]);
        return $exists;
    }
}

if (!function_exists('get_option')) {
    function get_option(string $key, $default = false)
    {
        $GLOBALS['codi_mcp_test_wp_options'] ??= array();
        $siteId = function_exists('get_current_blog_id') ? get_current_blog_id() : 1;
        $siteOptions = (array) ($GLOBALS['codi_mcp_test_wp_options'][$siteId] ?? array());
        return array_key_exists($key, $siteOptions) ? $siteOptions[$key] : $default;
    }
}

if (!function_exists('get_post_type_object')) {
    function get_post_type_object(string $post_type)
    {
        $known = array(
            'page' => array('public' => true, 'show_ui' => true, 'show_in_rest' => true, 'edit_cap' => 'edit_pages'),
            'post' => array('public' => true, 'show_ui' => true, 'show_in_rest' => true, 'edit_cap' => 'edit_posts'),
            'attachment' => array('public' => true, 'show_ui' => true, 'show_in_rest' => true, 'edit_cap' => 'upload_files'),
        ) + (array) ($GLOBALS['codi_mcp_test_public_post_types'] ?? array());
        if (!isset($known[$post_type]) || !is_array($known[$post_type])) {
            return null;
        }
        $data = $known[$post_type];
        return (object) array(
            'name' => $post_type,
            'public' => (bool) ($data['public'] ?? true),
            'show_ui' => (bool) ($data['show_ui'] ?? true),
            'show_in_rest' => (bool) ($data['show_in_rest'] ?? true),
            'hierarchical' => (bool) ($data['hierarchical'] ?? ('page' === $post_type)),
            'cap' => (object) array(
                'edit_posts' => (string) ($data['edit_cap'] ?? 'edit_posts'),
                'edit_others_posts' => (string) ($data['edit_others_cap'] ?? ('page' === $post_type ? 'edit_others_pages' : 'edit_others_posts')),
                'create_posts' => (string) ($data['create_cap'] ?? $data['edit_cap'] ?? 'edit_posts'),
                'publish_posts' => (string) ($data['publish_cap'] ?? ('page' === $post_type ? 'publish_pages' : 'publish_posts')),
                'delete_posts' => (string) ($data['delete_cap'] ?? ('page' === $post_type ? 'delete_pages' : 'delete_posts')),
                'read_private_posts' => (string) ($data['read_private_cap'] ?? ('page' === $post_type ? 'read_private_pages' : 'read_private_posts')),
            ),
        );
    }
}

if (!function_exists('post_type_supports')) {
    function post_type_supports(string $postType, string $feature): bool
    {
        if ('page' === $postType) {
            return in_array($feature, array('editor', 'page-attributes', 'excerpt', 'comments'), true);
        }
        if ('post' === $postType) {
            return in_array($feature, array('editor', 'excerpt', 'comments', 'trackbacks'), true);
        }
        return true;
    }
}

if (!function_exists('get_taxonomies')) {
    function get_taxonomies(array $args = array(), string $output = 'names')
    {
        $taxonomies = (array) ($GLOBALS['codi_mcp_test_wp_taxonomies'] ?? array());
        if ('objects' !== $output) {
            return array_keys($taxonomies);
        }
        $objects = array();
        foreach ($taxonomies as $slug => $taxonomy) {
            $data = is_array($taxonomy) ? $taxonomy : array();
            $objects[$slug] = (object) array(
                'name' => (string) $slug,
                'label' => (string) ($data['label'] ?? $slug),
                'public' => (bool) ($data['public'] ?? true),
                'object_type' => array_values(array_filter((array) ($data['object_type'] ?? array('post')), 'is_string')),
            );
        }
        return $objects;
    }
}

if (!function_exists('get_terms')) {
    function get_terms(array $args = array())
    {
        $taxonomy = (string) ($args['taxonomy'] ?? 'category');
        $terms = (array) (($GLOBALS['codi_mcp_test_wp_terms'] ?? array())[$taxonomy] ?? array());
        return array_map(static function ($term) use ($taxonomy) {
            $data = is_array($term) ? $term : array();
            return (object) array(
                'term_id' => (int) ($data['term_id'] ?? 0),
                'taxonomy' => $taxonomy,
                'slug' => (string) ($data['slug'] ?? ''),
                'name' => (string) ($data['name'] ?? ($data['slug'] ?? '')),
                'description' => (string) ($data['description'] ?? ''),
            );
        }, $terms);
    }
}

if (!function_exists('get_users')) {
    function get_users(array $args = array()): array
    {
        $users = array();
        foreach ((array) ($GLOBALS['codi_mcp_test_wp_users'] ?? array()) as $id => $user) {
            if (!is_array($user)) {
                continue;
            }
            $users[] = (object) array(
                'ID' => (int) ($user['ID'] ?? $user['id'] ?? $id),
                'user_login' => (string) ($user['user_login'] ?? ('user-' . $id)),
                'user_nicename' => (string) ($user['user_nicename'] ?? ($user['user_login'] ?? ('user-' . $id))),
                'display_name' => (string) ($user['display_name'] ?? ($user['user_login'] ?? ('User ' . $id))),
                'description' => (string) ($user['description'] ?? ''),
            );
        }
        return $users;
    }
}

if (!function_exists('get_avatar_url')) {
    function get_avatar_url($id): string
    {
        return 'https://example.test/avatar/' . (int) $id . '.png';
    }
}

if (!function_exists('get_author_posts_url')) {
    function get_author_posts_url($id): string
    {
        return 'https://example.test/author/' . (int) $id . '/';
    }
}

if (!function_exists('add_option')) {
    function add_option(string $key, $value, string $unused = '', bool $autoload = false): bool
    {
        $GLOBALS['codi_mcp_test_wp_options'] ??= array();
        $siteId = function_exists('get_current_blog_id') ? get_current_blog_id() : 1;
        $GLOBALS['codi_mcp_test_wp_options'][$siteId] ??= array();
        if (array_key_exists($key, $GLOBALS['codi_mcp_test_wp_options'][$siteId])) {
            return false;
        }
        $GLOBALS['codi_mcp_test_wp_options'][$siteId][$key] = $value;
        return true;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $key, $value, bool $autoload = false): bool
    {
        $GLOBALS['codi_mcp_test_wp_options'] ??= array();
        $failures = (array) ($GLOBALS['codi_mcp_test_wp_option_write_failures'] ?? array());
        if (!empty($failures[$key])) {
            if (is_int($failures[$key])) {
                $GLOBALS['codi_mcp_test_wp_option_write_failures'][$key] = max(0, $failures[$key] - 1);
            }
            return false;
        }
        $siteId = function_exists('get_current_blog_id') ? get_current_blog_id() : 1;
        $GLOBALS['codi_mcp_test_wp_options'][$siteId] ??= array();
        $oldValue = $GLOBALS['codi_mcp_test_wp_options'][$siteId][$key] ?? false;
        if (function_exists('apply_filters')) {
            $value = apply_filters('pre_update_option', $value, $key, $oldValue);
            $value = apply_filters('pre_update_option_' . $key, $value, $oldValue, $key);
        }
        $GLOBALS['codi_mcp_test_wp_options'][$siteId][$key] = $value;
        if (function_exists('do_action')) {
            do_action('updated_option', $key, $oldValue, $value);
            do_action('updated_option_' . $key, $oldValue, $value, $key);
        }
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $key): bool
    {
        $GLOBALS['codi_mcp_test_wp_options'] ??= array();
        $failures = (int) ($GLOBALS['codi_mcp_test_wp_option_delete_failures'][$key] ?? 0);
        if ($failures > 0) {
            $GLOBALS['codi_mcp_test_wp_option_delete_failures'][$key] = $failures - 1;
            return false;
        }
        $siteId = function_exists('get_current_blog_id') ? get_current_blog_id() : 1;
        $GLOBALS['codi_mcp_test_wp_options'][$siteId] ??= array();
        $exists = array_key_exists($key, $GLOBALS['codi_mcp_test_wp_options'][$siteId]);
        unset($GLOBALS['codi_mcp_test_wp_options'][$siteId][$key]);
        return $exists;
    }
}

if (!function_exists('get_site_option')) {
    function get_site_option(string $key, $default = false)
    {
        $GLOBALS['codi_mcp_test_wp_site_options'] ??= array();
        return array_key_exists($key, $GLOBALS['codi_mcp_test_wp_site_options']) ? $GLOBALS['codi_mcp_test_wp_site_options'][$key] : $default;
    }
}

if (!function_exists('add_site_option')) {
    function add_site_option(string $key, $value): bool
    {
        $GLOBALS['codi_mcp_test_wp_site_options'] ??= array();
        if (array_key_exists($key, $GLOBALS['codi_mcp_test_wp_site_options'])) {
            return false;
        }

        $GLOBALS['codi_mcp_test_wp_site_options'][$key] = $value;
        return true;
    }
}

if (!function_exists('update_site_option')) {
    function update_site_option(string $key, $value): bool
    {
        $GLOBALS['codi_mcp_test_wp_site_options'] ??= array();
        $failures = (int) ($GLOBALS['codi_mcp_test_wp_site_option_write_failures'][$key] ?? 0);
        if ($failures > 0) {
            $GLOBALS['codi_mcp_test_wp_site_option_write_failures'][$key] = $failures - 1;
            return false;
        }
        $GLOBALS['codi_mcp_test_wp_site_options'][$key] = $value;
        return true;
    }
}

if (!function_exists('delete_site_option')) {
    function delete_site_option(string $key): bool
    {
        $GLOBALS['codi_mcp_test_wp_site_options'] ??= array();
        $failures = (int) ($GLOBALS['codi_mcp_test_wp_site_option_delete_failures'][$key] ?? 0);
        if ($failures > 0) {
            $GLOBALS['codi_mcp_test_wp_site_option_delete_failures'][$key] = $failures - 1;
            return false;
        }
        $exists = array_key_exists($key, $GLOBALS['codi_mcp_test_wp_site_options']);
        unset($GLOBALS['codi_mcp_test_wp_site_options'][$key]);
        return $exists;
    }
}

if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir(): array
    {
        $baseDir = codi_mcp_test_temp_dir() . '/codi-mcp-test-uploads';
        $baseUrl = 'https://example.test/uploads';
        if (!is_dir($baseDir)) {
            @mkdir($baseDir, 0777, true);
        }
        return array(
            'path' => $baseDir,
            'url' => $baseUrl,
            'subdir' => '',
            'basedir' => $baseDir,
            'baseurl' => $baseUrl,
            'error' => false,
        );
    }
}

if (!function_exists('wp_save_post_revision')) {
    function wp_save_post_revision(int $postId, array $snapshot = array())
    {
        $post = get_post($postId);
        if (!is_object($post)) {
            return 0;
        }
        if ($snapshot === array()) {
            $snapshot = array(
                'post_title' => (string) ($post->post_title ?? ''),
                'post_excerpt' => (string) ($post->post_excerpt ?? ''),
                'post_content' => (string) ($post->post_content ?? ''),
                'post_status' => (string) ($post->post_status ?? 'publish'),
            );
        }
        $payload = array(
            'post_type' => 'revision',
            'post_status' => 'inherit',
            'post_name' => 'revision-' . $postId . '-' . substr(sha1((string) ($post->post_modified_gmt ?? CODI_MCP_TEST_NOW) . '|' . json_encode($snapshot)), 0, 12),
            'post_title' => (string) ($post->post_title ?? ''),
            'post_excerpt' => (string) ($post->post_excerpt ?? ''),
            'post_content' => (string) ($post->post_content ?? ''),
            'post_parent' => $postId,
        );
        $revisionId = wp_insert_post($payload, true);
        if ((int) $revisionId > 0) {
            update_post_meta((int) $revisionId, 'codi_mcp_test_revision_snapshot', $snapshot);
            $GLOBALS['codi_mcp_test_wp_post_meta'][$postId]['codi_mcp_test_last_native_revision_id'] = (int) $revisionId;
        }
        return $revisionId;
    }
}

if (!function_exists('wp_restore_post_revision')) {
    function wp_restore_post_revision(int $revisionId)
    {
        $revision = get_post($revisionId);
        if (!is_object($revision) || (string) ($revision->post_type ?? '') !== 'revision') {
            return 0;
        }
        $parentId = (int) ($revision->post_parent ?? 0);
        if ($parentId < 1) {
            return 0;
        }
        $snapshot = get_post_meta($revisionId, 'codi_mcp_test_revision_snapshot', true);
        $payload = array('ID' => $parentId);
        if (is_array($snapshot)) {
            if (array_key_exists('post_title', $snapshot)) {
                $payload['post_title'] = (string) $snapshot['post_title'];
            }
            if (array_key_exists('post_excerpt', $snapshot)) {
                $payload['post_excerpt'] = (string) $snapshot['post_excerpt'];
            }
            if (array_key_exists('post_content', $snapshot)) {
                $payload['post_content'] = (string) $snapshot['post_content'];
            }
            if (array_key_exists('post_status', $snapshot)) {
                $payload['post_status'] = (string) $snapshot['post_status'];
            }
        } else {
            $payload['post_title'] = (string) ($revision->post_title ?? '');
            $payload['post_excerpt'] = (string) ($revision->post_excerpt ?? '');
            $payload['post_content'] = (string) ($revision->post_content ?? '');
        }
        $result = wp_update_post($payload, true);
        if (is_wp_error($result) || (int) $result < 1) {
            return 0;
        }
        $GLOBALS['codi_mcp_test_wp_post_meta'][$parentId]['codi_mcp_test_last_native_revision_id'] = $revisionId;
        return $parentId;
    }
}

if (!function_exists('wp_get_post_revisions')) {
    function wp_get_post_revisions(int $postId): array
    {
        $revisions = array();
        foreach ((array) ($GLOBALS['codi_mcp_test_wp_posts'][get_current_blog_id()] ?? array()) as $post) {
            if ((string) ($post->post_type ?? '') !== 'revision' || (int) ($post->post_parent ?? 0) !== $postId) {
                continue;
            }
            $revisions[(int) $post->ID] = $post;
        }
        return $revisions;
    }
}

if (!function_exists('wp_insert_post')) {
    function wp_insert_post(array $payload, bool $wpError = false)
    {
        $siteId = get_current_blog_id();
        $nextId = 1;
        foreach ((array) ($GLOBALS['codi_mcp_test_wp_posts'] ?? array()) as $posts) {
            foreach ((array) $posts as $post) {
                $nextId = max($nextId, (int) ($post->ID ?? 0) + 1);
            }
        }
        $importId = (int) ($payload['import_id'] ?? 0);
        if ($importId > 0) {
            $occupied = false;
            foreach ((array) ($GLOBALS['codi_mcp_test_wp_posts'] ?? array()) as $posts) {
                foreach ((array) $posts as $post) {
                    if ((int) ($post->ID ?? 0) === $importId) {
                        $occupied = true;
                        break 2;
                    }
                }
            }
            if (!$occupied) {
                $nextId = $importId;
            }
        }
        $post = (object) array(
            'ID' => $nextId,
            'post_type' => (string) ($payload['post_type'] ?? 'post'),
            'post_status' => (string) ($payload['post_status'] ?? 'publish'),
            'post_name' => (string) ($payload['post_name'] ?? ''),
            'post_title' => (string) ($payload['post_title'] ?? ''),
            'post_excerpt' => (string) ($payload['post_excerpt'] ?? ''),
            'post_content' => (string) ($payload['post_content'] ?? ''),
            'post_parent' => (int) ($payload['post_parent'] ?? 0),
            'menu_order' => (int) ($payload['menu_order'] ?? 0),
            'comment_status' => (string) ($payload['comment_status'] ?? 'closed'),
            'ping_status' => (string) ($payload['ping_status'] ?? 'closed'),
            'post_mime_type' => (string) ($payload['post_mime_type'] ?? ''),
            'post_modified_gmt' => str_replace('T', ' ', substr((string) CODI_MCP_TEST_NOW, 0, 19)),
            'post_date_gmt' => (string) ($payload['post_date_gmt'] ?? str_replace('T', ' ', substr((string) CODI_MCP_TEST_NOW, 0, 19))),
            'post_date' => (string) ($payload['post_date'] ?? str_replace('T', ' ', substr((string) CODI_MCP_TEST_NOW, 0, 19))),
        );
        $GLOBALS['codi_mcp_test_wp_posts'][$siteId][] = $post;
        return $nextId;
    }
}

if (!function_exists('wp_update_post')) {
    function wp_update_post(array $payload, bool $wpError = false)
    {
        $postId = (int) ($payload['ID'] ?? 0);
        $failures = (array) ($GLOBALS['codi_mcp_test_wp_update_post_failures'] ?? array());
        if (!empty($failures[$postId])) {
            if (is_int($failures[$postId])) {
                $GLOBALS['codi_mcp_test_wp_update_post_failures'][$postId] = max(0, $failures[$postId] - 1);
            }
            return $wpError ? new WP_Error('update_failed', 'Configured post update failure.') : 0;
        }
        foreach ((array) ($GLOBALS['codi_mcp_test_wp_posts'] ?? array()) as $siteId => $posts) {
            foreach ((array) $posts as $index => $post) {
                if ((int) ($post->ID ?? 0) !== $postId) {
                    continue;
                }
                foreach (array('post_type','post_status','post_name','post_title','post_excerpt','post_content','post_parent','menu_order','comment_status','ping_status','post_mime_type','post_date_gmt','post_date') as $field) {
                    if (array_key_exists($field, $payload)) {
                        $post->{$field} = $payload[$field];
                    }
                }
                $post->post_modified_gmt = str_replace('T', ' ', substr((string) CODI_MCP_TEST_NOW, 0, 19));
                $GLOBALS['codi_mcp_test_wp_posts'][$siteId][$index] = $post;
                return $postId;
            }
        }
        return $wpError ? new WP_Error('not_found', 'Post not found.') : 0;
    }
}

if (!function_exists('is_sticky')) {
    function is_sticky(int $postId = 0): bool
    {
        return in_array($postId, array_map('intval', (array) get_option('sticky_posts', array())), true);
    }
}

if (!function_exists('stick_post')) {
    function stick_post(int $postId): void
    {
        $sticky = array_values(array_unique(array_merge(array_map('intval', (array) get_option('sticky_posts', array())), array($postId))));
        update_option('sticky_posts', $sticky, false);
    }
}

if (!function_exists('unstick_post')) {
    function unstick_post(int $postId): void
    {
        $sticky = array_values(array_filter(array_map('intval', (array) get_option('sticky_posts', array())), static fn (int $id): bool => $id !== $postId));
        update_option('sticky_posts', $sticky, false);
    }
}

if (!function_exists('get_posts')) {
    function get_posts(array $args = array()): array
    {
        $siteId = get_current_blog_id();
        $postTypes = array_values((array) ($args['post_type'] ?? array()));
        $limit = (int) ($args['posts_per_page'] ?? 10);
        $offset = max(0, (int) ($args['offset'] ?? 0));
        $query = strtolower(trim((string) ($args['s'] ?? '')));
        $statusFilter = array_values((array) ($args['post_status'] ?? array()));
        $permission = trim((string) ($args['perm'] ?? ''));
        $matches = array();

        foreach ((array) ($GLOBALS['codi_mcp_test_wp_posts'][$siteId] ?? array()) as $post) {
            if ($postTypes !== array() && !in_array((string) ($post->post_type ?? ''), $postTypes, true)) {
                continue;
            }
            if ($statusFilter !== array() && !in_array((string) ($post->post_status ?? 'publish'), $statusFilter, true)) {
                continue;
            }
            $postId = (int) ($post->ID ?? 0);
            if ('editable' === $permission && !current_user_can('edit_post', $postId)) {
                continue;
            }
            if ('readable' === $permission && !current_user_can('read_post', $postId)) {
                continue;
            }
            if ('' !== $query) {
                $haystack = strtolower(trim((string) ($post->post_title ?? '') . ' ' . (string) ($post->post_excerpt ?? '') . ' ' . (string) ($post->post_content ?? '')));
                if (!str_contains($haystack, $query)) {
                    continue;
                }
            }
            $matches[] = $post;
        }

        if ($offset > 0) {
            $matches = array_slice($matches, $offset);
        }
        if ($limit >= 0) {
            $matches = array_slice($matches, 0, $limit);
        }

        return array_values($matches);
    }
}

if (!function_exists('get_post')) {
    function get_post(int $postId): ?object
    {
        foreach ((array) ($GLOBALS['codi_mcp_test_wp_posts'] ?? array()) as $sitePosts) {
            foreach ((array) $sitePosts as $post) {
                if ((int) ($post->ID ?? 0) === $postId) {
                    return $post;
                }
            }
        }

        return null;
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta(int $postId, string $key, bool $single = true)
    {
        $value = $GLOBALS['codi_mcp_test_wp_post_meta'][$postId][$key] ?? null;
        return $single ? $value : array($value);
    }
}

if (!function_exists('wp_get_attachment_url')) {
    function wp_get_attachment_url(int $postId): string
    {
        return (string) (($GLOBALS['codi_mcp_test_wp_post_meta'][$postId]['codi_mcp_test_source_url'] ?? $GLOBALS['codi_mcp_test_wp_post_meta'][$postId]['source_url'] ?? home_url('uploads/' . $postId)));
    }
}

if (!function_exists('parse_blocks')) {
    function parse_blocks(string $content): array
    {
        if ('' === trim($content)) {
            return array();
        }

        $offset = 0;
        return codi_mcp_test_test_parse_top_level_blocks($content, $offset);
    }

    function codi_mcp_test_test_normalize_block_name(string $name): string
    {
        if ('' === $name || false !== strpos($name, '/')) {
            return $name;
        }

        return 'core/' . $name;
    }

    function codi_mcp_test_test_parse_top_level_blocks(string $content, int &$offset): array
    {
        $pattern = '/<!--\s*(\/)?wp:([\w\/-]+)(?:\s+(\{.*?\}))?\s*(\/)?\s*-->/s';
        $blocks = array();
        $length = strlen($content);
        while ($offset < $length) {
            if (!preg_match($pattern, $content, $match, PREG_OFFSET_CAPTURE, $offset)) {
                $remaining = substr($content, $offset);
                if ('' !== trim($remaining)) {
                    $blocks[] = array('blockName' => 'core/freeform', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => $remaining, 'innerContent' => array($remaining));
                }
                $offset = $length;
                break;
            }

            $matchStart = (int) $match[0][1];
            $matchText = (string) $match[0][0];
            $matchEnd = $matchStart + strlen($matchText);
            $before = substr($content, $offset, $matchStart - $offset);
            if ('' !== trim($before)) {
                $blocks[] = array('blockName' => 'core/freeform', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => $before, 'innerContent' => array($before));
            }

            $isClose = '/' === trim((string) ($match[1][0] ?? ''));
            $name = codi_mcp_test_test_normalize_block_name((string) ($match[2][0] ?? ''));
            $attrs = array();
            if (!empty($match[3][0])) {
                $decoded = json_decode((string) $match[3][0], true);
                if (is_array($decoded)) {
                    $attrs = $decoded;
                }
            }
            $selfClosing = '/' === trim((string) ($match[4][0] ?? '')) || str_ends_with(trim($matchText), '/-->');
            $offset = $matchEnd;

            if ($isClose) {
                continue;
            }

            if ($selfClosing) {
                $blocks[] = array('blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array(''));
                continue;
            }

            [$innerBlocks, $innerContent, $offset] = codi_mcp_test_test_parse_inner_block_content($content, $offset, $name, $pattern);
            $blocks[] = array(
                'blockName' => $name,
                'attrs' => $attrs,
                'innerBlocks' => $innerBlocks,
                'innerHTML' => codi_mcp_test_test_inner_html_from_segments($innerContent),
                'innerContent' => $innerContent,
            );
        }

        return $blocks;
    }

    function codi_mcp_test_test_parse_inner_block_content(string $content, int $offset, string $expectedCloseName, string $pattern): array
    {
        $children = array();
        $segments = array('');
        $cursor = $offset;
        $length = strlen($content);

        while ($cursor < $length) {
            if (!preg_match($pattern, $content, $match, PREG_OFFSET_CAPTURE, $cursor)) {
                $segments[count($segments) - 1] .= substr($content, $cursor);
                return array($children, codi_mcp_test_test_ensure_trailing_string_segment($segments), $length);
            }

            $matchStart = (int) $match[0][1];
            $matchText = (string) $match[0][0];
            $matchEnd = $matchStart + strlen($matchText);
            $segments[count($segments) - 1] .= substr($content, $cursor, $matchStart - $cursor);

            $isClose = '/' === trim((string) ($match[1][0] ?? ''));
            $name = codi_mcp_test_test_normalize_block_name((string) ($match[2][0] ?? ''));
            $attrs = array();
            if (!empty($match[3][0])) {
                $decoded = json_decode((string) $match[3][0], true);
                if (is_array($decoded)) {
                    $attrs = $decoded;
                }
            }
            $selfClosing = '/' === trim((string) ($match[4][0] ?? '')) || str_ends_with(trim($matchText), '/-->');
            $cursor = $matchEnd;

            if ($isClose) {
                if ($name === $expectedCloseName) {
                    return array($children, codi_mcp_test_test_ensure_trailing_string_segment($segments), $cursor);
                }
                $segments[count($segments) - 1] .= $matchText;
                continue;
            }

            if ($selfClosing) {
                $children[] = array('blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array(''));
                $segments[] = null;
                $segments[] = '';
                continue;
            }

            [$grandChildren, $innerContent, $cursor] = codi_mcp_test_test_parse_inner_block_content($content, $cursor, $name, $pattern);
            $children[] = array(
                'blockName' => $name,
                'attrs' => $attrs,
                'innerBlocks' => $grandChildren,
                'innerHTML' => codi_mcp_test_test_inner_html_from_segments($innerContent),
                'innerContent' => $innerContent,
            );
            $segments[] = null;
            $segments[] = '';
        }

        return array($children, codi_mcp_test_test_ensure_trailing_string_segment($segments), $cursor);
    }

    function codi_mcp_test_test_inner_html_from_segments(array $segments): string
    {
        $parts = array();
        foreach ($segments as $segment) {
            if (null !== $segment) {
                $parts[] = (string) $segment;
            }
        }
        return implode('', $parts);
    }

    function codi_mcp_test_test_ensure_trailing_string_segment(array $segments): array
    {
        if ($segments === array()) {
            return array('');
        }
        if (null === $segments[count($segments) - 1]) {
            $segments[] = '';
        }
        return $segments;
    }
}



if (!function_exists('get_stylesheet')) {
    function get_stylesheet(): string
    {
        $siteId = function_exists('get_current_blog_id') ? get_current_blog_id() : 1;
        $siteStylesheets = (array) ($GLOBALS['codi_mcp_test_wp_site_stylesheets'] ?? array());
        $stylesheet = trim((string) ($siteStylesheets[$siteId] ?? ''));
        if ('' !== $stylesheet) {
            return $stylesheet;
        }
        return (string) ($GLOBALS['codi_mcp_test_wp_theme_stylesheet'] ?? 'fixture-theme');
    }
}

if (!function_exists('get_stylesheet_directory')) {
    function get_stylesheet_directory(): string
    {
        return rtrim((string) ($GLOBALS['codi_mcp_test_wp_theme_root'] ?? sys_get_temp_dir()), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . get_stylesheet();
    }
}


if (!class_exists('WP_Block_Type')) {
    class WP_Block_Type
    {
        public string $name;
        public array $attributes;
        public mixed $render_callback;
        public array $parent;
        public array $ancestor;

        public function __construct(string $name, array $args = array())
        {
            $this->name = $name;
            $this->attributes = is_array($args['attributes'] ?? null) ? $args['attributes'] : array();
            $this->render_callback = $args['render_callback'] ?? null;
            $this->parent = array_values(array_filter((array) ($args['parent'] ?? array()), 'is_string'));
            $this->ancestor = array_values(array_filter((array) ($args['ancestor'] ?? array()), 'is_string'));
        }

        public function is_dynamic(): bool
        {
            return is_callable($this->render_callback);
        }

        public function get_attributes(): array
        {
            return $this->attributes;
        }
    }
}

if (!class_exists('WP_Block_Type_Registry')) {
    class WP_Block_Type_Registry
    {
        private static ?self $instance = null;
        private array $registered = array();

        public static function get_instance(): self
        {
            return self::$instance ??= new self();
        }

        public function register(string $name, array $args = array()): WP_Block_Type
        {
            return $this->registered[$name] = new WP_Block_Type($name, $args);
        }

        public function get_registered(string $name): ?WP_Block_Type
        {
            return $this->registered[$name] ?? null;
        }

        public function is_registered(string $name): bool
        {
            return isset($this->registered[$name]);
        }

        public function reset(): void
        {
            $this->registered = array();
        }
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        private array $params = array();
        public function __construct(public string $method = 'GET') {}
        public function set_param(string $key, mixed $value): void { $this->params[$key] = $value; }
        public function get_param(string $key): mixed { return $this->params[$key] ?? null; }
        public function get_params(): array { return $this->params; }
    }
}

if (!class_exists('WP_Theme_JSON')) {
    class WP_Theme_JSON
    {
        public const LATEST_SCHEMA = 3;
        public static function remove_insecure_properties(array $themeJson, string $origin = 'custom'): array
        {
            return $themeJson;
        }
    }
}

if (!function_exists('sanitize_title')) {
    function sanitize_title(string $title): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
        return $slug;
    }
}

if (!function_exists('get_post_types')) {
    function get_post_types(array $args = array(), string $output = 'names'): array
    {
        $types = array('post', 'page');
        foreach ((array) ($GLOBALS['codi_mcp_test_public_post_types'] ?? array()) as $name => $config) {
            if (is_string($name)) {
                $types[] = $name;
            }
        }
        $types = array_values(array_unique($types));
        if ('objects' === $output) {
            $objects = array();
            foreach ($types as $type) {
                $objects[$type] = get_post_type_object($type) ?? (object) array('name' => $type, 'show_ui' => true);
            }
            return $objects;
        }
        return array_combine($types, $types) ?: array();
    }
}

if (!function_exists('get_permalink')) {
    function get_permalink(int $postId): string
    {
        $post = get_post($postId);
        return is_object($post) ? home_url('/' . trim((string) ($post->post_name ?? ''), '/') . '/') : '';
    }
}

if (!function_exists('url_to_postid')) {
    function url_to_postid(string $url): int
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        foreach ((array) ($GLOBALS['codi_mcp_test_wp_posts'][get_current_blog_id()] ?? array()) as $post) {
            if ((string) ($post->post_name ?? '') === $path || ('' === $path && 'home' === (string) ($post->post_name ?? ''))) {
                return (int) ($post->ID ?? 0);
            }
        }
        return 0;
    }
}

if (!function_exists('get_page_template_slug')) {
    function get_page_template_slug(int $postId): string
    {
        return (string) (get_post_meta($postId, '_wp_page_template', true) ?? '');
    }
}

if (!function_exists('get_page_by_path')) {
    function get_page_by_path(string $slug, string $output = 'OBJECT', string|array $postType = 'page'): ?object
    {
        $types = array_values((array) $postType);
        foreach ((array) ($GLOBALS['codi_mcp_test_wp_posts'][get_current_blog_id()] ?? array()) as $post) {
            if ((string) ($post->post_name ?? '') === $slug && in_array((string) ($post->post_type ?? ''), $types, true)) {
                return $post;
            }
        }
        return null;
    }
}

if (!function_exists('wp_delete_post')) {
    function wp_delete_post(int $postId, bool $forceDelete = false): ?object
    {
        foreach ((array) ($GLOBALS['codi_mcp_test_wp_posts'] ?? array()) as $siteId => $posts) {
            foreach ((array) $posts as $index => $post) {
                if ((int) ($post->ID ?? 0) !== $postId) {
                    continue;
                }
                array_splice($GLOBALS['codi_mcp_test_wp_posts'][$siteId], $index, 1);
                unset($GLOBALS['codi_mcp_test_wp_post_meta'][$postId]);
                return $post;
            }
        }
        return null;
    }
}

if (!function_exists('serialize_block')) {
    function serialize_block(array $block): string
    {
        $name = (string) ($block['blockName'] ?? '');
        if ('' === $name || 'core/freeform' === $name) {
            return (string) ($block['innerHTML'] ?? '');
        }
        $commentName = str_starts_with($name, 'core/') ? substr($name, 5) : $name;
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        $attrJson = $attrs === array() ? '' : ' ' . json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $children = array_values(array_filter((array) ($block['innerBlocks'] ?? array()), 'is_array'));
        $innerContent = is_array($block['innerContent'] ?? null) ? array_values($block['innerContent']) : array((string) ($block['innerHTML'] ?? ''));
        $body = '';
        $childIndex = 0;
        foreach ($innerContent as $segment) {
            if (null === $segment) {
                if (isset($children[$childIndex])) {
                    $body .= serialize_block($children[$childIndex]);
                }
                $childIndex++;
            } else {
                $body .= (string) $segment;
            }
        }
        while (isset($children[$childIndex])) {
            $body .= serialize_block($children[$childIndex]);
            $childIndex++;
        }
        if ('' === $body && $children === array()) {
            return '<!-- wp:' . $commentName . $attrJson . ' /-->';
        }
        return '<!-- wp:' . $commentName . $attrJson . ' -->' . $body . '<!-- /wp:' . $commentName . ' -->';
    }
}

if (!function_exists('serialize_blocks')) {
    function serialize_blocks(array $blocks): string
    {
        return implode('', array_map(static fn (array $block): string => serialize_block($block), array_values(array_filter($blocks, 'is_array'))));
    }
}

if (!function_exists('render_block')) {
    function render_block(array $block): string
    {
        return serialize_block($block);
    }
}

if (!function_exists('get_block_template')) {
    function get_block_template(string $id, string $templateType = 'wp_template'): ?object
    {
        $template = $GLOBALS['codi_mcp_presentation_block_templates'][$templateType][$id] ?? null;
        return is_object($template) ? clone $template : null;
    }
}

if (!function_exists('get_block_templates')) {
    function get_block_templates(array $query = array(), string $templateType = 'wp_template'): array
    {
        $items = array_values((array) ($GLOBALS['codi_mcp_presentation_block_templates'][$templateType] ?? array()));
        $slugFilter = array_values(array_filter((array) ($query['slug__in'] ?? array()), 'is_string'));
        if ($slugFilter !== array()) {
            $items = array_values(array_filter($items, static fn ($item): bool => is_object($item) && in_array((string) ($item->slug ?? ''), $slugFilter, true)));
        }
        return array_map(static fn ($item) => is_object($item) ? clone $item : $item, $items);
    }
}

if (!class_exists('WP_REST_Templates_Controller')) {
    class WP_REST_Templates_Controller
    {
        public function __construct(private string $postType) {}

        public function update_item(WP_REST_Request $request): mixed
        {
            $id = (string) $request->get_param('id');
            $current = get_block_template($id, $this->postType);
            if (!is_object($current)) {
                return new WP_Error('template_not_found', 'Template not found.');
            }
            if ('custom' !== (string) ($current->source ?? '')) {
                $GLOBALS['codi_mcp_presentation_block_template_sources'][$this->postType][$id] = clone $current;
            }
            $wpId = (int) ($current->wp_id ?? 0);
            if ($wpId < 1) {
                $wpId = (int) wp_insert_post(array(
                    'post_type' => $this->postType,
                    'post_status' => 'publish',
                    'post_name' => (string) ($current->slug ?? ''),
                    'post_title' => (string) ($current->title ?? ''),
                    'post_content' => (string) ($current->content ?? ''),
                ), true);
            }
            $current->wp_id = $wpId;
            $current->source = 'custom';
            $current->origin = (string) (($current->origin ?? '') ?: 'theme');
            foreach (array('content', 'title', 'description', 'area') as $field) {
                $value = $request->get_param($field);
                if (null !== $value) {
                    $current->{$field} = $value;
                }
            }
            $current->has_theme_file = isset($GLOBALS['codi_mcp_presentation_block_template_sources'][$this->postType][$id]);
            wp_update_post(array('ID' => $wpId, 'post_content' => (string) ($current->content ?? ''), 'post_title' => (string) ($current->title ?? '')), true);
            $GLOBALS['codi_mcp_presentation_block_templates'][$this->postType][$id] = clone $current;
            return new WP_REST_Response(array('id' => $id));
        }

        public function create_item(WP_REST_Request $request): mixed
        {
            $slug = (string) $request->get_param('slug');
            $theme = (string) $request->get_param('theme');
            $id = $theme . '//' . $slug;
            if (isset($GLOBALS['codi_mcp_presentation_block_templates'][$this->postType][$id])) {
                return new WP_Error('template_exists', 'Template already exists.');
            }
            $wpId = (int) wp_insert_post(array(
                'post_type' => $this->postType,
                'post_status' => 'publish',
                'post_name' => $slug,
                'post_title' => (string) ($request->get_param('title') ?? $slug),
                'post_content' => (string) ($request->get_param('content') ?? ''),
            ), true);
            $template = (object) array(
                'id' => $id,
                'type' => $this->postType,
                'theme' => $theme,
                'slug' => $slug,
                'title' => (string) ($request->get_param('title') ?? $slug),
                'description' => (string) ($request->get_param('description') ?? ''),
                'content' => (string) ($request->get_param('content') ?? ''),
                'status' => 'publish',
                'source' => 'custom',
                'origin' => 'custom',
                'wp_id' => $wpId,
                'area' => (string) ($request->get_param('area') ?? ''),
                'has_theme_file' => false,
            );
            $GLOBALS['codi_mcp_presentation_block_templates'][$this->postType][$id] = $template;
            return new WP_REST_Response(array('id' => $id));
        }

        public function delete_item(WP_REST_Request $request): mixed
        {
            $id = (string) $request->get_param('id');
            $current = get_block_template($id, $this->postType);
            if (!is_object($current) || 'custom' !== (string) ($current->source ?? '')) {
                return new WP_Error('template_not_custom', 'Template customization not found.');
            }
            if ((int) ($current->wp_id ?? 0) > 0) {
                wp_delete_post((int) $current->wp_id, true);
            }
            $fallback = $GLOBALS['codi_mcp_presentation_block_template_sources'][$this->postType][$id] ?? null;
            if (is_object($fallback)) {
                $GLOBALS['codi_mcp_presentation_block_templates'][$this->postType][$id] = clone $fallback;
            } else {
                unset($GLOBALS['codi_mcp_presentation_block_templates'][$this->postType][$id]);
            }
            return new WP_REST_Response(array('deleted' => true));
        }
    }
}

if (!class_exists('WP_REST_Global_Styles_Controller')) {
    class WP_REST_Global_Styles_Controller
    {
        public function __construct(private string $postType = 'wp_global_styles') {}
        public function update_item(WP_REST_Request $request): mixed
        {
            $postId = (int) $request->get_param('id');
            $post = get_post($postId);
            if (!is_object($post) || 'wp_global_styles' !== (string) ($post->post_type ?? '')) {
                return new WP_Error('global_styles_not_found', 'Global Styles not found.');
            }
            $config = json_decode((string) ($post->post_content ?? ''), true);
            if (!is_array($config)) {
                $config = array('version' => 3, 'settings' => array(), 'styles' => array());
            }
            foreach (array('settings', 'styles') as $key) {
                $value = $request->get_param($key);
                if (is_array($value)) {
                    $config[$key] = $value;
                }
            }
            $config['version'] = 3;
            $config['isGlobalStylesUserThemeJSON'] = true;
            $result = wp_update_post(array('ID' => $postId, 'post_content' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), true);
            if (is_wp_error($result)) {
                return $result;
            }
            return new WP_REST_Response(array('id' => $postId));
        }
    }
}

if (!function_exists('codi_mcp_presentation_reset_test_doubles')) {
    function codi_mcp_presentation_reset_test_doubles(): void
    {
        $registry = WP_Block_Type_Registry::get_instance();
        $registry->reset();
        $registry->register('core/paragraph', array('attributes' => array(
            'content' => array('type' => 'string'), 'dropCap' => array('type' => 'boolean'), 'direction' => array('type' => 'string'),
            'backgroundColor' => array('type' => 'string'), 'textColor' => array('type' => 'string'), 'fontSize' => array('type' => 'string'), 'style' => array('type' => 'object'),
        )));
        $registry->register('core/heading', array('attributes' => array(
            'content' => array('type' => 'string'), 'level' => array('type' => 'integer'), 'levelOptions' => array('type' => 'array'),
            'backgroundColor' => array('type' => 'string'), 'textColor' => array('type' => 'string'), 'fontSize' => array('type' => 'string'), 'style' => array('type' => 'object'),
        )));
        $registry->register('core/list', array('attributes' => array(
            'ordered' => array('type' => 'boolean'), 'type' => array('type' => 'string'), 'start' => array('type' => 'number'),
            'reversed' => array('type' => 'boolean'), 'placeholder' => array('type' => 'string'),
            'backgroundColor' => array('type' => 'string'), 'textColor' => array('type' => 'string'), 'fontSize' => array('type' => 'string'), 'style' => array('type' => 'object'),
        )));
        $registry->register('core/list-item', array(
            'attributes' => array(
                'placeholder' => array('type' => 'string'), 'content' => array('type' => 'string'),
                'backgroundColor' => array('type' => 'string'), 'textColor' => array('type' => 'string'), 'fontSize' => array('type' => 'string'), 'style' => array('type' => 'object'),
            ),
            'parent' => array('core/list'),
        ));
        $registry->register('core/group', array('attributes' => array(
            'tagName' => array('type' => 'string'), 'templateLock' => array('type' => 'string'),
            'backgroundColor' => array('type' => 'string'), 'textColor' => array('type' => 'string'), 'fontSize' => array('type' => 'string'), 'style' => array('type' => 'object'),
        )));
        $registry->register('core/columns', array('attributes' => array(
            'verticalAlignment' => array('type' => 'string'), 'isStackedOnMobile' => array('type' => 'boolean'), 'templateLock' => array('type' => array('string','boolean')),
            'backgroundColor' => array('type' => 'string'), 'textColor' => array('type' => 'string'), 'fontSize' => array('type' => 'string'), 'style' => array('type' => 'object'),
        )));
        $registry->register('core/column', array(
            'attributes' => array(
                'verticalAlignment' => array('type' => 'string'), 'width' => array('type' => 'string'), 'templateLock' => array('type' => array('string','boolean')),
                'backgroundColor' => array('type' => 'string'), 'textColor' => array('type' => 'string'), 'fontSize' => array('type' => 'string'), 'style' => array('type' => 'object'),
            ),
            'parent' => array('core/columns'),
        ));
        $registry->register('core/buttons', array('attributes' => array(
            'backgroundColor' => array('type' => 'string'), 'fontSize' => array('type' => 'string'), 'style' => array('type' => 'object'),
        )));
        $registry->register('core/button', array(
            'attributes' => array(
                'tagName' => array('type' => 'string'), 'type' => array('type' => 'string'), 'url' => array('type' => 'string'),
                'title' => array('type' => 'string'), 'text' => array('type' => 'string'), 'linkTarget' => array('type' => 'string'),
                'rel' => array('type' => 'string'), 'backgroundColor' => array('type' => 'string'), 'textColor' => array('type' => 'string'),
                'gradient' => array('type' => 'string'),
            ),
            'parent' => array('core/buttons'),
            'render_callback' => static fn () => '',
        ));
        $registry->register('core/image', array('attributes' => array(
            'id' => array('type' => 'number'), 'width' => array('type' => 'string'), 'height' => array('type' => 'string'),
            'aspectRatio' => array('type' => 'string'), 'scale' => array('type' => 'string'), 'focalPoint' => array('type' => 'object'),
            'sizeSlug' => array('type' => 'string'), 'linkDestination' => array('type' => 'string'), 'isDecorative' => array('type' => 'boolean'),
            'lightbox' => array('type' => 'object'),
        )));
        $registry->register('core/cover', array('attributes' => array(
            'url' => array('type' => 'string'), 'useFeaturedImage' => array('type' => 'boolean'), 'id' => array('type' => 'number'),
            'alt' => array('type' => 'string'), 'hasParallax' => array('type' => 'boolean'), 'isRepeated' => array('type' => 'boolean'),
            'dimRatio' => array('type' => 'number'), 'overlayColor' => array('type' => 'string'), 'customOverlayColor' => array('type' => 'string'),
            'isUserOverlayColor' => array('type' => 'boolean'), 'backgroundType' => array('type' => 'string'), 'focalPoint' => array('type' => 'object'),
            'minHeight' => array('type' => 'number'), 'minHeightUnit' => array('type' => 'string'), 'gradient' => array('type' => 'string'),
            'customGradient' => array('type' => 'string'), 'contentPosition' => array('type' => 'string'), 'isDark' => array('type' => 'boolean'),
            'templateLock' => array(), 'tagName' => array('type' => 'string'), 'sizeSlug' => array('type' => 'string'), 'poster' => array('type' => 'string'),
            'allowedVideoProviders' => array('type' => 'array'), 'textColor' => array('type' => 'string'), 'fontSize' => array('type' => 'string'), 'style' => array('type' => 'object'),
        )));
        $registry->register('core/gallery', array('attributes' => array(
            'columns' => array('type' => 'number'), 'imageCrop' => array('type' => 'boolean'), 'align' => array('type' => 'string'),
            'anchor' => array('type' => 'string'), 'className' => array('type' => 'string'), 'backgroundColor' => array('type' => 'string'),
            'gradient' => array('type' => 'string'), 'style' => array('type' => 'object'), 'metadata' => array('type' => 'object'), 'lock' => array('type' => 'object'),
        )));
        $registry->register('core/navigation', array('attributes' => array('ref' => array('type' => 'integer')), 'render_callback' => static fn () => ''));
        $registry->register('core/navigation-link', array(
            'attributes' => array(
                'label' => array('type' => 'string'), 'type' => array('type' => 'string'), 'description' => array('type' => 'string'),
                'rel' => array('type' => 'string'), 'id' => array('type' => 'number'), 'opensInNewTab' => array('type' => 'boolean'),
                'url' => array('type' => 'string'), 'title' => array('type' => 'string'), 'kind' => array('type' => 'string'),
                'isTopLevelLink' => array('type' => 'boolean'),
            ),
            'parent' => array('core/navigation'), 'render_callback' => static fn () => '',
        ));
        $registry->register('core/navigation-submenu', array(
            'attributes' => array(
                'label' => array('type' => 'string'), 'type' => array('type' => 'string'), 'description' => array('type' => 'string'),
                'rel' => array('type' => 'string'), 'id' => array('type' => 'number'), 'opensInNewTab' => array('type' => 'boolean'),
                'url' => array('type' => 'string'), 'title' => array('type' => 'string'), 'kind' => array('type' => 'string'),
                'isTopLevelItem' => array('type' => 'boolean'), 'isParentSubmenu' => array('type' => 'boolean'),
            ),
            'parent' => array('core/navigation'), 'render_callback' => static fn () => '',
        ));
        $registry->register('core/block', array('attributes' => array('ref' => array('type' => 'integer'))));
        $registry->register('core/template-part', array('attributes' => array('slug' => array('type' => 'string'))));
        $registry->register('core/query', array('attributes' => array(
            'queryId' => array('type' => 'number'), 'query' => array('type' => 'object'), 'tagName' => array('type' => 'string'),
            'align' => array('type' => 'string'), 'anchor' => array('type' => 'string'), 'className' => array('type' => 'string'),
        ), 'render_callback' => static fn () => ''));
        $registry->register('core/post-template', array('attributes' => array(), 'ancestor' => array('core/query')));
        $registry->register('core/post-title', array('attributes' => array('isLink' => array('type' => 'boolean'), 'level' => array('type' => 'number')), 'render_callback' => static fn () => ''));
        $registry->register('core/query-pagination', array('attributes' => array(), 'ancestor' => array('core/query')));
        $registry->register('core/query-pagination-previous', array('attributes' => array(), 'parent' => array('core/query-pagination'), 'render_callback' => static fn () => ''));
        $registry->register('core/query-pagination-numbers', array('attributes' => array(), 'parent' => array('core/query-pagination'), 'render_callback' => static fn () => ''));
        $registry->register('core/query-pagination-next', array('attributes' => array(), 'parent' => array('core/query-pagination'), 'render_callback' => static fn () => ''));
        $registry->register('core/query-no-results', array('attributes' => array(), 'ancestor' => array('core/query')));

        $theme = get_stylesheet();
        $template = (object) array(
            'id' => $theme . '//front-page', 'type' => 'wp_template', 'theme' => $theme,
            'slug' => 'front-page', 'title' => 'Front Page', 'description' => 'Theme front page',
            'content' => '<!-- wp:paragraph --><p>Theme front page.</p><!-- /wp:paragraph -->',
            'status' => 'publish', 'source' => 'theme', 'origin' => 'theme', 'wp_id' => 0,
            'area' => '', 'has_theme_file' => true,
        );
        $part = (object) array(
            'id' => $theme . '//site-header', 'type' => 'wp_template_part', 'theme' => $theme,
            'slug' => 'site-header', 'title' => 'Site Header', 'description' => 'Theme header',
            'content' => '<!-- wp:paragraph --><p>Header.</p><!-- /wp:paragraph -->',
            'status' => 'publish', 'source' => 'theme', 'origin' => 'theme', 'wp_id' => 0,
            'area' => 'header', 'has_theme_file' => true,
        );
        $GLOBALS['codi_mcp_presentation_block_template_sources'] = array(
            'wp_template' => array($template->id => clone $template),
            'wp_template_part' => array($part->id => clone $part),
        );
        $GLOBALS['codi_mcp_presentation_block_templates'] = array(
            'wp_template' => array($template->id => clone $template),
            'wp_template_part' => array($part->id => clone $part),
        );
    }
}
