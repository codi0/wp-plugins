<?php

declare(strict_types=1);

namespace {
    if (!function_exists('media_handle_sideload')) {
        function media_handle_sideload(array $fileArray, int $parentId = 0, ?string $description = null, array $postData = array())
        {
            $id = (int) ($GLOBALS['codi_mcp_test_test_media_next_id'] ?? 9000);
            $GLOBALS['codi_mcp_test_test_media_next_id'] = $id + 1;
            $bytes = is_file((string) ($fileArray['tmp_name'] ?? '')) ? (string) file_get_contents((string) $fileArray['tmp_name']) : '';
            $GLOBALS['codi_mcp_test_test_media_create'] = array(
                'id' => $id,
                'bytes' => $bytes,
                'name' => (string) ($fileArray['name'] ?? ''),
                'parent_id' => $parentId,
            );
            $siteId = function_exists('get_current_blog_id') ? get_current_blog_id() : 1;
            $GLOBALS['codi_mcp_test_wp_posts'][$siteId][] = (object) array(
                'ID' => $id,
                'post_type' => 'attachment',
                'post_status' => (string) ($postData['post_status'] ?? 'inherit'),
                'post_name' => pathinfo((string) ($fileArray['name'] ?? 'attachment'), PATHINFO_FILENAME),
                'post_title' => (string) ($description ?? ''),
                'post_excerpt' => (string) ($postData['post_excerpt'] ?? ''),
                'post_content' => '',
                'post_parent' => $parentId,
                'post_mime_type' => 'image/png',
            );
            return $id;
        }
    }

    if (!function_exists('wp_handle_sideload')) {
        function wp_handle_sideload(array $fileArray, array $overrides = array()): array
        {
            $source = (string) ($fileArray['tmp_name'] ?? '');
            $directory = (string) ($GLOBALS['codi_mcp_test_test_media_move_dir'] ?? sys_get_temp_dir());
            if (!is_dir($directory)) {
                @mkdir($directory, 0775, true);
            }
            $destination = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'moved-' . basename((string) ($fileArray['name'] ?? 'media.bin'));
            if (!is_file($source) || !copy($source, $destination)) {
                return array('error' => 'copy failed');
            }
            $GLOBALS['codi_mcp_test_test_media_replace'] = array(
                'bytes' => (string) file_get_contents($destination),
                'name' => (string) ($fileArray['name'] ?? ''),
                'file' => $destination,
            );
            return array('file' => $destination, 'url' => 'https://example.test/uploads/' . basename($destination), 'type' => 'image/png');
        }
    }

    if (!function_exists('update_attached_file')) {
        function update_attached_file(int $postId, string $file): bool
        {
            $GLOBALS['codi_mcp_test_test_attached_files'][$postId] = $file;
            return true;
        }
    }
    if (!function_exists('get_attached_file')) {
        function get_attached_file(int $postId, bool $unfiltered = false): string
        {
            return (string) ($GLOBALS['codi_mcp_test_test_attached_files'][$postId] ?? '');
        }
    }

    if (!function_exists('wp_get_attachment_metadata')) {
        function wp_get_attachment_metadata(int $postId)
        {
            return $GLOBALS['codi_mcp_test_test_attachment_metadata'][$postId] ?? array();
        }
    }

    if (!function_exists('wp_update_attachment_metadata')) {
        function wp_update_attachment_metadata(int $postId, array $metadata): bool
        {
            $GLOBALS['codi_mcp_test_test_attachment_metadata'][$postId] = $metadata;
            return true;
        }
    }

    if (!function_exists('wp_generate_attachment_metadata')) {
        function wp_generate_attachment_metadata(int $postId, string $file): array
        {
            return array('file' => basename($file), 'sizes' => array());
        }
    }

