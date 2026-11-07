<?php

declare(strict_types=1);

namespace {
    $GLOBALS['codi_folder_test_base'] = codi_mcp_test_temp_dir() . '/codi-folder-' . bin2hex(random_bytes(8));
    if (!defined('WP_PLUGIN_DIR')) {
        define('WP_PLUGIN_DIR', $GLOBALS['codi_folder_test_base'] . '/wordpress/wp-content/plugins');
    }
    if (!defined('CODI_MCP_PLUGIN_BASENAME')) {
        define('CODI_MCP_PLUGIN_BASENAME', 'codi-mcp/codi-mcp.php');
    }
    function get_filesystem_method(): string { return $GLOBALS['codi_folder_test_method'] ?? 'direct'; }
    function get_plugins(): array { return $GLOBALS['codi_folder_test_plugins'] ?? array(); }
    function get_theme_root(string $stylesheet = ''): string { return $GLOBALS['codi_folder_test_base'] . '/wordpress/wp-content/themes'; }
    function wp_get_themes(array $args = array()): array { return $GLOBALS['codi_folder_test_themes'] ?? array(); }
    function wp_get_theme(string $stylesheet = ''): object { return wp_get_themes()[$stylesheet]; }
    function is_plugin_active(string $file): bool { return true; }
    function is_plugin_active_for_network(string $file): bool { return false; }
    function wp_clean_plugins_cache(bool $clear = true): void { $GLOBALS['codi_folder_test_plugin_cache'] = true; }
    function wp_clean_themes_cache(bool $clear = true): void { $GLOBALS['codi_folder_test_theme_cache'] = true; }
    function wp_opcache_invalidate(string $file, bool $force = false): bool { $GLOBALS['codi_folder_test_invalidated'][] = str_replace('\\', '/', $file); return true; }
}

namespace CodiMcpTest\Slow {
    use CodiMcp\Core\Deployment\FolderReplacement;
    use CodiMcp\Core\Deployment\ReplacementSelection;
    use CodiMcp\Core\Uploads\UploadCapabilityService;
    use CodiMcp\Core\Uploads\UploadStore;
    use CodiMcp\Packages\Plugins\PluginDeployment;
    use CodiMcp\Packages\Themes\ThemeDeployment;
    use CodiMcpTest\Framework\TestCase;

    final class FolderReplacementTest extends TestCase
    {
        private string $base;
        private string $wordpress;
        private string $plugin;

        protected function setUp(): void
        {
            $this->base = $GLOBALS['codi_folder_test_base'];
            $this->wordpress = $this->base . '/wordpress';
            $this->plugin = WP_PLUGIN_DIR . '/example';
            $this->put($this->plugin . '/example.php', '<?php /* Plugin Name: Example; Version: 1 */');
            $this->put($this->plugin . '/src/Module/old.php', 'original');
            $this->put($this->plugin . '/src/Module/obsolete.php', 'obsolete');
            $this->put($this->plugin . '/src/Other/live.php', 'unrelated');
            $GLOBALS['codi_folder_test_method'] = 'direct';
            $GLOBALS['codi_folder_test_plugins'] = array('example/example.php' => array('Name' => 'Example', 'Version' => '1'));
            $GLOBALS['codi_folder_test_invalidated'] = array();
        }

        protected function tearDown(): void
        {
            $this->remove($this->base);
            parent::tearDown();
        }

