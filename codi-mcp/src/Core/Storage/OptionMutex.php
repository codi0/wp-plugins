<?php

declare(strict_types=1);

namespace CodiMcp\Core\Storage;

final class OptionMutex
{
    public function __construct(private bool $networkScoped = false)
    {
    }

    private const STALE_AFTER_SECONDS = 120;
    private const ACQUIRE_ATTEMPTS = 50;
    private const RETRY_DELAY_MICROSECONDS = 10000;

    /** @return mixed */
    public function synchronized(string $scope, callable $callback)
    {
        if (!$this->storageAvailable()) {
            throw new \RuntimeException('WordPress option storage is unavailable for synchronization.');
        }

        $scope = trim($scope);
        if ($scope === '') {
            throw new \InvalidArgumentException('Synchronization scope is required.');
        }

        $name = 'codi_mcp_mutex_' . substr(hash('sha256', $scope), 0, 32);
        $token = bin2hex(random_bytes(16));
        $ownedRecord = $this->acquire($name, $token);
        if ($ownedRecord === null) {
            throw new \RuntimeException('Could not acquire the Codi MCP storage mutex.');
        }

        try {
            return $callback();
        } finally {
            $this->deleteIfUnchanged($name, $ownedRecord);
        }
    }

    /** @return array{token:string,expires_at:int}|null */
    private function acquire(string $name, string $token): ?array
    {
        for ($attempt = 0; $attempt < self::ACQUIRE_ATTEMPTS; $attempt++) {
            $now = time();
            $record = array(
                'token' => $token,
                'expires_at' => $now + self::STALE_AFTER_SECONDS,
            );
            if ($this->add($name, $record)) {
                return $record;
            }

            $current = $this->get($name, null);
            if (is_array($current) && (int) ($current['expires_at'] ?? 0) < $now) {
                $this->deleteIfUnchanged($name, $current);
                continue;
            }

            usleep(self::RETRY_DELAY_MICROSECONDS);
        }

        return null;
    }

    private function storageAvailable(): bool
    {
        if ($this->networkScoped) {
            return function_exists('add_site_option') && function_exists('get_site_option') && function_exists('delete_site_option');
        }
        return function_exists('add_option') && function_exists('get_option') && function_exists('delete_option');
    }

    private function add(string $name, array $record): bool
    {
        return $this->networkScoped
            ? (bool) add_site_option($name, $record)
            : (bool) add_option($name, $record, '', false);
    }

    private function get(string $name, $default)
    {
        return $this->networkScoped ? get_site_option($name, $default) : get_option($name, $default);
    }

    private function delete(string $name): bool
    {
        return $this->networkScoped ? (bool) delete_site_option($name) : (bool) delete_option($name);
    }

    /** @param array<string,mixed> $record */
    private function deleteIfUnchanged(string $name, array $record): bool
    {
        global $wpdb;

        if (!$this->networkScoped
            && is_object($wpdb)
            && isset($wpdb->options)
            && method_exists($wpdb, 'delete')
            && function_exists('maybe_serialize')) {
            $deleted = (int) $wpdb->delete(
                $wpdb->options,
                array('option_name' => $name, 'option_value' => maybe_serialize($record)),
                array('%s', '%s')
            );
            if ($deleted > 0 && function_exists('wp_cache_delete')) {
                wp_cache_delete($name, 'options');
            }
            return $deleted > 0;
        }

        if ($this->networkScoped
            && is_object($wpdb)
            && isset($wpdb->sitemeta)
            && method_exists($wpdb, 'delete')
            && function_exists('maybe_serialize')) {
            $networkId = function_exists('get_current_network_id') ? max(1, (int) get_current_network_id()) : 1;
            $deleted = (int) $wpdb->delete(
                $wpdb->sitemeta,
                array('site_id' => $networkId, 'meta_key' => $name, 'meta_value' => maybe_serialize($record)),
                array('%d', '%s', '%s')
            );
            if ($deleted > 0 && function_exists('wp_cache_delete')) {
                wp_cache_delete($networkId . ':' . $name, 'site-options');
            }
            return $deleted > 0;
        }

        if ($this->get($name, null) !== $record) {
            return false;
        }
        return $this->delete($name);
    }
}
