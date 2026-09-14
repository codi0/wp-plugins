<?php

declare(strict_types=1);

namespace CodiMcp\Auth;

use CodiMcp\Core\Storage\OptionMutex;

final class OAuthStore
{
    private const CLIENT_INDEX = 'codi_mcp_oauth_client_index';
    private const REGISTRATION_RATE_OPTION = 'codi_mcp_oauth_registration_rate_v1';
    private const CREDENTIAL_OPTION_PREFIX = 'codi_mcp_oauth_credentials_';

    public function __construct(private bool $networkScoped = false, private ?OptionMutex $mutex = null)
    {
        $this->mutex ??= new OptionMutex($this->networkScoped);
    }

    public function registerClient(string $clientId, array $record, int $maxClients, int $unusedTtl, int $usedIdleTtl): bool
    {
        return (bool) $this->mutex->synchronized('oauth-clients', function () use ($clientId, $record, $maxClients, $unusedTtl, $usedIdleTtl): bool {
            $index = $this->clientIndex();
            $now = time();
            $changed = false;
            foreach (array_keys($index) as $id) {
                $client = $this->clientRecord($id);
                if (!is_array($client)) {
                    unset($index[$id]);
                    $changed = true;
                    continue;
                }
                $usedAt = (int) ($client['used_at'] ?? 0);
                $createdAt = (int) ($client['created_at'] ?? 0);
                $lastUsedAt = (int) ($client['last_used_at'] ?? 0);
                $staleUnused = $usedAt === 0 && $createdAt > 0 && $createdAt < $now - max(1, $unusedTtl);
                $staleUsed = $usedAt > 0 && $lastUsedAt > 0 && $lastUsedAt < $now - max(1, $usedIdleTtl);
                if ($staleUnused || $staleUsed) {
                    $this->deleteClientRecord($id);
                    unset($index[$id]);
                    $changed = true;
                }
            }

            if (count($index) >= max(1, $maxClients)) {
                if ($changed) {
                    $this->writeClientIndex($index);
                }
                return false;
            }
            if (isset($index[$clientId]) || is_array($this->clientRecord($clientId))) {
                throw new \RuntimeException('OAuth client identifier collision.');
            }

            $this->writeClientRecord($clientId, $record);
            $index[$clientId] = true;
            try {
                $this->writeClientIndex($index);
            } catch (\Throwable $throwable) {
                $this->deleteClientRecord($clientId);
                throw $throwable;
            }
            return true;
        });
    }

    public function client(string $clientId): ?array
    {
        return $this->clientRecord($clientId);
    }

    public function deleteClient(string $clientId): void
    {
        $this->mutex->synchronized('oauth-clients', function () use ($clientId): void {
            $index = $this->clientIndex();
            unset($index[$clientId]);
            $this->writeClientIndex($index);
            $this->deleteClientRecord($clientId);
        });
    }

    public function touchClient(string $clientId): void
    {
        $this->mutex->synchronized('oauth-clients', function () use ($clientId): void {
            $record = $this->clientRecord($clientId);
            if (!is_array($record)) {
                throw new \RuntimeException('OAuth client no longer exists.');
            }
            $now = time();
            $record['used_at'] = (int) ($record['used_at'] ?? 0) > 0 ? (int) $record['used_at'] : $now;
            $record['last_used_at'] = $now;
            $this->writeClientRecord($clientId, $record);
        });
    }

    public function consumeRegistrationBudget(string $source, int $perSourceLimit, int $globalLimit, int $windowSeconds): int
    {
        return (int) $this->mutex->synchronized('oauth-registration-rate', function () use ($source, $perSourceLimit, $globalLimit, $windowSeconds): int {
            if (!$this->optionStorageAvailable()) {
                throw new \RuntimeException('WordPress option storage is unavailable for OAuth registration throttling.');
            }

            $now = time();
            $windowSeconds = max(1, $windowSeconds);
            $state = $this->readOption(self::REGISTRATION_RATE_OPTION, array());
            if (!is_array($state) || (int) ($state['window_started_at'] ?? 0) <= $now - $windowSeconds) {
                $state = array('window_started_at' => $now, 'global_count' => 0, 'sources' => array());
            }

            $sourceKey = substr(hash('sha256', trim($source) === '' ? 'unknown' : trim($source)), 0, 24);
            $sources = is_array($state['sources'] ?? null) ? $state['sources'] : array();
            $globalCount = (int) ($state['global_count'] ?? 0);
            $sourceCount = (int) ($sources[$sourceKey] ?? 0);
            if ($globalCount >= max(1, $globalLimit) || $sourceCount >= max(1, $perSourceLimit)) {
                return max(1, $windowSeconds - ($now - (int) $state['window_started_at']));
            }

            $state['global_count'] = $globalCount + 1;
            $sources[$sourceKey] = $sourceCount + 1;
            $state['sources'] = $sources;
            $this->writeOption(self::REGISTRATION_RATE_OPTION, $state, 'OAuth registration throttle state');
            return 0;
        });
    }