        public function test_selection_is_explicit_and_bounded(): void
        {
            $this->assertSame('entire', ReplacementSelection::fromInput(array())['replace']);
            $this->assertSame('entire', ReplacementSelection::fromInput(array('replace' => 'entire'))['replace']);
            $this->assertSame('src/Module', ReplacementSelection::fromInput(array('replace' => 'folder', 'folder' => 'src/Module'))['folder']);
            foreach (array('', '/', '.', '..', '../outside', 'src/../outside', 'src/./Module', 'src//Module', 'src/Module/', 'C:/outside', 'src\\Module', 'src/Module.', 'src/CON', 'src/NUL.txt', "src/\0x") as $path) {
                $this->assertTrue(ReplacementSelection::fromInput(array('replace' => 'folder', 'folder' => $path)) instanceof \WP_Error);
            }
            foreach (array(
                array('folder' => 'src/Module'),
                array('replace' => 'entire', 'folder' => 'src/Module'),
                array('replace' => null),
                array('replace' => 'folders'),
                array('folders' => array('src/Module')),
                array('replace' => 'folder', 'folder' => 'src/Module', 'folders' => array('src/Other')),
                array('replace' => 'folder'),
                array('replace' => 'folder', 'folder' => array('src/Module')),
            ) as $input) {
                $this->assertTrue(ReplacementSelection::fromInput($input) instanceof \WP_Error);
            }
        }

        public function test_replacement_preserves_every_unselected_file_and_removes_obsolete_selected_files(): void
        {
            $runner = $this->runner();
            $selection = $runner->capture($this->plugin, 'src/Module');
            $this->put($this->plugin . '/src/Other/live.php', 'new server edit');
            $zip = $this->zip(array(
                'example/src/Module/new.php' => 'replacement',
                'example/src/Other/live.php' => 'must not be installed',
                'example/example.php' => 'must not replace header',
            ));
            $result = $this->apply($runner, $selection, $zip);
            $this->assertSame(true, $result);
            $this->assertSame(array('new.php' => 'replacement'), $this->snapshot($this->plugin . '/src/Module'));
            $this->assertSame('new server edit', file_get_contents($this->plugin . '/src/Other/live.php'));
            $this->assertSame('<?php /* Plugin Name: Example; Version: 1 */', file_get_contents($this->plugin . '/example.php'));
            $this->assertFalse(file_exists($this->wordpress . '/.maintenance'));
            $this->assertContains(str_replace('\\', '/', (string) realpath($this->plugin)) . '/src/Module/obsolete.php', $GLOBALS['codi_folder_test_invalidated']);
        }

        public function test_a_changed_selected_folder_causes_no_installation(): void
        {
            $runner = $this->runner();
            $selection = $runner->capture($this->plugin, 'src/Module');
            $this->put($this->plugin . '/src/Module/old.php', 'intervening edit');
            $before = $this->snapshot($this->plugin);
            $result = $this->apply($runner, $selection, $this->zip(array('example/src/Module/new.php' => 'replacement')));
            $this->assertSame('codi_mcp_folder_conflict', $result->code);
            $this->assertSame($before, $this->snapshot($this->plugin));
        }

        public function test_handled_failures_at_each_move_boundary_restore_the_original_tree(): void
        {
            foreach (array(1, 2) as $fault) {
                foreach (array(false, true) as $afterMove) {
                    $moves = 0;
                    $runner = $this->runner(static function (string $from, string $to) use (&$moves, $fault, $afterMove): bool {
                        $moves++;
                        if ($moves === $fault && !$afterMove) {
                            return false;
                        }
                        $result = rename($from, $to);
                        if ($moves === $fault && $afterMove) {
                            throw new \RuntimeException('Injected failure after move.');
                        }
                        return $result;
                    });
                    $selection = $runner->capture($this->plugin, 'src/Module');
                    $before = $this->snapshot($this->plugin);
                    $result = $this->apply($runner, $selection, $this->zip(array('example/src/Module/new.php' => 'replacement')));
                    $this->assertTrue($result instanceof \WP_Error);
                    $this->assertSame($before, $this->snapshot($this->plugin));
                    $this->assertFalse(file_exists($this->wordpress . '/.maintenance'));
                }
            }
        }

