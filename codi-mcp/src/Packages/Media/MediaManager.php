<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Media;

final class MediaManager
{
    public function __construct(private MediaStagingService $staging)
    {
    }

    public function canInspect(): bool
    {
        return function_exists('current_user_can') && (current_user_can('upload_files') || current_user_can('edit_posts'));
    }

    public function canUpload(): bool
    {
        return function_exists('current_user_can') && current_user_can('upload_files');
    }

    public function canRead(array $input): bool
    {
        $id = (int) ($input['attachment_id'] ?? 0);
        $post = $id > 0 && function_exists('get_post') ? get_post($id) : null;
        return is_object($post)
            && (string) ($post->post_type ?? '') === 'attachment'
            && function_exists('current_user_can')
            && current_user_can('read_post', $id);
    }

    public function canCreate(array $input): bool
    {
        return $this->canUpload() && !($this->validateParent((int) ($input['parent_post_id'] ?? 0)) instanceof \WP_Error);
    }

    public function canEdit(array $input): bool
    {
        $id = (int) ($input['attachment_id'] ?? 0);
        $post = $id > 0 && function_exists('get_post') ? get_post($id) : null;
        return is_object($post)
            && (string) ($post->post_type ?? '') === 'attachment'
            && $this->canUpload()
            && current_user_can('edit_post', $id)
            && !($this->validateParent((int) ($input['parent_post_id'] ?? 0)) instanceof \WP_Error);
    }

    public function canDelete(array $input): bool
    {
        $id = (int) ($input['attachment_id'] ?? 0);
        $post = $id > 0 && function_exists('get_post') ? get_post($id) : null;
        return is_object($post)
            && (string) ($post->post_type ?? '') === 'attachment'
            && function_exists('current_user_can')
            && current_user_can('delete_post', $id);
    }

    /** @return array<string,mixed> */
    public function config(): array
    {
        $sizes = array();
        $registeredSizes = function_exists('wp_get_registered_image_subsizes') ? wp_get_registered_image_subsizes() : array();
        foreach ((array) $registeredSizes as $name => $size) {
            $size = is_array($size) ? $size : array();
            $sizes[] = array(
                'name' => (string) $name,
                'width' => max(0, (int) ($size['width'] ?? 0)),
                'height' => max(0, (int) ($size['height'] ?? 0)),
                'crop_json' => (string) (function_exists('wp_json_encode') ? wp_json_encode($size['crop'] ?? false) : json_encode($size['crop'] ?? false)),
            );
        }
        usort($sizes, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        $mimeTypes = array();
        foreach ((array) (function_exists('get_allowed_mime_types') ? get_allowed_mime_types() : array()) as $extensions => $mime) {
            $mimeTypes[] = array('extensions' => (string) $extensions, 'mime' => (string) $mime);
        }
        usort($mimeTypes, static fn (array $a, array $b): int => strnatcasecmp($a['extensions'], $b['extensions']));

        $editorClasses = function_exists('apply_filters') ? (array) apply_filters('wp_image_editors', array('WP_Image_Editor_Imagick', 'WP_Image_Editor_GD')) : array('WP_Image_Editor_Imagick', 'WP_Image_Editor_GD');
        $editors = array();
        foreach ($editorClasses as $editorClass) {
            $editorClass = (string) $editorClass;
            if ($editorClass === '') {
                continue;
            }
            $available = class_exists($editorClass);
            if ($available && is_callable(array($editorClass, 'test'))) {
                try { $available = (bool) call_user_func(array($editorClass, 'test'), array()); } catch (\Throwable) { $available = false; }
            }
            $editors[] = array('class' => $editorClass, 'available' => $available);
        }

        return array(
            'wp_max_upload_size' => function_exists('wp_max_upload_size') ? (int) wp_max_upload_size() : 0,
            'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
            'post_max_size' => (string) ini_get('post_max_size'),
            'image_sizes' => $sizes,
            'allowed_mime_types' => $mimeTypes,
            'image_editors' => $editors,
            'supports_webp' => function_exists('wp_image_editor_supports') && wp_image_editor_supports(array('mime_type' => 'image/webp')),
            'supports_avif' => function_exists('wp_image_editor_supports') && wp_image_editor_supports(array('mime_type' => 'image/avif')),
        );
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function find(array $input): array
    {
        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($input['per_page'] ?? 20)));
        $args = array(
            'post_type' => array('attachment'),
            'post_status' => array('inherit', 'publish', 'private', 'draft', 'future'),
            'perm' => 'readable',
            'posts_per_page' => $perPage,
            'offset' => ($page - 1) * $perPage,
        );
        if (trim((string) ($input['search'] ?? '')) !== '') {
            $args['s'] = trim((string) $input['search']);
        }
        if ((int) ($input['parent_post_id'] ?? 0) > 0) {
            $args['post_parent'] = (int) $input['parent_post_id'];
        }
        $posts = function_exists('get_posts') ? (array) get_posts($args) : array();
        $items = array();
        foreach ($posts as $post) {
            $attachmentId = is_object($post) ? (int) ($post->ID ?? 0) : 0;
            if (!is_object($post) || (string) ($post->post_type ?? '') !== 'attachment' || !$this->canRead(array('attachment_id' => $attachmentId))) {
                continue;
            }
            $mimeFilter = trim((string) ($input['mime_type'] ?? ''));
            $mime = (string) ($post->post_mime_type ?? '');
            if ($mimeFilter !== '' && $mime !== $mimeFilter && !str_starts_with($mime, rtrim($mimeFilter, '*'))) {
                continue;
            }
            $items[] = $this->record($post);
        }
        return array('items' => $items, 'page' => $page, 'per_page' => $perPage, 'returned' => count($items));
    }

