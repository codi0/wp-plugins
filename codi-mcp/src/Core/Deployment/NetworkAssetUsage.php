<?php

declare(strict_types=1);

namespace CodiMcp\Core\Deployment;

final class NetworkAssetUsage
{
    public function pluginActiveAnywhere(string $pluginFile): bool
    {
        return $this->pluginActiveSiteIds($pluginFile) !== array();
    }

    /** @return int[] */
    public function pluginActiveSiteIds(string $pluginFile): array
    {
        $pluginFile = trim($pluginFile);
        if ($pluginFile === '') {
            return array();
        }
        if (!is_multisite()) {
            return in_array($pluginFile, (array) get_option('active_plugins', array()), true)
                ? array(max(1, (int) get_current_blog_id()))
                : array();
        }
        $siteIds = $this->siteIds();
        if (array_key_exists($pluginFile, (array) get_site_option('active_sitewide_plugins', array()))) {
            return $siteIds;
        }
        return array_values(array_filter(
            $siteIds,
            static fn (int $siteId): bool => in_array($pluginFile, (array) get_blog_option($siteId, 'active_plugins', array()), true)
        ));
    }

    public function themeOrChildActiveAnywhere(string $stylesheet): bool
    {
        return $this->themeActiveSiteIds($stylesheet) !== array();
    }

    /** @return int[] */
    public function themeActiveSiteIds(string $stylesheet): array
    {
        $stylesheet = trim($stylesheet);
        if ($stylesheet === '') {
            return array();
        }
        if (!is_multisite()) {
            return ((string) get_option('stylesheet', '') === $stylesheet || (string) get_option('template', '') === $stylesheet)
                ? array(max(1, (int) get_current_blog_id()))
                : array();
        }
        return array_values(array_filter(
            $this->siteIds(),
            static fn (int $siteId): bool => (string) get_blog_option($siteId, 'stylesheet', '') === $stylesheet
                || (string) get_blog_option($siteId, 'template', '') === $stylesheet
        ));
    }

    /** @return int[] */
    private function siteIds(): array
    {
        return array_values(array_filter(
            array_map('intval', (array) get_sites(array(
                'fields' => 'ids',
                'number' => 0,
                'deleted' => 0,
            ))),
            static fn (int $siteId): bool => $siteId > 0
        ));
    }
}
