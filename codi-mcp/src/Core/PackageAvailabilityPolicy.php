<?php

namespace CodiMcp\Core;

final class PackageAvailabilityPolicy
{
    public const ENABLED = 'enabled';
    public const DISABLED = 'disabled';
    public const DELEGATE = 'delegate';

    private const NETWORK_OPTION = 'codi_mcp_package_policy';
    private const SITE_OPTION = 'codi_mcp_site_packages';

    /** @var array<string,string>|null */
    private ?array $networkStates = null;

    /** @var array<string,bool>|null */
    private ?array $siteStates = null;

    public function networkState(string $packageKey): string
    {
        $packageKey = $this->normalizeKey($packageKey);
        if ($packageKey === '') {
            return self::DISABLED;
        }

        if (!$this->isMultisite()) {
            return self::ENABLED;
        }

        $state = $this->networkStates()[$packageKey] ?? $this->defaultNetworkState($packageKey);
        if ($packageKey === 'multisite' && $state === self::DELEGATE) {
            return self::DISABLED;
        }

        return $state;
    }

    public function isPackageEnabled(string $packageKey): bool
    {
        $packageKey = $this->normalizeKey($packageKey);
        if ($packageKey === '') {
            return false;
        }

        if (!$this->isMultisite()) {
            return true;
        }

        $state = $this->networkState($packageKey);
        if ($state === self::ENABLED) {
            return true;
        }
        if ($state === self::DISABLED) {
            return false;
        }

        return $this->siteEnabled($packageKey);
    }

    public function siteEnabled(string $packageKey): bool
    {
        $packageKey = $this->normalizeKey($packageKey);
        if ($packageKey === '') {
            return false;
        }

        $states = $this->siteStates();
        return array_key_exists($packageKey, $states) ? (bool) $states[$packageKey] : true;
    }

    /**
     * @param array<string,string> $states
     * @param string[] $packageKeys
     */
    public function saveNetworkStates(array $states, array $packageKeys): bool
    {
        if (!$this->isMultisite()) {
            return true;
        }

        $clean = array();
        foreach ($packageKeys as $packageKey) {
            $packageKey = $this->normalizeKey($packageKey);
            if ($packageKey === '') {
                continue;
            }

            $requested = isset($states[$packageKey]) && is_string($states[$packageKey])
                ? strtolower(trim($states[$packageKey]))
                : $this->defaultNetworkState($packageKey);

            if ($packageKey === 'multisite') {
                $clean[$packageKey] = $requested === self::ENABLED ? self::ENABLED : self::DISABLED;
                continue;
            }

            $clean[$packageKey] = in_array($requested, array(self::ENABLED, self::DISABLED, self::DELEGATE), true)
                ? $requested
                : self::DELEGATE;
        }

        if (!$this->writeNetworkOption($clean)) {
            $this->networkStates = null;
            return false;
        }
        $this->networkStates = $clean;
        return true;
    }

    /**
     * @param string[] $enabledPackageKeys
     * @param string[] $delegatedPackageKeys
     */
    public function saveSiteDelegates(array $enabledPackageKeys, array $delegatedPackageKeys): bool
    {
        if (!$this->isMultisite()) {
            return true;
        }

        $enabled = array_fill_keys(array_values(array_filter(array_map([$this, 'normalizeKey'], $enabledPackageKeys))), true);
        $states = $this->siteStates();
        foreach ($delegatedPackageKeys as $packageKey) {
            $packageKey = $this->normalizeKey($packageKey);
            if ($packageKey === '' || $packageKey === 'multisite' || $this->networkState($packageKey) !== self::DELEGATE) {
                continue;
            }
            $states[$packageKey] = isset($enabled[$packageKey]);
        }

        if (!function_exists('update_option') || !function_exists('get_option')) {
            return false;
        }
        update_option(self::SITE_OPTION, $states, false);
        if (get_option(self::SITE_OPTION, array()) !== $states) {
            $this->siteStates = null;
            return false;
        }
        $this->siteStates = $states;
        return true;
    }

    public function isDelegated(string $packageKey): bool
    {
        return $this->networkState($packageKey) === self::DELEGATE;
    }

    public function isNetworkManaged(string $packageKey): bool
    {
        return $this->isMultisite() && $this->networkState($packageKey) === self::ENABLED;
    }

    private function defaultNetworkState(string $packageKey): string
    {
        return $packageKey === 'multisite' ? self::DISABLED : self::DELEGATE;
    }

    /** @return array<string,string> */
    private function networkStates(): array
    {
        if ($this->networkStates !== null) {
            return $this->networkStates;
        }

        $raw = $this->readNetworkOption();
        $this->networkStates = array();
        if (!is_array($raw)) {
            return $this->networkStates;
        }

        foreach ($raw as $packageKey => $state) {
            if (!is_string($packageKey) || !is_string($state)) {
                continue;
            }
            $packageKey = $this->normalizeKey($packageKey);
            $state = strtolower(trim($state));
            if ($packageKey !== '' && in_array($state, array(self::ENABLED, self::DISABLED, self::DELEGATE), true)) {
                $this->networkStates[$packageKey] = $state;
            }
        }

        return $this->networkStates;
    }

    /** @return array<string,bool> */
    private function siteStates(): array
    {
        if ($this->siteStates !== null) {
            return $this->siteStates;
        }

        $raw = function_exists('get_option') ? get_option(self::SITE_OPTION, array()) : array();
        $this->siteStates = array();
        if (!is_array($raw)) {
            return $this->siteStates;
        }

        foreach ($raw as $packageKey => $enabled) {
            if (is_string($packageKey) && $this->normalizeKey($packageKey) !== '') {
                $this->siteStates[$this->normalizeKey($packageKey)] = (bool) $enabled;
            }
        }

        return $this->siteStates;
    }

    private function readNetworkOption()
    {
        $networkId = function_exists('get_current_network_id') ? max(1, (int) get_current_network_id()) : 1;
        if (function_exists('get_network_option')) {
            return get_network_option($networkId, self::NETWORK_OPTION, array());
        }
        return function_exists('get_site_option') ? get_site_option(self::NETWORK_OPTION, array()) : array();
    }

    /** @param array<string,string> $states */
    private function writeNetworkOption(array $states): bool
    {
        $networkId = function_exists('get_current_network_id') ? max(1, (int) get_current_network_id()) : 1;
        if (function_exists('update_network_option')) {
            update_network_option($networkId, self::NETWORK_OPTION, $states);
        } elseif (function_exists('update_site_option')) {
            update_site_option(self::NETWORK_OPTION, $states);
        } else {
            return false;
        }
        return $this->readNetworkOption() === $states;
    }

    private function normalizeKey(string $packageKey): string
    {
        return strtolower(trim($packageKey));
    }

    private function isMultisite(): bool
    {
        return function_exists('is_multisite') && is_multisite();
    }
}