    /** @return array<string,mixed>|\WP_Error */
    public function inspect(int $attachmentId): array|\WP_Error
    {
        $post = function_exists('get_post') ? get_post($attachmentId) : null;
        if (!is_object($post) || (string) ($post->post_type ?? '') !== 'attachment') {
            return new \WP_Error('codi_mcp_media_not_found', 'The requested attachment was not found.');
        }
        return $this->record($post, true);
    }

    /** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
    public function create(array $input): array|\WP_Error
    {
        $parent = $this->validateParent((int) ($input['parent_post_id'] ?? 0));
        if ($parent instanceof \WP_Error) {
            return $parent;
        }
        $source = $this->source($input, true);
        if ($source instanceof \WP_Error) {
            return $source;
        }
        $this->ensureMediaFunctions();
        $fileArray = $this->preparedFileArray($source, $input);
        if ($fileArray instanceof \WP_Error) {
            return $fileArray;
        }
        try {
            if (!function_exists('media_handle_sideload')) {
                return new \WP_Error('codi_mcp_media_unavailable', 'WordPress media sideload support is unavailable.');
            }
            $id = media_handle_sideload(
                $fileArray,
                max(0, (int) ($input['parent_post_id'] ?? 0)),
                (string) ($input['title'] ?? ''),
                array('post_excerpt' => (string) ($input['caption'] ?? ''), 'post_status' => 'inherit')
            );
            if (is_wp_error($id)) {
                return $id;
            }
            $id = (int) $id;
            if ($id < 1) {
                return new \WP_Error('codi_mcp_media_create_failed', 'WordPress did not create the attachment.');
            }
            if (array_key_exists('alt_text', $input) && function_exists('update_post_meta')) {
                update_post_meta($id, '_wp_attachment_image_alt', (string) $input['alt_text']);
            }
            $this->consumeSource($source);
            return $this->inspect($id);
        } finally {
            $this->cleanupPreparedFile($fileArray, $source);
        }
    }

    /** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
    public function update(array $input): array|\WP_Error
    {
        $id = (int) ($input['attachment_id'] ?? 0);
        $post = function_exists('get_post') ? get_post($id) : null;
        if (!is_object($post) || (string) ($post->post_type ?? '') !== 'attachment') {
            return new \WP_Error('codi_mcp_media_not_found', 'The requested attachment was not found.');
        }
        $parent = $this->validateParent((int) ($input['parent_post_id'] ?? 0));
        if ($parent instanceof \WP_Error) {
            return $parent;
        }
        $source = $this->source($input, false);
        if ($source instanceof \WP_Error) {
            return $source;
        }
        $this->ensureMediaFunctions();
        $prepared = null;
        $replacement = null;
        $moved = null;
        if ($source !== null) {
            $prepared = $this->preparedFileArray($source, $input);
            if ($prepared instanceof \WP_Error) {
                return $prepared;
            }
            if (!function_exists('wp_handle_sideload')) {
                $this->cleanupPreparedFile($prepared, $source);
                return new \WP_Error('codi_mcp_media_unavailable', 'WordPress attachment replacement support is unavailable.');
            }
            $before = $this->attachmentFileState($id);
            $moved = wp_handle_sideload($prepared, array('test_form' => false));
            if (!is_array($moved) || !empty($moved['error']) || empty($moved['file'])) {
                $this->cleanupPreparedFile($prepared, $source);
                return new \WP_Error('codi_mcp_media_replace_failed', 'WordPress could not replace the attachment file.');
            }
            $newFile = (string) $moved['file'];
            if (!function_exists('update_attached_file') || !update_attached_file($id, $newFile)) {
                $this->removeAttachmentFiles($id, array(), array(), $newFile);
                $this->cleanupPreparedFile($prepared, $source);
                return new \WP_Error('codi_mcp_media_replace_failed', 'WordPress could not bind the replacement file to the attachment.');
            }
            $newMetadata = $this->refreshAttachmentMetadata($id, $newFile);
            $replacement = array('before' => $before, 'file' => $newFile, 'metadata' => $newMetadata);
        }

        $payload = array('ID' => $id);
        foreach (array('title' => 'post_title', 'caption' => 'post_excerpt') as $inputKey => $postKey) {
            if (array_key_exists($inputKey, $input)) {
                $payload[$postKey] = (string) $input[$inputKey];
            }
        }
        if (is_array($replacement) && is_array($moved) && !empty($moved['type'])) {
            $payload['post_mime_type'] = (string) $moved['type'];
        }
        if (array_key_exists('parent_post_id', $input)) {
            $payload['post_parent'] = max(0, (int) $input['parent_post_id']);
        }
        if (count($payload) > 1 && function_exists('wp_update_post')) {
            $updated = wp_update_post($payload, true);
            if (is_wp_error($updated)) {
                if (is_array($replacement)) {
                    $this->restoreAttachmentFileState($id, (array) $replacement['before']);
                    $this->removeAttachmentFiles($id, (array) $replacement['metadata'], array(), (string) $replacement['file']);
                }
                if (is_array($prepared)) { $this->cleanupPreparedFile($prepared, $source); }
                return $updated;
            }
        }
        if (array_key_exists('alt_text', $input) && function_exists('update_post_meta')) {
            update_post_meta($id, '_wp_attachment_image_alt', (string) $input['alt_text']);
        }
        if (is_array($replacement)) {
            $before = (array) $replacement['before'];
            $oldFile = (string) ($before['file'] ?? '');
            if ($oldFile !== '' && $oldFile !== (string) $replacement['file']) {
                $this->removeAttachmentFiles($id, (array) ($before['metadata'] ?? array()), (array) ($before['backup_sizes'] ?? array()), $oldFile);
            }
            if (function_exists('delete_post_meta')) {
                delete_post_meta($id, '_wp_attachment_backup_sizes');
            }
        }
        if ($source !== null) {
            $this->consumeSource($source);
            if (is_array($prepared)) { $this->cleanupPreparedFile($prepared, $source); }
        }
        return $this->inspect($id);
    }

    /** @return array<string,mixed>|\WP_Error */
    public function delete(int $attachmentId): array|\WP_Error
    {
        $post = function_exists('get_post') ? get_post($attachmentId) : null;
        if (!is_object($post) || (string) ($post->post_type ?? '') !== 'attachment') {
            return new \WP_Error('codi_mcp_media_not_found', 'The requested attachment was not found.');
        }
        if (function_exists('wp_delete_attachment')) {
            $deleted = wp_delete_attachment($attachmentId, true);
        } elseif (function_exists('wp_delete_post')) {
            $deleted = wp_delete_post($attachmentId, true);
        } else {
            return new \WP_Error('codi_mcp_media_unavailable', 'WordPress attachment deletion support is unavailable.');
        }
        if (!$deleted) {
            return new \WP_Error('codi_mcp_media_delete_failed', 'WordPress could not delete the attachment.');
        }
        return array('attachment_id' => $attachmentId, 'deleted' => true);
    }