    if (!function_exists('wp_delete_attachment_files')) {
        function wp_delete_attachment_files(int $postId, array $meta, array $backupSizes, string $file): void
        {
            $GLOBALS['codi_mcp_test_test_deleted_attachment_files'][] = $file;
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    if (!function_exists('wp_max_upload_size')) {
        function wp_max_upload_size(): int
        {
            return (int) ($GLOBALS['codi_mcp_test_test_media_max_upload_bytes'] ?? 1024 * 1024);
        }
    }

    if (!function_exists('download_url')) {
        function download_url(string $url)
        {
            $bytes = (string) ($GLOBALS['codi_mcp_test_test_media_remote_bytes'] ?? 'remote-media');
            $args = function_exists('apply_filters') ? (array) apply_filters('http_request_args', array(), $url) : array();
            $limit = max(0, (int) ($args['limit_response_size'] ?? 0));
            if ($limit > 0) {
                $bytes = substr($bytes, 0, $limit);
            }
            $temp = tempnam(sys_get_temp_dir(), 'codi-mcp-test-download-');
            if (!is_string($temp) || false === file_put_contents($temp, $bytes)) {
                return new \WP_Error('download_failed', 'Could not prepare test download.');
            }
            return $temp;
        }
    }
}

namespace CodiMcpTest\Unit {
    use CodiMcp\Core\Uploads\UploadCapabilityService;
    use CodiMcp\Core\Uploads\UploadStore;
    use CodiMcp\Packages\Media\MediaManager;
    use CodiMcp\Packages\Media\MediaStagingService;
    use CodiMcp\Packages\Media\Package as MediaPackage;
    use CodiMcpTest\Framework\TestCase;

    final class MediaPackageSafetyTest extends TestCase
    {
        protected function setUp(): void
        {
            \codi_mcp_test_reset_environment();
            $GLOBALS['codi_mcp_test_wp_current_user_id'] = 7;
            if (!defined('CODI_MCP_ABILITY_PREFIX')) {
                define('CODI_MCP_ABILITY_PREFIX', 'codi');
            }
            unset(
                $GLOBALS['codi_mcp_test_test_media_create'],
                $GLOBALS['codi_mcp_test_test_media_replace'],
                $GLOBALS['codi_mcp_test_test_attached_files']
            );
            unset(
                $GLOBALS['codi_mcp_test_test_attachment_metadata'],
                $GLOBALS['codi_mcp_test_test_deleted_attachment_files'],
                $GLOBALS['codi_mcp_test_test_media_max_upload_bytes'],
                $GLOBALS['codi_mcp_test_test_media_remote_bytes']
            );
        }

        public function test_media_upload_ability_creates_shared_put_capability(): void
        {
            $package = new MediaPackage();
            $package->registerCategories();
            $package->registerAbilities();

            $descriptor = (array) ($GLOBALS['codi_mcp_test_registered_abilities']['codi/media-upload'] ?? array());
            $execute = $descriptor['execute_callback'] ?? null;
            $permission = $descriptor['permission_callback'] ?? null;
            $this->assertTrue(is_callable($execute));
            $this->assertTrue(is_callable($permission));
            $this->assertTrue((bool) $permission(array()));

            $bytes = 'media-shared-upload';
            $created = $execute(array(
                'filename' => 'hero.png',
                'mime_type' => 'image/png',
                'size' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
            ));
            $this->assertFalse(is_wp_error($created));
            $this->assertFalse((bool) ($created['complete'] ?? true));
            $this->assertSame('PUT', (string) ($created['upload']['method'] ?? ''));
            $this->assertSame('image/png', (string) ($created['upload']['content_type'] ?? ''));
            $uploadId = (string) ($created['upload_id'] ?? '');
            $this->assertSame(32, strlen($uploadId));

            $token = basename((string) parse_url((string) ($created['upload']['url'] ?? ''), PHP_URL_PATH));
            $stream = fopen('php://temp', 'w+b');
            $this->assertTrue(is_resource($stream));
            fwrite($stream, $bytes);
            rewind($stream);
            $received = (new UploadCapabilityService())->receive(MediaStagingService::PURPOSE, $uploadId, $token, $stream);
            fclose($stream);
            $this->assertFalse(is_wp_error($received));
            $this->assertTrue((bool) ($received['complete'] ?? false));

            $staging = new MediaStagingService();
            $completed = $staging->complete($uploadId);
            $this->assertFalse(is_wp_error($completed));
            $this->assertSame($bytes, (string) file_get_contents((string) ($completed['path'] ?? '')));
            $staging->consume($uploadId);
        }

        public function test_public_media_upload_find_and_update_replace_attachment_end_to_end(): void
        {
            $package = new MediaPackage();
            $package->registerCategories();
            $package->registerAbilities();

            $upload = $GLOBALS['codi_mcp_test_registered_abilities']['codi/media-upload']['execute_callback'] ?? null;
            $find = $GLOBALS['codi_mcp_test_registered_abilities']['codi/media-find']['execute_callback'] ?? null;
            $update = $GLOBALS['codi_mcp_test_registered_abilities']['codi/media-update']['execute_callback'] ?? null;
            $this->assertTrue(is_callable($upload));
            $this->assertTrue(is_callable($find));
            $this->assertTrue(is_callable($update));

            $bytes = 'public-media-workflow';
            $uploaded = $upload(array(
                'filename' => 'workflow-replacement.png',
                'mime_type' => 'image/png',
                'size' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
            ));
            $this->assertFalse(is_wp_error($uploaded));
            $this->assertFalse((bool) ($uploaded['complete'] ?? true));
            $uploadId = (string) ($uploaded['upload_id'] ?? '');
            $token = basename((string) parse_url((string) ($uploaded['upload']['url'] ?? ''), PHP_URL_PATH));
            $stream = fopen('php://temp', 'w+b');
            $this->assertTrue(is_resource($stream));
            fwrite($stream, $bytes);
            rewind($stream);
            $received = (new UploadCapabilityService())->receive(MediaStagingService::PURPOSE, $uploadId, $token, $stream);
            fclose($stream);
            $this->assertFalse(is_wp_error($received));

            $found = $find(array('search' => 'Hero Illustration', 'per_page' => 1));
            $attachmentId = (int) ($found['items'][0]['attachment_id'] ?? 0);
            $this->assertSame(107, $attachmentId);

            $updated = $update(array(
                'attachment_id' => $attachmentId,
                'source_upload_id' => $uploadId,
                'filename' => 'workflow-replacement.png',
            ));
            $this->assertFalse(is_wp_error($updated));
            $this->assertSame(107, (int) ($updated['attachment_id'] ?? 0));
            $this->assertSame($bytes, (string) ($GLOBALS['codi_mcp_test_test_media_replace']['bytes'] ?? ''));
            $this->assertTrue(isset($GLOBALS['codi_mcp_test_test_attached_files'][107]));
            $this->assertTrue(is_wp_error((new MediaStagingService())->complete($uploadId)));
        }

        public function test_media_manager_create_consumes_completed_staged_upload(): void
        {
            [$staging, $root, $capabilities] = $this->isolatedStaging();
            try {
                $bytes = 'new-attachment-bytes';
                $uploadId = $this->stage($staging, $capabilities, 'created.png', 'image/png', $bytes);
                $manager = new MediaManager($staging);
                $record = $manager->create(array(
                    'source_upload_id' => $uploadId,
                    'filename' => 'created.png',
                    'title' => 'Created media',
                    'caption' => 'Created from staged bytes',
                ));

                $this->assertFalse(is_wp_error($record));
                $this->assertTrue((int) ($record['attachment_id'] ?? 0) >= 9000);
                $this->assertSame($bytes, (string) ($GLOBALS['codi_mcp_test_test_media_create']['bytes'] ?? ''));
                $this->assertSame('created.png', (string) ($GLOBALS['codi_mcp_test_test_media_create']['name'] ?? ''));
                $this->assertTrue(is_wp_error($staging->complete($uploadId)));
            } finally {
                $this->removeTree($root);
            }
        }

        public function test_media_manager_update_replaces_existing_file_and_consumes_upload(): void
        {
            [$staging, $root, $capabilities] = $this->isolatedStaging();
            $moveDir = $root . DIRECTORY_SEPARATOR . 'moved';
            $GLOBALS['codi_mcp_test_test_media_move_dir'] = $moveDir;
            try {
                $bytes = 'replacement-attachment-bytes';
                $oldFile = $root . DIRECTORY_SEPARATOR . 'old.png';
                file_put_contents($oldFile, 'old-attachment-bytes');
                $GLOBALS['codi_mcp_test_test_attached_files'][107] = $oldFile;
                $GLOBALS['codi_mcp_test_test_attachment_metadata'][107] = array('file' => 'old.png', 'sizes' => array());
                $uploadId = $this->stage($staging, $capabilities, 'replacement.png', 'image/png', $bytes);
                $manager = new MediaManager($staging);
                $record = $manager->update(array(
                    'attachment_id' => 107,
                    'source_upload_id' => $uploadId,
                    'filename' => 'replacement.png',
                    'caption' => 'Replaced image',
                    'parent_post_id' => 101,
                ));

                $this->assertFalse(is_wp_error($record));
                $this->assertSame(107, (int) ($record['attachment_id'] ?? 0));
                $this->assertSame($bytes, (string) ($GLOBALS['codi_mcp_test_test_media_replace']['bytes'] ?? ''));
                $this->assertTrue(isset($GLOBALS['codi_mcp_test_test_attached_files'][107]));
                $this->assertTrue(is_wp_error($staging->complete($uploadId)));
                $this->assertFalse(is_file($oldFile), 'Successful replacement must retire the previous attachment file.');
            } finally {
                unset($GLOBALS['codi_mcp_test_test_media_move_dir']);
                $this->removeTree($root);
            }
        }

        public function test_media_permissions_filter_unreadable_items_and_require_parent_and_delete_caps(): void
        {
            $manager = new MediaManager(new MediaStagingService());
            $GLOBALS['codi_mcp_test_meta_capabilities']['read_post'][107] = false;
            $this->assertFalse($manager->canRead(array('attachment_id' => 107)));
            $found = $manager->find(array('per_page' => 50));
            $this->assertFalse(in_array(107, array_map(static fn (array $item): int => (int) $item['attachment_id'], $found['items']), true));

            $GLOBALS['codi_mcp_test_meta_capabilities']['delete_post'][107] = false;
            $this->assertFalse($manager->canDelete(array('attachment_id' => 107)), 'Edit/upload permission must not substitute for delete permission.');

            $GLOBALS['codi_mcp_test_meta_capabilities']['edit_post'][101] = false;
            $this->assertFalse($manager->canCreate(array('parent_post_id' => 101)), 'Attaching media to a post requires edit permission on the parent.');
            $this->assertFalse($manager->canEdit(array('attachment_id' => 107, 'parent_post_id' => 101)), 'Re-parenting media requires edit permission on the destination parent.');
        }

        public function test_media_replacement_compensates_when_post_update_fails(): void
        {
            [$staging, $root, $capabilities] = $this->isolatedStaging();
            $GLOBALS['codi_mcp_test_test_media_move_dir'] = $root . DIRECTORY_SEPARATOR . 'moved';
            try {
                $oldFile = $root . DIRECTORY_SEPARATOR . 'old.png';
                file_put_contents($oldFile, 'old-bytes');
                $oldMetadata = array('file' => 'old.png', 'sizes' => array());
                $GLOBALS['codi_mcp_test_test_attached_files'][107] = $oldFile;
                $GLOBALS['codi_mcp_test_test_attachment_metadata'][107] = $oldMetadata;
                $uploadId = $this->stage($staging, $capabilities, 'replacement.png', 'image/png', 'replacement-bytes');
                $GLOBALS['codi_mcp_test_wp_update_post_failures'][107] = 1;

                $result = (new MediaManager($staging))->update(array(
                    'attachment_id' => 107,
                    'source_upload_id' => $uploadId,
                    'filename' => 'replacement.png',
                    'caption' => 'This update will fail',
                ));

                $this->assertTrue(is_wp_error($result));
                $this->assertSame($oldFile, (string) ($GLOBALS['codi_mcp_test_test_attached_files'][107] ?? ''));
                $this->assertSame($oldMetadata, (array) ($GLOBALS['codi_mcp_test_test_attachment_metadata'][107] ?? array()));
                $this->assertTrue(is_file($oldFile), 'Compensation must keep the previous attachment file.');
                $newFile = (string) ($GLOBALS['codi_mcp_test_test_media_replace']['file'] ?? '');
                $this->assertTrue($newFile !== '');
                $this->assertFalse(is_file($newFile), 'Compensation must remove the newly staged replacement file.');
                $this->assertFalse(is_wp_error($staging->complete($uploadId)), 'Failed replacement must not consume the staged upload.');
            } finally {
                unset($GLOBALS['codi_mcp_test_test_media_move_dir']);
                $this->removeTree($root);
            }
        }

        public function test_remote_media_download_is_bounded_by_wordpress_upload_limit(): void
        {
            $GLOBALS['codi_mcp_test_test_media_max_upload_bytes'] = 8;
            $GLOBALS['codi_mcp_test_test_media_remote_bytes'] = str_repeat('x', 32);
            $result = (new MediaManager(new MediaStagingService()))->create(array(
                'source_url' => 'https://example.test/large.png',
                'filename' => 'large.png',
            ));
            $this->assertTrue(is_wp_error($result));
            $this->assertSame('codi_mcp_media_download_too_large', (string) ($result->code ?? ''));
        }

        public function test_media_create_rejects_unknown_staged_upload(): void
        {
            $result = (new MediaManager(new MediaStagingService()))->create(array(
                'source_upload_id' => str_repeat('a', 32),
                'filename' => 'missing.png',
                'title' => 'Missing staged media',
            ));

            $this->assertTrue(is_wp_error($result));
        }

        public function test_media_schemas_use_capability_uploads_and_opaque_source_ids(): void
        {
            $media = new MediaPackage();
            $media->registerAbilities();
            $upload = (array) ($GLOBALS['codi_mcp_test_registered_abilities']['codi/media-upload'] ?? array());
            $create = (array) ($GLOBALS['codi_mcp_test_registered_abilities']['codi/media-create'] ?? array());
            $uploadInput = (array) ($upload['input_schema']['properties'] ?? array());
            $sourceUploadId = (array) ($create['input_schema']['properties']['source_upload_id'] ?? array());

            $this->assertSame(array('filename', 'mime_type', 'size', 'sha256'), array_keys($uploadInput));
            $this->assertSame(32, (int) ($sourceUploadId['minLength'] ?? 0));
            $this->assertSame(32, (int) ($sourceUploadId['maxLength'] ?? 0));

            $uploadOutput = (array) ($upload['output_schema']['properties'] ?? array());
            $transfer = (array) ($uploadOutput['upload']['properties'] ?? array());
            $this->assertSame(array('PUT'), array_values((array) ($transfer['method']['enum'] ?? array())));
            $this->assertArrayHasKey('url', $transfer);

            $encoded = json_encode(array($upload, $create, $GLOBALS['codi_mcp_test_registered_abilities']['codi/media-update'] ?? array()), JSON_UNESCAPED_SLASHES);
            $this->assertTrue(is_string($encoded) && str_contains($encoded, 'source_upload_id'));
            $this->assertFalse(is_string($encoded) && str_contains($encoded, 'source_file'));
            $this->assertFalse(is_string($encoded) && str_contains($encoded, '"format":"file"'));
            $updateInputProperties = (array) (($GLOBALS['codi_mcp_test_registered_abilities']['codi/media-update']['input_schema']['properties'] ?? array()));
            $this->assertFalse(array_key_exists('mime_type', $updateInputProperties), 'media-update must not accept a caller-authored MIME type.');
        }

        /** @return array{0:MediaStagingService,1:string,2:UploadCapabilityService} */
        private function isolatedStaging(): array
        {
            $root = rtrim(\codi_mcp_test_temp_dir(), DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . 'codi-mcp-media-' . str_replace('.', '-', uniqid('', true));
            @mkdir($root, 0775, true);
            $capabilities = new UploadCapabilityService(new UploadStore(1024 * 1024, $root, 3600));
            return array(
                new MediaStagingService($capabilities),
                $root,
                $capabilities,
            );
        }

        private function stage(MediaStagingService $staging, UploadCapabilityService $capabilities, string $filename, string $mimeType, string $bytes): string
        {
            $result = $staging->upload(array(
                'filename' => $filename,
                'mime_type' => $mimeType,
                'size' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
            ));
            if ($result instanceof \WP_Error) {
                throw new \RuntimeException($result->get_error_message());
            }
            $uploadId = (string) ($result['upload_id'] ?? '');
            $token = basename((string) parse_url((string) ($result['upload']['url'] ?? ''), PHP_URL_PATH));
            $stream = fopen('php://temp', 'w+b');
            if (!is_resource($stream)) {
                throw new \RuntimeException('Could not create media upload stream.');
            }
            fwrite($stream, $bytes);
            rewind($stream);
            $received = $capabilities->receive(MediaStagingService::PURPOSE, $uploadId, $token, $stream);
            fclose($stream);
            if ($received instanceof \WP_Error) {
                throw new \RuntimeException($received->get_error_message());
            }
            $this->assertTrue((bool) ($received['complete'] ?? false));
            return $uploadId;
        }

        private function removeTree(string $path): void
        {
            if (!is_dir($path)) {
                return;
            }
            $items = scandir($path);
            if (!is_array($items)) {
                return;
            }
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $child = $path . DIRECTORY_SEPARATOR . $item;
                if (is_dir($child)) {
                    $this->removeTree($child);
                } else {
                    @unlink($child);
                }
            }
            @rmdir($path);
        }
    }
}
