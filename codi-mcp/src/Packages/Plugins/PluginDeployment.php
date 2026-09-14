<?php

namespace CodiMcp\Packages\Plugins;

use CodiMcp\Core\Downloads\ArtifactDownloadStore;
use CodiMcp\Core\Archives\ZipArchiveGuard;
use CodiMcp\Core\Deployment\UpgraderResult;
use CodiMcp\Core\Uploads\UploadCapabilityService;
use CodiMcp\Core\Uploads\UploadStore;

final class PluginDeployment
{
    private const UPLOAD_PURPOSE = 'plugins.install';
    private const DOWNLOAD_PURPOSE = 'plugins.export';

    private int $maxZipBytes;
    private UploadCapabilityService $uploads;
    private ArtifactDownloadStore $downloads;

    public function __construct(int $maxZipBytes, int $downloadChunkBytes, ?UploadCapabilityService $uploads = null, ?ArtifactDownloadStore $downloads = null)
    {
        $this->maxZipBytes = $maxZipBytes;
        $this->uploads = $uploads ?? new UploadCapabilityService(new UploadStore($maxZipBytes));
        $this->downloads = $downloads ?? new ArtifactDownloadStore($maxZipBytes, $downloadChunkBytes);
    }

    public function canInstallPlugins(): bool
    {
        if (!current_user_can('install_plugins') || !current_user_can('update_plugins')) {
            return false;
        }

        if (is_multisite() && !is_super_admin()) {
            return false;
        }

        return true;
    }

    public function canActivatePlugins(): bool
    {
        if (!current_user_can('activate_plugins')) {
            return false;
        }

        if (is_multisite() && !is_super_admin()) {
            return false;
        }

        return true;
    }

    public function canDeletePlugins(): bool
    {
        if (!current_user_can('delete_plugins')) {
            return false;
        }

        // Plugin files are shared across sites on multisite, so deletion is not site-local there.
        return !is_multisite();
    }

    public function canExportPlugins(): bool
    {
        if (!current_user_can('install_plugins') || !current_user_can('update_plugins')) {
            return false;
        }

        return !is_multisite() || is_super_admin();
    }

    public function upload($input)
    {
        $input = is_array($input) ? $input : array();
        $pluginSlug = strtolower(trim((string) ($input['plugin_slug'] ?? '')));
        $size = (int) ($input['size'] ?? 0);
        $sha256 = strtolower(trim((string) ($input['sha256'] ?? '')));

        $validation = $this->validateUploadDescriptor($pluginSlug, $size, $sha256);
        if ($validation instanceof \WP_Error) {
            return $validation;
        }

        $result = $this->uploads->create(
            self::UPLOAD_PURPOSE,
            $size,
            $sha256,
            'application/zip',
            array('expected_plugin_slug' => $pluginSlug)
        );
        if ($result instanceof \WP_Error) {
            return $result;
        }
        $result['instruction'] = 'Stream the exact plugin ZIP directly from the machine holding it to upload.url using HTTP PUT. Do not send plugin bytes through MCP, base64, or the client/model workspace. After the PUT succeeds, pass upload_id to codi/plugin-install.';
        return $result;
    }

