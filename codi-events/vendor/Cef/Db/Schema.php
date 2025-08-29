<?php
// File: vendor/Cef/Db/Schema.php

namespace Cef\Db;

defined('ABSPATH') || exit;

class Schema
{
    public static function install(): void
    {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        $tbl_events        = $wpdb->prefix . 'cef_events';
        $tbl_actions       = $wpdb->prefix . 'cef_actions';
        $tbl_rules         = $wpdb->prefix . 'cef_rules';
        $tbl_jobs          = $wpdb->prefix . 'cef_jobs';
        $tbl_event_actions = $wpdb->prefix . 'cef_event_actions';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Events table
        $sql_events = "CREATE TABLE $tbl_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_key VARCHAR(190) NOT NULL,
            label VARCHAR(190) NULL,
            hook VARCHAR(190) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            debounce_seconds INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_event_key (event_key),
            KEY idx_enabled (enabled),
            KEY idx_hook (hook)
        ) $charset;";

        // Actions table
        $sql_actions = "CREATE TABLE $tbl_actions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            type VARCHAR(60) NOT NULL,
            name VARCHAR(190) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            config_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_enabled (enabled),
            KEY idx_type (type)
        ) $charset;";

        // Rules table
        $sql_rules = "CREATE TABLE $tbl_rules (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            target_kind VARCHAR(20) NOT NULL DEFAULT 'action',
            target_id BIGINT UNSIGNED NOT NULL,
            group_index INT NOT NULL DEFAULT 1,
            provider_key VARCHAR(120) NOT NULL,
            operator VARCHAR(60) NOT NULL,
            value_text LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_target (target_kind, target_id),
            KEY idx_group (target_kind, target_id, group_index)
        ) $charset;";

        // Jobs table (with attempts column for retries)
        $sql_jobs = "CREATE TABLE $tbl_jobs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_key VARCHAR(190) NOT NULL,
            action_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'queued',
            triggered_at DATETIME NOT NULL,
            earliest_run_at DATETIME NOT NULL,
            last_eval_at DATETIME NULL,
            executed_at DATETIME NULL,
            dedupe_key VARCHAR(190) NULL,
            context_json LONGTEXT NULL,
            result_text TEXT NULL,
            attempts INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status_time (status, earliest_run_at),
            KEY idx_action (action_id),
            KEY idx_event (event_key),
            KEY idx_dedupe (dedupe_key)
        ) $charset;";

        // Event?Action link table
        $sql_event_actions = "CREATE TABLE $tbl_event_actions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_key VARCHAR(190) NOT NULL,
            action_id BIGINT UNSIGNED NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_event_action (event_key, action_id),
            KEY idx_event (event_key),
            KEY idx_action (action_id),
            KEY idx_enabled (enabled)
        ) $charset;";

        // Create/update tables
        dbDelta($sql_events);
        dbDelta($sql_actions);
        dbDelta($sql_rules);
        dbDelta($sql_jobs);
        dbDelta($sql_event_actions);

        if (defined('CEF_DB_OPT_VER')) {
            update_option(CEF_DB_OPT_VER, defined('CEF_VER') ? CEF_VER : '1.0.0', false);
        }
    }

    public static function uninstall(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        global $wpdb;
        $tables = [
            $wpdb->prefix . 'cef_event_actions',
            $wpdb->prefix . 'cef_jobs',
            $wpdb->prefix . 'cef_rules',
            $wpdb->prefix . 'cef_actions',
            $wpdb->prefix . 'cef_events',
        ];

        foreach ($tables as $t) {
            $wpdb->query("DROP TABLE IF EXISTS $t");
        }

        if (defined('CEF_DB_OPT_VER')) {
            delete_option(CEF_DB_OPT_VER);
        }
    }
}