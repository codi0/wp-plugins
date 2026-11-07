<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Themes;

use CodiMcp\Core\Downloads\ArtifactDownloadStore;
use CodiMcp\Core\Downloads\DownloadCapabilityService;
use CodiMcp\Core\Archives\ZipArchiveGuard;
use CodiMcp\Core\Deployment\UpgraderResult;
use CodiMcp\Core\Deployment\CorePackageInstaller;
use CodiMcp\Core\Deployment\DeploymentRuntimeVerifier;
use CodiMcp\Core\Deployment\NetworkAssetUsage;
use CodiMcp\Core\Deployment\FolderReplacement;
use CodiMcp\Core\Deployment\ReplacementSelection;
use CodiMcp\Core\Uploads\UploadCapabilityService;
use CodiMcp\Core\Uploads\UploadStore;

final class ThemeDeployment
{
    private const UPLOAD_PURPOSE = 'themes.install';
    private const DOWNLOAD_PURPOSE = 'themes.export';

    private UploadCapabilityService $uploads;
    private ArtifactDownloadStore $downloads;
    private FolderReplacement $folderReplacement;

    public function __construct(private int $maxZipBytes, int $downloadChunkBytes, ?UploadCapabilityService $uploads = null, ?ArtifactDownloadStore $downloads = null, ?FolderReplacement $folderReplacement = null)
    {
        $this->uploads = $uploads ?? new UploadCapabilityService(new UploadStore($maxZipBytes));
        $this->downloads = $downloads ?? new ArtifactDownloadStore($maxZipBytes, $downloadChunkBytes);
        $this->folderReplacement = $folderReplacement ?? new FolderReplacement();
    }

    public function canInstallThemes(): bool
    {
        if (!current_user_can('install_themes') || !current_user_can('update_themes')) {
            return false;
        }
        return !is_multisite() || is_super_admin();
    }

    public function canActivateThemes(): bool
    {
        return current_user_can('switch_themes');
    }

    public function canDeleteThemes(): bool
    {
        if (!current_user_can('delete_themes')) {
            return false;
        }

        return !is_multisite() || is_super_admin();
    }

    public function canExportThemes(): bool
    {
        if (!current_user_can('update_themes')) {
            return false;
        }
        return !is_multisite() || is_super_admin();
    }

    public function upload($input)
    {
        $input = is_array($input) ? $input : array();
        $stylesheet = trim((string) ($input['stylesheet'] ?? ''));
        $size = (int) ($input['size'] ?? 0);
        $sha256 = strtolower(trim((string) ($input['sha256'] ?? '')));

        $validation = $this->validateUploadDeclaration($stylesheet, $size, $sha256);
        if ($validation instanceof \WP_Error) {
            return $validation;
        }

        $selection = ReplacementSelection::fromInput($input);
        if ($selection instanceof \WP_Error) {
            return $selection;
        }
        $metadata = array('stylesheet' => $stylesheet, 'filename' => $stylesheet . '.zip', 'replace' => $selection['replace']);
        if ($selection['replace'] === 'folder') {
            $target = $this->installedFolderTarget($stylesheet);
            if ($target instanceof \WP_Error) {
                return $target;
            }
            $captured = $this->folderReplacement->capture($target['root'], $selection['folder']);
            if ($captured instanceof \WP_Error) {
                return $captured;
            }
            $metadata['replacement'] = $captured;
        }

        $result = $this->uploads->create(
            self::UPLOAD_PURPOSE,
            $size,
            $sha256,
            'application/zip',
            $metadata
        );
        if ($result instanceof \WP_Error) {
            return $result;
        }
        $result['replace'] = $selection['replace'];
        $result['folder'] = $selection['folder'];
        $result['instruction'] = 'PUT bytes directly to upload.url, then call codi/theme-install with upload_id. Choose the wrapped folder layout for folder replacement.';
        return $result;
    }

