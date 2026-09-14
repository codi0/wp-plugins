<?php

declare(strict_types=1);

namespace CodiMcp\Core\Downloads;

final class ArtifactDownloadStore
{
    private const DEFAULT_TTL_SECONDS = 3600;
    private const DEFAULT_MAX_FILES = 5000;
    private const DEFAULT_MAX_SOURCE_BYTES = 50 * 1024 * 1024;

    private string $rootDirectory;

    /** @var callable|null */
    private $archiveWriter;

    public function __construct(
        private int $maxArchiveBytes,
        private int $chunkBytes,
        ?string $rootDirectory = null,
        private int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        private int $maxFiles = self::DEFAULT_MAX_FILES,
        private int $maxSourceBytes = self::DEFAULT_MAX_SOURCE_BYTES,
        ?callable $archiveWriter = null
    ) {
        if ($this->maxArchiveBytes < 1 || $this->chunkBytes < 1 || $this->ttlSeconds < 1 || $this->maxFiles < 1 || $this->maxSourceBytes < 1) {
            throw new \InvalidArgumentException('Artifact download limits must be positive.');
        }
        $rootDirectory = is_string($rootDirectory) ? trim($rootDirectory) : '';
        $this->rootDirectory = '' !== $rootDirectory ? rtrim($rootDirectory, "\\/") : self::defaultRootDirectory();
        $this->archiveWriter = $archiveWriter;
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed>|\WP_Error */
    public function stageDirectory(string $purpose, string $sourceDirectory, string $archiveRoot, string $filename, array $metadata = array()): array|\WP_Error
    {
        $purpose = strtolower(trim($purpose));
        $archiveRoot = trim($archiveRoot);
        $filename = trim($filename);
        if (1 !== preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/', $purpose)) {
            return new \WP_Error('codi_mcp_bad_download_purpose', 'Download purpose is invalid.');
        }
        if (1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $archiveRoot) || in_array($archiveRoot, array('.', '..'), true)) {
            return new \WP_Error('codi_mcp_bad_archive_root', 'Archive root name is invalid.');
        }
        if (1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,199}\.zip$/', $filename)) {
            return new \WP_Error('codi_mcp_bad_archive_filename', 'Archive filename is invalid.');
        }

        $sourceRoot = realpath($sourceDirectory);
        if (!is_string($sourceRoot) || '' === $sourceRoot || !is_dir($sourceRoot) || is_link($sourceRoot)) {
            return new \WP_Error('codi_mcp_export_source_invalid', 'Export source directory is unavailable.');
        }

        $scan = $this->scanSource($sourceRoot);
        if ($scan instanceof \WP_Error) {
            return $scan;
        }
        if (!$this->ensureDirectory($this->rootDirectory)) {
            return new \WP_Error('codi_mcp_storage', 'Could not create the artifact download directory.');
        }
        $this->cleanupExpired();

        do {
            $downloadId = bin2hex(random_bytes(16));
            $paths = $this->paths($downloadId);
        } while (file_exists($paths['directory']));

        if (!@mkdir($paths['directory'], 0700)) {
            return new \WP_Error('codi_mcp_storage', 'Could not create the artifact download staging directory.');
        }
        @chmod($paths['directory'], 0700);

        $written = $this->writeArchive($scan['files'], $sourceRoot, $archiveRoot, $paths['archive']);
        if ($written instanceof \WP_Error) {
            $this->removeDownloadFiles($paths);
            return $written;
        }
        clearstatcache(true, $paths['archive']);
        $size = @filesize($paths['archive']);
        if (false === $size || $size < 1 || $size > $this->maxArchiveBytes) {
            $this->removeDownloadFiles($paths);
            return new \WP_Error('codi_mcp_export_archive_too_large', 'Generated ZIP is empty or exceeds the maximum artifact size.');
        }
        $sha256 = hash_file('sha256', $paths['archive']);
        if (!is_string($sha256) || '' === $sha256) {
            $this->removeDownloadFiles($paths);
            return new \WP_Error('codi_mcp_storage', 'Could not hash the generated artifact.');
        }
        @chmod($paths['archive'], 0600);

        $record = array(
            'purpose' => $purpose,
            'created' => time(),
            'size' => (int) $size,
            'sha256' => $sha256,
            'filename' => $filename,
            'file_count' => (int) $scan['file_count'],
            'source_bytes' => (int) $scan['source_bytes'],
            'metadata' => $metadata,
        );
        $encoded = $this->encodeJson($record);
        if (!is_string($encoded) || false === @file_put_contents($paths['meta'], $encoded, LOCK_EX)) {
            $this->removeDownloadFiles($paths);
            return new \WP_Error('codi_mcp_storage', 'Could not save artifact download metadata.');
        }
        @chmod($paths['meta'], 0600);