        public function test_failed_installed_content_verification_restores_original_content(): void
        {
            $moves = 0;
            $runner = $this->runner(static function (string $from, string $to) use (&$moves): bool {
                $result = rename($from, $to);
                if (++$moves === 2) {
                    file_put_contents($to . '/new.php', 'corrupted');
                }
                return $result;
            });
            $selection = $runner->capture($this->plugin, 'src/Module');
            $before = $this->snapshot($this->plugin);
            $result = $this->apply($runner, $selection, $this->zip(array('example/src/Module/new.php' => 'replacement')));
            $this->assertTrue($result instanceof \WP_Error);
            $this->assertSame($before, $this->snapshot($this->plugin));
        }

        public function test_runtime_verification_must_succeed_before_folder_commit(): void
        {
            $runner = $this->runner();
            $selection = $runner->capture($this->plugin, 'src/Module');
            $before = $this->snapshot($this->plugin);
            $zip = $this->zip(array('example/src/Module/new.php' => 'replacement'));
            $calls = 0;
            $result = $runner->locked($this->plugin, function () use ($runner, $selection, $zip, &$calls) {
                return $runner->replace(
                    $this->plugin,
                    $selection,
                    $zip,
                    'example',
                    bin2hex(random_bytes(16)),
                    function () use (&$calls) {
                        ++$calls;
                        $this->assertSame('replacement', file_get_contents($this->plugin . '/src/Module/new.php'));
                        $this->assertTrue(file_exists($this->wordpress . '/.maintenance'));
                        return new \WP_Error('fixture_runtime_unhealthy', 'Fixture runtime verification failed.');
                    }
                );
            });

            $this->assertTrue($result instanceof \WP_Error);
            $this->assertSame('codi_mcp_folder_replace_failed', $result->get_error_code());
            $this->assertSame(1, $calls);
            $this->assertSame($before, $this->snapshot($this->plugin));
            $this->assertFalse(file_exists($this->wordpress . '/.maintenance'));
        }

        public function test_failed_recovery_retains_backups_and_blocks_subsequent_deployments(): void
        {
            $moves = 0;
            $runner = $this->runner(static function (string $from, string $to) use (&$moves): bool {
                if (++$moves === 2 || $moves === 3) {
                    return false;
                }
                return rename($from, $to);
            });
            $selection = $runner->capture($this->plugin, 'src/Module');
            $result = $this->apply($runner, $selection, $this->zip(array('example/src/Module/new.php' => 'replacement')));
            $this->assertSame('codi_mcp_recovery_required', $result->code);
            $journals = glob($this->base . '/private/transactions/*/*/journal.json');
            $this->assertCount(1, $journals);
            $this->assertSame('original', file_get_contents(dirname($journals[0]) . '/original/old.php'));
            $this->assertTrue(file_exists($this->wordpress . '/.maintenance'));
            $this->assertSame('codi_mcp_recovery_required', $runner->capture($this->plugin, 'src/Module')->code);
        }

