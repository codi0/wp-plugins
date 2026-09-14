<?php

declare(strict_types=1);

namespace CodiMcpTest\Unit;

use CodiMcp\Core\Uploads\UploadCapabilityService;
use CodiMcp\Core\Uploads\UploadStore;
use CodiMcp\Core\Deployment\UpgraderResult;
use CodiMcp\Packages\Plugins\PluginDeployment;
use CodiMcp\Packages\Themes\ThemeDeployment;
use CodiMcpTest\Framework\TestCase;

final class CoreUploadCapabilityServiceTest extends TestCase
{
    private string $rootDirectory;

    protected function setUp(): void
    {
        \codi_mcp_test_reset_environment();
        $this->rootDirectory = rtrim(codi_mcp_test_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'codi-mcp-upload-test-'
            . bin2hex(random_bytes(6));
        @mkdir($this->rootDirectory, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->rootDirectory);
        parent::tearDown();
    }

    public function test_upload_store_is_purpose_bound_hash_verified_and_consumable(): void
    {
        $data = 'abcdefgh';
        $store = new UploadStore(1024, $this->rootDirectory);
        $started = $store->start('design.media', strlen($data), hash('sha256', $data), array(
            'filename' => 'image.bin',
        ));

        $this->assertFalse($started instanceof \WP_Error);
        $uploadId = (string) ($started['upload_id'] ?? '');
        $this->assertSame(32, strlen($uploadId));

        $wrongPurpose = $store->get($uploadId, 'plugins.install');
        $this->assertTrue($wrongPurpose instanceof \WP_Error);
        $this->assertSame('codi_mcp_upload_purpose_mismatch', (string) ($wrongPurpose->code ?? ''));

        $stream = $this->stream($data);
        $received = $store->writeStream($uploadId, 'design.media', $stream);
        fclose($stream);
        $this->assertFalse($received instanceof \WP_Error);

        $complete = $store->complete($uploadId, 'design.media');
        $this->assertFalse($complete instanceof \WP_Error);
        $this->assertTrue((bool) ($complete['complete'] ?? false));
        $this->assertSame($data, (string) file_get_contents((string) ($complete['path'] ?? '')));
        $this->assertSame('image.bin', (string) ($complete['meta']['metadata']['filename'] ?? ''));

        $this->assertTrue($store->consume($uploadId, 'design.media') === true);
        $missing = $store->get($uploadId, 'design.media');
        $this->assertTrue($missing instanceof \WP_Error);
        $this->assertSame('codi_mcp_upload_not_found', (string) ($missing->code ?? ''));
    }

    public function test_upload_store_defaults_to_fifteen_minute_expiry_and_cleanup_is_callable(): void
    {
        $store = new UploadStore(1024, $this->rootDirectory);
        $started = $store->start('plugins.install', 3, hash('sha256', 'abc'));
        $this->assertFalse($started instanceof \WP_Error);
        $expiresAt = strtotime((string) ($started['expires_at'] ?? ''));
        $this->assertTrue(is_int($expiresAt));
        $remaining = $expiresAt - time();
        $this->assertTrue($remaining >= 895 && $remaining <= 905);

        $uploadId = (string) ($started['upload_id'] ?? '');
        $meta = $this->rootDirectory . DIRECTORY_SEPARATOR . 'upload-' . $uploadId . DIRECTORY_SEPARATOR . 'metadata.json';
        touch($meta, time() - 901);
        $this->assertSame(1, $store->cleanupExpired());
        $this->assertTrue($store->get($uploadId, 'plugins.install') instanceof \WP_Error);
    }

    public function test_cleanup_cron_uses_builtin_hourly_schedule_once_and_can_be_cleared(): void
    {
        UploadCapabilityService::registerCleanupCron();
        UploadCapabilityService::registerCleanupCron();

        $events = (array) ($GLOBALS['codi_mcp_test_cron_events'] ?? array());
        $this->assertSame(1, count($events));
        $this->assertSame('hourly', (string) ($events[0]['recurrence'] ?? ''));
        $this->assertSame(UploadCapabilityService::CLEANUP_HOOK, (string) ($events[0]['hook'] ?? ''));
        $this->assertTrue(isset($GLOBALS['codi_mcp_test_actions'][UploadCapabilityService::CLEANUP_HOOK]));

        UploadCapabilityService::clearCleanupCron();
        $this->assertSame(0, count((array) ($GLOBALS['codi_mcp_test_cron_events'] ?? array())));
    }

    public function test_upgrader_false_result_maps_to_explicit_filesystem_error(): void
    {
        foreach (array('plugin' => 'plugins', 'theme' => 'themes') as $artifact => $label) {
            $error = UpgraderResult::error(false, $artifact);
            $this->assertTrue($error instanceof \WP_Error);
            $this->assertSame('fs_unavailable', (string) ($error->code ?? ''));
            $this->assertTrue(str_contains((string) ($error->message ?? ''), 'managing ' . $label));
        }

        $original = new \WP_Error('fixture_failure', 'Fixture failure.');
        $this->assertSame($original, UpgraderResult::error($original, 'plugin'));
        $this->assertSame(null, UpgraderResult::error(true, 'plugin'));
    }

    public function test_capability_service_creates_one_put_destination_and_receives_exact_bytes(): void
    {
        $data = "PK\x03\x04test-artifact";
        $store = new UploadStore(1024, $this->rootDirectory);
        $service = new UploadCapabilityService($store);

        $created = $service->create(
            'plugins.install',
            strlen($data),
            hash('sha256', $data),
            'application/zip',
            array('expected_plugin_slug' => 'test-plugin')
        );
        $this->assertFalse($created instanceof \WP_Error);
        $this->assertFalse((bool) ($created['complete'] ?? true));
        $this->assertSame('PUT', (string) ($created['upload']['method'] ?? ''));
        $this->assertSame('application/zip', (string) ($created['upload']['content_type'] ?? ''));
        $this->assertSame(strlen($data), (int) ($created['upload']['size'] ?? 0));
        $this->assertSame(hash('sha256', $data), (string) ($created['upload']['sha256'] ?? ''));
        $this->assertTrue(str_starts_with((string) ($created['upload']['url'] ?? ''), 'https://'));

        [$purpose, $uploadId, $token] = $this->capabilityParts((string) ($created['upload']['url'] ?? ''));
        $this->assertSame('plugins.install', $purpose);
        $this->assertSame((string) ($created['upload_id'] ?? ''), $uploadId);
        $this->assertSame(64, strlen($token));

        $badToken = ('a' === $token[0] ? 'b' : 'a') . substr($token, 1);
        $invalid = $service->receive($purpose, $uploadId, $badToken, $this->stream($data));
        $this->assertTrue($invalid instanceof \WP_Error);
        $this->assertSame('codi_mcp_upload_capability_invalid', (string) ($invalid->code ?? ''));

        $stream = $this->stream($data);
        $received = $service->receive($purpose, $uploadId, $token, $stream);
        fclose($stream);
        $this->assertFalse($received instanceof \WP_Error);
        $this->assertTrue((bool) ($received['complete'] ?? false));
        $this->assertSame(strlen($data), (int) ($received['received'] ?? 0));

        $complete = $service->complete($uploadId, 'plugins.install');
        $this->assertFalse($complete instanceof \WP_Error);
        $this->assertSame($data, (string) file_get_contents((string) ($complete['path'] ?? '')));
        $this->assertSame('test-plugin', (string) ($complete['meta']['metadata']['expected_plugin_slug'] ?? ''));

        $stream = $this->stream($data);
        $repeat = $service->receive($purpose, $uploadId, $token, $stream);
        fclose($stream);
        $this->assertTrue($repeat instanceof \WP_Error);
        $this->assertSame('codi_mcp_upload_already_received', (string) ($repeat->code ?? ''));
    }

    public function test_capability_receiver_rejects_size_and_hash_mismatches_without_partial_commit(): void
    {
        $store = new UploadStore(1024, $this->rootDirectory);
        $service = new UploadCapabilityService($store);
        $created = $service->create('themes.install', 3, hash('sha256', 'abc'), 'application/zip');
        $this->assertFalse($created instanceof \WP_Error);
        [$purpose, $uploadId, $token] = $this->capabilityParts((string) ($created['upload']['url'] ?? ''));

        $stream = $this->stream('abcd');
        $tooLarge = $service->receive($purpose, $uploadId, $token, $stream);
        fclose($stream);
        $this->assertTrue($tooLarge instanceof \WP_Error);
        $this->assertSame('codi_mcp_too_much_data', (string) ($tooLarge->code ?? ''));

        $stream = $this->stream('abd');
        $wrongHash = $service->receive($purpose, $uploadId, $token, $stream);
        fclose($stream);
        $this->assertTrue($wrongHash instanceof \WP_Error);
        $this->assertSame('codi_mcp_hash_mismatch', (string) ($wrongHash->code ?? ''));

        $stream = $this->stream('abc');
        $received = $service->receive($purpose, $uploadId, $token, $stream);
        fclose($stream);
        $this->assertFalse($received instanceof \WP_Error);
        $this->assertTrue((bool) ($received['complete'] ?? false));
    }

    public function test_plugin_deployment_uses_shared_capability_service(): void
    {
        $data = "PK\x03\x04test-plugin-artifact";
        $service = new UploadCapabilityService(new UploadStore(1024, $this->rootDirectory));
        $deployment = new PluginDeployment(1024, 16, $service);

        $badSlug = $deployment->upload(array('plugin_slug' => '../bad', 'size' => 3, 'sha256' => hash('sha256', 'abc')));
        $this->assertTrue($badSlug instanceof \WP_Error);
        $this->assertSame('codi_mcp_bad_slug', (string) ($badSlug->code ?? ''));

        $result = $deployment->upload(array(
            'plugin_slug' => 'test-plugin',
            'size' => strlen($data),
            'sha256' => hash('sha256', $data),
        ));
        $this->assertFalse($result instanceof \WP_Error);
        $this->assertSame('PUT', (string) ($result['upload']['method'] ?? ''));
        $this->assertTrue(str_contains((string) ($result['instruction'] ?? ''), 'Do not send plugin bytes through MCP'));

        [$purpose, $uploadId, $token] = $this->capabilityParts((string) ($result['upload']['url'] ?? ''));
        $stream = $this->stream($data);
        $received = $service->receive($purpose, $uploadId, $token, $stream);
        fclose($stream);
        $this->assertFalse($received instanceof \WP_Error);

        $complete = $service->complete($uploadId, 'plugins.install');
        $this->assertFalse($complete instanceof \WP_Error);
        $this->assertSame('test-plugin', (string) ($complete['meta']['metadata']['expected_plugin_slug'] ?? ''));
    }

    public function test_theme_deployment_uses_shared_capability_service(): void
    {
        $data = "PK\x03\x04test-theme-artifact";
        $service = new UploadCapabilityService(new UploadStore(1024, $this->rootDirectory));
        $deployment = new ThemeDeployment(1024, 16, $service);

        $result = $deployment->upload(array(
            'stylesheet' => 'test-theme',
            'size' => strlen($data),
            'sha256' => hash('sha256', $data),
        ));
        $this->assertFalse($result instanceof \WP_Error);
        $this->assertSame('PUT', (string) ($result['upload']['method'] ?? ''));
        $this->assertSame('application/zip', (string) ($result['upload']['content_type'] ?? ''));
        $this->assertTrue(str_contains((string) ($result['instruction'] ?? ''), 'Do not send theme bytes through MCP'));

        [$purpose, $uploadId, $token] = $this->capabilityParts((string) ($result['upload']['url'] ?? ''));
        $stream = $this->stream($data);
        $received = $service->receive($purpose, $uploadId, $token, $stream);
        fclose($stream);
        $this->assertFalse($received instanceof \WP_Error);

        $complete = $service->complete($uploadId, 'themes.install');
        $this->assertFalse($complete instanceof \WP_Error);
        $this->assertSame('test-theme', (string) ($complete['meta']['metadata']['stylesheet'] ?? ''));
    }

    /** @return resource */
    private function stream(string $data)
    {
        $stream = fopen('php://temp', 'w+b');
        if (!is_resource($stream)) {
            throw new \RuntimeException('Could not create temporary upload stream.');
        }
        fwrite($stream, $data);
        rewind($stream);
        return $stream;
    }

    /** @return array{0:string,1:string,2:string} */
    private function capabilityParts(string $url): array
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $parts = explode('/', $path);
        $count = count($parts);
        if ($count < 4) {
            throw new \RuntimeException('Capability URL is malformed.');
        }
        return array(
            rawurldecode((string) $parts[$count - 3]),
            (string) $parts[$count - 2],
            (string) $parts[$count - 1],
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $entries = scandir($directory);
        if (!is_array($entries)) {
            return;
        }
        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}