    /** @return array<string,mixed>|\WP_Error|null */
    private function source(array $input, bool $required): array|\WP_Error|null
    {
        $uploadId = trim((string) ($input['source_upload_id'] ?? ''));
        $url = trim((string) ($input['source_url'] ?? ''));
        if ($uploadId !== '' && $url !== '') {
            return new \WP_Error('codi_mcp_media_source_invalid', 'Provide only one media source: source_upload_id or source_url.');
        }
        if ($uploadId === '' && $url === '') {
            return $required ? new \WP_Error('codi_mcp_media_source_required', 'source_upload_id or source_url is required.') : null;
        }
        if ($uploadId !== '') {
            $upload = $this->staging->complete($uploadId);
            if ($upload instanceof \WP_Error) {
                return $upload;
            }
            return array('kind' => 'upload', 'upload_id' => $uploadId, 'path' => (string) ($upload['path'] ?? ''), 'metadata' => (array) ($upload['meta']['metadata'] ?? array()));
        }
        if (!preg_match('#^https?://#i', $url)) {
            return new \WP_Error('codi_mcp_media_source_invalid', 'source_url must be an HTTP or HTTPS URL.');
        }
        return array('kind' => 'url', 'url' => $url);
    }

    /** @return array{name:string,tmp_name:string}|\WP_Error */
    private function preparedFileArray(array $source, array $input): array|\WP_Error
    {
        if (($source['kind'] ?? '') === 'upload') {
            $path = (string) ($source['path'] ?? '');
            if ($path === '' || !is_file($path)) {
                return new \WP_Error('codi_mcp_media_upload_missing', 'The completed staged media upload is unavailable.');
            }
            $temp = tempnam(sys_get_temp_dir(), 'codi-mcp-media-');
            if (!is_string($temp) || $temp === '' || !copy($path, $temp)) {
                return new \WP_Error('codi_mcp_media_prepare_failed', 'Failed to prepare staged media for WordPress.');
            }
            $metadata = (array) ($source['metadata'] ?? array());
            $name = trim((string) ($input['filename'] ?? $metadata['filename'] ?? 'media'));
            return array('name' => $name !== '' ? basename($name) : 'media', 'tmp_name' => $temp);
        }
        if (!function_exists('download_url')) {
            return new \WP_Error('codi_mcp_media_unavailable', 'WordPress remote media download support is unavailable.');
        }
        $url = (string) ($source['url'] ?? '');
        $maxBytes = function_exists('wp_max_upload_size') ? max(1, (int) wp_max_upload_size()) : MediaStagingService::maximumUploadBytes();
        $limitResponseSize = static function (array $args, string $requestUrl) use ($url, $maxBytes): array {
            if ($requestUrl === $url) {
                $args['limit_response_size'] = $maxBytes + 1;
            }
            return $args;
        };
        if (function_exists('add_filter')) {
            add_filter('http_request_args', $limitResponseSize, 10, 2);
        }
        try {
            $temp = download_url($url);
        } finally {
            if (function_exists('remove_filter')) {
                remove_filter('http_request_args', $limitResponseSize, 10);
            }
        }
        if (is_wp_error($temp) || !is_string($temp) || $temp === '') {
            return new \WP_Error('codi_mcp_media_download_failed', 'WordPress could not download the media source.');
        }
        clearstatcache(true, $temp);
        $downloadedBytes = @filesize($temp);
        if (false === $downloadedBytes || $downloadedBytes > $maxBytes) {
            @unlink($temp);
            return new \WP_Error('codi_mcp_media_download_too_large', 'Remote media exceeds the WordPress upload size limit.');
        }
        $path = parse_url((string) $source['url'], PHP_URL_PATH);
        $name = trim((string) ($input['filename'] ?? (is_string($path) ? basename($path) : 'media')));
        return array('name' => $name !== '' ? basename($name) : 'media', 'tmp_name' => $temp);
    }