        return array('download_id' => $downloadId, 'meta' => $record);
    }

    /** @return array<string,mixed>|\WP_Error */
    public function readBase64(string $downloadId, string $purpose, int $offset): array|\WP_Error
    {
        $download = $this->get($downloadId, $purpose);
        if ($download instanceof \WP_Error) {
            return $download;
        }
        $size = (int) ($download['meta']['size'] ?? 0);
        if ($offset < 0 || $offset > $size) {
            return new \WP_Error('codi_mcp_bad_offset', 'Download offset is outside the generated artifact.');
        }
        $remaining = $size - $offset;
        $bytes = min($this->chunkBytes, $remaining);
        $data = '';
        if ($bytes > 0) {
            $handle = @fopen((string) $download['path'], 'rb');
            if (!is_resource($handle)) {
                return new \WP_Error('codi_mcp_storage', 'Could not open the generated artifact.');
            }
            try {
                if (0 !== fseek($handle, $offset)) {
                    return new \WP_Error('codi_mcp_storage', 'Could not seek in the generated artifact.');
                }
                $raw = fread($handle, $bytes);
                if (!is_string($raw) || strlen($raw) !== $bytes) {
                    return new \WP_Error('codi_mcp_storage', 'Could not read the requested artifact chunk.');
                }
                $data = base64_encode($raw);
            } finally {
                fclose($handle);
            }
        }
        $nextOffset = $offset + $bytes;
        return array(
            'download_id' => $downloadId,
            'filename' => (string) ($download['meta']['filename'] ?? ''),
            'size' => $size,
            'sha256' => (string) ($download['meta']['sha256'] ?? ''),
            'offset' => $offset,
            'next_offset' => $nextOffset,
            'chunk_size' => $this->chunkBytes,
            'data' => $data,
            'complete' => $nextOffset >= $size,
            'meta' => is_array($download['meta']['metadata'] ?? null) ? $download['meta']['metadata'] : array(),
        );
    }

    /** @return array<string,mixed>|\WP_Error */
    public function get(string $downloadId, string $purpose): array|\WP_Error
    {
        if (1 !== preg_match('/^[a-f0-9]{32}$/', $downloadId)) {
            return new \WP_Error('codi_mcp_bad_download_id', 'Invalid download ID.');
        }
        $paths = $this->paths($downloadId);
        if (!is_file($paths['archive']) || !is_file($paths['meta'])) {
            return new \WP_Error('codi_mcp_download_not_found', 'Download was not found or has expired.');
        }
        $decoded = json_decode((string) @file_get_contents($paths['meta']), true);
        if (!is_array($decoded) || !is_string($decoded['purpose'] ?? null) || !is_int($decoded['created'] ?? null) || !is_int($decoded['size'] ?? null) || !is_string($decoded['sha256'] ?? null) || !is_array($decoded['metadata'] ?? null)) {
            return new \WP_Error('codi_mcp_bad_download_metadata', 'Download metadata is invalid.');
        }
        if (!hash_equals($decoded['purpose'], strtolower(trim($purpose)))) {
            return new \WP_Error('codi_mcp_download_purpose_mismatch', 'Download is not valid for this operation.');
        }
        if (($decoded['created'] + $this->ttlSeconds) < time()) {
            $this->removeDownloadFiles($paths);
            return new \WP_Error('codi_mcp_download_not_found', 'Download was not found or has expired.');
        }
        return array('download_id' => $downloadId, 'path' => $paths['archive'], 'meta' => $decoded);
    }

    public static function defaultRootDirectory(): string
    {
        $temporary = function_exists('get_temp_dir') ? (string) get_temp_dir() : sys_get_temp_dir();
        return rtrim($temporary, "\\/") . DIRECTORY_SEPARATOR . 'codi-mcp' . DIRECTORY_SEPARATOR . 'downloads';
    }

    /** @return array{files:array<int,string>,file_count:int,source_bytes:int}|\WP_Error */
    private function scanSource(string $sourceRoot): array|\WP_Error
    {
        $files = array();
        $bytes = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $info) {
                if (!$info instanceof \SplFileInfo) {
                    continue;
                }
                if ($info->isLink()) {
                    return new \WP_Error('codi_mcp_export_symlink', 'Artifact export refuses symbolic links.');
                }
                if (!$info->isFile()) {
                    continue;
                }
                $resolved = $info->getRealPath();
                if (!is_string($resolved) || !$this->withinRoot($resolved, $sourceRoot)) {
                    return new \WP_Error('codi_mcp_export_scope', 'Artifact source resolves outside the selected package directory.');
                }
                $files[] = $resolved;
                $bytes += max(0, (int) $info->getSize());
                if (count($files) > $this->maxFiles || $bytes > $this->maxSourceBytes) {
                    return new \WP_Error('codi_mcp_export_source_too_large', 'Artifact source exceeds the file-count or uncompressed-size limit.');
                }
            }
        } catch (\Throwable) {
            return new \WP_Error('codi_mcp_export_scan_failed', 'Artifact source could not be scanned safely.');
        }
        if ($files === array()) {
            return new \WP_Error('codi_mcp_export_empty', 'Artifact source directory contains no files.');
        }
        sort($files, SORT_STRING);
        return array('files' => $files, 'file_count' => count($files), 'source_bytes' => $bytes);
    }

    /** @param array<int,string> $files */
    private function writeArchive(array $files, string $sourceRoot, string $archiveRoot, string $archivePath): bool|\WP_Error
    {
        if (is_callable($this->archiveWriter)) {
            try {
                $result = ($this->archiveWriter)($files, $sourceRoot, $archiveRoot, $archivePath);
                return true === $result ? true : new \WP_Error('codi_mcp_export_zip_failed', 'Configured artifact writer could not create the ZIP.');
            } catch (\Throwable) {
                return new \WP_Error('codi_mcp_export_zip_failed', 'Configured artifact writer could not create the ZIP.');
            }
        }
        if (class_exists('ZipArchive')) {
            $zip = new \ZipArchive();
            $opened = $zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            if (true !== $opened) {
                return new \WP_Error('codi_mcp_export_zip_failed', 'Could not create the artifact ZIP.');
            }
            foreach ($files as $file) {
                $relative = ltrim(str_replace('\\', '/', substr($file, strlen($sourceRoot))), '/');
                if (!$zip->addFile($file, $archiveRoot . '/' . $relative)) {
                    $zip->close();
                    @unlink($archivePath);
                    return new \WP_Error('codi_mcp_export_zip_failed', 'Could not add a source file to the artifact ZIP.');
                }
            }
            return $zip->close() ? true : new \WP_Error('codi_mcp_export_zip_failed', 'Could not finalize the artifact ZIP.');
        }

        $pclZipPath = defined('ABSPATH') ? ABSPATH . 'wp-admin/includes/class-pclzip.php' : '';
        if (!class_exists('PclZip') && $pclZipPath !== '' && is_file($pclZipPath)) {
            require_once $pclZipPath;
        }
        if (!class_exists('PclZip')) {
            return new \WP_Error('codi_mcp_export_zip_unavailable', 'WordPress ZIP support is unavailable.');
        }
        $archive = new \PclZip($archivePath);
        $result = $archive->create($files, PCLZIP_OPT_REMOVE_PATH, $sourceRoot, PCLZIP_OPT_ADD_PATH, $archiveRoot);
        return 0 === $result ? new \WP_Error('codi_mcp_export_zip_failed', 'WordPress could not create the artifact ZIP.') : true;
    }

    private function withinRoot(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');
        return $path === $root || str_starts_with($path, $root . '/');
    }

    /** @return array{directory:string,archive:string,meta:string} */
    private function paths(string $downloadId): array
    {
        $directory = $this->rootDirectory . DIRECTORY_SEPARATOR . 'download-' . $downloadId;
        return array('directory' => $directory, 'archive' => $directory . DIRECTORY_SEPARATOR . 'artifact.zip', 'meta' => $directory . DIRECTORY_SEPARATOR . 'metadata.json');
    }

    private function ensureDirectory(string $directory): bool
    {
        if (is_dir($directory)) {
            return true;
        }
        $created = function_exists('wp_mkdir_p') ? (bool) wp_mkdir_p($directory) : @mkdir($directory, 0700, true);
        if ($created) {
            @chmod($directory, 0700);
        }
        return $created || is_dir($directory);
    }

    private function cleanupExpired(): void
    {
        if (!is_dir($this->rootDirectory)) {
            return;
        }
        $cutoff = time() - $this->ttlSeconds;
        $directories = glob($this->rootDirectory . DIRECTORY_SEPARATOR . 'download-*', GLOB_ONLYDIR);
        if (!is_array($directories)) {
            return;
        }
        foreach ($directories as $directory) {
            $modified = @filemtime($directory);
            if (false === $modified || $modified >= $cutoff) {
                continue;
            }
            $id = substr(basename($directory), strlen('download-'));
            if (1 === preg_match('/^[a-f0-9]{32}$/', $id)) {
                $this->removeDownloadFiles($this->paths($id));
            }
        }
    }

    /** @param array{directory:string,archive:string,meta:string} $paths */
    private function removeDownloadFiles(array $paths): void
    {
        foreach (array($paths['archive'], $paths['meta']) as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        if (is_dir($paths['directory'])) {
            @rmdir($paths['directory']);
        }
    }

    /** @param array<string,mixed> $value */
    private function encodeJson(array $value): string|false
    {
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($value, JSON_UNESCAPED_SLASHES) : json_encode($value, JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : false;
    }
}
