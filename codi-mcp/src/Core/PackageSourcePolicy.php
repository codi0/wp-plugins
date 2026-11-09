<?php

declare(strict_types=1);

namespace CodiMcp\Core;

final class PackageSourcePolicy
{
    public const MANAGED = 'managed';
    public const EXTERNAL = 'external';
    public const PROTECTED = 'protected';

    private const OPTION = 'codi_mcp_managed_sources_v1';
    private const PROTECTED_KEY = 'plugin:codi-mcp';
    private const INTRINSIC_MANAGED_KEY = 'plugin:codi-mcp-custom';

    /** @var array<string,bool>|null */
    private ?array $managed = null;

    public function state(string $kind, string $identity): string
    {
        $key = $this->key($kind, $identity);
        if ($key === '') {
            return self::EXTERNAL;
        }
        if ($key === self::PROTECTED_KEY) {
            return self::PROTECTED;
        }
        if ($key === self::INTRINSIC_MANAGED_KEY) {
            return self::MANAGED;
        }
        return isset($this->managed()[$key]) ? self::MANAGED : self::EXTERNAL;
    }

    public function canCustomReplace(string $kind, string $identity, string $mode = 'entire'): bool
    {
        $state = $this->state($kind, $identity);
        if ($state === self::MANAGED) {
            return true;
        }
        return $state === self::PROTECTED && $kind === 'plugin' && $this->slug($identity) === 'codi-mcp' && $mode === 'entire';
    }

    public function canDelete(string $kind, string $identity): bool
    {
        return $this->state($kind, $identity) === self::MANAGED;
    }

    public function isFixed(string $kind, string $identity): bool
    {
        $key = $this->key($kind, $identity);
        return $key === self::PROTECTED_KEY || $key === self::INTRINSIC_MANAGED_KEY;
    }

    /** @param string[] $keys */
    public function saveManaged(array $keys): bool
    {
        $clean = array();
        foreach ($keys as $key) {
            $key = strtolower(trim((string) $key));
            if (!preg_match('/^(plugin|theme):[a-z0-9][a-z0-9._-]{0,99}$/D', $key) || $key === self::PROTECTED_KEY || $key === self::INTRINSIC_MANAGED_KEY) {
                continue;
            }
            $clean[$key] = true;
        }
        ksort($clean, SORT_STRING);
        $value = array_keys($clean);
        if ($this->networkScoped()) {
            if (!function_exists('update_site_option') || !function_exists('get_site_option')) {
                return false;
            }
            update_site_option(self::OPTION, $value);
            $written = get_site_option(self::OPTION, array()) === $value;
        } else {
            if (!function_exists('update_option') || !function_exists('get_option')) {
                return false;
            }
            update_option(self::OPTION, $value, false);
            $written = get_option(self::OPTION, array()) === $value;
        }
        $this->managed = $written ? $clean : null;
        return $written;
    }

    /** @return string[] */
    public function managedKeys(): array
    {
        return array_keys($this->managed());
    }

    public function key(string $kind, string $identity): string
    {
        $kind = strtolower(trim($kind));
        if (!in_array($kind, array('plugin', 'theme'), true)) {
            return '';
        }
        $slug = $kind === 'plugin' ? $this->slug($identity) : strtolower(trim($identity));
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/D', $slug)) {
            return '';
        }
        return $kind . ':' . $slug;
    }

    /** @return array<string,bool> */
    private function managed(): array
    {
        if ($this->managed !== null) {
            return $this->managed;
        }
        $raw = $this->networkScoped() && function_exists('get_site_option')
            ? get_site_option(self::OPTION, array())
            : (function_exists('get_option') ? get_option(self::OPTION, array()) : array());
        $this->managed = array();
        foreach ((array) $raw as $key) {
            $key = strtolower(trim((string) $key));
            if (preg_match('/^(plugin|theme):[a-z0-9][a-z0-9._-]{0,99}$/D', $key) && $key !== self::PROTECTED_KEY && $key !== self::INTRINSIC_MANAGED_KEY) {
                $this->managed[$key] = true;
            }
        }
        return $this->managed;
    }

    private function slug(string $identity): string
    {
        $identity = str_replace('\\', '/', strtolower(trim($identity)));
        if (str_contains($identity, '/')) {
            return (string) explode('/', $identity, 2)[0];
        }
        return preg_replace('/\.php$/', '', $identity) ?? '';
    }

    private function networkScoped(): bool
    {
        return function_exists('is_multisite') && is_multisite();
    }
}