    private function consumeSource(array $source): void
    {
        if (($source['kind'] ?? '') !== 'upload') {
            return;
        }
        $result = $this->staging->consume((string) ($source['upload_id'] ?? ''));
        if ($result instanceof \WP_Error && function_exists('error_log')) {
            error_log('[codi-mcp] Committed Media upload could not be removed immediately; TTL cleanup will retry later.');
        }
    }

    private function cleanupPreparedFile(array $prepared, ?array $source): void
    {
        $temp = (string) ($prepared['tmp_name'] ?? '');
        if ($temp !== '' && file_exists($temp)) {
            @unlink($temp);
        }
    }

    /** @return array<string,mixed> */
    private function refreshAttachmentMetadata(int $id, string $file): array
    {
        if (!function_exists('wp_generate_attachment_metadata') || !function_exists('wp_update_attachment_metadata')) {
            return array();
        }
        $metadata = wp_generate_attachment_metadata($id, $file);
        if (!is_array($metadata)) {
            return array();
        }
        wp_update_attachment_metadata($id, $metadata);
        return $metadata;
    }

    /** @return array{file:string,metadata:array<string,mixed>,backup_sizes:array<string,mixed>} */
    private function attachmentFileState(int $id): array
    {
        $file = function_exists('get_attached_file') ? get_attached_file($id, true) : '';
        $metadata = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($id) : array();
        $backupSizes = function_exists('get_post_meta') ? get_post_meta($id, '_wp_attachment_backup_sizes', true) : array();
        return array(
            'file' => is_string($file) ? $file : '',
            'metadata' => is_array($metadata) ? $metadata : array(),
            'backup_sizes' => is_array($backupSizes) ? $backupSizes : array(),
        );
    }

