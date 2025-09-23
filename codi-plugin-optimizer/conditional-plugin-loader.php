<?php

if (!defined('ABSPATH')) exit;


define('CPL_MAP_FILE', WP_CONTENT_DIR . '/cpl-plugin-map.json');
define('CPL_LOG', true);

/**
 * Load the usage map from JSON.
 */
function cpl_load_map() {
    if (!file_exists(CPL_MAP_FILE)) return [];
    $data = json_decode(@file_get_contents(CPL_MAP_FILE), true);
    return is_array($data) ? $data : [];
}

/**
 * Save the usage map to JSON.
 */
function cpl_save_map($map) {
    @file_put_contents(CPL_MAP_FILE, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/**
 * Get context key for a post.
 */
function cpl_context_for_post($post_id) {
    return "post:$post_id";
}

/**
 * Get main plugin file for a given plugin folder slug.
 */
function cpl_main_file_for_plugin($slug) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $plugins = get_plugins("/$slug");
    if (!empty($plugins)) {
        foreach ($plugins as $file => $headers) {
            if (dirname($file) === '.' || strpos($file, $slug . '.php') !== false) {
                return "$slug/$file";
            }
        }
        $first = array_keys($plugins)[0];
        return "$slug/$first";
    }
    return null;
}

/**
 * Resolve a callback to the file path where it's defined.
 */
function cpl_callback_file($callback) {
    try {
        if (is_string($callback) && function_exists($callback)) {
            return (new ReflectionFunction($callback))->getFileName();
        }
        if (is_array($callback)) {
            if (is_object($callback[0])) {
                return (new ReflectionClass($callback[0]))->getFileName();
            }
            if (is_string($callback[0]) && class_exists($callback[0])) {
                return (new ReflectionClass($callback[0]))->getFileName();
            }
        }
    } catch (ReflectionException $e) {}
    return null;
}

/**
 * Build registry automatically from registered shortcodes, blocks, and widgets.
 */
function cpl_build_registry() {
    $registry = [
        'shortcode' => [],
        'block'     => [],
        'widget'    => [],
    ];

    // Shortcodes
    global $shortcode_tags;
    foreach ((array) $shortcode_tags as $tag => $cb) {
        $file = cpl_callback_file($cb);
        if ($file && strpos($file, WP_PLUGIN_DIR) === 0) {
            $slug = explode('/', str_replace(WP_PLUGIN_DIR . '/', '', $file))[0];
            if ($main = cpl_main_file_for_plugin($slug)) {
                $registry['shortcode'][$tag] = $main;
            }
        }
    }

    // Blocks
    if (class_exists('WP_Block_Type_Registry')) {
        $blocks = \WP_Block_Type_Registry::get_instance()->get_all_registered();
        foreach ($blocks as $name => $block) {
            if (!empty($block->render_callback)) {
                $file = cpl_callback_file($block->render_callback);
                if ($file && strpos($file, WP_PLUGIN_DIR) === 0) {
                    $slug = explode('/', str_replace(WP_PLUGIN_DIR . '/', '', $file))[0];
                    if ($main = cpl_main_file_for_plugin($slug)) {
                        $registry['block'][$name] = $main;
                    }
                }
            }
        }
    }

    // Widgets
    global $wp_widget_factory;
    foreach ((array) $wp_widget_factory->widgets as $class => $obj) {
        try {
            $file = (new ReflectionClass($obj))->getFileName();
        } catch (ReflectionException $e) {
            $file = null;
        }
        if ($file && strpos($file, WP_PLUGIN_DIR) === 0) {
            $slug = explode('/', str_replace(WP_PLUGIN_DIR . '/', '', $file))[0];
            if ($main = cpl_main_file_for_plugin($slug)) {
                $id_base = property_exists($obj, 'id_base') ? $obj->id_base : $class;
                $registry['widget'][$id_base] = $main;
            }
        }
    }

    return $registry;
}

/**
 * Detect plugin usage signals for a post (shortcodes, blocks, widgets).
 */
function cpl_detect_for_post(WP_Post $post) {
    $found = [];
    $registry = cpl_build_registry();
    $content = (string) $post->post_content;

    // Shortcodes
    if (!empty($registry['shortcode'])) {
        global $shortcode_tags;
        $tags = array_keys((array) $shortcode_tags);
        if (!empty($tags)) {
            $pattern = get_shortcode_regex($tags);
            if ($pattern && preg_match_all('/' . $pattern . '/s', $content, $matches) && isset($matches[2])) {
                foreach ($matches[2] as $tag) {
                    if (isset($registry['shortcode'][$tag])) {
                        $found[] = $registry['shortcode'][$tag];
                    }
                }
            }
        }
    }

    // Blocks
    if (!empty($registry['block']) && function_exists('has_blocks') && has_blocks($post)) {
        $blocks = parse_blocks($content);
        $stack = is_array($blocks) ? $blocks : [];
        while ($stack) {
            $b = array_shift($stack);
            if (!empty($b['blockName']) && isset($registry['block'][$b['blockName']])) {
                $found[] = $registry['block'][$b['blockName']];
            }
            if (!empty($b['innerBlocks'])) {
                foreach ($b['innerBlocks'] as $ib) $stack[] = $ib;
            }
        }
    }

    // Widgets (active sidebars only)
    if (!empty($registry['widget'])) {
        $active_sidebars = wp_get_sidebars_widgets();
        foreach ((array) $active_sidebars as $sidebar_id => $widgets) {
            if (empty($widgets) || 'wp_inactive_widgets' === $sidebar_id) continue;
            foreach ((array) $widgets as $widget_id) {
                $parts = explode('-', $widget_id);
                $id_base = array_shift($parts);
                if (isset($registry['widget'][$id_base])) {
                    $found[] = $registry['widget'][$id_base];
                }
            }
        }
    }

    return array_values(array_unique($found));
}

/**
 * On save_post, detect and update map.
 */
add_action('save_post', function($post_id, $post, $update) {
    if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) return;
    if (!in_array($post->post_status, ['publish','future','private','draft','pending'], true)) return;

    $context_key = cpl_context_for_post($post_id);
    $plugins = cpl_detect_for_post($post);

    $map = cpl_load_map();
    $map[$context_key] = $plugins;
    cpl_save_map($map);

    if (CPL_LOG) error_log("[CPL] {$context_key} => " . implode(',', $plugins));
}, 10, 3);

