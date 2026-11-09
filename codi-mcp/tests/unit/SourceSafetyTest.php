<?php

declare(strict_types=1);

namespace CodiMcpTest\Unit;

use CodiMcp\Core\Deployment\CodeRecoveryStore;
use CodiMcp\Core\Deployment\PackageFingerprint;
use CodiMcp\Core\Deployment\VerifiedPackageSnapshot;
use CodiMcp\Core\Downloads\ArtifactDownloadStore;
use CodiMcp\Core\PackageSourcePolicy;
use CodiMcpTest\Framework\TestCase;

final class SourceSafetyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = codi_mcp_test_temp_dir() . DIRECTORY_SEPARATOR . 'codi-source-safety-' . CODI_MCP_TEST_RUN_ID . '-' . getmypid();
        codi_mcp_test_remove_directory_tree($this->root);
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        codi_mcp_test_remove_directory_tree($this->root);
    }

    public function test_source_policy_defaults_external_with_fixed_codi_package_exceptions(): void
    {
        $policy = new PackageSourcePolicy();
        $this->assertSame(PackageSourcePolicy::EXTERNAL, $policy->state('plugin', 'woocommerce/woocommerce.php'));
        $this->assertFalse($policy->canCustomReplace('plugin', 'woocommerce', 'entire'));
        $this->assertFalse($policy->canDelete('plugin', 'woocommerce'));

        $this->assertSame(PackageSourcePolicy::MANAGED, $policy->state('plugin', 'codi-mcp-custom/codi-mcp-custom.php'));
        $this->assertTrue($policy->canCustomReplace('plugin', 'codi-mcp-custom', 'entire'));
        $this->assertTrue($policy->canCustomReplace('plugin', 'codi-mcp-custom', 'folder'));
        $this->assertTrue($policy->canCustomReplace('plugin', 'codi-mcp-custom', 'file'));
        $this->assertTrue($policy->canDelete('plugin', 'codi-mcp-custom'));
        $this->assertTrue($policy->isFixed('plugin', 'codi-mcp-custom'));
        $this->assertTrue($policy->isFixed('plugin', 'codi-mcp'));

        $this->assertTrue($policy->saveManaged(array('plugin:mwf', 'theme:bhi', 'plugin:codi-mcp', 'plugin:codi-mcp-custom', 'bad/value')));
        $fresh = new PackageSourcePolicy();
        $this->assertSame(PackageSourcePolicy::MANAGED, $fresh->state('plugin', 'mwf/index.php'));
        $this->assertSame(PackageSourcePolicy::MANAGED, $fresh->state('theme', 'bhi'));
        $this->assertSame(PackageSourcePolicy::PROTECTED, $fresh->state('plugin', 'codi-mcp/codi-mcp.php'));
        $this->assertTrue($fresh->canCustomReplace('plugin', 'codi-mcp', 'entire'));
        $this->assertFalse($fresh->canCustomReplace('plugin', 'codi-mcp', 'folder'));
        $this->assertFalse($fresh->canCustomReplace('plugin', 'codi-mcp', 'file'));
        $this->assertFalse($fresh->canDelete('plugin', 'codi-mcp'));
        $this->assertNotContains('plugin:codi-mcp', $fresh->managedKeys());
        $this->assertSame(PackageSourcePolicy::MANAGED, $fresh->state('plugin', 'codi-mcp-custom/codi-mcp-custom.php'));
        $this->assertNotContains('plugin:codi-mcp-custom', $fresh->managedKeys());
    }

    public function test_package_fingerprint_is_deterministic_change_sensitive_and_has_explicit_absence(): void
    {
        $package = $this->root . DIRECTORY_SEPARATOR . 'package';
        mkdir($package . DIRECTORY_SEPARATOR . 'inc', 0777, true);
        file_put_contents($package . DIRECTORY_SEPARATOR . 'plugin.php', '<?php echo 1;');
        file_put_contents($package . DIRECTORY_SEPARATOR . 'inc' . DIRECTORY_SEPARATOR . 'a.php', '<?php return 1;');

        $fingerprints = new PackageFingerprint();
        $first = $fingerprints->directory($package);
        $this->assertSame($first, $fingerprints->directory($package));
        file_put_contents($package . DIRECTORY_SEPARATOR . 'inc' . DIRECTORY_SEPARATOR . 'a.php', '<?php return 2;');
        $this->assertFalse(hash_equals($first, $fingerprints->directory($package)));
        $this->assertSame(PackageFingerprint::absent(), $fingerprints->current($this->root . DIRECTORY_SEPARATOR . 'missing'));
    }

    public function test_recovery_store_keeps_immutable_code_snapshot_metadata_and_detects_archive_tampering(): void
    {
        $source = $this->root . DIRECTORY_SEPARATOR . 'mwf';
        mkdir($source, 0777, true);
        file_put_contents($source . DIRECTORY_SEPARATOR . 'index.php', '<?php echo "before";');
        $fingerprint = (new PackageFingerprint())->directory($source);

        $artifactRoot = $this->root . DIRECTORY_SEPARATOR . 'artifacts';
        $artifacts = new ArtifactDownloadStore(
            1024 * 1024,
            1024,
            $artifactRoot,
            3600,
            100,
            1024 * 1024,
            static function (array $files, string $sourceRoot, string $archiveRoot, string $archivePath): bool {
                $payload = array();
                foreach ($files as $file) {
                    $payload[$archiveRoot . '/' . ltrim(str_replace('\\', '/', substr($file, strlen($sourceRoot))), '/')] = file_get_contents($file);
                }
                return file_put_contents($archivePath, json_encode($payload, JSON_THROW_ON_ERROR)) !== false;
            }
        );
        $store = new CodeRecoveryStore($artifacts);
        $created = $store->create('plugin', 'mwf', $source, 'mwf', $fingerprint, array('before_version' => '1.0.0'));
        $this->assertFalse($created instanceof \WP_Error);
        file_put_contents($source . DIRECTORY_SEPARATOR . 'index.php', '<?php echo "after";');
        $after = (new PackageFingerprint())->directory($source);
        $committed = $store->commit((string) $created['recovery_id'], $after, '1.1.0');
        $this->assertFalse($committed instanceof \WP_Error);
        $latest = $store->latest('plugin', 'mwf');
        $this->assertSame((string) $created['recovery_id'], (string) ($latest['recovery_id'] ?? ''));
        $this->assertSame($fingerprint, (string) ($latest['before_fingerprint'] ?? ''));
        $this->assertSame($after, (string) ($latest['after_fingerprint'] ?? ''));

        $artifact = $store->artifact((string) $created['recovery_id'], 'plugin');
        $this->assertFalse($artifact instanceof \WP_Error);
        file_put_contents((string) $artifact['path'], 'tampered');
        $tampered = $store->artifact((string) $created['recovery_id'], 'plugin');
        $this->assertTrue($tampered instanceof \WP_Error);
        $this->assertSame('codi_mcp_recovery_integrity', $tampered->get_error_code());
    }

    public function test_verified_snapshot_refuses_a_source_that_changes_while_it_is_copied(): void
    {
        $source = $this->root . DIRECTORY_SEPARATOR . 'racing-package';
        mkdir($source, 0777, true);
        file_put_contents($source . DIRECTORY_SEPARATOR . 'a.php', '<?php echo "a";');
        file_put_contents($source . DIRECTORY_SEPARATOR . 'b.php', '<?php echo "before";');
        $expected = (new PackageFingerprint())->directory($source);

        $changed = false;
        $snapshots = new VerifiedPackageSnapshot(
            $this->root . DIRECTORY_SEPARATOR . 'snapshot-workspace',
            static function (string $from) use ($source, &$changed): void {
                if (!$changed && basename($from) === 'a.php') {
                    file_put_contents($source . DIRECTORY_SEPARATOR . 'b.php', '<?php echo "after";');
                    $changed = true;
                }
            }
        );
        $result = $snapshots->create($source, $expected);
        $this->assertTrue($result instanceof \WP_Error);
        $this->assertSame('codi_mcp_source_changed', $result->get_error_code());
    }

    public function test_pending_recovery_never_evicts_committed_points_and_restored_points_are_consumed(): void
    {
        $source = $this->root . DIRECTORY_SEPARATOR . 'retained-package';
        mkdir($source, 0777, true);
        $artifactRoot = $this->root . DIRECTORY_SEPARATOR . 'retention-artifacts';
        $artifacts = new ArtifactDownloadStore(
            1024 * 1024,
            1024,
            $artifactRoot,
            3600,
            100,
            1024 * 1024,
            static function (array $files, string $sourceRoot, string $archiveRoot, string $archivePath): bool {
                $payload = array();
                foreach ($files as $file) {
                    $payload[$archiveRoot . '/' . ltrim(str_replace('\\', '/', substr($file, strlen($sourceRoot))), '/')] = file_get_contents($file);
                }
                return file_put_contents($archivePath, json_encode($payload, JSON_THROW_ON_ERROR)) !== false;
            }
        );
        $store = new CodeRecoveryStore($artifacts, new VerifiedPackageSnapshot($this->root . DIRECTORY_SEPARATOR . 'retention-staging'));
        $ids = array();
        for ($index = 1; $index <= 3; $index++) {
            file_put_contents($source . DIRECTORY_SEPARATOR . 'index.php', '<?php echo "before-' . $index . '";');
            $before = (new PackageFingerprint())->directory($source);
            $created = $store->create('plugin', 'retained-package', $source, 'retained-package', $before);
            $this->assertFalse($created instanceof \WP_Error);
            file_put_contents($source . DIRECTORY_SEPARATOR . 'index.php', '<?php echo "after-' . $index . '";');
            $after = (new PackageFingerprint())->directory($source);
            $committed = $store->commit((string) $created['recovery_id'], $after, (string) $index);
            $this->assertFalse($committed instanceof \WP_Error);
            $ids[] = (string) $created['recovery_id'];
        }

        file_put_contents($source . DIRECTORY_SEPARATOR . 'index.php', '<?php echo "pending";');
        $pendingBefore = (new PackageFingerprint())->directory($source);
        $pending = $store->create('plugin', 'retained-package', $source, 'retained-package', $pendingBefore);
        $this->assertFalse($pending instanceof \WP_Error);
        $store->abandon((string) $pending['recovery_id']);

        $available = 0;
        foreach ($ids as $id) {
            if (!($store->artifact($id, 'plugin') instanceof \WP_Error)) {
                ++$available;
            }
        }
        $this->assertSame(3, $available);

        file_put_contents($source . DIRECTORY_SEPARATOR . 'index.php', '<?php echo "fourth-before";');
        $fourthBefore = (new PackageFingerprint())->directory($source);
        $fourth = $store->create('plugin', 'retained-package', $source, 'retained-package', $fourthBefore);
        $this->assertFalse($fourth instanceof \WP_Error);
        file_put_contents($source . DIRECTORY_SEPARATOR . 'index.php', '<?php echo "fourth-after";');
        $fourthAfter = (new PackageFingerprint())->directory($source);
        $fourthCommitted = $store->commit((string) $fourth['recovery_id'], $fourthAfter, '4');
        $this->assertFalse($fourthCommitted instanceof \WP_Error);
        $allIds = array_merge($ids, array((string) $fourth['recovery_id']));
        $available = 0;
        foreach ($allIds as $id) {
            if (!($store->artifact($id, 'plugin') instanceof \WP_Error)) {
                ++$available;
            }
        }
        $this->assertSame(3, $available);

        $latest = $store->latest('plugin', 'retained-package');
        $this->assertTrue(is_array($latest));
        $latestId = (string) ($latest['recovery_id'] ?? '');
        $store->markRestored($latestId);
        $this->assertTrue($store->artifact($latestId, 'plugin') instanceof \WP_Error);
        $next = $store->latest('plugin', 'retained-package');
        $this->assertTrue($next === null || (string) ($next['recovery_id'] ?? '') !== $latestId);
    }
}