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
        $map = $this->pluginActiveSiteMap(array($pluginFile));
        return $map[$pluginFile] ?? array();
    }

    /** @param string[] $pluginFiles @return array<string,int[]> */
    public function pluginActiveSiteMap(array $pluginFiles): array
    {
        $pluginFiles = array_values(array_unique(array_filter(array_map(
            static fn ($pluginFile): string => trim((string) $pluginFile),
            $pluginFiles
        ), static fn (string $pluginFile): bool => $pluginFile !== '')));
        sort($pluginFiles, SORT_STRING);
        if ($pluginFiles === array()) {
            return array();
        }

        $map = array_fill_keys($pluginFiles, array());
        $siteIds = $this->siteIds();
        if (!is_multisite()) {
            $active = array_fill_keys((array) get_option('active_plugins', array()), true);
            foreach ($pluginFiles as $pluginFile) {
                if (isset($active[$pluginFile])) {
                    $map[$pluginFile] = $siteIds;
                }
            }
            return $map;
        }

        $networkActive = (array) get_site_option('active_sitewide_plugins', array());
        foreach ($pluginFiles as $pluginFile) {
            if (array_key_exists($pluginFile, $networkActive)) {
                $map[$pluginFile] = $siteIds;
            }
        }
        foreach ($siteIds as $siteId) {
            foreach ((array) get_blog_option($siteId, 'active_plugins', array()) as $pluginFile) {
                $pluginFile = (string) $pluginFile;
                if (array_key_exists($pluginFile, $map) && !array_key_exists($pluginFile, $networkActive)) {
                    $map[$pluginFile][] = $siteId;
                }
            }
        }
        return $map;
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
    public function siteIds(): array
    {
        if (!is_multisite()) {
            return array(max(1, (int) get_current_blog_id()));
        }
        $siteIds = array_values(array_filter(
            array_map('intval', (array) get_sites(array(
                'fields' => 'ids',
                'number' => 0,
                'deleted' => 0,
            ))),
            static fn (int $siteId): bool => $siteId > 0
        ));
        sort($siteIds, SORT_NUMERIC);
        return $siteIds;
    }
}
