<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Media;

use CodiMcp\Core\Uploads\UploadCapabilityService;
use CodiMcp\Core\Uploads\UploadStore;

final class MediaStagingService
{
    public const PURPOSE = 'media.library';
    public const MAX_MEDIA_BYTES = 50 * 1024 * 1024;

    private UploadCapabilityService $uploads;

    public function __construct(?UploadCapabilityService $uploads = null)
    {
        $this->uploads = $uploads ?? new UploadCapabilityService(new UploadStore(self::maximumUploadBytes()));
    }

    public static function maximumUploadBytes(): int
    {
        $maximum = self::MAX_MEDIA_BYTES;
        if (function_exists('wp_max_upload_size')) {
            $wordpressMaximum = (int) wp_max_upload_size();
            if ($wordpressMaximum > 0) {
                $maximum = min($maximum, $wordpressMaximum);
            }
        }
        return max(1, $maximum);
    }

    /** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
    public function upload(array $input): array|\WP_Error
    {
        $size = (int) ($input['size'] ?? 0);
        $sha256 = strtolower(trim((string) ($input['sha256'] ?? '')));
        $filename = $this->normalizeFilename((string) ($input['filename'] ?? ''));
        if ($filename instanceof \WP_Error) {
            return $filename;
        }
        $mimeType = strtolower(trim((string) ($input['mime_type'] ?? '')));
        if ($mimeType === '') {
            $mimeType = 'application/octet-stream';
        }

        $result = $this->uploads->create(
            self::PURPOSE,
            $size,
            $sha256,
            $mimeType,
            array('filename' => $filename, 'mime_type' => $mimeType)
        );
        if ($result instanceof \WP_Error) {
            return $result;
        }
        $result['instruction'] = 'Stream the exact media file directly from the machine holding it to upload.url using HTTP PUT. Do not send media bytes through MCP, base64, or the client/model workspace. After the PUT succeeds, pass upload_id as source_upload_id to codi/media-create or codi/media-update.';
        return $result;
    }

    /** @return array<string,mixed>|\WP_Error */
    public function complete(string $uploadId): array|\WP_Error
    {
        return $this->uploads->complete(trim($uploadId), self::PURPOSE);
    }

    public function consume(string $uploadId): bool|\WP_Error
    {
        return $this->uploads->consume(trim($uploadId), self::PURPOSE);
    }

    /** @return string|\WP_Error */
    private function normalizeFilename(string $filename): string|\WP_Error
    {
        $filename = trim($filename);
        if ($filename === '' || $filename !== basename(str_replace('\\', '/', $filename))) {
            return new \WP_Error('codi_mcp_bad_filename', 'filename must be a plain file name without a directory path.');
        }
        $normalized = function_exists('sanitize_file_name') ? sanitize_file_name($filename) : $filename;
        $normalized = trim((string) $normalized);
        if ($normalized === '' || strlen($normalized) > 255) {
            return new \WP_Error('codi_mcp_bad_filename', 'filename is invalid.');
        }
        return $normalized;
    }
}
