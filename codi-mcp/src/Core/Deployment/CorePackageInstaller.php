<?php

declare(strict_types=1);

namespace CodiMcp\Core\Deployment;

/** Adds Core's backup/restore lifecycle to the native uploaded-package installer. */
final class CorePackageInstaller
{
    public function __construct(private ?\Closure $verify = null) {}

    public function install(object $upgrader, string $zipPath, string $type, string $slug, string $root, bool $active)
    {
        if (!in_array($type, ['plugin', 'theme'], true) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $slug) || in_array($slug, ['.', '..'], true)) {
            return new \WP_Error('codi_mcp_bad_package', 'Invalid native installation target.');
        }
        if (!is_dir(rtrim($root, '/\\') . '/' . $slug)) {
            return $upgrader->install($zipPath, ['overwrite_package' => true]);
        }

        global $wp_filesystem;
        if (!is_object($wp_filesystem) || !$wp_filesystem->wp_content_dir() || !$wp_filesystem->abspath()) {
            return new \WP_Error('fs_unavailable', 'WordPress filesystem access is unavailable.');
        }
        $backup = ['slug' => $slug, 'src' => $root, 'dir' => $type . 's'];
        $backupPath = $wp_filesystem->wp_content_dir() . 'upgrade-temp-backup/' . $backup['dir'] . '/' . $slug;
        $maintenancePath = $wp_filesystem->abspath() . '.maintenance';
        if ($wp_filesystem->exists($backupPath)) {
            return new \WP_Error('codi_mcp_recovery_required', 'A previous WordPress update backup requires inspection before another replacement.', ['backup' => $backupPath]);
        }
        if ($wp_filesystem->exists($maintenancePath)) {
            return new \WP_Error('codi_mcp_deployment_busy', 'WordPress already has a maintenance file. Resolve the existing operation first.');
        }
        $verify = $this->verify;
        if ($active && $verify === null) {
            // Load the probe before replacing Codi itself or any other active plugin.
            require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
            $probe = new CoreFatalErrorCheck();
            $verify = [$probe, 'verify'];
        }

        $token = bin2hex(random_bytes(16));
        $attached = false;
        $finished = false;
        $retain = false;
        $maintenance = null;
        $options = static function (array $options) use ($zipPath, $type, $root, $backup, $token, &$attached): array {
            if (($options['package'] ?? '') === $zipPath && ($options['hook_extra']['type'] ?? '') === $type) {
                $options['hook_extra']['codi_transaction'] = $token;
                if (rtrim((string) ($options['destination'] ?? ''), '/\\') === rtrim($root, '/\\')) {
                    $options['hook_extra']['temp_backup'] = $backup;
                    $attached = true;
                }
            }
            return $options;
        };
        $before = static function ($result, array $extra) use ($token, &$attached) {
            if (($extra['codi_transaction'] ?? '') === $token && !$attached) {
                return new \WP_Error('codi_mcp_install_root_mismatch', 'The native installation destination does not match the package being backed up.');
            }
            return $result;
        };
        $after = static function ($result, array $extra) use ($token, $active, $verify, &$finished) {
            if (($extra['codi_transaction'] ?? '') !== $token || is_wp_error($result)) {
                return $result;
            }
            $finished = true;
            if (!$active) {
                return $result;
            }
            try {
                $health = $verify();
                return $health === true ? $result : (is_wp_error($health) ? $health : new \WP_Error('codi_mcp_update_health_failed', 'WordPress verification did not succeed.'));
            } catch (\Throwable $error) {
                return new \WP_Error('codi_mcp_update_health_failed', 'WordPress verification failed: ' . $error->getMessage());
            }
        };
        add_filter('upgrader_package_options', $options, PHP_INT_MAX);
        add_filter('upgrader_pre_install', $before, PHP_INT_MAX, 2);
        add_filter('upgrader_post_install', $after, PHP_INT_MAX, 2);

        try {
            $upgrader->maintenance_mode(true);
            $maintenance = $wp_filesystem->get_contents($maintenancePath);
            if (!is_string($maintenance) || $maintenance === '') {
                return new \WP_Error('codi_mcp_maintenance_failed', 'WordPress could not enable maintenance protection.');
            }
            try {
                $result = $upgrader->install($zipPath, ['overwrite_package' => true]);
            } catch (\Throwable $error) {
                $result = new \WP_Error('codi_mcp_update_failed', 'WordPress installation failed: ' . $error->getMessage());
            }
            if ($result === true && (!$attached || !$finished)) {
                $result = new \WP_Error('codi_mcp_update_unverified', 'The native update did not complete the required backup and verification lifecycle.');
            }
            $failure = UpgraderResult::error($result, $type);
            if ($failure !== null && $attached && $wp_filesystem->is_dir($backupPath)) {
                $restored = $upgrader->restore_temp_backup([$backup]);
                if ($restored !== true || $wp_filesystem->is_dir($backupPath)) {
                    $retain = true;
                    return new \WP_Error('codi_mcp_recovery_required', 'WordPress could not restore the previous package. Maintenance protection and the backup have been retained.', ['backup' => $backupPath, 'cause' => $failure->get_error_code()]);
                }
                if ($type === 'plugin') {
                    wp_clean_plugins_cache(true);
                } else {
                    wp_clean_themes_cache(true);
                }
                return new \WP_Error('codi_mcp_update_rolled_back', 'Update rejected; WordPress restored the previous package. ' . $failure->get_error_message(), ['status' => 500, 'cause' => $failure->get_error_code()]);
            }
            if ($failure !== null) {
                return $failure;
            }
            $cleaned = $upgrader->delete_temp_backup([$backup]);
            if ($cleaned !== true) {
                return new \WP_Error('codi_mcp_backup_cleanup_failed', 'The updated package passed verification, but WordPress could not remove its temporary backup.', ['backup' => $backupPath]);
            }
            return true;
        } finally {
            remove_filter('upgrader_package_options', $options, PHP_INT_MAX);
            remove_filter('upgrader_pre_install', $before, PHP_INT_MAX);
            remove_filter('upgrader_post_install', $after, PHP_INT_MAX);
            // Complete recovery/cleanup while the caller still owns its package lock.
            remove_action('shutdown', [$upgrader, 'restore_temp_backup'], 10);
            remove_action('shutdown', [$upgrader, 'delete_temp_backup'], 100);
            if (!$retain && is_string($maintenance) && $wp_filesystem->get_contents($maintenancePath) === $maintenance) {
                $upgrader->maintenance_mode(false);
            }
        }
    }
}