    public function install($input)
    {
        $input = is_array($input) ? $input : [];
        $uploadId = isset($input['upload_id']) ? (string) $input['upload_id'] : '';
        $upload = $this->uploads->complete($uploadId, self::UPLOAD_PURPOSE);
        if ($upload instanceof \WP_Error) {
            return $upload;
        }

        $metadata = is_array($upload['meta']['metadata'] ?? null) ? $upload['meta']['metadata'] : array();
        $zipPath = (string) ($upload['path'] ?? '');
        $expectedSlug = strtolower(trim((string) ($metadata['expected_plugin_slug'] ?? '')));
        $package = $this->inspectPluginZip($zipPath, $expectedSlug, $uploadId);
        if (is_wp_error($package)) {
            return $package;
        }
        $pluginSlug = (string) $package['plugin_slug'];

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $pluginDirExisted = is_dir(WP_PLUGIN_DIR . '/' . $pluginSlug);
        $skin = new \Automatic_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader($skin);
        $result = $upgrader->install($zipPath, ['overwrite_package' => true]);

        $upgraderError = UpgraderResult::error($result, 'plugin');
        if ($upgraderError instanceof \WP_Error) {
            return $upgraderError;
        }

        wp_clean_plugins_cache(true);

        $installedPath = WP_PLUGIN_DIR . '/' . $package['plugin_file'];
        $installedData = file_exists($installedPath) ? get_plugin_data($installedPath, false, false) : [];

        $this->uploads->consume($uploadId, self::UPLOAD_PURPOSE);

        return [
            'action' => $pluginDirExisted ? 'updated' : 'installed',
            'plugin_slug' => $pluginSlug,
            'plugin_file' => $package['plugin_file'],
            'name' => !empty($installedData['Name']) ? $installedData['Name'] : $package['name'],
            'version' => isset($installedData['Version']) ? (string) $installedData['Version'] : (string) $package['version'],
            'active' => is_plugin_active($package['plugin_file']),
            'network_active' => is_multisite() && is_plugin_active_for_network($package['plugin_file']),
        ];
    }

    public function activate($input)
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $input = is_array($input) ? $input : [];
        $pluginFile = isset($input['plugin_file']) ? plugin_basename($input['plugin_file']) : '';
        $scope = isset($input['scope']) ? (string) $input['scope'] : '';

        $validation = $this->validateActivationRequest($pluginFile, $scope);
        if (is_wp_error($validation)) {
            return $validation;
        }

        if ($scope === 'network') {
            if (!is_plugin_active_for_network($pluginFile)) {
                $result = activate_plugin($pluginFile, '', true, true);
                if (is_wp_error($result)) {
                    return $result;
                }
            }
        } else {
            if (is_multisite() && is_plugin_active_for_network($pluginFile)) {
                return new \WP_Error('codi_mcp_network_active', 'Plugin is network active. Deactivate it at network scope before activating it only for this site.');
            }
            if (!is_plugin_active($pluginFile)) {
                $result = activate_plugin($pluginFile, '', false, true);
                if (is_wp_error($result)) {
                    return $result;
                }
            }
        }

