<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Multisite;

final class ReplayGuard
{
    private const PREFIX = 'codi_mcp_fed_nonce_';
    private const INDEX = 'codi_mcp_fed_nonce_index';
    private const MAX_TRACKED = 4096;

    private SiteDirectory $sites;
    private $clock;

    public function __construct(SiteDirectory $sites, ?callable $clock = null)
    {
        $this->sites = $sites;
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function consume(string $nonce, int $expiresAt): bool
    {
        $now = (int) call_user_func($this->clock);
        if ($nonce === '' || $expiresAt < $now) {
            return false;
        }

        $networkId = $this->sites->networkId();
        $index = $this->getOption($networkId, self::INDEX, array());
        $index = is_array($index) ? $index : array();

        foreach ($index as $key => $expiry) {
            if (!is_string($key) || (int) $expiry < $now) {
                if (is_string($key)) {
                    $this->deleteOption($networkId, $key);
                }
                unset($index[$key]);
            }
        }

        $key = self::PREFIX . hash('sha256', $nonce);
        $existing = $this->getOption($networkId, $key, false);
        if ($existing !== false && (int) $existing >= $now) {
            return false;
        }
        if ($existing !== false) {
            $this->deleteOption($networkId, $key);
        }

        if (count($index) >= self::MAX_TRACKED) {
            return false;
        }
        if (!$this->addOption($networkId, $key, $expiresAt)) {
            return false;
        }

        $index[$key] = $expiresAt;
        asort($index, SORT_NUMERIC);
        $this->updateOption($networkId, self::INDEX, $index);
        return true;
    }

    private function getOption(int $networkId, string $key, $default)
    {
        if (function_exists('get_network_option')) {
            return get_network_option($networkId, $key, $default);
        }
        return function_exists('get_site_option') ? get_site_option($key, $default) : $default;
    }

    private function addOption(int $networkId, string $key, $value): bool
    {
        if (function_exists('add_network_option')) {
            return (bool) add_network_option($networkId, $key, $value);
        }
        return function_exists('add_site_option') && (bool) add_site_option($key, $value);
    }

    private function updateOption(int $networkId, string $key, $value): bool
    {
        if (function_exists('update_network_option')) {
            return (bool) update_network_option($networkId, $key, $value);
        }
        return function_exists('update_site_option') && (bool) update_site_option($key, $value);
    }

    private function deleteOption(int $networkId, string $key): bool
    {
        if (function_exists('delete_network_option')) {
            return (bool) delete_network_option($networkId, $key);
        }
        return function_exists('delete_site_option') && (bool) delete_site_option($key);
    }
}