    public function saveCode(string $hash, array $record, int $ttl): void
    {
        $this->saveCredential('code', $hash, $record, $ttl);
    }

    public function deleteCode(string $hash): void
    {
        $this->deleteCredential('code', $hash);
    }

    public function exchangeCode(string $hash, callable $callback)
    {
        return $this->exchange('code', $hash, $callback);
    }

    public function saveAccessToken(string $hash, array $record, int $ttl): void
    {
        $this->saveCredential('access', $hash, $record, $ttl);
    }

    public function accessToken(string $hash): ?array
    {
        return $this->credential('access', $hash);
    }

    public function deleteAccessToken(string $hash): void
    {
        $this->deleteCredential('access', $hash);
    }

    public function saveRefreshToken(string $hash, array $record, int $ttl): void
    {
        $this->saveCredential('refresh', $hash, $record, $ttl);
    }

    public function deleteRefreshToken(string $hash): void
    {
        $this->deleteCredential('refresh', $hash);
    }

    public function exchangeRefreshToken(string $hash, callable $callback)
    {
        return $this->exchange('refresh', $hash, $callback);
    }

    private function exchange(string $bucket, string $hash, callable $callback)
    {
        return $this->mutex->synchronized($this->mutexScope('oauth-' . $bucket), function () use ($bucket, $hash, $callback) {
            $entries = $this->pruneCredentials($this->credentialBucket($bucket));
            $entry = $entries[$hash] ?? null;
            if (!is_array($entry) || !is_array($entry['record'] ?? null)) {
                $this->writeCredentialBucket($bucket, $entries);
                return null;
            }

            $exchange = $callback((array) $entry['record']);
            if (!is_array($exchange) || !array_key_exists('consume', $exchange) || !array_key_exists('result', $exchange)) {
                throw new \RuntimeException('Invalid OAuth credential exchange result.');
            }

            if ((bool) $exchange['consume']) {
                unset($entries[$hash]);
                $this->writeCredentialBucket($bucket, $entries);
            }
            return $exchange['result'];
        });
    }

    private function saveCredential(string $bucket, string $hash, array $record, int $ttl): void
    {
        $this->mutex->synchronized($this->mutexScope('oauth-' . $bucket), function () use ($bucket, $hash, $record, $ttl): void {
            $entries = $this->pruneCredentials($this->credentialBucket($bucket));
            $entries[$hash] = array(
                'record' => $record,
                'expires_at' => time() + max(1, $ttl),
            );
            $this->writeCredentialBucket($bucket, $entries);
        });
    }

    private function credential(string $bucket, string $hash): ?array
    {
        $entries = $this->credentialBucket($bucket);
        $entry = $entries[$hash] ?? null;
        if (!is_array($entry) || !is_array($entry['record'] ?? null)) {
            return null;
        }
        if ((int) ($entry['expires_at'] ?? 0) > time()) {
            return (array) $entry['record'];
        }

        $this->deleteCredential($bucket, $hash);
        return null;
    }

    private function deleteCredential(string $bucket, string $hash): void
    {
        $this->mutex->synchronized($this->mutexScope('oauth-' . $bucket), function () use ($bucket, $hash): void {
            $entries = $this->pruneCredentials($this->credentialBucket($bucket));
            unset($entries[$hash]);
            $this->writeCredentialBucket($bucket, $entries);
        });
    }

    /** @return array<string,array{record:array<string,mixed>,expires_at:int}> */
    private function credentialBucket(string $bucket): array
    {
        $value = $this->readOption($this->credentialOptionName($bucket), array());
        return is_array($value) ? $value : array();
    }