        return $this->activationState($pluginFile, $scope);
    }

    public function deactivate($input)
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $input = is_array($input) ? $input : [];
        $pluginFile = isset($input['plugin_file']) ? plugin_basename($input['plugin_file']) : '';
        $scope = isset($input['scope']) ? (string) $input['scope'] : '';

        $validation = $this->validateActivationRequest($pluginFile, $scope);
        if (is_wp_error($validation)) {
            return $validation;
        }

        if ($scope === 'network') {
            if (is_plugin_active_for_network($pluginFile)) {
                deactivate_plugins($pluginFile, true, true);
            }
        } else {
            if (is_multisite() && is_plugin_active_for_network($pluginFile)) {
                return new \WP_Error('codi_mcp_network_active', 'Plugin is network active and cannot be deactivated for only one site. Use network scope.');
            }
            if (is_plugin_active($pluginFile)) {
                deactivate_plugins($pluginFile, true, false);
            }
        }

        return $this->activationState($pluginFile, $scope);
    }

    public function export($input)
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $input = is_array($input) ? $input : [];
        $requested = (string) ($input['plugin_file'] ?? '');
        $pluginFile = $requested !== '' ? plugin_basename($requested) : '';
        $offset = max(0, (int) ($input['offset'] ?? 0));
        $downloadId = trim((string) ($input['download_id'] ?? ''));
        $plugins = function_exists('get_plugins') ? get_plugins() : [];

        if ($pluginFile === '' || !isset($plugins[$pluginFile])) {
            return new \WP_Error('codi_mcp_plugin_not_found', 'Installed standard plugin was not found.');
        }
        if ($pluginFile === CODI_MCP_PLUGIN_BASENAME) {
            return new \WP_Error('codi_mcp_self_export', 'Codi MCP cannot export its own source package.');
        }
        $slug = dirname($pluginFile);
        if ($slug === '.' || str_contains($slug, '/') || str_contains($slug, '\\')) {
            return new \WP_Error('codi_mcp_plugin_export_unsupported', 'Only directory-backed standard plugins can be exported.');
        }

        if ($downloadId === '') {
            if ($offset !== 0) {
                return new \WP_Error('codi_mcp_bad_offset', 'The first plugin-export call must use offset 0.');
            }
            $pluginRoot = realpath(WP_PLUGIN_DIR);
            $source = realpath(WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $slug);
            if (!is_string($pluginRoot) || !is_string($source) || !$this->withinRoot($source, $pluginRoot)) {
                return new \WP_Error('codi_mcp_plugin_export_scope', 'Plugin source directory is outside the WordPress plugin root.');
            }
            $staged = $this->downloads->stageDirectory(self::DOWNLOAD_PURPOSE, $source, $slug, $slug . '.zip', array('plugin_file' => $pluginFile, 'plugin_slug' => $slug));
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
            if ($pluginFile !== (string) ($metadata['plugin_file'] ?? '')) {
                return new \WP_Error('codi_mcp_download_metadata_mismatch', 'plugin_file must match the generated plugin artifact.');
            }
        }

        $chunk = $this->downloads->readBase64($downloadId, self::DOWNLOAD_PURPOSE, $offset);
        if ($chunk instanceof \WP_Error) {
            return $chunk;
        }
        unset($chunk['meta']);
        $chunk['plugin_file'] = $pluginFile;
        return $chunk;
    }

    public function delete($input)
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        if (is_multisite()) {
            return new \WP_Error('codi_mcp_plugin_delete_multisite', 'Plugin deletion is unavailable on multisite because plugin files are shared across sites.');
        }

        $input = is_array($input) ? $input : [];
        $requested = isset($input['plugin_file']) ? (string) $input['plugin_file'] : '';
        $pluginFile = $requested !== '' ? plugin_basename($requested) : '';
        $plugins = function_exists('get_plugins') ? get_plugins() : [];

        if ($pluginFile === '' || !isset($plugins[$pluginFile])) {
            return new \WP_Error('codi_mcp_plugin_not_found', 'Installed standard plugin was not found.');
        }
        if ($pluginFile === CODI_MCP_PLUGIN_BASENAME) {
            return new \WP_Error('codi_mcp_self_delete', 'Codi MCP cannot delete itself.');
        }
        if (is_plugin_active($pluginFile)) {
            return new \WP_Error('codi_mcp_plugin_active', 'Deactivate the plugin before deleting it.');
        }

        $result = delete_plugins([$pluginFile]);
        if (is_wp_error($result)) {
            return $result;
        }
        if ($result === null) {
            return new \WP_Error('codi_mcp_filesystem_credentials_required', 'WordPress requires interactive filesystem credentials and cannot delete this plugin through MCP.');
        }
        if ($result !== true) {
            return new \WP_Error('codi_mcp_plugin_delete_failed', 'WordPress could not delete the selected plugin.');
        }

        wp_clean_plugins_cache(true);
        return ['plugin_file' => $pluginFile, 'deleted' => true];
    }

    private function validateUploadDescriptor(string $pluginSlug, int $size, string $sha256)
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $pluginSlug)) {
            return new \WP_Error('codi_mcp_bad_slug', 'Invalid plugin slug.');
        }
        if ($size < 1 || $size > $this->maxZipBytes) {
            return new \WP_Error('codi_mcp_bad_size', 'ZIP size is outside the allowed range.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            return new \WP_Error('codi_mcp_bad_hash', 'Invalid SHA-256.');
        }

        return true;
    }

    private function inspectPluginZip(string $zipPath, string $expectedSlug, string $uploadId)
    {
        $archiveValidation = (new ZipArchiveGuard())->validate($zipPath);
        if ($archiveValidation instanceof \WP_Error) {
            return $archiveValidation;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        if (!WP_Filesystem()) {
            return new \WP_Error('codi_mcp_filesystem', 'WordPress could not initialise filesystem access.');
        }

        global $wp_filesystem;
        $inspectDir = $this->pluginInspectionRoot() . DIRECTORY_SEPARATOR . 'inspect-' . $uploadId;
        if ($wp_filesystem->is_dir($inspectDir)) {
            $wp_filesystem->delete($inspectDir, true);
        }
        if (!wp_mkdir_p($inspectDir)) {
            return new \WP_Error('codi_mcp_storage', 'Could not create the package inspection directory.');
        }

        $unzipped = unzip_file($zipPath, $inspectDir);
        if (is_wp_error($unzipped)) {
            $wp_filesystem->delete($inspectDir, true);
            return $unzipped;
        }

        $entries = [];
        foreach (scandir($inspectDir) as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '__MACOSX') {
                continue;
            }
            $entries[] = $entry;
        }

        if (count($entries) !== 1 || !is_dir($inspectDir . '/' . $entries[0])) {
            $wp_filesystem->delete($inspectDir, true);
            return new \WP_Error('codi_mcp_bad_package', 'ZIP must contain exactly one top-level plugin directory.');
        }

        $pluginSlug = strtolower((string) $entries[0]);
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $pluginSlug)) {
            $wp_filesystem->delete($inspectDir, true);
            return new \WP_Error('codi_mcp_bad_package', 'Plugin package directory has an invalid slug.');
        }
        if ($expectedSlug !== '' && !hash_equals($expectedSlug, $pluginSlug)) {
            $wp_filesystem->delete($inspectDir, true);
            return new \WP_Error('codi_mcp_plugin_slug_mismatch', 'Plugin package does not match expected.plugin_slug.');
        }

        $pluginDir = $inspectDir . '/' . $pluginSlug;
        $pluginFiles = glob($pluginDir . '/*.php');
        $pluginData = null;
        $pluginFile = null;
        if (is_array($pluginFiles)) {
            foreach ($pluginFiles as $candidate) {
                $data = get_plugin_data($candidate, false, false);
                if (!empty($data['Name'])) {
                    $pluginData = $data;
                    $pluginFile = $pluginSlug . '/' . basename($candidate);
                    break;
                }
            }
        }

        $wp_filesystem->delete($inspectDir, true);
        if (!$pluginData || !$pluginFile) {
            return new \WP_Error('codi_mcp_bad_package', 'No valid plugin header was found in the package root.');
        }

        return ['plugin_slug' => $pluginSlug, 'plugin_file' => $pluginFile, 'name' => (string) $pluginData['Name'], 'version' => (string) $pluginData['Version']];
    }

    private function pluginInspectionRoot(): string
    {
        $temporary = function_exists('get_temp_dir') ? (string) get_temp_dir() : sys_get_temp_dir();
        return rtrim($temporary, "\\/") . DIRECTORY_SEPARATOR . 'codi-mcp';
    }

    private function withinRoot(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');
        return $path === $root || str_starts_with($path, $root . '/');
    }

    private function validateActivationRequest(string $pluginFile, string $scope)
    {
        if (!in_array($scope, ['site', 'network'], true)) {
            return new \WP_Error('codi_mcp_bad_scope', 'Scope must be site or network.');
        }
        if (!$pluginFile || !isset(get_plugins()[$pluginFile])) {
            return new \WP_Error('codi_mcp_plugin_not_found', 'Installed plugin was not found.');
        }
        if ($pluginFile === CODI_MCP_PLUGIN_BASENAME) {
            return new \WP_Error('codi_mcp_self_activation', 'Codi MCP cannot change its own activation state.');
        }
        if ($scope === 'network' && !is_multisite()) {
            return new \WP_Error('codi_mcp_network_activation', 'Network activation is only available on multisite.');
        }

        return true;
    }

    private function activationState(string $pluginFile, string $scope): array
    {
        return [
            'plugin_file' => $pluginFile,
            'active' => is_plugin_active($pluginFile),
            'network_active' => is_multisite() && is_plugin_active_for_network($pluginFile),
            'scope' => $scope,
        ];
    }

}
