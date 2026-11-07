<?php

declare(strict_types=1);

namespace CodiMcpTest\Unit;

use CodiMcp\Core\Downloads\ArtifactDownloadStore;
use CodiMcp\Core\Downloads\DownloadCapabilityService;
use CodiMcpTest\Framework\TestCase;

final class DownloadCapabilityServiceTest extends TestCase
{
    private string $root;
    private ArtifactDownloadStore $store;
    private DownloadCapabilityService $service;
    private int $now;
    private string $id;
    private string $bytes = "PK\x03\x04artifact-bytes";

    protected function setUp(): void
    {
        $this->root = codi_mcp_test_temp_dir() . '/download-capability-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
        file_put_contents($this->root . '/source.zip', $this->bytes);
        $this->store = new ArtifactDownloadStore(4096, 7, $this->root . '/store');
        $staged = $this->store->stageFile('plugins.export', $this->root . '/source.zip', 'fixture.zip');
        $this->assertFalse(is_wp_error($staged));
        $this->id = $staged['download_id'];
        $this->now = time();
        $this->service = new DownloadCapabilityService($this->store, 'fixture-secret', fn (): int => $this->now);
    }

    protected function tearDown(): void
    {
        unset($this->service);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    public function test_direct_download_is_bounded_and_contains_no_archive_data_or_filesystem_paths(): void
    {
        $result = $this->service->response($this->id, 'plugins.export', []);
        $this->assertFalse(is_wp_error($result));
        $this->assertSame(strlen($this->bytes), $result['size']);
        $this->assertSame(hash('sha256', $this->bytes), $result['sha256']);
        $this->assertFalse(isset($result['data']));
        $this->assertFalse(isset($result['path']));
        $this->assertTrue(str_starts_with($result['download']['url'], 'https://'));
        $this->assertSame($this->now + 300, strtotime($result['download']['expires_at']));
        $this->assertTrue(strlen(json_encode($result)) < 1200);
        $response = $this->service->handle($this->request($result));
        $this->assertSame('application/zip', $response->get_headers()['Content-Type']);
        $this->assertSame((string) strlen($this->bytes), $response->get_headers()['Content-Length']);
        $this->assertSame('private, no-store', $response->get_headers()['Cache-Control']);
    }

    public function test_capability_is_bound_to_site_purpose_id_signature_and_expiry(): void
    {
        $result = $this->service->response($this->id, 'plugins.export', []);
        foreach ([
            ['purpose' => 'themes.export'],
            ['download_id' => str_repeat('0', 32)],
            ['token' => str_repeat('0', 64)],
            ['expires' => $this->now + 301],
            ['expires' => $this->now - 1],
        ] as $changes) {
            $this->assertTrue(is_wp_error($this->service->handle($this->request($result, 'GET', $changes))));
        }
        $GLOBALS['codi_mcp_test_wp_current_blog_id'] = 9;
        $this->assertTrue(is_wp_error($this->service->handle($this->request($result))));
        $GLOBALS['codi_mcp_test_wp_current_blog_id'] = 1;
        $this->now += 301;
        $this->assertTrue(is_wp_error($this->service->handle($this->request($result))));
    }

    public function test_archive_integrity_is_verified_before_streaming(): void
    {
        $result = $this->service->response($this->id, 'plugins.export', []);
        $record = $this->store->get($this->id, 'plugins.export');
        file_put_contents($record['path'], str_repeat('x', strlen($this->bytes)));
        $this->assertTrue(is_wp_error($this->service->handle($this->request($result))));
        unlink($record['path']);
        $this->assertTrue(is_wp_error($this->service->handle($this->request($result))));
    }

    public function test_raw_streaming_requires_the_owned_response_and_head_emits_no_body(): void
    {
        $result = $this->service->response($this->id, 'plugins.export', []);
        foreach (['GET', 'HEAD'] as $method) {
            $request = $this->request($result, $method);
            $response = $this->service->handle($request);
            ob_start();
            $served = $this->service->serve(false, $response, $request, null);
            $bytes = ob_get_clean();
            $this->assertTrue($served);
            $this->assertSame($method === 'HEAD' ? '' : $this->bytes, $bytes);
        }
        $request = $this->request($result);
        $this->service->handle($request);
        ob_start();
        $served = $this->service->serve(false, new \WP_REST_Response(['other' => true]), $request, null);
        $bytes = ob_get_clean();
        $this->assertFalse($served);
        $this->assertSame('', $bytes);
    }

    public function test_transfer_selection_is_explicit_and_unambiguous(): void
    {
        $default = $this->service->response($this->id, 'plugins.export', array());
        $this->assertArrayHasKey('download', $default);
        $this->assertFalse(isset($default['data']));

        $url = $this->service->response($this->id, 'plugins.export', ['download_id' => $this->id]);
        $this->assertArrayHasKey('download', $url);
        $this->assertFalse(isset($url['data']));

        $chunk = $this->service->response($this->id, 'plugins.export', ['transfer' => 'chunks', 'offset' => 0]);
        $this->assertSame(substr($this->bytes, 0, 7), base64_decode($chunk['data'], true));
        $this->assertFalse(isset($chunk['download']));

        $this->assertTrue(is_wp_error($this->service->response($this->id, 'plugins.export', ['offset' => 0])));
        $this->assertTrue(is_wp_error($this->service->response($this->id, 'plugins.export', ['transfer' => 'url', 'offset' => 0])));
        $this->assertTrue(is_wp_error($this->service->response($this->id, 'plugins.export', ['transfer' => 'unknown'])));
    }

    private function request(array $result, string $method = 'GET', array $changes = []): object
    {
        $parts = explode('/', parse_url($result['download']['url'], PHP_URL_PATH));
        $params = array_combine(['purpose', 'download_id', 'expires', 'token'], array_slice($parts, -4));
        return new class(array_replace($params, $changes), $method) {
            public function __construct(private array $params, private string $method) {}
            public function get_param(string $key): mixed { return $this->params[$key] ?? null; }
            public function get_method(): string { return $this->method; }
        };
    }
}
