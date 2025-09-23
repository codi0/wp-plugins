<?php

if (!defined('WPINC')) die;


// Quick bypass for debugging
if (defined('CPL_BYPASS') && CPL_BYPASS) return;
if (!empty($_GET['cpl_bypass'])) return;

/**
 * Build dependency map from plugin headers.
 * Returns array: slug => [dep_slug1, dep_slug2, ...]
 */
function cpl_build_dependency_map_mu() {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $deps = [];
    foreach (get_plugins() as $file => $data) {
        if (!empty($data['RequiresPlugins'])) {
            $slug = dirname($file);
            $requires = array_map('trim', explode(',', $data['RequiresPlugins']));
            $deps[$slug] = $requires;
        }
    }
    return $deps;
}

/**
 * Expand allowed plugin list to include dependencies.
 * @param array $allowed_plugins Array of plugin main file paths.
 * @return array Expanded array including dependencies.
 */
function cpl_expand_with_dependencies_mu($allowed_plugins) {
    $deps = cpl_build_dependency_map_mu();
    $slugs_to_keep = array_unique(array_map(function($f){ return dirname($f); }, $allowed_plugins));

    $changed = true;
    while ($changed) {
        $changed = false;
        foreach ($slugs_to_keep as $slug) {
            if (!empty($deps[$slug])) {
                foreach ($deps[$slug] as $dep_slug) {
                    if (!in_array($dep_slug, $slugs_to_keep, true)) {
                        $slugs_to_keep[] = $dep_slug;
                        $changed = true;
                    }
                }
            }
        }
    }

    // Convert slugs back to main plugin files
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $final = [];
    foreach (get_plugins() as $file => $data) {
        if (in_array(dirname($file), $slugs_to_keep, true)) {
            $final[] = $file;
        }
    }
    return $final;
}

add_filter('option_active_plugins', function($active) {
    // Do not interfere with admin or CLI
    if (is_admin() || (defined('WP_CLI') && WP_CLI)) return $active;

    $always_keep = get_option('cpl_always_needed', []);
    $network_active = array_keys((array) get_site_option('active_sitewide_plugins', []));

    // Load map
    $map_file = WP_CONTENT_DIR . '/cpl-plugin-map.json';
    $map = [];
    if (file_exists($map_file)) {
        $decoded = json_decode(@file_get_contents($map_file), true);
        if (is_array($decoded)) $map = $decoded;
    }

    // Determine simple context (singular posts only for v1)
    $context = null;
    if (is_singular()) {
        global $post;
        if ($post && isset($post->ID)) {
            $context = 'post:' . $post->ID;
        }
    }

    $allowed = [];
    if ($context && isset($map[$context]) && is_array($map[$context])) {
        $allowed = $map[$context];
    }

    // Merge allowlists
    $allowed = array_unique(array_merge($allowed, (array) $always_keep, (array) $network_active));

    // Expand with dependencies
    $allowed = cpl_expand_with_dependencies_mu($allowed);

    // Intersect with currently active list
    $filtered = array_values(array_intersect((array) $active, $allowed));

    // Safety: if we filtered everything (likely misconfig), keep original
    if (empty($filtered)) {
        return $active;
    }

    // Optional logging
    if (defined('CPL_LOG') && CPL_LOG) {
        error_log('[CPL MU] context=' . ($context ?: 'none') . ' allowed=' . implode(',', $allowed));
        $skipped = array_diff((array) $active, $filtered);
        if (!empty($skipped)) error_log('[CPL MU] skipped=' . implode(',', $skipped));
    }

    return $filtered;
}, 1);