        public function test_process_termination_preserves_recovery_state_and_releases_the_lock(): void
        {
            foreach (array('src/Module', 'src/NewModule') as $folder) {
                $workspace = $this->base . '/private-' . basename($folder);
                $runner = new FolderReplacement($workspace, null, $this->wordpress, $this->fixtureExtractor());
                $selection = $runner->capture($this->plugin, $folder);
                $original = $selection['exists'] ? $this->snapshot($this->plugin . '/' . $folder) : null;
                $zip = $this->zip(array('example/' . $folder . '/new.php' => 'new'));
                $child = <<<'PHP'
require $argv[1] . '/bootstrap.php';
$runner = new \CodiMcp\Core\Deployment\FolderReplacement(
    $argv[2],
    static function (string $from, string $to): bool {
        if (rename($from, $to)) {
            exit(73); // Exit after the first live move, without finally cleanup.
        }
        return false;
    },
    $argv[3],
    static function (string $archivePath, string $wrapper, string $folder, string $destination) {
        $entries = json_decode((string) file_get_contents($archivePath), true);
        if (!is_array($entries)) {
            return new \WP_Error('fixture_archive_invalid', 'Fixture archive is invalid.');
        }
        $prefix = $wrapper . '/' . $folder;
        $found = false;
        foreach ($entries as $name => $contents) {
            $path = rtrim((string) $name, '/');
            if ($path !== $prefix && !str_starts_with($path, $prefix . '/')) {
                continue;
            }
            $found = true;
            if (!is_dir($destination)) {
                mkdir($destination, 0755, true);
            }
            $relative = ltrim(substr($path, strlen($prefix)), '/');
            if ($relative === '') {
                continue;
            }
            $target = $destination . '/' . $relative;
            if ($contents === null) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
                continue;
            }
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }
            file_put_contents($target, (string) $contents);
        }
        return $found ? true : new \WP_Error('fixture_archive_missing', 'Fixture archive does not contain the selected folder.');
    }
);
$selection = json_decode($argv[5], true);
$result = $runner->locked($argv[4], fn () => $runner->replace($argv[4], $selection, $argv[6], 'example', $argv[7]));
if ($result instanceof \WP_Error) {
    fwrite(STDERR, $result->get_error_message());
}
exit(1);
PHP;
                $pipes = array();
                $process = proc_open(array(
                    PHP_BINARY, '-r', $child,
                    dirname(__DIR__), $workspace, $this->wordpress, $this->plugin,
                    json_encode($selection, JSON_THROW_ON_ERROR), $zip, bin2hex(random_bytes(16)),
                ), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
                $this->assertTrue(is_resource($process));
                fclose($pipes[0]);
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(73, proc_close($process), $stdout . $stderr);
                $journals = glob($workspace . '/transactions/*/*/journal.json');
                $this->assertCount(1, $journals);
                $journal = json_decode(file_get_contents($journals[0]), true);
                $this->assertSame($selection['exists'], $journal['exists']);
                if ($original !== null) {
                    $this->assertSame($original, $this->snapshot(dirname($journals[0]) . '/original'));
                    $this->assertFalse(is_dir($this->plugin . '/' . $folder));
                } else {
                    $this->assertFalse(is_dir(dirname($journals[0]) . '/original'));
                    $this->assertSame('new', file_get_contents($this->plugin . '/' . $folder . '/new.php'));
                }
                $this->assertTrue(file_exists($this->wordpress . '/.maintenance'));
                $this->assertSame('codi_mcp_recovery_required', $runner->capture($this->plugin, $folder)->code);
                unlink($this->wordpress . '/.maintenance'); // Isolated fixture, owned by this child.
            }
        }

        public function test_additions_through_plugin_and_theme_flows_preserve_other_files(): void
        {
            $themeRoot = get_theme_root() . '/example-theme';
            $this->put($themeRoot . '/style.css', 'Theme Name: Example theme');
            $this->put($themeRoot . '/templates/index.html', 'existing template');
            $GLOBALS['codi_folder_test_themes'] = array('example-theme' => new class($themeRoot) {
                public function __construct(private string $root) {}
                public function get_stylesheet_directory(): string { return $this->root; }
                public function get(string $field): string { return $field === 'Name' ? 'Example theme' : '1'; }
            });
            foreach (array('plugin', 'theme') as $kind) {
                $folder = $kind === 'plugin' ? 'src/NewModule' : 'templates/new';
                $wrapper = $kind === 'plugin' ? 'example' : 'example-theme';
                $root = $kind === 'plugin' ? $this->plugin : $themeRoot;
                $before = $this->snapshot($root);
                [$deployment, $upload, $service] = $this->uploaded($kind, $this->zip(array($wrapper . '/' . $folder . '/nested/new.txt' => 'added')), $folder);
                $stored = $service->complete($upload['upload_id'], $kind === 'plugin' ? 'plugins.install' : 'themes.install');
                $this->assertSame(false, $stored['meta']['metadata']['replacement']['exists']);
                $result = $deployment->install(array('upload_id' => $upload['upload_id']));
                $this->assertFalse($result instanceof \WP_Error);
                $this->assertSame('1', $result['version']);
                $this->assertSame('added', file_get_contents($root . '/' . $folder . '/nested/new.txt'));
                $this->remove($root . '/' . $folder);
                $this->assertSame($before, $this->snapshot($root));
                $this->assertFalse(file_exists($this->wordpress . '/.maintenance'));
            }
        }

