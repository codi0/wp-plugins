<?php

declare(strict_types=1);

namespace CodiMcp\Core\Deployment;

use CodiMcp\Core\Downloads\ArtifactDownloadStore;
use CodiMcp\Core\Storage\OptionMutex;

final class CodeRecoveryStore
{
    private const OPTION = 'codi_mcp_code_recoveries_v1';
    private const TTL = 7 * 86400;
    private const MAX_PER_TARGET = 3;

    private ArtifactDownloadStore $artifacts;
    private OptionMutex $mutex;
    private VerifiedPackageSnapshot $snapshots;

    public function __construct(?ArtifactDownloadStore $artifacts = null, ?VerifiedPackageSnapshot $snapshots = null)
    {
        $temporary = function_exists('get_temp_dir') ? (string) get_temp_dir() : sys_get_temp_dir();
        $this->artifacts = $artifacts ?? new ArtifactDownloadStore(
            100 * 1024 * 1024,
            256 * 1024,
            rtrim($temporary, "\\/") . DIRECTORY_SEPARATOR . 'codi-mcp' . DIRECTORY_SEPARATOR . 'recoveries',
            self::TTL,
            5000,
            100 * 1024 * 1024
        );
        $this->mutex = new OptionMutex($this->networkScoped());
        $this->snapshots = $snapshots ?? new VerifiedPackageSnapshot();
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed>|\WP_Error */
    public function create(string $kind, string $identity, string $sourceDirectory, string $archiveRoot, string $beforeFingerprint, array $metadata = array()): array|\WP_Error
    {
        $purpose = $this->purpose($kind);
        if ($purpose === '') {
            return new \WP_Error('codi_mcp_recovery_kind', 'Code recovery kind is invalid.');
        }
        $snapshot = $this->snapshots->create($sourceDirectory, $beforeFingerprint);
        if ($snapshot instanceof \WP_Error) {
            return $snapshot;
        }
        try {
            $staged = $this->artifacts->stageDirectory($purpose, (string) $snapshot['path'], $archiveRoot, 'recovery-' . $archiveRoot . '.zip', array('kind' => $kind, 'identity' => $identity));
            if ($staged instanceof \WP_Error) {
                return $staged;
            }
        } finally {
            $this->snapshots->remove((string) ($snapshot['path'] ?? ''));
        }
        return $this->persistStaged($kind, $identity, $beforeFingerprint, $metadata, $staged);
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed>|\WP_Error */
    public function createFile(string $kind, string $identity, string $sourceDirectory, string $relativeFile, string $beforeFingerprint, array $metadata = array()): array|\WP_Error
    {
        $purpose = $this->purpose($kind);
        if ($purpose === '' || !ReplacementSelection::validFile($relativeFile)) {
            return new \WP_Error('codi_mcp_recovery_kind', 'File recovery target is invalid.');
        }
        $root = realpath($sourceDirectory);
        if (!is_string($root) || !is_dir($root) || is_link($root)) {
            return new \WP_Error('codi_mcp_recovery_source', 'File recovery source package is unavailable.');
        }
        $path = $root;
        foreach (explode('/', $relativeFile) as $part) {
            $path .= DIRECTORY_SEPARATOR . $part;
            if (is_link($path)) {
                return new \WP_Error('codi_mcp_recovery_source', 'File recovery source contains a symbolic link.');
            }
        }
        if (!is_file($path)) {
            return new \WP_Error('codi_mcp_recovery_source', 'File recovery source is unavailable.');
        }
        try {
            $fingerprint = (new PackageFingerprint())->file($path);
        } catch (\Throwable $error) {
            return new \WP_Error('codi_mcp_recovery_source', $error->getMessage());
        }
        if (!hash_equals($beforeFingerprint, $fingerprint)) {
            return new \WP_Error('codi_mcp_recovery_source_changed', 'Selected file changed before its recovery point could be created.');
        }

        $temporary = function_exists('get_temp_dir') ? (string) get_temp_dir() : sys_get_temp_dir();
        $snapshot = rtrim($temporary, "\\/") . DIRECTORY_SEPARATOR . 'codi-mcp' . DIRECTORY_SEPARATOR . 'file-recovery-' . bin2hex(random_bytes(16));
        $snapshotFile = $snapshot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeFile);
        if (!@mkdir(dirname($snapshotFile), 0700, true) || !@copy($path, $snapshotFile)) {
            $this->removeTree($snapshot);
            return new \WP_Error('codi_mcp_recovery_storage', 'Could not stage the selected file recovery point.');
        }
        @chmod($snapshotFile, 0600);
        try {
            $staged = $this->artifacts->stageDirectory(
                $purpose,
                $snapshot,
                $identity,
                'recovery-' . $identity . '.zip',
                array('kind' => $kind, 'identity' => $identity, 'scope' => 'file', 'file' => $relativeFile)
            );
            if ($staged instanceof \WP_Error) {
                return $staged;
            }
        } finally {
            $this->removeTree($snapshot);
        }
        $metadata['scope'] = 'file';
        $metadata['file'] = $relativeFile;
        return $this->persistStaged($kind, $identity, $beforeFingerprint, $metadata, $staged);
    }

    /** @param array<string,mixed> $metadata @param array<string,mixed> $staged @return array<string,mixed>|\WP_Error */
    private function persistStaged(string $kind, string $identity, string $beforeFingerprint, array $metadata, array $staged): array|\WP_Error
    {
        $purpose = $this->purpose($kind);
        $id = (string) ($staged['download_id'] ?? '');
        $now = time();
        $record = array(
            'recovery_id' => $id,
            'kind' => $kind,
            'identity' => $identity,
            'created_at' => gmdate('c', $now),
            'expires_at' => gmdate('c', $now + self::TTL),
            'before_fingerprint' => $beforeFingerprint,
            'after_fingerprint' => '',
            'archive_sha256' => (string) ($staged['meta']['sha256'] ?? ''),
            'metadata' => $metadata,
            'restored_at' => '',
        );
        $removed = array();
        try {
            $this->mutate(function (array $records) use ($record, &$removed): array {
                foreach ($records as $existingId => $existing) {
                    $expires = strtotime((string) ($existing['expires_at'] ?? '')) ?: 0;
                    if ($expires > time()) {
                        continue;
                    }
                    $removed[] = array('id' => (string) $existingId, 'kind' => (string) ($existing['kind'] ?? ''));
                    unset($records[$existingId]);
                }
                $records[$record['recovery_id']] = $record;
                return $records;
            });
        } catch (\Throwable $error) {
            if ($purpose !== '') {
                $this->artifacts->delete($id, $purpose);
            }
            return new \WP_Error('codi_mcp_recovery_storage', 'Could not persist code recovery metadata.');
        }
        foreach ($removed as $old) {
            $oldPurpose = $this->purpose((string) ($old['kind'] ?? ''));
            if ($oldPurpose !== '') {
                $this->artifacts->delete((string) ($old['id'] ?? ''), $oldPurpose);
            }
        }
        return $record;
    }

    /** @return array<string,mixed>|\WP_Error */
    public function commit(string $recoveryId, string $afterFingerprint, string $afterVersion = ''): array|\WP_Error
    {
        $removed = array();
        try {
            $committed = $this->mutate(function (array $records) use ($recoveryId, $afterFingerprint, $afterVersion, &$removed): array {
                if (!isset($records[$recoveryId])) {
                    throw new \RuntimeException('Recovery record was not found.');
                }
                $records[$recoveryId]['after_fingerprint'] = $afterFingerprint;
                $records[$recoveryId]['metadata']['after_version'] = $afterVersion;
                $kind = (string) ($records[$recoveryId]['kind'] ?? '');
                $identity = (string) ($records[$recoveryId]['identity'] ?? '');
                $same = array_values(array_filter($records, static fn (array $row): bool =>
                    ($row['kind'] ?? '') === $kind
                    && ($row['identity'] ?? '') === $identity
                    && (string) ($row['after_fingerprint'] ?? '') !== ''
                    && (string) ($row['restored_at'] ?? '') === ''
                    && (string) ($row['recovery_id'] ?? '') !== $recoveryId
                ));
                usort($same, static function (array $a, array $b): int {
                    $created = strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
                    return $created !== 0
                        ? $created
                        : strcmp((string) ($b['recovery_id'] ?? ''), (string) ($a['recovery_id'] ?? ''));
                });
                foreach (array_slice($same, self::MAX_PER_TARGET - 1) as $old) {
                    $oldId = (string) ($old['recovery_id'] ?? '');
                    $removed[] = array('id' => $oldId, 'kind' => (string) ($old['kind'] ?? ''));
                    unset($records[$oldId]);
                }
                return $records;
            }, $recoveryId);
            foreach ($removed as $old) {
                $purpose = $this->purpose((string) ($old['kind'] ?? ''));
                if ($purpose !== '') {
                    $this->artifacts->delete((string) ($old['id'] ?? ''), $purpose);
                }
            }
            return $committed;
        } catch (\Throwable $error) {
            return new \WP_Error('codi_mcp_recovery_storage', 'Could not finalize code recovery metadata.', array('recovery_id' => $recoveryId, 'recovery_scope' => 'code'));
        }
    }

    public function abandon(string $recoveryId): void
    {
        $record = $this->record($recoveryId);
        if (is_array($record)) {
            $this->artifacts->delete($recoveryId, $this->purpose((string) ($record['kind'] ?? '')));
        }
        try {
            $this->mutate(function (array $records) use ($recoveryId): array {
                unset($records[$recoveryId]);
                return $records;
            });
        } catch (\Throwable) {
        }
    }

    /** @return array<string,mixed>|null */
    public function latest(string $kind, string $identity): ?array
    {
        $rows = array_values(array_filter($this->records(), static function (array $row) use ($kind, $identity): bool {
            $expires = strtotime((string) ($row['expires_at'] ?? '')) ?: 0;
            return ($row['kind'] ?? '') === $kind
                && ($row['identity'] ?? '') === $identity
                && (string) ($row['after_fingerprint'] ?? '') !== ''
                && (string) ($row['restored_at'] ?? '') === ''
                && $expires > time();
        }));
        usort($rows, static function (array $a, array $b): int {
            $created = strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
            return $created !== 0
                ? $created
                : strcmp((string) ($b['recovery_id'] ?? ''), (string) ($a['recovery_id'] ?? ''));
        });
        foreach ($rows as $row) {
            $purpose = $this->purpose((string) ($row['kind'] ?? ''));
            if ($purpose !== '' && !($this->artifacts->get((string) ($row['recovery_id'] ?? ''), $purpose) instanceof \WP_Error)) {
                return $row;
            }
        }
        return null;
    }

    /** @return array<string,mixed>|\WP_Error */
    public function artifact(string $recoveryId, string $kind): array|\WP_Error
    {
        $record = $this->record($recoveryId);
        if (!is_array($record) || ($record['kind'] ?? '') !== $kind || (string) ($record['after_fingerprint'] ?? '') === '' || (string) ($record['restored_at'] ?? '') !== '') {
            return new \WP_Error('codi_mcp_recovery_not_found', 'Committed code recovery point was not found.');
        }
        $artifact = $this->artifacts->get($recoveryId, $this->purpose($kind));
        if ($artifact instanceof \WP_Error) {
            return new \WP_Error('codi_mcp_recovery_expired', 'Code recovery artifact is no longer available.');
        }
        $actualHash = is_file((string) ($artifact['path'] ?? '')) ? hash_file('sha256', (string) $artifact['path']) : false;
        if (!is_string($actualHash)
            || !hash_equals((string) ($record['archive_sha256'] ?? ''), $actualHash)
            || !hash_equals((string) ($record['archive_sha256'] ?? ''), (string) ($artifact['meta']['sha256'] ?? ''))) {
            return new \WP_Error('codi_mcp_recovery_integrity', 'Code recovery artifact integrity check failed.');
        }
        return array('record' => $record, 'path' => (string) $artifact['path']);
    }

    public function markRestored(string $recoveryId): void
    {
        $record = $this->record($recoveryId);
        try {
            $this->mutate(function (array $records) use ($recoveryId): array {
                unset($records[$recoveryId]);
                return $records;
            });
        } catch (\Throwable) {
            return;
        }
        if (is_array($record)) {
            $purpose = $this->purpose((string) ($record['kind'] ?? ''));
            if ($purpose !== '') {
                $this->artifacts->delete($recoveryId, $purpose);
            }
        }
    }

    /** @return array<string,mixed>|null */
    private function record(string $recoveryId): ?array
    {
        $records = $this->records();
        return isset($records[$recoveryId]) && is_array($records[$recoveryId]) ? $records[$recoveryId] : null;
    }

    /**
     * @param callable(array<string,array<string,mixed>>):array<string,array<string,mixed>> $callback
     * @return array<string,mixed>|array<string,array<string,mixed>>>
     */
    private function mutate(callable $callback, string $returnId = ''): array
    {
        return $this->mutex->synchronized('code-recovery-index', function () use ($callback, $returnId): array {
            $next = $callback($this->records());
            $this->writeRecords($next);
            return $returnId !== '' ? (array) ($next[$returnId] ?? array()) : $next;
        });
    }

    /** @return array<string,array<string,mixed>> */
    private function records(): array
    {
        $raw = $this->networkScoped() && function_exists('get_site_option')
            ? get_site_option(self::OPTION, array())
            : (function_exists('get_option') ? get_option(self::OPTION, array()) : array());
        $records = array();
        foreach ((array) $raw as $id => $record) {
            if (is_string($id) && preg_match('/^[a-f0-9]{32}$/D', $id) && is_array($record)) {
                $records[$id] = $record;
            }
        }
        return $records;
    }

    /** @param array<string,array<string,mixed>> $records */
    private function writeRecords(array $records): void
    {
        $ok = $this->networkScoped()
            ? function_exists('update_site_option') && (update_site_option(self::OPTION, $records) || get_site_option(self::OPTION, array()) === $records)
            : function_exists('update_option') && (update_option(self::OPTION, $records, false) || get_option(self::OPTION, array()) === $records);
        if (!$ok) {
            throw new \RuntimeException('Could not persist code recovery index.');
        }
    }

    private function removeTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: array() as $name) {
                if ($name !== '.' && $name !== '..') {
                    $this->removeTree($path . DIRECTORY_SEPARATOR . $name);
                }
            }
            @rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            @unlink($path);
        }
    }

    private function purpose(string $kind): string
    {
        return match ($kind) {
            'plugin' => 'recovery.plugin',
            'theme' => 'recovery.theme',
            default => '',
        };
    }

    private function networkScoped(): bool
    {
        return function_exists('is_multisite') && is_multisite();
    }
}