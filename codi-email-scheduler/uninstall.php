<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$ces_uninstall_site = static function (): void {
    if (class_exists('ActionScheduler') && method_exists('ActionScheduler', 'is_initialized') && !ActionScheduler::is_initialized() && method_exists('ActionScheduler', 'init')) {
        ActionScheduler::init();
    }

    if (function_exists('as_unschedule_all_actions') && (!class_exists('ActionScheduler') || !method_exists('ActionScheduler', 'is_initialized') || ActionScheduler::is_initialized())) {
        as_unschedule_all_actions('ces_run_scheduled_email', [], 'codi-email-scheduler');
        as_unschedule_all_actions('ces_run_retrospective_batch', [], 'codi-email-scheduler');
    }

    // Remove retrospective options by prefix so interrupted cleanup, manually
    // removed definitions, and state left by older versions cannot survive uninstall.
    global $wpdb;
    if (isset($wpdb) && is_object($wpdb) && isset($wpdb->options)
        && method_exists($wpdb, 'prepare') && method_exists($wpdb, 'esc_like') && method_exists($wpdb, 'get_col')) {
        foreach (['ces_backfill_active_', 'ces_backfill_latest_', 'ces_backfill_run_'] as $prefix) {
            $option_names = $wpdb->get_col($wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like($prefix) . '%'
            ));
            foreach (is_array($option_names) ? $option_names : [] as $option_name) {
                delete_option((string) $option_name);
            }
        }
    }
    delete_option('ces_emails');
    delete_option('ces_settings');
};

if (is_multisite()) {
    $site_ids = get_sites([
        'fields' => 'ids',
        'number' => 0,
    ]);

    foreach ($site_ids as $site_id) {
        switch_to_blog((int) $site_id);
        $ces_uninstall_site();
        restore_current_blog();
    }
} else {
    $ces_uninstall_site();
}
