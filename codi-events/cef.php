<?php
/*
Plugin Name: Codi Events Framework
Description: Unified, condition-driven automation framework for WordPress. Capture events, evaluate rules, execute actions.
Version: 2.0.0
Author: codi0
Author URI: https://github.com/codi0/wp-plugins/
*/

defined('ABSPATH') || exit;

if (!defined('CEF_VER')) define('CEF_VER', '2.0.0');
if (!defined('CEF_DB_OPT_VER')) define('CEF_DB_OPT_VER', 'cef_db_version');
if (!defined('CEF_CRON_RUN_JOB')) define('CEF_CRON_RUN_JOB', 'cef_run_job');
if (!defined('CEF_CRON_TICK')) define('CEF_CRON_TICK', 'cef_cron_tick');

// -----------------------------------------------------------------------------
// Simple PSR-4 autoloader for vendor/ (loads Cef classes + any third-party libs)
// -----------------------------------------------------------------------------
spl_autoload_register(function ($class) {
    $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
    $file = __DIR__ . '/vendor/' . $relativePath;
    if (file_exists($file)) {
        require $file;
    }
});

// -----------------------------------------------------------------------------
// Core plugin bootstrap
// -----------------------------------------------------------------------------
final class CEF_Plugin {
    private static $instance;

    /** @var Cef\Registry\ConditionRegistry */
    public $conditions;
    /** @var Cef\Registry\ActionRegistry */
    public $actions;
    /** @var Cef\Registry\EventRegistry */
    public $events;
    /** @var Cef\Engine\ConditionEngine */
    public $engine;
    /** @var Cef\Scheduler\Scheduler */
    public $scheduler;
    /** @var Cef\Templating\Templating */
    public $templating;

    public static function instance(): self {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Instantiate core services
        $this->conditions = new \Cef\Registry\ConditionRegistry();
        $this->actions    = new \Cef\Registry\ActionRegistry();
        $this->events     = new \Cef\Registry\EventRegistry();
        $this->engine     = new \Cef\Engine\ConditionEngine($this->conditions);
        $this->templating = new \Cef\Templating\Templating();
        $this->scheduler  = new \Cef\Scheduler\Scheduler($this->engine, $this->actions, $this->events, $this->templating);

        // Lifecycle: install/uninstall
        register_activation_hook(__FILE__, function () {
            Cef\Db\Schema::install();

            // Ensure cron schedule exists, then schedule our tick
            if (!wp_next_scheduled(CEF_CRON_TICK)) {
                // Small delay avoids double-run on activation request
                wp_schedule_event(time() + 60, 'cef_every_minute', CEF_CRON_TICK);
            }
        });

        register_deactivation_hook(__FILE__, function () {
            $timestamp = wp_next_scheduled(CEF_CRON_TICK);
            if ($timestamp) {
                wp_unschedule_event($timestamp, CEF_CRON_TICK);
            }
        });

        register_uninstall_hook(__FILE__, ['Cef\\Db\\Schema', 'uninstall']);

        // Init and bootstrap
        add_action('init', [$this, 'bootstrap'], 8);
        add_action('plugins_loaded', [$this, 'register_builtins'], 5);

        // Admin wiring (after services are ready)
        add_action('plugins_loaded', function () {
            if (is_admin()) {
                new \Cef\Admin\ActionsController($this->actions, $this->conditions, $this->events);
                new \Cef\Admin\JobsController($this->engine, $this->conditions, $this->scheduler);
            }
        }, 20);

        // Cron hooks
        add_action(CEF_CRON_RUN_JOB, [$this->scheduler, 'run_job'], 10, 1);
        add_action(CEF_CRON_TICK, [$this->scheduler, 'dispatch_due_jobs'], 10, 0);

		// Cron schedule: add a per-minute interval for CEF dispatcher
		add_filter('cron_schedules', function($schedules) {
			$schedules['cef_every_minute'] = [
				'interval' => 60,
				'display'  => __('Every Minute (CEF)', 'cef'),
			];
			return $schedules;
		});
    }

    public function bootstrap() {
        // Bind all enabled events (DB + programmatic registrations)
        $this->events->bind_all([$this->scheduler, 'enqueue_for_event']);
    }

    public function register_builtins() {
        // Register built-in action types
        \Cef\Registry\Actions\EmailAction::register($this->actions);

        // Register built-in events
        \Cef\Registry\Events\CoreEvents::register_all($this->events);
        \Cef\Registry\Events\WooCommerceEvents::register_all($this->events);

        // Register built-in condition providers
        \Cef\Registry\Providers\CoreProviders::register_all($this->conditions);
        \Cef\Registry\Providers\WooCommerceProviders::register_all($this->conditions);
    }
}

// -----------------------------------------------------------------------------
// Convenience wrappers
// -----------------------------------------------------------------------------
function cef(): CEF_Plugin {
    return \CEF_Plugin::instance();
}

function cef_register_condition(array $def) {
	return cef()->conditions->register($def);
}

function cef_register_action(array $def) {
	return cef()->actions->register($def);
}

function cef_register_event(array $def) {
	return cef()->events->register($def);
}

function cef_link_event_action(string $event_key, int $action_id, bool $enabled = true): bool {
    return cef()->events->link_action($event_key, $action_id, $enabled);
}

// Boot
cef();