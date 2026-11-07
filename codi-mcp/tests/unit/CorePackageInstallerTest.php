<?php

declare(strict_types=1);

namespace {
    function wp_clean_plugins_cache($clear = true): void { $GLOBALS['codi_native_cache_cleans'] = ($GLOBALS['codi_native_cache_cleans'] ?? 0) + 1; }
    function wp_clean_themes_cache($clear = true): void {}
    class WP_Automatic_Updater
    {
        protected function has_fatal_error(): bool
        {
            $GLOBALS['upgrading'] = 12345;
            $key = md5('12345');
            $body = $GLOBALS['codi_native_probe_body'] ?? '';
            apply_filters('http_response', ['body' => $body, 'response' => ['code' => 200]], [], 'https://example.test/?wp_scrape_key=' . $key);
            return $GLOBALS['codi_native_probe_fatal'] ?? false;
        }
    }
}

namespace CodiMcpTest\Unit {
    use CodiMcp\Core\Deployment\CorePackageInstaller;
    use CodiMcp\Core\Deployment\CoreFatalErrorCheck;
    use CodiMcpTest\Framework\TestCase;

    final class CorePackageInstallerTest extends TestCase
    {
        private string $root;
        private NativeInstallerFilesystem $fs;
        private NativeInstallerDouble $upgrader;

        protected function setUp(): void
        {
            $this->root = codi_mcp_test_temp_dir() . '/native-installer-' . bin2hex(random_bytes(6));
            mkdir($this->root . '/plugins/example', 0700, true);
            file_put_contents($this->root . '/plugins/example/old.php', 'original');
            $this->fs = new NativeInstallerFilesystem($this->root);
            $GLOBALS['wp_filesystem'] = $this->fs;
            $this->upgrader = new NativeInstallerDouble($this->root);
        }

        protected function tearDown(): void
        {
            codi_mcp_test_remove_directory_tree($this->root);
            unset($GLOBALS['wp_filesystem']);
            parent::tearDown();
        }

        public function test_new_packages_use_the_unmodified_core_install_path(): void
        {
            $installer = new CorePackageInstaller(static fn () => throw new \RuntimeException('No probe for a new package'));
            $result = $installer->install($this->upgrader, 'new.zip', 'plugin', 'new', $this->root . '/plugins', false);
            $this->assertSame(true, $result);
            $this->assertFalse(isset($this->upgrader->extra['temp_backup']));
            $this->assertFalse(isset($this->upgrader->extra['codi_transaction']));
            $this->assertFalse($this->fs->exists($this->root . '/.maintenance'));
        }

        public function test_backups_survive_until_verification_then_core_cleans_up_under_the_lock(): void
        {
            $calls = 0;
            $installer = new CorePackageInstaller(function () use (&$calls) {
                ++$calls;
                $this->assertSame('original', file_get_contents($this->backup() . '/old.php'));
                $this->assertTrue(is_file($this->root . '/plugins/example/new.php'));
                $this->assertTrue(is_file($this->root . '/.maintenance'));
                return true;
            });
            $this->assertSame(true, $this->install($installer));
            $this->assertSame(1, $calls);
            $this->assertSame('plugins', $this->upgrader->extra['temp_backup']['dir']);
            $this->assertFalse(is_dir($this->backup()));
            $this->assertFalse(is_file($this->root . '/.maintenance'));
            $this->assertSame([], array_values($GLOBALS['codi_mcp_test_actions']['shutdown'] ?? []));
            $this->assertSame([], $GLOBALS['codi_mcp_test_filters']['upgrader_package_options']);
            $this->assertSame([], $GLOBALS['codi_mcp_test_filters']['upgrader_post_install']);
        }

