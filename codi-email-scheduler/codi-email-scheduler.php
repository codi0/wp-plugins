<?php
/**
 * Plugin Name: Codi Email Scheduler
 * Description: Lightweight event + delay + condition email scheduler powered by Action Scheduler.
 * Version: 0.50.2
 * Author: Codi
 * Requires at least: 6.8
 * Requires PHP: 7.4
 * Requires Plugins: action-scheduler
 * Text Domain: codi-email-scheduler
 */

defined('ABSPATH') || exit;

define('CES_VERSION', '0.50.2');
define('CES_PLUGIN_FILE', __FILE__);
define('CES_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CES_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CES_OPTION_EMAILS', 'ces_emails');
define('CES_OPTION_SETTINGS', 'ces_settings');
define('CES_AS_GROUP', 'codi-email-scheduler');
define('CES_AS_ACTION', 'ces_run_scheduled_email');
define('CES_AS_BACKFILL_ACTION', 'ces_run_retrospective_batch');
define('CES_OPTION_BACKFILL_LATEST_PREFIX', 'ces_backfill_latest_');
define('CES_OPTION_BACKFILL_PREFIX', 'ces_backfill_run_');
define('CES_OPTION_BACKFILL_ACTIVE_PREFIX', 'ces_backfill_active_');
define('CES_ACTION_SCHEDULER_MIN_VERSION', '4.0.0');

spl_autoload_register(static function (string $class): void {
    if (strpos($class, 'CES_') !== 0) {
        return;
    }

    $slug = strtolower(str_replace('_', '-', $class));
    $file = CES_PLUGIN_DIR . 'includes/class-' . $slug . '.php';
    if (is_readable($file)) {
        require_once $file;
    }
});

require_once CES_PLUGIN_DIR . 'includes/functions.php';

register_activation_hook(CES_PLUGIN_FILE, static function (): void {
    CES_Default_Emails::maybe_seed();
});

// Core items and WordPress trigger bindings are registered immediately so early/custom
// registration flows cannot fire before CES is listening.
CES_Registry::register_core_items();
CES_Custom_Hook_Events::init();
CES_Engine::init();
CES_Backfill::init();
CES_WordPress_Events::init();
if (CES_WooCommerce_Helpers::is_woocommerce_dependency_active()) {
    CES_WooCommerce_Tokens::init();
    CES_WooCommerce_Events::init();
}
if (is_admin()) {
    CES_Admin::init();
}
