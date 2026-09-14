<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Multisite;

final class SiteDirectory
{
    /** @return array<int,array{site_id:int,name:string,url:string,is_main:bool}> */
    public function accessibleSites(int $userId): array
    {
        if (!$this->isMultisite() || $userId < 1) {
            return array();
        }

        $siteIds = array();
        if (function_exists('is_super_admin') && is_super_admin($userId) && function_exists('get_sites')) {
            $siteIds = get_sites(array(
                'network_id' => $this->networkId(),
                'fields' => 'ids',
                'number' => 0,
                'spam' => 0,
                'deleted' => 0,
                'archived' => 0,
            ));
        } elseif (function_exists('get_blogs_of_user')) {
            foreach ((array) get_blogs_of_user($userId) as $blog) {
                $siteId = (int) ($blog->userblog_id ?? $blog->blog_id ?? 0);
                if ($siteId > 0) {
                    $siteIds[] = $siteId;
                }
            }
        }

        $siteIds = array_values(array_unique(array_filter(array_map('intval', $siteIds), static fn (int $siteId): bool => $siteId > 0)));
        sort($siteIds, SORT_NUMERIC);

        $sites = array();
        foreach ($siteIds as $siteId) {
            if (!$this->canAccessSite($userId, $siteId)) {
                continue;
            }
            $site = $this->describeSite($siteId);
            if ($site !== null) {
                $sites[] = $site;
            }
        }

        return $sites;
    }

    public function canAccessSite(int $userId, int $siteId): bool
    {
        if (!$this->isMultisite() || $userId < 1 || $siteId < 1 || !$this->isActiveNetworkSite($siteId)) {
            return false;
        }

        if (function_exists('is_super_admin') && is_super_admin($userId)) {
            return true;
        }

        if (function_exists('user_can_for_site')) {
            return (bool) user_can_for_site($userId, $siteId, 'read');
        }

        if (!function_exists('get_blogs_of_user')) {
            return false;
        }

        foreach ((array) get_blogs_of_user($userId) as $blog) {
            if ((int) ($blog->userblog_id ?? $blog->blog_id ?? 0) === $siteId) {
                return true;
            }
        }

        return false;
    }

    public function isGatewaySite(): bool
    {
        return $this->isMultisite() && $this->currentSiteId() === $this->mainSiteId();
    }

    public function currentSiteId(): int
    {
        return function_exists('get_current_blog_id') ? max(1, (int) get_current_blog_id()) : 1;
    }

    public function networkId(): int
    {
        return function_exists('get_current_network_id') ? max(1, (int) get_current_network_id()) : 1;
    }

    public function mainSiteId(): int
    {
        if (function_exists('get_main_site_id')) {
            return max(1, (int) get_main_site_id($this->networkId()));
        }
        if (defined('BLOG_ID_CURRENT_SITE')) {
            return max(1, (int) BLOG_ID_CURRENT_SITE);
        }
        return 1;
    }

    public function belongsToCurrentNetwork(int $siteId): bool
    {
        if ($siteId < 1) {
            return false;
        }

        if (function_exists('get_site')) {
            $site = get_site($siteId);
            if (!$site) {
                return false;
            }
            $networkId = isset($site->network_id) ? (int) $site->network_id : $this->networkId();
            return $networkId === $this->networkId();
        }

        if (!function_exists('get_sites')) {
            return $siteId === $this->currentSiteId();
        }

        $ids = get_sites(array('network_id' => $this->networkId(), 'fields' => 'ids', 'number' => 0));
        return in_array($siteId, array_map('intval', (array) $ids), true);
    }

    private function isActiveNetworkSite(int $siteId): bool
    {
        if (!$this->belongsToCurrentNetwork($siteId)) {
            return false;
        }

        $site = function_exists('get_site') ? get_site($siteId) : (function_exists('get_blog_details') ? get_blog_details($siteId) : null);
        if (!is_object($site)) {
            return true;
        }

        foreach (array('archived', 'spam', 'deleted') as $field) {
            if (!empty($site->{$field})) {
                return false;
            }
        }

        return true;
    }

    private function isMultisite(): bool
    {
        return function_exists('is_multisite') && is_multisite();
    }

    /** @return array{site_id:int,name:string,url:string,is_main:bool}|null */
    private function describeSite(int $siteId): ?array
    {
        if (!$this->belongsToCurrentNetwork($siteId)) {
            return null;
        }

        $details = function_exists('get_blog_details') ? get_blog_details($siteId) : null;
        $name = is_object($details) && isset($details->blogname) ? trim((string) $details->blogname) : '';
        if ($name === '') {
            $name = 'Site ' . $siteId;
        }

        if (function_exists('get_home_url')) {
            $url = (string) get_home_url($siteId, '/');
        } elseif (is_object($details) && isset($details->home)) {
            $url = (string) $details->home;
        } else {
            $url = '';
        }

        return array(
            'site_id' => $siteId,
            'name' => $name,
            'url' => $url,
            'is_main' => $siteId === $this->mainSiteId(),
        );
    }
}