        public function test_an_appearing_destination_is_never_replaced(): void
        {
            $runner = $this->runner();
            $zip = $this->zip(array('example/src/NewModule/new.txt' => 'must not be installed'));
            foreach (array('empty', 'directory', 'file') as $kind) {
                $selection = $runner->capture($this->plugin, 'src/NewModule');
                $this->assertSame(false, $selection['exists']);
                $target = $this->plugin . '/src/NewModule';
                if ($kind === 'empty') {
                    mkdir($target);
                } elseif ($kind === 'directory') {
                    $this->put($target . '/manual.txt', 'preserve manual addition');
                } else {
                    $this->put($target, 'preserve colliding file');
                }
                $before = $this->snapshot($this->plugin);
                $this->assertTrue($this->apply($runner, $selection, $zip) instanceof \WP_Error);
                $this->assertSame($before, $this->snapshot($this->plugin));
                $this->remove($target);
            }
        }

        public function test_failed_additions_restore_absence(): void
        {
            foreach (array('before', 'after', 'verification') as $failure) {
                $moves = 0;
                $runner = $this->runner(static function (string $from, string $to) use (&$moves, $failure): bool {
                    if (++$moves === 1 && $failure === 'before') {
                        return false;
                    }
                    $result = rename($from, $to);
                    if ($moves === 1 && $failure === 'after') {
                        throw new \RuntimeException('Injected failure after addition.');
                    }
                    if ($moves === 1 && $failure === 'verification') {
                        file_put_contents($to . '/new.txt', 'corrupted');
                    }
                    return $result;
                });
                $selection = $runner->capture($this->plugin, 'src/NewModule');
                $before = $this->snapshot($this->plugin);
                $result = $this->apply($runner, $selection, $this->zip(array('example/src/NewModule/new.txt' => 'new')));
                $this->assertTrue($result instanceof \WP_Error);
                $this->assertSame($before, $this->snapshot($this->plugin));
                $this->assertFalse(file_exists($this->wordpress . '/.maintenance'));
            }
        }

        public function test_failed_addition_cleanup_retains_recovery_state(): void
        {
            $moves = 0;
            $runner = $this->runner(static function (string $from, string $to) use (&$moves): bool {
                if (++$moves === 2) {
                    return false;
                }
                $result = rename($from, $to);
                throw new \RuntimeException('Injected failure after addition.');
            });
            $selection = $runner->capture($this->plugin, 'src/NewModule');
            $result = $this->apply($runner, $selection, $this->zip(array('example/src/NewModule/new.txt' => 'new')));
            $this->assertSame('codi_mcp_recovery_required', $result->code);
            $journals = glob($this->base . '/private/transactions/*/*/journal.json');
            $this->assertCount(1, $journals);
            $journal = json_decode(file_get_contents($journals[0]), true);
            $this->assertSame(false, $journal['exists']);
            $this->assertFalse(is_dir(dirname($journals[0]) . '/original'));
            $this->assertSame('new', file_get_contents($this->plugin . '/src/NewModule/new.txt'));
            $this->assertSame('codi_mcp_recovery_required', $runner->capture($this->plugin, 'src/NewModule')->code);
        }

        public function test_replacement_requires_complete_capture_metadata(): void
        {
            $runner = $this->runner();
            $selection = $runner->capture($this->plugin, 'src/Module');
            $before = $this->snapshot($this->plugin);
            unset($selection['exists']);
            $result = $this->apply($runner, $selection, $this->zip(array('example/src/Module/new.txt' => 'new')));
            $this->assertTrue($result instanceof \WP_Error);
            $this->assertSame('codi_mcp_bad_replacement', $result->get_error_code());
            $this->assertSame($before, $this->snapshot($this->plugin));
        }

