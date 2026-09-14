<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Themes;

use CodiMcp\Core\Downloads\ArtifactDownloadStore;
use CodiMcp\Core\Archives\ZipArchiveGuard;
use CodiMcp\Core\Deployment\UpgraderResult;
use CodiMcp\Core\Uploads\UploadCapabilityService;
use CodiMcp\Core\Uploads\UploadStore;

final class ThemeDeployment
{
    private const UPLOAD_PURPOSE = 'themes.install';
    private const DOWNLOAD_PURPOSE = 'themes.export';

    private UploadCapabilityService $uploads;
    private ArtifactDownloadStore $downloads;

    public function __construct(private int $maxZipBytes, int $downloadChunkBytes, ?UploadCapabilityService $uploads = null, ?ArtifactDownloadStore $downloads = null)
    {
        $this->uploads = $uploads ?? new UploadCapabilityService(new UploadStore($maxZipBytes));
        $this->downloads = $downloads ?? new ArtifactDownloadStore($maxZipBytes, $downloadChunkBytes);
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
        return current_user_can('delete_themes') && !is_multisite();
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

        $result = $this->uploads->create(
            self::UPLOAD_PURPOSE,
            $size,
            $sha256,
            'application/zip',
            array('stylesheet' => $stylesheet, 'filename' => $stylesheet . '.zip')
        );
        if ($result instanceof \WP_Error) {
            return $result;
        }
        $result['instruction'] = 'Stream the exact theme ZIP directly from the machine holding it to upload.url using HTTP PUT. Do not send theme bytes through MCP, base64, or the client/model workspace. After the PUT succeeds, pass upload_id to codi/theme-install.';
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

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        $installedBefore = (array) wp_get_themes(array('errors' => null, 'allowed' => null));
        $existed = isset($installedBefore[$stylesheet]);
        $skin = new \Automatic_Upgrader_Skin();
        $upgrader = new \Theme_Upgrader($skin);
        $result = $upgrader->install($zipPath, array('overwrite_package' => true));
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
        require_once ABSPATH . 'wp-admin/includes/theme.php';
        $input = is_array($input) ? $input : array();
        $stylesheet = trim((string) ($input['stylesheet'] ?? ''));
        $themes = function_exists('wp_get_themes') ? (array) wp_get_themes(array('errors' => null, 'allowed' => null)) : array();
        $theme = $themes[$stylesheet] ?? null;
        if ($stylesheet === '' || !is_object($theme)) {
            return new \WP_Error('codi_mcp_theme_not_found', 'Installed theme was not found.');
        }
        if (is_multisite()) {
            return new \WP_Error('codi_mcp_theme_delete_multisite', 'Theme deletion is unavailable on multisite because theme files are shared across sites.');
        }
        $active = function_exists('get_stylesheet') ? (string) get_stylesheet() : '';
        $template = function_exists('get_template') ? (string) get_template() : '';
        if ($stylesheet === $active || $stylesheet === $template) {
            return new \WP_Error('codi_mcp_theme_active', 'The active theme or its active parent cannot be deleted.');
        }
        foreach ($themes as $childStylesheet => $candidate) {
            if ($childStylesheet === $stylesheet || !is_object($candidate) || !method_exists($candidate, 'get_template')) {
                continue;
            }
            if ((string) $candidate->get_template() === $stylesheet) {
                return new \WP_Error('codi_mcp_theme_parent_in_use', 'Theme cannot be deleted because an installed child theme depends on it.');
            }
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
            $staged = $this->downloads->stageDirectory(self::DOWNLOAD_PURPOSE, $source, $stylesheet, $stylesheet . '.zip', array('stylesheet' => $stylesheet));
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
        $chunk = $this->downloads->readBase64($downloadId, self::DOWNLOAD_PURPOSE, $offset);
        if ($chunk instanceof \WP_Error) {
            return $chunk;
        }
        unset($chunk['meta']);
        $chunk['stylesheet'] = $stylesheet;
        return $chunk;
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
        $themeDir = $inspectDir . DIRECTORY_SEPARATOR . $expectedStylesheet;
        if (count($entries) !== 1 || $entries[0] !== $expectedStylesheet || !is_dir($themeDir) || !is_file($themeDir . DIRECTORY_SEPARATOR . 'style.css')) {
            $wp_filesystem->delete($inspectDir, true);
            return new \WP_Error('codi_mcp_bad_theme_package', 'ZIP must contain exactly one top-level directory matching stylesheet and a root style.css file.');
        }
        $headers = function_exists('get_file_data') ? get_file_data($themeDir . DIRECTORY_SEPARATOR . 'style.css', array('Name' => 'Theme Name', 'Version' => 'Version'), 'theme') : array();
        $wp_filesystem->delete($inspectDir, true);
        if (trim((string) ($headers['Name'] ?? '')) === '') {
            return new \WP_Error('codi_mcp_bad_theme_package', 'Theme style.css does not contain a valid Theme Name header.');
        }
        return array('name' => (string) $headers['Name'], 'version' => (string) ($headers['Version'] ?? ''));
    }

    private function withinRoot(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');
        return $path === $root || str_starts_with($path, $root . '/');
    }
}
