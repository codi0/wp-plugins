<?php

declare(strict_types=1);

namespace CodiMcp\Core\Deployment;

final class NetworkAssetUsage
{
    public function pluginActiveAnywhere(string $pluginFile): bool
    {
        $pluginFile = trim($pluginFile);
        if ($pluginFile === '') {
            return false;
        }

        if (!is_multisite()) {
            return in_array($pluginFile, (array) get_option('active_plugins', array()), true);
        }

        $networkActive = (array) get_site_option('active_sitewide_plugins', array());
        if (array_key_exists($pluginFile, $networkActive)) {
            return true;
        }

        foreach ($this->siteIds() as $siteId) {
            if (in_array($pluginFile, (array) get_blog_option($siteId, 'active_plugins', array()), true)) {
                return true;
            }
        }

        return false;
    }

    public function themeOrChildActiveAnywhere(string $stylesheet): bool
    {
        $stylesheet = trim($stylesheet);
        if ($stylesheet === '') {
            return false;
        }

        if (!is_multisite()) {
            return (string) get_option('stylesheet', '') === $stylesheet
                || (string) get_option('template', '') === $stylesheet;
        }

        foreach ($this->siteIds() as $siteId) {
            if ((string) get_blog_option($siteId, 'stylesheet', '') === $stylesheet
                || (string) get_blog_option($siteId, 'template', '') === $stylesheet) {
                return true;
            }
        }

        return false;
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