        public function test_an_empty_explicit_source_directory_clears_only_the_selected_folder(): void
        {
            $runner = $this->runner();
            $selection = $runner->capture($this->plugin, 'src/Module');
            $this->assertSame(true, $this->apply($runner, $selection, $this->zip(array('example/src/Module/' => null))));
            $this->assertSame(array(), $this->snapshot($this->plugin . '/src/Module'));
            $this->assertSame('unrelated', file_get_contents($this->plugin . '/src/Other/live.php'));
        }

        public function test_unsafe_or_ambiguous_archives_never_modify_the_target(): void
        {
            $runner = new FolderReplacement($this->base . '/archive-private', null, $this->wordpress);
            if (!class_exists('ZipArchive')) {
                $result = $runner->capture($this->plugin, 'src/Module');
                $this->assertTrue($result instanceof \WP_Error);
                $this->assertSame('codi_mcp_zip_required', $result->get_error_code());
                return;
            }
            $selection = $runner->capture($this->plugin, 'src/Module');
            $before = $this->snapshot($this->plugin);
            foreach (array(
                array('other/src/Module/a.php' => 'wrong target'),
                array('example/src/Missing/a.php' => 'missing source'),
                array('example/src/Module/../escape.php' => 'traversal'),
                array('example/src/Module/a.php' => 'a', 'example/src/Module/A.php' => 'case collision'),
                array('example/src/Module/Case/a.php' => 'a', 'example/src/Module/case/b.php' => 'implicit directory case collision'),
                array('example/src/Module/a' => 'file', 'example/src/Module/a/b.php' => 'collision'),
                array('example/src/Module//a.php' => 'ambiguous'),
                array('example/src/Module/a.php' => 'new', 'example/outside-link' => 'symlink'),
            ) as $entries) {
                $zip = $this->realZip($entries);
                if (isset($entries['example/outside-link'])) {
                    $archive = new \ZipArchive();
                    $archive->open($zip);
                    $archive->setExternalAttributesName('example/outside-link', \ZipArchive::OPSYS_UNIX, 0120777 << 16);
                    $archive->close();
                }
                $this->assertTrue($this->apply($runner, $selection, $zip) instanceof \WP_Error);
                $this->assertSame($before, $this->snapshot($this->plugin));
            }
        }

        public function test_lock_and_existing_maintenance_protection_are_preserved(): void
        {
            $runner = $this->runner();
            $this->assertSame('codi_mcp_deployment_busy', $runner->locked($this->plugin, fn () => $runner->capture($this->plugin, 'src/Module'))->code);
            $selection = $runner->capture($this->plugin, 'src/Module');
            $this->put($this->wordpress . '/.maintenance', 'another deployment');
            $before = $this->snapshot($this->plugin);
            $result = $this->apply($runner, $selection, $this->zip(array('example/src/Module/new.php' => 'new')));
            $this->assertTrue($result instanceof \WP_Error);
            $this->assertSame($before, $this->snapshot($this->plugin));
            $this->assertSame('another deployment', file_get_contents($this->wordpress . '/.maintenance'));
        }

        public function test_target_and_staging_constraints_fail_before_replacement(): void
        {
            $runner = $this->runner();
            $this->assertSame(false, $runner->capture($this->plugin, 'missing')['exists']);
            $this->assertTrue($runner->capture($this->plugin, 'missing/child') instanceof \WP_Error);
            $this->assertTrue($runner->capture($this->plugin, 'src/module') instanceof \WP_Error);
            $GLOBALS['codi_folder_test_method'] = 'ftpext';
            $this->assertSame('codi_mcp_direct_filesystem_required', $runner->capture($this->plugin, 'src/Module')->code);
            $GLOBALS['codi_folder_test_method'] = 'direct';
            $publicRunner = new FolderReplacement($this->wordpress . '/public-stage', null, $this->wordpress, $this->fixtureExtractor());
            $before = $this->snapshot($this->plugin);
            $result = $publicRunner->capture($this->plugin, 'src/Module');
            $this->assertSame('codi_mcp_private_staging_required', $result->code);
            $this->assertSame($before, $this->snapshot($this->plugin));
        }