/**
 * Helper: is a plugin referenced anywhere in the map?
 */
function cpl_plugin_in_map($plugin_file, $map) {
    foreach ($map as $plugins) {
        if (in_array($plugin_file, (array) $plugins, true)) return true;
    }
    return false;
}

/**
 * Admin UI for Always Needed list and visibility of current statuses.
 */
add_action('admin_menu', function() {
    add_options_page('Conditional Loader', 'Conditional Loader', 'manage_options', 'cpl-admin', 'cpl_render_admin_page');
});

function cpl_render_admin_page() {
    if (!current_user_can('manage_options')) return;

    require_once ABSPATH . 'wp-admin/includes/plugin.php';

    $active_plugins = get_option('active_plugins', []);
    $network_active = array_keys((array) get_site_option('active_sitewide_plugins', []));
    $all_plugins = array_values(array_unique(array_merge($active_plugins, $network_active)));
    
    sort($all_plugins);

    $always_needed = get_option('cpl_always_needed', []);
    $map = cpl_load_map();

    // Handle save
    if (isset($_POST['cpl_save'])) {
        check_admin_referer('cpl_save_settings');
        $always_needed = array_map('sanitize_text_field', $_POST['always_needed'] ?? []);
        update_option('cpl_always_needed', $always_needed);
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }

    echo '<div class="wrap"><h1>Conditional Plugin Loader</h1>';
    echo '<form method="post">';
    wp_nonce_field('cpl_save_settings');

    echo '<table class="widefat fixed striped">';
    echo '<thead><tr><th>Plugin</th><th>Status</th><th>Always Needed</th></tr></thead><tbody>';

    foreach ($all_plugins as $plugin_file) {
        $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
        $data = file_exists($plugin_path) ? get_plugin_data($plugin_path, false, false) : [];
        $name = !empty($data['Name']) ? $data['Name'] : $plugin_file;

        if (in_array($plugin_file, $always_needed, true)) {
            $status = '<span style="color:green;">Always Needed</span>';
        } elseif (cpl_plugin_in_map($plugin_file, $map)) {
            $status = '<span style="color:orange;">Conditional</span>';
        } else {
            $status = '<span style="color:red;">Skipped</span>';
        }

        echo '<tr>';
        echo '<td>' . esc_html($name) . '<br><code>' . esc_html($plugin_file) . '</code></td>';
        echo '<td>' . $status . '</td>';
        echo '<td><input type="checkbox" name="always_needed[]" value="' . esc_attr($plugin_file) . '" ' . checked(in_array($plugin_file, $always_needed, true), true, false) . '></td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    submit_button('Save Changes', 'primary', 'cpl_save');
    echo '</form></div>';
}