    /** @param array<string,mixed> $entries @return array<string,array{record:array<string,mixed>,expires_at:int}> */
    private function pruneCredentials(array $entries): array
    {
        $now = time();
        $valid = array();
        foreach ($entries as $hash => $entry) {
            if (!is_string($hash) || !is_array($entry) || !is_array($entry['record'] ?? null)) {
                continue;
            }
            $expiresAt = (int) ($entry['expires_at'] ?? 0);
            if ($expiresAt <= $now) {
                continue;
            }
            $valid[$hash] = array('record' => (array) $entry['record'], 'expires_at' => $expiresAt);
        }
        return $valid;
    }

    /** @param array<string,array{record:array<string,mixed>,expires_at:int}> $entries */
    private function writeCredentialBucket(string $bucket, array $entries): void
    {
        $name = $this->credentialOptionName($bucket);
        if ($entries === array()) {
            $this->deleteOption($name, 'OAuth credential bucket');
            return;
        }
        $this->writeOption($name, $entries, 'OAuth credential bucket');
    }

    private function credentialOptionName(string $bucket): string
    {
        if (!in_array($bucket, array('code', 'access', 'refresh'), true)) {
            throw new \InvalidArgumentException('Unknown OAuth credential bucket.');
        }
        return self::CREDENTIAL_OPTION_PREFIX . $bucket;
    }

    /** @return array<string,bool> */
    private function clientIndex(): array
    {
        if (!$this->optionStorageAvailable()) {
            throw new \RuntimeException('WordPress option storage is unavailable for OAuth clients.');
        }
        $value = $this->readOption(self::CLIENT_INDEX, array());
        if (!is_array($value)) {
            return array();
        }
        $index = array();
        foreach (array_keys($value) as $clientId) {
            if (is_string($clientId) && $clientId !== '') {
                $index[$clientId] = true;
            }
        }
        return $index;
    }

    /** @param array<string,bool> $index */
    private function writeClientIndex(array $index): void
    {
        $this->writeOption(self::CLIENT_INDEX, $index, 'OAuth client index');
    }

    private function clientRecord(string $clientId): ?array
    {
        if (!$this->optionStorageAvailable()) {
            throw new \RuntimeException('WordPress option storage is unavailable for OAuth clients.');
        }
        $record = $this->readOption($this->clientOptionName($clientId), false);
        return is_array($record) ? $record : null;
    }

    private function writeClientRecord(string $clientId, array $record): void
    {
        $this->writeOption($this->clientOptionName($clientId), $record, 'OAuth client');
    }

    private function deleteClientRecord(string $clientId): void
    {
        if (!$this->optionStorageAvailable()) {
            throw new \RuntimeException('WordPress option storage is unavailable for OAuth clients.');
        }
        $this->deleteOption($this->clientOptionName($clientId), 'OAuth client');
    }

    /** @param mixed $value */
    private function writeOption(string $key, $value, string $label): void
    {
        if (!$this->optionStorageAvailable()) {
            throw new \RuntimeException('WordPress option storage is unavailable for ' . $label . '.');
        }
        $written = $this->networkScoped
            ? (bool) update_site_option($key, $value)
            : (bool) update_option($key, $value, false);
        if (!$written && $this->readOption($key, null) !== $value) {
            throw new \RuntimeException('Could not persist ' . $label . '.');
        }
    }

    private function readOption(string $key, $default)
    {
        return $this->networkScoped ? get_site_option($key, $default) : get_option($key, $default);
    }

    private function deleteOption(string $key, string $label): void
    {
        if (!$this->optionStorageAvailable()) {
            throw new \RuntimeException('WordPress option storage is unavailable for ' . $label . '.');
        }
        if ($this->readOption($key, null) === null) {
            return;
        }
        $deleted = $this->networkScoped ? (bool) delete_site_option($key) : (bool) delete_option($key);
        if (!$deleted && $this->readOption($key, null) !== null) {
            throw new \RuntimeException('Could not delete ' . $label . '.');
        }
    }

    private function optionStorageAvailable(): bool
    {
        if ($this->networkScoped) {
            return function_exists('get_site_option') && function_exists('update_site_option') && function_exists('delete_site_option');
        }
        return function_exists('get_option') && function_exists('update_option') && function_exists('delete_option');
    }

    private function clientOptionName(string $clientId): string
    {
        return 'codi_mcp_oauth_client_' . substr(hash('sha256', $clientId), 0, 40);
    }

    private function mutexScope(string $scope): string
    {
        return ($this->networkScoped ? 'network-' : 'site-') . $scope;
    }
}