        public function test_plugin_upload_and_install_bind_the_folder_and_preserve_identity(): void
        {
            [$deployment, $upload, $service] = $this->uploaded('plugin', $this->zip(array('example/src/Module/new.php' => 'new')));
            $stored = $service->complete($upload['upload_id'], 'plugins.install');
            $this->assertSame('folder', $stored['meta']['metadata']['replace']);
            $this->assertSame('src/Module', $stored['meta']['metadata']['replacement']['folder']);
            $result = $deployment->install(array('upload_id' => $upload['upload_id']));
            $this->assertFalse($result instanceof \WP_Error);
            $this->assertSame('folder', $result['replace']);
            $this->assertSame('1', $result['version']);
            $this->assertSame('example/example.php', $result['plugin_file']);
            $this->assertSame('new', file_get_contents($this->plugin . '/src/Module/new.php'));
            $this->assertSame('unrelated', file_get_contents($this->plugin . '/src/Other/live.php'));
            $this->assertTrue($service->complete($upload['upload_id'], 'plugins.install') instanceof \WP_Error);
        }

        public function test_theme_upload_and_install_share_folder_mechanics_without_changing_root_files(): void
        {
            $themeRoot = get_theme_root() . '/example-theme';
            $this->put($themeRoot . '/style.css', 'Theme Name: Example theme');
            $this->put($themeRoot . '/assets/old.css', 'old');
            $this->put($themeRoot . '/templates/index.html', 'unchanged template');
            $GLOBALS['codi_folder_test_themes'] = array('example-theme' => new class($themeRoot) {
                public function __construct(private string $root) {}
                public function get_stylesheet_directory(): string { return $this->root; }
                public function get(string $field): string { return $field === 'Name' ? 'Example theme' : '1'; }
            });
            [$deployment, $upload] = $this->uploaded('theme', $this->zip(array('example-theme/assets/new.css' => 'new')), 'assets');
            $result = $deployment->install(array('upload_id' => $upload['upload_id']));
            $this->assertFalse($result instanceof \WP_Error);
            $this->assertSame('folder', $result['replace']);
            $this->assertSame('1', $result['version']);
            $this->assertSame('Theme Name: Example theme', file_get_contents($themeRoot . '/style.css'));
            $this->assertSame('unchanged template', file_get_contents($themeRoot . '/templates/index.html'));
            $this->assertFalse(file_exists($themeRoot . '/assets/old.css'));
        }

        public function test_entrypoints_and_codi_self_replacement_are_rejected_at_upload_creation(): void
        {
            $deployment = new PluginDeployment(1024, 16, null, null, $this->runner());
            foreach (array('codi-mcp' => 'src', 'example' => 'src/Module') as $slug => $folder) {
                if ($slug === 'example') {
                    $GLOBALS['codi_folder_test_plugins']['example/src/Module/nested.php'] = array('Name' => 'Nested plugin', 'Version' => '1');
                }
                $result = $deployment->upload(array('plugin_slug' => $slug, 'size' => 3, 'sha256' => hash('sha256', 'abc'), 'replace' => 'folder', 'folder' => $folder));
                $this->assertTrue($result instanceof \WP_Error);
            }
        }

