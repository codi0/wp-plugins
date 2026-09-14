<?php

declare(strict_types=1);

namespace CodiMcp\Core\Archives;

final class ZipArchiveGuard
{
    private const DEFAULT_MAX_ENTRIES = 5000;
    private const DEFAULT_MAX_UNCOMPRESSED_BYTES = 100 * 1024 * 1024;

    public function __construct(
        private int $maxEntries = self::DEFAULT_MAX_ENTRIES,
        private int $maxUncompressedBytes = self::DEFAULT_MAX_UNCOMPRESSED_BYTES
    ) {
        if ($this->maxEntries < 1 || $this->maxUncompressedBytes < 1) {
            throw new \InvalidArgumentException('ZIP archive limits must be positive.');
        }
    }

    /** @return true|\WP_Error */
    public function validate(string $zipPath): true|\WP_Error
    {
        if (!is_file($zipPath)) {
            return new \WP_Error('codi_mcp_archive_invalid', 'ZIP archive is unavailable.');
        }

        if (class_exists('ZipArchive')) {
            $zip = new \ZipArchive();
            $opened = $zip->open($zipPath, \ZipArchive::RDONLY);
            if (true !== $opened) {
                return new \WP_Error('codi_mcp_archive_invalid', 'ZIP archive could not be inspected.');
            }
            try {
                $entries = array();
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $stat = $zip->statIndex($index);
                    if (!is_array($stat)) {
                        return new \WP_Error('codi_mcp_archive_invalid', 'ZIP archive contains unreadable directory metadata.');
                    }
                    $entries[] = array(
                        'name' => (string) ($stat['name'] ?? ''),
                        'size' => max(0, (int) ($stat['size'] ?? 0)),
                    );
                    if (count($entries) > $this->maxEntries) {
                        return new \WP_Error('codi_mcp_archive_too_many_entries', 'ZIP archive contains too many entries.');
                    }
                }
            } finally {
                $zip->close();
            }
            return $this->validateEntries($entries);
        }

        $pclZipPath = defined('ABSPATH') ? ABSPATH . 'wp-admin/includes/class-pclzip.php' : '';
        if (!class_exists('PclZip') && $pclZipPath !== '' && is_file($pclZipPath)) {
            require_once $pclZipPath;
        }
        if (!class_exists('PclZip')) {
            return new \WP_Error('codi_mcp_archive_inspection_unavailable', 'WordPress ZIP inspection support is unavailable.');
        }
        $archive = new \PclZip($zipPath);
        $listed = $archive->listContent();
        if (!is_array($listed)) {
            return new \WP_Error('codi_mcp_archive_invalid', 'ZIP archive could not be inspected.');
        }
        $entries = array();
        foreach ($listed as $entry) {
            if (!is_array($entry)) {
                return new \WP_Error('codi_mcp_archive_invalid', 'ZIP archive contains unreadable directory metadata.');
            }
            $entries[] = array(
                'name' => (string) ($entry['filename'] ?? ''),
                'size' => max(0, (int) ($entry['size'] ?? 0)),
            );
            if (count($entries) > $this->maxEntries) {
                return new \WP_Error('codi_mcp_archive_too_many_entries', 'ZIP archive contains too many entries.');
            }
        }
        return $this->validateEntries($entries);
    }

    /** @param array<int,array{name?:string,size?:int}> $entries @return true|\WP_Error */
    public function validateEntries(array $entries): true|\WP_Error
    {
        if (count($entries) > $this->maxEntries) {
            return new \WP_Error('codi_mcp_archive_too_many_entries', 'ZIP archive contains too many entries.');
        }
        $uncompressedBytes = 0;
        foreach ($entries as $entry) {
            $name = str_replace('\\', '/', trim((string) ($entry['name'] ?? '')));
            if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('#^[A-Za-z]:/#', $name) || preg_match('#(?:^|/)\.\.(?:/|$)#', $name)) {
                return new \WP_Error('codi_mcp_archive_path_invalid', 'ZIP archive contains an unsafe entry path.');
            }
            $uncompressedBytes += max(0, (int) ($entry['size'] ?? 0));
            if ($uncompressedBytes > $this->maxUncompressedBytes) {
                return new \WP_Error('codi_mcp_archive_too_large', 'ZIP archive exceeds the maximum uncompressed size.');
            }
        }
        return true;
    }
}
