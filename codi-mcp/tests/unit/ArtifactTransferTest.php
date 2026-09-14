<?php

declare(strict_types=1);

namespace CodiMcpTest\Unit;

use CodiMcp\Core\Downloads\ArtifactDownloadStore;
use CodiMcp\Core\Uploads\UploadStore;
use CodiMcpTest\Framework\TestCase;

final class ArtifactTransferTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        \codi_mcp_test_reset_environment();
        $this->root = \codi_mcp_test_temp_dir() . DIRECTORY_SEPARATOR . 'codi-transfer-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
        @mkdir($this->root, 0777, true);
    }

    public function test_shared_upload_store_is_purpose_bound_and_hash_verified(): void
    {
        $store = new UploadStore(1024, $this->root . DIRECTORY_SEPARATOR . 'uploads', 3600);
        $payload = 'abcdefgh';
        $hash = hash('sha256', $payload);
        $started = $store->start('themes.install', strlen($payload), $hash, array('stylesheet' => 'demo'));
        $this->assertFalse(is_wp_error($started));
        $uploadId = (string) $started['upload_id'];

        $stream = fopen('php://temp', 'w+b');
        $this->assertTrue(is_resource($stream));
        fwrite($stream, $payload);
        rewind($stream);
        $received = $store->writeStream($uploadId, 'themes.install', $stream);
        fclose($stream);
        $this->assertFalse(is_wp_error($received));

        $wrongPurpose = $store->get($uploadId, 'plugins.install');
        $this->assertTrue(is_wp_error($wrongPurpose));
        $this->assertSame('codi_mcp_upload_purpose_mismatch', $wrongPurpose->code);

        $completed = $store->complete($uploadId, 'themes.install');
        $this->assertFalse(is_wp_error($completed));
        $this->assertSame($payload, (string) file_get_contents((string) $completed['path']));
    }

    public function test_shared_download_store_exports_bounded_sequential_chunks(): void
    {
        $source = $this->root . DIRECTORY_SEPARATOR . 'source';
        @mkdir($source, 0777, true);
        file_put_contents($source . DIRECTORY_SEPARATOR . 'a.php', '<?php echo "a";');
        file_put_contents($source . DIRECTORY_SEPARATOR . 'b.css', 'body{}');

        $writer = static function (array $files, string $sourceRoot, string $archiveRoot, string $archivePath): bool {
            $content = '';
            foreach ($files as $file) {
                $relative = ltrim(str_replace('\\', '/', substr($file, strlen($sourceRoot))), '/');
                $content .= $archiveRoot . '/' . $relative . ':' . file_get_contents($file) . "\n";
            }
            return false !== file_put_contents($archivePath, $content);
        };

        $store = new ArtifactDownloadStore(4096, 7, $this->root . DIRECTORY_SEPARATOR . 'downloads', 3600, 10, 1024, $writer);
        $staged = $store->stageDirectory('plugins.export', $source, 'demo-plugin', 'demo-plugin.zip', array('plugin_file' => 'demo-plugin/demo.php'));
        $this->assertFalse(is_wp_error($staged));
        $downloadId = (string) $staged['download_id'];

        $bytes = '';
        $offset = 0;
        do {
            $chunk = $store->readBase64($downloadId, 'plugins.export', $offset);
            $this->assertFalse(is_wp_error($chunk));
            $bytes .= base64_decode((string) $chunk['data'], true) ?: '';
            $offset = (int) $chunk['next_offset'];
        } while (!(bool) $chunk['complete']);

        $this->assertSame((int) $staged['meta']['size'], strlen($bytes));
        $this->assertSame((string) $staged['meta']['sha256'], hash('sha256', $bytes));
        $this->assertTrue(str_contains($bytes, 'demo-plugin/a.php:'));
        $this->assertTrue(str_contains($bytes, 'demo-plugin/b.css:'));

        $wrongPurpose = $store->readBase64($downloadId, 'themes.export', 0);
        $this->assertTrue(is_wp_error($wrongPurpose));
        $this->assertSame('codi_mcp_download_purpose_mismatch', $wrongPurpose->code);
    }

    public function test_download_store_rejects_oversized_uncompressed_source(): void
    {
        $source = $this->root . DIRECTORY_SEPARATOR . 'large-source';
        @mkdir($source, 0777, true);
        file_put_contents($source . DIRECTORY_SEPARATOR . 'large.txt', str_repeat('x', 20));
        $writer = static fn (array $files, string $sourceRoot, string $archiveRoot, string $archivePath): bool => false !== file_put_contents($archivePath, 'unused');
        $store = new ArtifactDownloadStore(4096, 16, $this->root . DIRECTORY_SEPARATOR . 'large-downloads', 3600, 10, 10, $writer);

        $result = $store->stageDirectory('themes.export', $source, 'demo-theme', 'demo-theme.zip');
        $this->assertTrue(is_wp_error($result));
        $this->assertSame('codi_mcp_export_source_too_large', $result->code);
    }
}