        private function uploaded(string $kind, string $zip, string $folder = 'src/Module'): array
        {
            $service = new UploadCapabilityService(new UploadStore(2 * 1024 * 1024, $this->base . '/uploads'));
            $deployment = $kind === 'plugin'
                ? new PluginDeployment(2 * 1024 * 1024, 1024, $service, null, $this->runner())
                : new ThemeDeployment(2 * 1024 * 1024, 1024, $service, null, $this->runner());
            $input = $kind === 'plugin' ? array('plugin_slug' => 'example') : array('stylesheet' => 'example-theme');
            $upload = $deployment->upload($input + array('replace' => 'folder', 'folder' => $folder, 'size' => filesize($zip), 'sha256' => hash_file('sha256', $zip)));
            $this->assertFalse($upload instanceof \WP_Error);
            $stream = fopen($zip, 'rb');
            try {
                $result = $service->receive($kind === 'plugin' ? 'plugins.install' : 'themes.install', $upload['upload_id'], basename(parse_url($upload['upload']['url'], PHP_URL_PATH)), $stream);
                $this->assertFalse($result instanceof \WP_Error);
            } finally {
                fclose($stream);
            }
            return array($deployment, $upload, $service);
        }

        private function runner(?callable $move = null): FolderReplacement
        {
            return new FolderReplacement($this->base . '/private', $move, $this->wordpress, $this->fixtureExtractor());
        }

        private function apply(FolderReplacement $runner, array $selection, string $zip): mixed
        {
            return $runner->locked($this->plugin, fn () => $runner->replace($this->plugin, $selection, $zip, 'example', bin2hex(random_bytes(16))));
        }

        private function zip(array $entries): string
        {
            $path = $this->base . '/archive-' . bin2hex(random_bytes(8)) . '.json';
            file_put_contents($path, json_encode($entries, JSON_THROW_ON_ERROR));
            return $path;
        }

        private function realZip(array $entries): string
        {
            $path = $this->base . '/archive-' . bin2hex(random_bytes(8)) . '.zip';
            $zip = new \ZipArchive();
            $zip->open($path, \ZipArchive::CREATE);
            foreach ($entries as $name => $contents) {
                if ($contents === null) {
                    $zip->addEmptyDir($name);
                } else {
                    $zip->addFromString($name, $contents);
                }
            }
            $zip->close();
            return $path;
        }

        private function fixtureExtractor(): callable
        {
            return static function (string $archivePath, string $wrapper, string $folder, string $destination): true|\WP_Error {
                $entries = json_decode((string) file_get_contents($archivePath), true);
                if (!is_array($entries)) {
                    return new \WP_Error('fixture_archive_invalid', 'Fixture archive is invalid.');
                }
                $prefix = $wrapper . '/' . $folder;
                $found = false;
                foreach ($entries as $name => $contents) {
                    $path = rtrim((string) $name, '/');
                    if ($path !== $prefix && !str_starts_with($path, $prefix . '/')) {
                        continue;
                    }
                    $found = true;
                    if (!is_dir($destination)) {
                        mkdir($destination, 0755, true);
                    }
                    $relative = ltrim(substr($path, strlen($prefix)), '/');
                    if ($relative === '') {
                        continue;
                    }
                    $target = $destination . '/' . $relative;
                    if ($contents === null) {
                        if (!is_dir($target)) {
                            mkdir($target, 0755, true);
                        }
                        continue;
                    }
                    if (!is_dir(dirname($target))) {
                        mkdir(dirname($target), 0755, true);
                    }
                    file_put_contents($target, (string) $contents);
                }
                return $found ? true : new \WP_Error('fixture_archive_missing', 'Fixture archive does not contain the selected folder.');
            };
        }

        private function put(string $path, string $contents): void
        {
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, $contents);
        }

        private function snapshot(string $root): array
        {
            $result = array();
            foreach (scandir($root) as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $path = $root . '/' . $name;
                if (is_dir($path) && !is_link($path)) {
                    $result[$name . '/'] = $this->snapshot($path);
                } else {
                    $result[$name] = file_get_contents($path);
                }
            }
            return $result;
        }

        private function remove(string $path): void
        {
            if (is_dir($path) && !is_link($path)) {
                foreach (scandir($path) as $name) {
                    if ($name !== '.' && $name !== '..') {
                        $this->remove($path . '/' . $name);
                    }
                }
                rmdir($path);
            } elseif (file_exists($path) || is_link($path)) {
                unlink($path);
            }
        }
    }
}
