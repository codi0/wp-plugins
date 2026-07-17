<?php

defined('ABSPATH') || exit;

final class CES_Admin {
    public const PAGE_SLUG = 'codi-email-scheduler';

    public static function init(): void {
        if (!is_admin()) {
            return;
        }

        CES_Admin_Form_Handler::init();
        CES_Admin_Page::init();
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('admin_notices', [__CLASS__, 'action_scheduler_notice']);
    }


    public static function enqueue_assets(string $hook_suffix): void {
        if ($hook_suffix !== 'tools_page_' . self::PAGE_SLUG) {
            return;
        }

        wp_enqueue_style(
            'ces-admin',
            CES_PLUGIN_URL . 'assets/admin.css',
            [],
            CES_VERSION
        );

        wp_enqueue_script(
            'ces-admin',
            CES_PLUGIN_URL . 'assets/admin.js',
            [],
            CES_VERSION,
            true
        );
    }

    public static function action_scheduler_notice(): void {
        if (!current_user_can('manage_options') || CES_Engine::action_scheduler_available()) {
            return;
        }

        $screen = get_current_screen();
        $is_ces_page = $screen && $screen->id === 'tools_page_' . self::PAGE_SLUG;
        $message = __('Email Scheduler requires the standalone Action Scheduler plugin version 4.0.0 or newer. Install, activate, or update Action Scheduler before scheduled emails can be queued.', 'codi-email-scheduler');

        printf(
            '<div class="notice notice-error%s"><p>%s</p></div>',
            $is_ces_page ? '' : ' is-dismissible',
            esc_html($message)
        );
    }
}
