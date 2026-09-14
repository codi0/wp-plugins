<?php

declare(strict_types=1);

namespace CodiMcp\Core\Uploads;

final class UploadStore
{
    private const DEFAULT_TTL_SECONDS = 15 * 60;

    private string $rootDirectory;

    public function __construct(
        private int $maxBytes,
        ?string $rootDirectory = null,
        private int $ttlSeconds = self::DEFAULT_TTL_SECONDS
    ) {
        if ($this->maxBytes < 1) {
            throw new \InvalidArgumentException('maxBytes must be at least 1.');
        }
        if ($this->ttlSeconds < 1) {
            throw new \InvalidArgumentException('ttlSeconds must be at least 1.');
        }

        $rootDirectory = is_string($rootDirectory) ? trim($rootDirectory) : '';
        $this->rootDirectory = '' !== $rootDirectory
            ? rtrim($rootDirectory, "\\/")
            : self::defaultRootDirectory();
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed>|\WP_Error */
    public function start(string $purpose, int $size, string $sha256, array $metadata = array()): array|\WP_Error
    {
        $purpose = strtolower(trim($purpose));
        $sha256 = strtolower(trim($sha256));

        if (1 !== preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/', $purpose)) {
            return new \WP_Error('codi_mcp_bad_upload_purpose', 'Upload purpose is invalid.');
        }
        if ($size < 1 || $size > $this->maxBytes) {
            return new \WP_Error('codi_mcp_bad_size', 'Upload size is outside the allowed range.');
        }
        if (1 !== preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            return new \WP_Error('codi_mcp_bad_hash', 'Invalid SHA-256.');
        }
        if (!$this->ensureDirectory($this->rootDirectory)) {
            return new \WP_Error('codi_mcp_storage', 'Could not create the upload staging directory.');
        }

        $this->cleanupExpired();

        do {
            $uploadId = bin2hex(random_bytes(16));
            $paths = $this->paths($uploadId);
        } while (file_exists($paths['directory']));

        if (!@mkdir($paths['directory'], 0700)) {
            return new \WP_Error('codi_mcp_storage', 'Could not create the upload staging directory.');
        }
        @chmod($paths['directory'], 0700);

        $handle = @fopen($paths['payload'], 'x+b');
        if (!is_resource($handle)) {
            @rmdir($paths['directory']);
            return new \WP_Error('codi_mcp_storage', 'Could not create the staged upload file.');
        }
        fclose($handle);
        @chmod($paths['payload'], 0600);

        $record = array(
            'purpose' => $purpose,
            'size' => $size,
            'sha256' => $sha256,
            'created' => time(),
            'metadata' => $metadata,
        );
        $encoded = $this->encodeJson($record);
        if (!is_string($encoded) || false === @file_put_contents($paths['meta'], $encoded, LOCK_EX)) {
            @unlink($paths['payload']);
            @rmdir($paths['directory']);
            return new \WP_Error('codi_mcp_storage', 'Could not save upload metadata.');
        }
        @chmod($paths['meta'], 0600);

        return array(
            'upload_id' => $uploadId,
            'expires_at' => gmdate('c', $record['created'] + $this->ttlSeconds),
        );
    }

    /** @param resource $stream @return array<string,mixed>|\WP_Error */
    public function writeStream(string $uploadId, string $purpose, $stream): array|\WP_Error
    {
        if (!is_resource($stream)) {
            return new \WP_Error('codi_mcp_bad_upload_stream', 'Upload body stream is unavailable.');
        }

        $upload = $this->get($uploadId, $purpose);
        if ($upload instanceof \WP_Error) {
            return $upload;
        }

        $handle = @fopen((string) $upload['path'], 'c+b');
        if (!is_resource($handle)) {
            return new \WP_Error('codi_mcp_storage', 'Could not open the staged upload file.');
        }
        if (!@flock($handle, LOCK_EX)) {
            fclose($handle);
            return new \WP_Error('codi_mcp_storage', 'Could not lock the staged upload file.');
        }

        try {
            $stat = fstat($handle);
            $currentSize = is_array($stat) && isset($stat['size']) ? (int) $stat['size'] : 0;
            if (0 !== $currentSize) {
                return new \WP_Error('codi_mcp_upload_already_received', 'Upload destination has already received data.');
            }

            $declaredSize = (int) ($upload['meta']['size'] ?? 0);
            $declaredHash = (string) ($upload['meta']['sha256'] ?? '');
            $hash = hash_init('sha256');
            $written = 0;

            while (!feof($stream)) {
                $remaining = max(1, $declaredSize - $written + 1);
                $chunk = fread($stream, min(65536, $remaining));
                if (false === $chunk) {
                    ftruncate($handle, 0);
                    fflush($handle);
                    return new \WP_Error('codi_mcp_storage', 'Could not read the upload body.');
                }
                if ($chunk === '') {
                    if (feof($stream)) {
                        break;
                    }
                    continue;
                }

                $received = strlen($chunk);
                if ($written + $received > $declaredSize) {
                    ftruncate($handle, 0);
                    fflush($handle);
                    return new \WP_Error('codi_mcp_too_much_data', 'Upload body exceeds the declared size.');
                }

                $offset = 0;
                while ($offset < $received) {
                    $result = fwrite($handle, substr($chunk, $offset));
                    if (false === $result || 0 === $result) {
                        ftruncate($handle, 0);
                        fflush($handle);
                        return new \WP_Error('codi_mcp_storage', 'Could not write the complete upload body.');
                    }
                    $offset += $result;
                }
                hash_update($hash, $chunk);
                $written += $received;
            }

            fflush($handle);
            if ($written !== $declaredSize) {
                ftruncate($handle, 0);
                fflush($handle);
                return new \WP_Error('codi_mcp_incomplete_upload', 'Upload body does not match the declared size.');
            }

            $actualHash = hash_final($hash);
            if (!hash_equals($declaredHash, $actualHash)) {
                ftruncate($handle, 0);
                fflush($handle);
                return new \WP_Error('codi_mcp_hash_mismatch', 'Uploaded file does not match the declared SHA-256.');
            }
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }

        return $this->complete($uploadId, $purpose);
    }

    /** @return array<string,mixed>|\WP_Error */
    public function get(string $uploadId, string $purpose): array|\WP_Error
    {
        if (1 !== preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
            return new \WP_Error('codi_mcp_bad_upload_id', 'Invalid upload ID.');
        }

        $paths = $this->paths($uploadId);
        if (!is_file($paths['payload']) || !is_file($paths['meta'])) {
            return new \WP_Error('codi_mcp_upload_not_found', 'Upload was not found or has expired.');
        }

        $decoded = json_decode((string) @file_get_contents($paths['meta']), true);
        if (!is_array($decoded)
            || !isset($decoded['purpose'], $decoded['size'], $decoded['sha256'], $decoded['created'])
            || !is_string($decoded['purpose'])
            || !is_int($decoded['size'])
            || !is_string($decoded['sha256'])
            || !is_int($decoded['created'])
            || !is_array($decoded['metadata'] ?? null)
        ) {
            return new \WP_Error('codi_mcp_bad_metadata', 'Upload metadata is invalid.');
        }

        if (!hash_equals($decoded['purpose'], strtolower(trim($purpose)))) {
            return new \WP_Error('codi_mcp_upload_purpose_mismatch', 'Upload is not valid for this operation.');
        }
        if (($decoded['created'] + $this->ttlSeconds) < time()) {
            $this->removeUploadFiles($paths);
            return new \WP_Error('codi_mcp_upload_not_found', 'Upload was not found or has expired.');
        }

        return array(
            'upload_id' => $uploadId,
            'path' => $paths['payload'],
            'meta' => $decoded,
        );
    }

    /** @return array<string,mixed>|\WP_Error */
    public function complete(string $uploadId, string $purpose): array|\WP_Error
    {
        $upload = $this->get($uploadId, $purpose);
        if ($upload instanceof \WP_Error) {
            return $upload;
        }

        clearstatcache(true, (string) $upload['path']);
        $actualSize = filesize((string) $upload['path']);
        if (false === $actualSize || (int) $actualSize !== (int) ($upload['meta']['size'] ?? 0)) {
            return new \WP_Error('codi_mcp_incomplete_upload', 'Upload is not complete.');
        }

        $actualHash = hash_file('sha256', (string) $upload['path']);
        if (!is_string($actualHash) || !hash_equals((string) ($upload['meta']['sha256'] ?? ''), $actualHash)) {
            return new \WP_Error('codi_mcp_hash_mismatch', 'Uploaded file does not match the declared SHA-256.');
        }

        $upload['complete'] = true;
        return $upload;
    }

    public function consume(string $uploadId, string $purpose): bool|\WP_Error
    {
        $upload = $this->get($uploadId, $purpose);
        if ($upload instanceof \WP_Error) {
            return $upload;
        }

        $paths = $this->paths($uploadId);
        return $this->removeUploadFiles($paths)
            ? true
            : new \WP_Error('codi_mcp_storage', 'Could not remove the staged upload.');
    }

    public function discard(string $uploadId, string $purpose): bool|\WP_Error
    {
        return $this->consume($uploadId, $purpose);
    }

    public static function defaultRootDirectory(): string
    {
        $temporary = function_exists('get_temp_dir') ? (string) get_temp_dir() : sys_get_temp_dir();
        return rtrim($temporary, "\\/") . DIRECTORY_SEPARATOR . 'codi-mcp' . DIRECTORY_SEPARATOR . 'uploads';
    }

    /** @return array{directory:string,payload:string,meta:string} */
    private function paths(string $uploadId): array
    {
        $directory = $this->rootDirectory . DIRECTORY_SEPARATOR . 'upload-' . $uploadId;
        return array(
            'directory' => $directory,
            'payload' => $directory . DIRECTORY_SEPARATOR . 'payload.part',
            'meta' => $directory . DIRECTORY_SEPARATOR . 'metadata.json',
        );
    }

    private function ensureDirectory(string $directory): bool
    {
        if (is_dir($directory)) {
            return true;
        }
        if (function_exists('wp_mkdir_p')) {
            $created = (bool) wp_mkdir_p($directory);
        } else {
            $created = @mkdir($directory, 0700, true);
        }
        if ($created) {
            @chmod($directory, 0700);
        }
        return $created || is_dir($directory);
    }

    public function cleanupExpired(): int
    {
        if (!is_dir($this->rootDirectory)) {
            return 0;
        }

        $directories = glob($this->rootDirectory . DIRECTORY_SEPARATOR . 'upload-*', GLOB_ONLYDIR);
        if (!is_array($directories)) {
            return 0;
        }

        $removed = 0;
        $cutoff = time() - $this->ttlSeconds;
        foreach ($directories as $directory) {
            $name = basename($directory);
            if (1 !== preg_match('/^upload-([a-f0-9]{32})$/', $name, $matches)) {
                continue;
            }
            $paths = $this->paths((string) $matches[1]);
            $created = @filemtime($paths['meta']);
            if (false === $created) {
                $created = @filemtime($paths['directory']);
            }
            if (false !== $created && $created < $cutoff && $this->removeUploadFiles($paths)) {
                ++$removed;
            }
        }
        return $removed;
    }

    /** @param array{directory:string,payload:string,meta:string} $paths */
    private function removeUploadFiles(array $paths): bool
    {
        $ok = true;
        foreach (array($paths['payload'], $paths['meta']) as $path) {
            if (is_file($path) && !@unlink($path)) {
                $ok = false;
            }
        }
        if (is_dir($paths['directory']) && !@rmdir($paths['directory'])) {
            $ok = false;
        }
        return $ok;
    }

    /** @param array<string,mixed> $value */
    private function encodeJson(array $value): string|false
    {
        if (function_exists('wp_json_encode')) {
            $encoded = wp_json_encode($value, JSON_UNESCAPED_SLASHES);
        } else {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
        }
        return is_string($encoded) ? $encoded : false;
    }
}