    /** @param array{file:string,metadata:array<string,mixed>,backup_sizes:array<string,mixed>} $state */
    private function restoreAttachmentFileState(int $id, array $state): void
    {
        $file = (string) ($state['file'] ?? '');
        if ($file !== '' && function_exists('update_attached_file')) {
            update_attached_file($id, $file);
        } elseif (function_exists('delete_post_meta')) {
            delete_post_meta($id, '_wp_attached_file');
        }
        if (function_exists('wp_update_attachment_metadata')) {
            wp_update_attachment_metadata($id, (array) ($state['metadata'] ?? array()));
        }
        $backupSizes = (array) ($state['backup_sizes'] ?? array());
        if ($backupSizes !== array() && function_exists('update_post_meta')) {
            update_post_meta($id, '_wp_attachment_backup_sizes', $backupSizes);
        } elseif (function_exists('delete_post_meta')) {
            delete_post_meta($id, '_wp_attachment_backup_sizes');
        }
    }

    /** @param array<string,mixed> $metadata @param array<string,mixed> $backupSizes */
    private function removeAttachmentFiles(int $id, array $metadata, array $backupSizes, string $file): void
    {
        if ($file === '') {
            return;
        }
        if (function_exists('wp_delete_attachment_files')) {
            wp_delete_attachment_files($id, $metadata, $backupSizes, $file);
            return;
        }
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /** @return true|\WP_Error */
    private function validateParent(int $parentId): true|\WP_Error
    {
        if ($parentId <= 0) {
            return true;
        }
        $parent = function_exists('get_post') ? get_post($parentId) : null;
        if (!is_object($parent)) {
            return new \WP_Error('codi_mcp_media_parent_invalid', 'parent_post_id must identify an existing post.');
        }
        if (in_array((string) ($parent->post_type ?? ''), array('revision', 'attachment'), true)) {
            return new \WP_Error('codi_mcp_media_parent_invalid', 'Attachments and revisions cannot be media parents.');
        }
        if (!function_exists('current_user_can') || !current_user_can('edit_post', $parentId)) {
            return new \WP_Error('codi_mcp_media_parent_forbidden', 'The current user cannot edit the requested media parent.');
        }
        return true;
    }

    /** @return array<string,mixed> */
    private function record(object $post, bool $includeMetadata = false): array
    {
        $id = (int) ($post->ID ?? 0);
        $record = array(
            'attachment_id' => $id,
            'title' => (string) ($post->post_title ?? ''),
            'caption' => (string) ($post->post_excerpt ?? ''),
            'alt_text' => function_exists('get_post_meta') ? (string) get_post_meta($id, '_wp_attachment_image_alt', true) : '',
            'mime_type' => (string) ($post->post_mime_type ?? ''),
            'parent_post_id' => (int) ($post->post_parent ?? 0),
            'status' => (string) ($post->post_status ?? ''),
            'url' => function_exists('wp_get_attachment_url') ? (string) wp_get_attachment_url($id) : '',
        );
        if ($includeMetadata) {
            $metadata = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($id) : array();
            $record['metadata_json'] = (string) (function_exists('wp_json_encode') ? wp_json_encode(is_array($metadata) ? $metadata : array()) : json_encode(is_array($metadata) ? $metadata : array()));
        }
        return $record;
    }

    private function ensureMediaFunctions(): void
    {
        if (!defined('ABSPATH')) { return; }
        foreach (array(ABSPATH . 'wp-admin/includes/file.php', ABSPATH . 'wp-admin/includes/media.php', ABSPATH . 'wp-admin/includes/image.php') as $include) {
            if (is_file($include)) { require_once $include; }
        }
    }
}