        public function test_failed_or_throwing_verification_restores_the_previous_package(): void
        {
            foreach ([
                static fn () => new \WP_Error('fatal', 'Faulty PHP'),
                static fn () => false,
                static fn () => throw new \RuntimeException('Probe failed'),
            ] as $verify) {
                $result = $this->install(new CorePackageInstaller($verify));
                $this->assertSame('codi_mcp_update_rolled_back', $result->get_error_code());
                $this->assertTrue(($GLOBALS['codi_native_cache_cleans'] ?? 0) > 0);
                $this->assertSame('original', file_get_contents($this->root . '/plugins/example/old.php'));
                $this->assertFalse(is_file($this->root . '/plugins/example/new.php'));
                $this->assertFalse(is_file($this->root . '/.maintenance'));
            }
        }

        public function test_failed_restoration_retains_recovery_material_and_blocks_another_update(): void
        {
            $this->upgrader->restoreFails = true;
            $installer = new CorePackageInstaller(static fn () => new \WP_Error('fatal', 'Faulty PHP'));
            $result = $this->install($installer);
            $this->assertSame('codi_mcp_recovery_required', $result->get_error_code());
            $this->assertTrue(is_dir($this->backup()));
            $this->assertTrue(is_file($this->root . '/.maintenance'));
            $this->assertSame('codi_mcp_recovery_required', $this->install($installer)->get_error_code());
            do_action('shutdown');
            $this->assertTrue(is_dir($this->backup()));
        }

        public function test_an_unrelated_maintenance_file_or_backup_is_preserved(): void
        {
            file_put_contents($this->root . '/.maintenance', 'another deployment');
            $result = $this->install(new CorePackageInstaller(static fn () => true));
            $this->assertSame('codi_mcp_deployment_busy', $result->get_error_code());
            $this->assertSame('another deployment', file_get_contents($this->root . '/.maintenance'));
            unlink($this->root . '/.maintenance');
            mkdir($this->backup(), 0700, true);
            $this->assertSame('codi_mcp_recovery_required', $this->install(new CorePackageInstaller())->get_error_code());
            $this->assertSame(0, $this->upgrader->installs);
        }

        public function test_installation_errors_before_backup_leave_the_old_package_intact(): void
        {
            $this->upgrader->beforeBackupError = true;
            $result = $this->install(new CorePackageInstaller(static fn () => true));
            $this->assertSame('pre_install_failed', $result->get_error_code());
            $this->assertSame('original', file_get_contents($this->root . '/plugins/example/old.php'));
            $this->assertFalse(is_dir($this->backup()));
            $this->assertFalse(is_file($this->root . '/.maintenance'));
        }

        public function test_hooks_are_scoped_and_inactive_packages_still_receive_native_backups(): void
        {
            $installer = new CorePackageInstaller(function () {
                $foreign = apply_filters('upgrader_package_options', [
                    'package' => 'other.zip', 'destination' => $this->root . '/plugins',
                    'hook_extra' => ['type' => 'plugin', 'action' => 'install'],
                ]);
                $this->assertFalse(isset($foreign['hook_extra']['temp_backup']));
                $this->assertSame(true, apply_filters('upgrader_post_install', true, ['type' => 'plugin']));
                return true;
            });
            $this->assertSame(true, $this->install($installer));
            $inactive = new CorePackageInstaller(static fn () => throw new \RuntimeException('Inactive probe'));
            $this->assertSame(true, $inactive->install($this->upgrader, 'example.zip', 'plugin', 'example', $this->root . '/plugins', false));
            $this->assertArrayHasKey('temp_backup', $this->upgrader->extra);
        }

        public function test_a_different_native_destination_is_rejected_before_any_files_move(): void
        {
            $this->upgrader->destinationOverride = $this->root . '/different';
            $result = $this->install(new CorePackageInstaller(static fn () => true));
            $this->assertSame('codi_mcp_install_root_mismatch', $result->get_error_code());
            $this->assertSame('original', file_get_contents($this->root . '/plugins/example/old.php'));
            $this->assertFalse(is_dir($this->backup()));
        }