    public function install($input)
    {
        $input = is_array($input) ? $input : array();
        $uploadId = trim((string) ($input['upload_id'] ?? ''));
        $upload = $this->uploads->complete($uploadId, self::UPLOAD_PURPOSE);
        if ($upload instanceof \WP_Error) {
            return $upload;
        }
        $metadata = (array) ($upload['meta']['metadata'] ?? array());
        $stylesheet = (string) ($metadata['stylesheet'] ?? '');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/D', $stylesheet)) {
            return new \WP_Error('codi_mcp_bad_metadata', 'Upload theme identity is invalid.');
        }
        $root = rtrim((string) get_theme_root($stylesheet), '/\\') . '/' . $stylesheet;
        $replacement = $this->folderReplacement;
        return $replacement->locked($root, function () use ($replacement, $uploadId, $stylesheet) {
            $upload = $this->uploads->complete($uploadId, self::UPLOAD_PURPOSE);
            if ($upload instanceof \WP_Error) {
                return $upload;
            }
            $metadata = (array) ($upload['meta']['metadata'] ?? array());
            $mode = array_key_exists('replace', $metadata) ? $metadata['replace'] : 'entire';
            if ($mode === 'entire') {
                $result = $this->installEntire($upload, $uploadId);
            } elseif ($mode === 'folder' && is_array($metadata['replacement'] ?? null)) {
                $selection = $metadata['replacement'];
                $target = $this->installedFolderTarget($stylesheet);
                if ($target instanceof \WP_Error) {
                    return $target;
                }
                $siteIds = (new NetworkAssetUsage())->themeActiveSiteIds($stylesheet);
                $runtimeVerifier = $siteIds === array() ? null : new DeploymentRuntimeVerifier();
                $result = $replacement->replace(
                    $target['root'],
                    $selection,
                    (string) $upload['path'],
                    $stylesheet,
                    $uploadId,
                    $runtimeVerifier === null ? null : static fn () => $runtimeVerifier->verify($siteIds)
                );
                if ($result instanceof \WP_Error) {
                    return $result;
                }
                if (function_exists('wp_clean_themes_cache')) {
                    wp_clean_themes_cache(true);
                }
                $this->uploads->consume($uploadId, self::UPLOAD_PURPOSE);
                $theme = wp_get_theme($stylesheet);
                $result = array(
                    'action' => 'updated',
                    'stylesheet' => $stylesheet,
                    'name' => (string) $theme->get('Name'),
                    'version' => (string) $theme->get('Version'),
                    'active' => get_stylesheet() === $stylesheet,
                );
            } else {
                return new \WP_Error('codi_mcp_bad_metadata', 'Upload replacement selection is invalid.');
            }
            if (is_array($result)) {
                $result['replace'] = $mode;
                $result['folder'] = $mode === 'folder' ? $metadata['replacement']['folder'] : '';
            }
            return $result;
        });
    }

    private function installEntire(array $upload, string $uploadId)
    {
        $metadata = is_array($upload['meta']['metadata'] ?? null) ? $upload['meta']['metadata'] : array();
        $stylesheet = (string) ($metadata['stylesheet'] ?? '');
        if ($stylesheet === '') {
            return new \WP_Error('codi_mcp_bad_metadata', 'Upload metadata is invalid.');
        }
        $zipPath = (string) ($upload['path'] ?? '');
        $package = $this->inspectThemeZip($zipPath, $stylesheet, $uploadId);
        if ($package instanceof \WP_Error) {
            return $package;
        }
        $preparedPackage = $this->prepareInstallPackage($zipPath, $stylesheet, $uploadId, (string) ($package['layout'] ?? 'wrapped'));
        if ($preparedPackage instanceof \WP_Error) {
            return $preparedPackage;
        }
        $installZipPath = (string) ($preparedPackage['path'] ?? $zipPath);

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        $installedBefore = (array) wp_get_themes(array('errors' => null, 'allowed' => null));
        $existed = isset($installedBefore[$stylesheet]);
        $skin = new \Automatic_Upgrader_Skin();
        $upgrader = new \Theme_Upgrader($skin);
        $siteIds = (new NetworkAssetUsage())->themeActiveSiteIds($stylesheet);
        $runtimeVerifier = $siteIds === array() ? null : new DeploymentRuntimeVerifier();
        $bufferLevel = ob_get_level();
        ob_start();
        try {
            $result = (new CorePackageInstaller(
                $runtimeVerifier === null ? null : static fn () => $runtimeVerifier->verify($siteIds)
            ))->install($upgrader, $installZipPath, 'theme', $stylesheet, get_theme_root($stylesheet), $siteIds !== array());
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            $this->cleanupPreparedInstallPackage($preparedPackage);
        }
        $upgraderError = UpgraderResult::error($result, 'theme');
        if ($upgraderError instanceof \WP_Error) {
            return $upgraderError;
        }
        if (function_exists('wp_clean_themes_cache')) {
            wp_clean_themes_cache(true);
        }
        $theme = function_exists('wp_get_theme') ? wp_get_theme($stylesheet) : null;
        if (!is_object($theme) || (method_exists($theme, 'exists') && !$theme->exists())) {
            return new \WP_Error('codi_mcp_theme_install_failed', 'Installed theme could not be resolved after installation.');
        }
        $this->uploads->consume($uploadId, self::UPLOAD_PURPOSE);
        return array(
            'action' => $existed ? 'updated' : 'installed',
            'stylesheet' => $stylesheet,
            'name' => method_exists($theme, 'get') ? (string) $theme->get('Name') : (string) ($package['name'] ?? ''),
            'version' => method_exists($theme, 'get') ? (string) $theme->get('Version') : (string) ($package['version'] ?? ''),
            'active' => function_exists('get_stylesheet') && get_stylesheet() === $stylesheet,
        );
    }

    public function activate($input)
    {
        $input = is_array($input) ? $input : array();
        $stylesheet = trim((string) ($input['stylesheet'] ?? ''));
        $themes = function_exists('wp_get_themes') ? (array) wp_get_themes(array('errors' => null, 'allowed' => null)) : array();
        $theme = $themes[$stylesheet] ?? null;
        if ($stylesheet === '' || !is_object($theme)) {
            return new \WP_Error('codi_mcp_theme_not_found', 'The requested installed theme was not found.');
        }
        $errors = method_exists($theme, 'errors') ? $theme->errors() : false;
        if (is_wp_error($errors)) {
            return new \WP_Error('codi_mcp_theme_broken', 'The requested theme has WordPress-detected errors and cannot be activated safely.');
        }
        $active = function_exists('get_stylesheet') ? (string) get_stylesheet() : (string) get_option('stylesheet', '');
        if (is_multisite() && $stylesheet !== $active) {
            $allowed = (array) wp_get_themes(array('allowed' => true));
            if (!isset($allowed[$stylesheet])) {
                return new \WP_Error('codi_mcp_theme_not_allowed', 'The requested theme is not enabled for this site in multisite.');
            }
        }
        if (function_exists('validate_theme_requirements')) {
            $requirements = validate_theme_requirements($stylesheet);
            if (is_wp_error($requirements)) {
                return $requirements;
            }
        }
        if ($stylesheet !== $active) {
            switch_theme($stylesheet);
        }
        $newActive = function_exists('get_stylesheet') ? (string) get_stylesheet() : (string) get_option('stylesheet', '');
        if ($newActive !== $stylesheet) {
            return new \WP_Error('codi_mcp_theme_switch_failed', 'WordPress did not activate the requested theme.');
        }
        return array('stylesheet' => $stylesheet, 'name' => method_exists($theme, 'get') ? (string) $theme->get('Name') : $stylesheet, 'active' => true, 'changed' => $stylesheet !== $active);
    }

    public function delete($input)
    {
        $stylesheet = (string) (is_array($input) ? ($input['stylesheet'] ?? '') : '');
        $root = rtrim((string) get_theme_root($stylesheet), '/\\') . '/' . $stylesheet;
        return $this->folderReplacement->locked($root, fn () => $this->deleteUnlocked($input));
    }

    private function deleteUnlocked($input)
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/theme.php';
        $input = is_array($input) ? $input : array();
        $stylesheet = trim((string) ($input['stylesheet'] ?? ''));
        $themes = function_exists('wp_get_themes') ? (array) wp_get_themes(array('errors' => null, 'allowed' => null)) : array();
        $theme = $themes[$stylesheet] ?? null;
        if ($stylesheet === '' || !is_object($theme)) {
            return new \WP_Error('codi_mcp_theme_not_found', 'Installed theme was not found.');
        }
        if ((new NetworkAssetUsage())->themeOrChildActiveAnywhere($stylesheet)) {
            return new \WP_Error('codi_mcp_theme_active', 'Theme or one of its child themes is active on at least one site. Switch those sites away before deleting it.');
        }

        $result = delete_theme($stylesheet);
        if (is_wp_error($result)) {
            return $result;
        }
        if ($result === null) {
            return new \WP_Error('codi_mcp_filesystem_credentials_required', 'WordPress requires interactive filesystem credentials and cannot delete this theme through MCP.');
        }
        if ($result !== true) {
            return new \WP_Error('codi_mcp_theme_delete_failed', 'WordPress could not delete the selected theme.');
        }
        return array('stylesheet' => $stylesheet, 'deleted' => true);
    }

    public function export($input)
    {
        $input = is_array($input) ? $input : array();
        $stylesheet = trim((string) ($input['stylesheet'] ?? ''));
        $offset = max(0, (int) ($input['offset'] ?? 0));
        $downloadId = trim((string) ($input['download_id'] ?? ''));
        $themes = function_exists('wp_get_themes') ? (array) wp_get_themes(array('errors' => null, 'allowed' => null)) : array();
        $theme = $themes[$stylesheet] ?? null;
        if ($stylesheet === '' || !is_object($theme)) {
            return new \WP_Error('codi_mcp_theme_not_found', 'Installed theme was not found.');
        }

        if ($downloadId === '') {
            if ($offset !== 0) {
                return new \WP_Error('codi_mcp_bad_offset', 'The first theme-export call must use offset 0.');
            }
            $source = method_exists($theme, 'get_stylesheet_directory') ? $theme->get_stylesheet_directory() : '';
            $themeRoot = function_exists('get_theme_root') ? get_theme_root($stylesheet) : dirname((string) $source);
            $source = realpath((string) $source);
            $themeRoot = realpath((string) $themeRoot);
            if (!is_string($source) || !is_string($themeRoot) || !$this->withinRoot($source, $themeRoot)) {
                return new \WP_Error('codi_mcp_theme_export_scope', 'Theme source directory is outside the WordPress theme root.');
            }

            $isBlockTheme = method_exists($theme, 'is_block_theme') && (bool) $theme->is_block_theme();
            if ($isBlockTheme) {
                $sourceValidation = $this->validateNativeThemeSource($source);
                if ($sourceValidation instanceof \WP_Error) {
                    return $sourceValidation;
                }
                $nativeExport = $this->generateNativeThemeExport($theme, $themes, $stylesheet, $source);
                if ($nativeExport instanceof \WP_Error) {
                    return $nativeExport;
                }
                if (class_exists('ZipArchive') || class_exists('PclZip')) {
                    $archiveValidation = (new ZipArchiveGuard())->validate($nativeExport);
                    if ($archiveValidation instanceof \WP_Error) {
                        @unlink($nativeExport);
                        return $archiveValidation;
                    }
                }
                try {
                    $staged = $this->downloads->stageFile(self::DOWNLOAD_PURPOSE, $nativeExport, $stylesheet . '.zip', array('stylesheet' => $stylesheet, 'layout' => 'wordpress-native'));
                } finally {
                    if (is_file($nativeExport)) {
                        @unlink($nativeExport);
                    }
                }
            } else {
                $staged = $this->downloads->stageDirectory(self::DOWNLOAD_PURPOSE, $source, $stylesheet, $stylesheet . '.zip', array('stylesheet' => $stylesheet, 'layout' => 'wrapped-directory'));
            }
            if ($staged instanceof \WP_Error) {
                return $staged;
            }
            $downloadId = (string) ($staged['download_id'] ?? '');
        } else {
            $download = $this->downloads->get($downloadId, self::DOWNLOAD_PURPOSE);
            if ($download instanceof \WP_Error) {
                return $download;
            }
            $metadata = is_array($download['meta']['metadata'] ?? null) ? $download['meta']['metadata'] : array();
            if ($stylesheet !== (string) ($metadata['stylesheet'] ?? '')) {
                return new \WP_Error('codi_mcp_download_metadata_mismatch', 'stylesheet must match the generated theme artifact.');
            }
        }
        $chunk = (new DownloadCapabilityService($this->downloads))->response($downloadId, self::DOWNLOAD_PURPOSE, $input);
        if ($chunk instanceof \WP_Error) {
            return $chunk;
        }
        unset($chunk['meta']);
        $chunk['stylesheet'] = $stylesheet;
        return $chunk;
    }

    private function installedFolderTarget(string $stylesheet): array|\WP_Error
    {
        if (!function_exists('get_filesystem_method')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $themes = (array) wp_get_themes(array('errors' => null, 'allowed' => null));
        $theme = $themes[$stylesheet] ?? null;
        if (!is_object($theme) || !method_exists($theme, 'get_stylesheet_directory')) {
            return new \WP_Error('codi_mcp_theme_not_found', 'Folder replacement requires an installed theme.');
        }
        $root = (string) $theme->get_stylesheet_directory();
        $expected = rtrim((string) get_theme_root($stylesheet), '/\\') . '/' . $stylesheet;
        if (realpath($root) === false || realpath($root) !== realpath($expected)) {
            return new \WP_Error('codi_mcp_theme_scope', 'Installed theme directory does not match its registered theme root.');
        }
        return array('root' => $root);
    }

    private function validateUploadDeclaration(string $stylesheet, int $size, string $sha256)
    {
        if (1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $stylesheet)) {
            return new \WP_Error('codi_mcp_theme_stylesheet_invalid', 'Invalid theme stylesheet.');
        }
        if ($size < 1 || $size > $this->maxZipBytes) {
            return new \WP_Error('codi_mcp_bad_size', 'ZIP size is outside the allowed range.');
        }
        if (1 !== preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            return new \WP_Error('codi_mcp_bad_hash', 'Invalid SHA-256.');
        }
        return true;
    }

    private function inspectThemeZip(string $zipPath, string $expectedStylesheet, string $uploadId)
    {
        $archiveValidation = (new ZipArchiveGuard())->validate($zipPath);
        if ($archiveValidation instanceof \WP_Error) {
            return $archiveValidation;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        if (!WP_Filesystem()) {
            return new \WP_Error('codi_mcp_filesystem', 'WordPress could not initialise filesystem access.');
        }
        global $wp_filesystem;
        $temporary = function_exists('get_temp_dir') ? (string) get_temp_dir() : sys_get_temp_dir();
        $inspectDir = rtrim($temporary, "\\/") . DIRECTORY_SEPARATOR . 'codi-mcp' . DIRECTORY_SEPARATOR . 'theme-inspect-' . $uploadId;
        if ($wp_filesystem->is_dir($inspectDir)) {
            $wp_filesystem->delete($inspectDir, true);
        }
        if (!wp_mkdir_p($inspectDir)) {
            return new \WP_Error('codi_mcp_storage', 'Could not create the theme package inspection directory.');
        }
        $unzipped = unzip_file($zipPath, $inspectDir);
        if (is_wp_error($unzipped)) {
            $wp_filesystem->delete($inspectDir, true);
            return $unzipped;
        }
        $entries = array_values(array_filter((array) scandir($inspectDir), static fn (string $entry): bool => !in_array($entry, array('.', '..', '__MACOSX'), true)));
        $wrappedThemeDir = $inspectDir . DIRECTORY_SEPARATOR . $expectedStylesheet;
        $layout = 'wrapped';
        if (count($entries) === 1 && $entries[0] === $expectedStylesheet && is_dir($wrappedThemeDir) && is_file($wrappedThemeDir . DIRECTORY_SEPARATOR . 'style.css')) {
            $themeDir = $wrappedThemeDir;
        } elseif (is_file($inspectDir . DIRECTORY_SEPARATOR . 'style.css')) {
            $themeDir = $inspectDir;
            $layout = 'root';
        } else {
            $wp_filesystem->delete($inspectDir, true);
            return new \WP_Error('codi_mcp_bad_theme_package', 'ZIP must contain either one top-level directory matching stylesheet or a native WordPress root-level theme, with a style.css file.');
        }
        $headers = function_exists('get_file_data') ? get_file_data($themeDir . DIRECTORY_SEPARATOR . 'style.css', array('Name' => 'Theme Name', 'Version' => 'Version'), 'theme') : array();
        $wp_filesystem->delete($inspectDir, true);
        if (trim((string) ($headers['Name'] ?? '')) === '') {
            return new \WP_Error('codi_mcp_bad_theme_package', 'Theme style.css does not contain a valid Theme Name header.');
        }
        return array('name' => (string) $headers['Name'], 'version' => (string) ($headers['Version'] ?? ''), 'layout' => $layout);
    }

    /** @param array<string,object> $themes @return string|\WP_Error */
    private function generateNativeThemeExport(object $theme, array $themes, string $stylesheet, string $stylesheetDirectory): string|\WP_Error
    {
        if (!function_exists('wp_generate_block_templates_export_file')) {
            return new \WP_Error('codi_mcp_theme_export_unavailable', 'WordPress native block-theme export is unavailable.');
        }

        $template = method_exists($theme, 'get_template') ? trim((string) $theme->get_template()) : $stylesheet;
        if ($template === '') {
            $template = $stylesheet;
        }
        $templateDirectory = $stylesheetDirectory;
        if ($template !== $stylesheet) {
            $templateTheme = $themes[$template] ?? null;
            $candidate = is_object($templateTheme) && method_exists($templateTheme, 'get_stylesheet_directory')
                ? (string) $templateTheme->get_stylesheet_directory()
                : (function_exists('get_theme_root') ? rtrim((string) get_theme_root($template), "\\/") . DIRECTORY_SEPARATOR . $template : '');
            $candidateRoot = function_exists('get_theme_root') ? realpath((string) get_theme_root($template)) : false;
            $candidate = realpath($candidate);
            if (!is_string($candidate) || !is_string($candidateRoot) || !$this->withinRoot($candidate, $candidateRoot)) {
                return new \WP_Error('codi_mcp_theme_export_parent_scope', 'Parent theme source directory is outside the WordPress theme root.');
            }
            $parentValidation = $this->validateNativeThemeSource($candidate);
            if ($parentValidation instanceof \WP_Error) {
                return $parentValidation;
            }
            $templateDirectory = $candidate;
        }

        $stylesheetFilter = static fn ($value): string => $stylesheet;
        $templateFilter = static fn ($value): string => $template;
        $stylesheetDirectoryFilter = static fn ($value): string => $stylesheetDirectory;
        $templateDirectoryFilter = static fn ($value): string => $templateDirectory;
        $priority = PHP_INT_MAX;

        add_filter('stylesheet', $stylesheetFilter, $priority, 1);
        add_filter('template', $templateFilter, $priority, 1);
        add_filter('stylesheet_directory', $stylesheetDirectoryFilter, $priority, 3);
        add_filter('template_directory', $templateDirectoryFilter, $priority, 3);
        $this->cleanThemeJsonResolverCache();
        try {
            $result = wp_generate_block_templates_export_file();
        } catch (\Throwable $exception) {
            return new \WP_Error('codi_mcp_theme_export_failed', 'WordPress native theme export failed.');
        } finally {
            remove_filter('template_directory', $templateDirectoryFilter, $priority);
            remove_filter('stylesheet_directory', $stylesheetDirectoryFilter, $priority);
            remove_filter('template', $templateFilter, $priority);
            remove_filter('stylesheet', $stylesheetFilter, $priority);
            $this->cleanThemeJsonResolverCache();
        }

        if ($result instanceof \WP_Error) {
            return $result;
        }
        $path = is_string($result) ? realpath($result) : false;
        if (!is_string($path) || !is_file($path)) {
            return new \WP_Error('codi_mcp_theme_export_failed', 'WordPress did not produce a readable theme export ZIP.');
        }
        return $path;
    }

    /** @return true|\WP_Error */
    private function validateNativeThemeSource(string $sourceRoot): true|\WP_Error
    {
        try {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $info) {
                if (!$info instanceof \SplFileInfo) {
                    continue;
                }
                $path = $info->getPathname();
                $relative = ltrim(str_replace('\\', '/', substr($path, strlen($sourceRoot))), '/');
                if (function_exists('wp_is_theme_directory_ignored') && wp_is_theme_directory_ignored($relative)) {
                    continue;
                }
                if ($info->isLink()) {
                    return new \WP_Error('codi_mcp_export_symlink', 'Theme export refuses symbolic links included by the WordPress native exporter.');
                }
                if (!$info->isFile()) {
                    continue;
                }
                $resolved = $info->getRealPath();
                if (!is_string($resolved) || !$this->withinRoot($resolved, $sourceRoot)) {
                    return new \WP_Error('codi_mcp_export_scope', 'Theme export source resolves outside the selected theme directory.');
                }
            }
        } catch (\Throwable $exception) {
            return new \WP_Error('codi_mcp_export_scan_failed', 'Theme export source could not be scanned safely.');
        }
        return true;
    }

    private function cleanThemeJsonResolverCache(): void
    {
        if (class_exists('WP_Theme_JSON_Resolver') && method_exists('WP_Theme_JSON_Resolver', 'clean_cached_data')) {
            \WP_Theme_JSON_Resolver::clean_cached_data();
        }
    }

    /** @return array{path:string,directory:string}|\WP_Error */
    private function prepareInstallPackage(string $zipPath, string $stylesheet, string $uploadId, string $layout): array|\WP_Error
    {
        if ($layout !== 'root') {
            return array('path' => $zipPath, 'directory' => '');
        }
        $temporary = function_exists('get_temp_dir') ? (string) get_temp_dir() : sys_get_temp_dir();
        $directory = rtrim($temporary, "\\/") . DIRECTORY_SEPARATOR . 'codi-mcp' . DIRECTORY_SEPARATOR . 'theme-install-' . $uploadId;
        $created = is_dir($directory) || (function_exists('wp_mkdir_p') ? wp_mkdir_p($directory) : @mkdir($directory, 0700, true));
        if (!$created) {
            return new \WP_Error('codi_mcp_storage', 'Could not create the temporary native-theme installation directory.');
        }
        $preparedPath = $directory . DIRECTORY_SEPARATOR . $stylesheet . '.zip';
        if (!@copy($zipPath, $preparedPath)) {
            @rmdir($directory);
            return new \WP_Error('codi_mcp_storage', 'Could not prepare the native WordPress theme ZIP for installation.');
        }
        return array('path' => $preparedPath, 'directory' => $directory);
    }

    /** @param array{path?:string,directory?:string} $preparedPackage */
    private function cleanupPreparedInstallPackage(array $preparedPackage): void
    {
        $directory = (string) ($preparedPackage['directory'] ?? '');
        if ($directory === '') {
            return;
        }
        $path = (string) ($preparedPackage['path'] ?? '');
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
        @rmdir($directory);
    }

    private function withinRoot(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');
        return $path === $root || str_starts_with($path, $root . '/');
    }
}