        public function test_native_probe_requires_positive_success_and_removes_its_observer(): void
        {
            $GLOBALS['upgrading'] = 12345;
            $key = md5('12345');
            $probe = new CoreFatalErrorCheck();
            $GLOBALS['codi_native_probe_fatal'] = false;
            foreach (['', '<html>cached page</html>', "###### wp_scraping_result_start:$key ###### true"] as $body) {
                $GLOBALS['codi_native_probe_body'] = $body;
                $this->assertTrue(is_wp_error($probe->verify()));
            }
            $GLOBALS['codi_native_probe_body'] = "###### wp_scraping_result_start:$key ###### true ###### wp_scraping_result_end:$key ######";
            $this->assertSame(true, $probe->verify());
            $GLOBALS['codi_native_probe_fatal'] = true;
            $this->assertTrue(is_wp_error($probe->verify()));
            $this->assertSame([], $GLOBALS['codi_mcp_test_filters']['http_response']);
        }

        private function install(CorePackageInstaller $installer)
        {
            return $installer->install($this->upgrader, 'example.zip', 'plugin', 'example', $this->root . '/plugins', true);
        }
        private function backup(): string { return $this->root . '/content/upgrade-temp-backup/plugins/example'; }
    }

    final class NativeInstallerFilesystem
    {
        public function __construct(private string $root) {}
        public function wp_content_dir(): string { return $this->root . '/content/'; }
        public function abspath(): string { return $this->root . '/'; }
        public function exists(string $path): bool { clearstatcache(true, $path); return file_exists($path); }
        public function is_dir(string $path): bool { clearstatcache(true, $path); return is_dir($path); }
        public function get_contents(string $path): string|false { return is_file($path) ? file_get_contents($path) : false; }
    }

    final class NativeInstallerDouble
    {
        public array $extra = [];
        public int $installs = 0;
        public bool $restoreFails = false;
        public bool $beforeBackupError = false;
        public ?string $destinationOverride = null;
        public function __construct(private string $root) {}
        public function install(string $path, array $args)
        {
            ++$this->installs;
            $options = apply_filters('upgrader_package_options', [
                'package' => $path, 'destination' => $this->destinationOverride ?? $this->root . '/plugins', 'hook_extra' => ['type' => 'plugin', 'action' => 'install'],
            ]);
            $this->extra = $options['hook_extra'];
            $pre = apply_filters('upgrader_pre_install', true, $this->extra);
            if (is_wp_error($pre)) { return $pre; }
            if ($this->beforeBackupError) { return new \WP_Error('pre_install_failed', 'Before backup'); }
            if (!isset($this->extra['temp_backup'])) { return true; }
            $backup = $this->root . '/content/upgrade-temp-backup/plugins/example';
            mkdir(dirname($backup), 0700, true);
            rename($this->root . '/plugins/example', $backup);
            mkdir($this->root . '/plugins/example');
            file_put_contents($this->root . '/plugins/example/new.php', 'updated');
            $result = apply_filters('upgrader_post_install', true, $this->extra);
            if (is_wp_error($result)) { add_action('shutdown', [$this, 'restore_temp_backup'], 10, 0); }
            add_action('shutdown', [$this, 'delete_temp_backup'], 100, 0);
            return is_wp_error($result) ? $result : true;
        }
        public function maintenance_mode(bool $enable): void
        {
            $path = $this->root . '/.maintenance';
            if ($enable) { file_put_contents($path, '<?php $upgrading = ' . time() . '; ?>'); }
            elseif (is_file($path)) { unlink($path); }
        }
        public function restore_temp_backup(array $backups = [])
        {
            if ($this->restoreFails) { return new \WP_Error('restore_failed', 'Cannot restore'); }
            codi_mcp_test_remove_directory_tree($this->root . '/plugins/example');
            return rename($this->root . '/content/upgrade-temp-backup/plugins/example', $this->root . '/plugins/example');
        }
        public function delete_temp_backup(array $backups = []): bool
        {
            codi_mcp_test_remove_directory_tree($this->root . '/content/upgrade-temp-backup/plugins/example');
            return true;
        }
    }
}
