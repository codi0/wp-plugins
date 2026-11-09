<?php

namespace CodiMcp\Packages\Plugins;

use CodiMcp\Core\PackageSourcePolicy;
use CodiMcp\Core\Downloads\ArtifactDownloadStore;
use CodiMcp\Core\Downloads\DownloadCapabilityService;
use CodiMcp\Core\Archives\ZipArchiveGuard;
use CodiMcp\Core\Deployment\CodeRecoveryStore;
use CodiMcp\Core\Deployment\PackageFingerprint;
use CodiMcp\Core\Deployment\UpgraderResult;
use CodiMcp\Core\Deployment\CorePackageInstaller;
use CodiMcp\Core\Deployment\DeploymentRuntimeVerifier;
use CodiMcp\Core\Deployment\NetworkAssetUsage;
use CodiMcp\Core\Deployment\FolderReplacement;
use CodiMcp\Core\Deployment\ReplacementSelection;
use CodiMcp\Core\Uploads\UploadCapabilityService;
use CodiMcp\Core\Uploads\UploadStore;

final class PluginDeployment
{
    private const UPLOAD_PURPOSE = 'plugins.install';
    private const DOWNLOAD_PURPOSE = 'plugins.export';

    private int $maxZipBytes;
    private UploadCapabilityService $uploads;
    private ArtifactDownloadStore $downloads;
    private FolderReplacement $folderReplacement;
    private PackageSourcePolicy $sourcePolicy;
    private CodeRecoveryStore $recoveries;
    private PackageFingerprint $fingerprints;

    public function __construct(int $maxZipBytes, int $downloadChunkBytes, ?UploadCapabilityService $uploads = null, ?ArtifactDownloadStore $downloads = null, ?FolderReplacement $folderReplacement = null, ?PackageSourcePolicy $sourcePolicy = null, ?CodeRecoveryStore $recoveries = null, ?PackageFingerprint $fingerprints = null)
    {
        $this->maxZipBytes = $maxZipBytes;
        $this->uploads = $uploads ?? new UploadCapabilityService(new UploadStore($maxZipBytes));
        $this->downloads = $downloads ?? new ArtifactDownloadStore($maxZipBytes, $downloadChunkBytes);
        $this->folderReplacement = $folderReplacement ?? new FolderReplacement();
        $this->sourcePolicy = $sourcePolicy ?? new PackageSourcePolicy();
        $this->recoveries = $recoveries ?? new CodeRecoveryStore();
        $this->fingerprints = $fingerprints ?? new PackageFingerprint();
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

        return !is_multisite() || is_super_admin();
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

        $selection = ReplacementSelection::fromInput($input);
        if ($selection instanceof \WP_Error) {
            return $selection;
        }
        $metadata = array('expected_plugin_slug' => $pluginSlug, 'replace' => $selection['replace']);
        $root = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR . '/' . $pluginSlug : '';
        if ($root !== '' && is_dir($root)) {
            if (!$this->sourcePolicy->canCustomReplace('plugin', $pluginSlug, (string) $selection['replace'])) {
                return new \WP_Error('codi_mcp_external_source', 'Installed external plugin source cannot be replaced by an uploaded custom package. Use the native WordPress update path or mark the package as managed in Codi MCP settings.');
            }
            if ($selection['replace'] !== 'file') {
                try {
                    $metadata['base_fingerprint'] = $this->fingerprints->directory($root);
                } catch (\Throwable $error) {
                    return new \WP_Error('codi_mcp_source_inspection', $error->getMessage());
                }
            }
        }
        if ($selection['replace'] === 'folder') {
            $target = $this->installedFolderTarget($pluginSlug, $selection['folder']);
            if ($target instanceof \WP_Error) {
                return $target;
            }
            $captured = $this->folderReplacement->capture($target['root'], $selection['folder']);
            if ($captured instanceof \WP_Error) {
                return $captured;
            }
            $metadata['replacement'] = $captured;
        } else if ($selection['replace'] === 'file') {
            $target = $this->installedFileTarget($pluginSlug, $selection['file']);
            if ($target instanceof \WP_Error) {
                return $target;
            }
            $captured = $this->folderReplacement->captureFile($target['root'], $selection['file']);
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
        $result['file'] = $selection['file'];
        $result['instruction'] = 'PUT upload.url, then call codi/plugin-install with upload_id.';
        return $result;
    }

    public function install($input)
    {
        $input = is_array($input) ? $input : array();
        $uploadId = (string) ($input['upload_id'] ?? '');
        $upload = $this->uploads->complete($uploadId, self::UPLOAD_PURPOSE);
        if ($upload instanceof \WP_Error) {
            return $upload;
        }
        $metadata = (array) ($upload['meta']['metadata'] ?? array());
        $slug = (string) ($metadata['expected_plugin_slug'] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/D', $slug)) {
            return new \WP_Error('codi_mcp_bad_metadata', 'Upload plugin identity is invalid.');
        }
        $replacement = $this->folderReplacement;
        return $replacement->locked(WP_PLUGIN_DIR . '/' . $slug, function () use ($replacement, $uploadId, $slug) {
            $upload = $this->uploads->complete($uploadId, self::UPLOAD_PURPOSE);
            if ($upload instanceof \WP_Error) {
                return $upload;
            }
            $metadata = (array) ($upload['meta']['metadata'] ?? array());
            $mode = array_key_exists('replace', $metadata) ? $metadata['replace'] : 'entire';
            $root = WP_PLUGIN_DIR . '/' . $slug;
            $existed = is_dir($root);
            $recovery = null;
            if ($existed) {
                if (!$this->sourcePolicy->canCustomReplace('plugin', $slug, (string) $mode)) {
                    return new \WP_Error('codi_mcp_external_source', 'Installed external plugin source cannot be replaced by a custom package.');
                }
                $pluginFile = $this->pluginFileForSlug($slug);
                if ($mode === 'file') {
                    $selection = is_array($metadata['replacement'] ?? null) ? $metadata['replacement'] : array();
                    $relativeFile = (string) ($selection['file'] ?? '');
                    $before = (string) ($selection['fingerprint'] ?? '');
                    $recovery = $this->recoveries->createFile('plugin', $slug, $root, $relativeFile, $before, array(
                        'plugin_file' => $pluginFile,
                        'before_version' => $this->pluginVersion($pluginFile),
                        'operation' => 'custom_replace',
                    ));
                    if ($recovery instanceof \WP_Error) {
                        return $recovery;
                    }
                } else {
                    $expected = (string) ($metadata['base_fingerprint'] ?? '');
                    try {
                        $current = $this->fingerprints->directory($root);
                    } catch (\Throwable $error) {
                        return new \WP_Error('codi_mcp_source_inspection', $error->getMessage());
                    }
                    if ($expected === '' || !hash_equals($expected, $current)) {
                        return new \WP_Error('codi_mcp_source_changed', 'Installed plugin source changed after upload creation. Create a fresh upload against the current source.');
                    }
                    $recovery = $this->recoveries->create('plugin', $slug, $root, $slug, $current, array(
                        'plugin_file' => $pluginFile,
                        'before_version' => $this->pluginVersion($pluginFile),
                        'operation' => 'custom_replace',
                    ));
                    if ($recovery instanceof \WP_Error) {
                        return $recovery;
                    }
                }
            }
            if ($mode === 'entire') {
                $result = $this->installEntire($upload, $uploadId);
            } elseif ($mode === 'folder' && is_array($metadata['replacement'] ?? null)) {
                $selection = $metadata['replacement'];
                $package = $this->installedFolderTarget($slug, (string) ($selection['folder'] ?? ''));
                if ($package instanceof \WP_Error) {
                    if (is_array($recovery)) {
                        $this->finalizeFailedMutation($package, $recovery, $root);
                    }
                    return $package;
                }
                $siteIds = (new NetworkAssetUsage())->pluginActiveSiteIds((string) $package['plugin_file']);
                $runtimeVerifier = $siteIds === array() ? null : new DeploymentRuntimeVerifier();
                $result = $replacement->replace(
                    $package['root'],
                    $selection,
                    (string) $upload['path'],
                    $slug,
                    $uploadId,
                    $runtimeVerifier === null ? null : static fn () => $runtimeVerifier->verify($siteIds)
                );
                if ($result instanceof \WP_Error) {
                    if (is_array($recovery)) {
                        $this->finalizeFailedMutation($result, $recovery, $root);
                    }
                    return $result;
                }
                wp_clean_plugins_cache(true);
                $this->uploads->consume($uploadId, self::UPLOAD_PURPOSE);
                $result = array(
                    'action' => 'updated',
                    'plugin_slug' => $slug,
                    'plugin_file' => $package['plugin_file'],
                    'name' => $package['name'],
                    'version' => $package['version'],
                    'active' => is_plugin_active($package['plugin_file']),
                    'network_active' => is_multisite() && is_plugin_active_for_network($package['plugin_file']),
                );
            } elseif ($mode === 'file' && is_array($metadata['replacement'] ?? null)) {
                $selection = $metadata['replacement'];
                $package = $this->installedFileTarget($slug, (string) ($selection['file'] ?? ''));
                if ($package instanceof \WP_Error) {
                    if (is_array($recovery)) {
                        $this->finalizeFailedFileMutation($package, $recovery, $root, (string) ($selection['file'] ?? ''));
                    }
                    return $package;
                }
                $siteIds = (new NetworkAssetUsage())->pluginActiveSiteIds((string) $package['plugin_file']);
                $runtimeVerifier = $siteIds === array() ? null : new DeploymentRuntimeVerifier();
                $result = $replacement->replaceFile(
                    $package['root'],
                    $selection,
                    (string) $upload['path'],
                    $slug,
                    $uploadId,
                    $runtimeVerifier === null ? null : static fn () => $runtimeVerifier->verify($siteIds)
                );
                if ($result instanceof \WP_Error) {
                    if (is_array($recovery)) {
                        $this->finalizeFailedFileMutation($result, $recovery, $root, (string) ($selection['file'] ?? ''));
                    }
                    return $result;
                }
                wp_clean_plugins_cache(true);
                $this->uploads->consume($uploadId, self::UPLOAD_PURPOSE);
                $result = array(
                    'action' => 'updated',
                    'plugin_slug' => $slug,
                    'plugin_file' => $package['plugin_file'],
                    'name' => $package['name'],
                    'version' => $package['version'],
                    'active' => is_plugin_active($package['plugin_file']),
                    'network_active' => is_multisite() && is_plugin_active_for_network($package['plugin_file']),
                );
            } else {
                $error = new \WP_Error('codi_mcp_bad_metadata', 'Upload replacement selection is invalid.');
                if (is_array($recovery)) {
                    $this->finalizeFailedMutation($error, $recovery, $root);
                }
                return $error;
            }
            if ($result instanceof \WP_Error) {
                if (is_array($recovery)) {
                    $this->finalizeFailedMutation($result, $recovery, $root);
                }
                return $result;
            }
            if (is_array($recovery)) {
                try {
                    $after = $mode === 'file'
                        ? $this->fingerprints->file($root . '/' . (string) ($metadata['replacement']['file'] ?? ''))
                        : $this->fingerprints->directory($root);
                } catch (\Throwable $error) {
                    return new \WP_Error('codi_mcp_recovery_required', 'Plugin changed successfully but its post-change fingerprint could not be recorded. The recovery snapshot has been retained.', array('recovery_id' => $recovery['recovery_id']));
                }
                $committed = $this->recoveries->commit((string) $recovery['recovery_id'], $after, (string) ($result['version'] ?? ''));
                if ($committed instanceof \WP_Error) {
                    return $committed;
                }
                $result['recovery_id'] = (string) $committed['recovery_id'];
                $result['recovery_expires_at'] = (string) $committed['expires_at'];
                $result['recovery_scope'] = 'code';
            }
            if (is_array($result)) {
                $result['replace'] = $mode;
                $result['folder'] = $mode === 'folder' ? (string) ($metadata['replacement']['folder'] ?? '') : '';
                $result['file'] = $mode === 'file' ? (string) ($metadata['replacement']['file'] ?? '') : '';
            }
            return $result;
        });
    }

    private function installEntire(array $upload, string $uploadId)
    {
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
        $siteIds = (new NetworkAssetUsage())->pluginActiveSiteIds((string) $package['plugin_file']);
        $runtimeVerifier = $siteIds === array() ? null : new DeploymentRuntimeVerifier();
        $bufferLevel = ob_get_level();
        ob_start();
        try {
            $result = (new CorePackageInstaller(
                $runtimeVerifier === null ? null : static fn () => $runtimeVerifier->verify($siteIds)
            ))->install($upgrader, $zipPath, 'plugin', $pluginSlug, WP_PLUGIN_DIR, $siteIds !== array());
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
        }

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

        $chunk = (new DownloadCapabilityService($this->downloads))->response($downloadId, self::DOWNLOAD_PURPOSE, $input);
        if ($chunk instanceof \WP_Error) {
            return $chunk;
        }
        unset($chunk['meta']);
        $chunk['plugin_file'] = $pluginFile;
        return $chunk;
    }

    public function delete($input)
    {
        if (!function_exists('plugin_basename')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $file = plugin_basename((string) (is_array($input) ? ($input['plugin_file'] ?? '') : ''));
        $root = WP_PLUGIN_DIR . '/' . (str_contains($file, '/') ? explode('/', $file)[0] : $file);
        return $this->folderReplacement->locked($root, fn () => $this->deleteUnlocked($input));
    }

    private function deleteUnlocked($input)
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

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
        if ((new NetworkAssetUsage())->pluginActiveAnywhere($pluginFile)) {
            return new \WP_Error('codi_mcp_plugin_active', 'Plugin is active on the network or at least one site. Deactivate it everywhere before deleting it.');
        }

        $slug = dirname($pluginFile);
        if ($slug === '.' || !$this->sourcePolicy->canDelete('plugin', $slug)) {
            return new \WP_Error('codi_mcp_external_source', 'Only directory-backed plugins explicitly marked as managed may be deleted by Codi MCP.');
        }
        $root = WP_PLUGIN_DIR . '/' . $slug;
        try {
            $before = $this->fingerprints->directory($root);
        } catch (\Throwable $error) {
            return new \WP_Error('codi_mcp_source_inspection', $error->getMessage());
        }
        $recovery = $this->recoveries->create('plugin', $slug, $root, $slug, $before, array(
            'plugin_file' => $pluginFile,
            'before_version' => $this->pluginVersion($pluginFile),
            'operation' => 'delete',
        ));
        if ($recovery instanceof \WP_Error) {
            return $recovery;
        }

        $result = delete_plugins([$pluginFile]);
        if (is_wp_error($result)) {
            $this->finalizeFailedMutation($result, $recovery, $root);
            return $result;
        }
        if ($result === null) {
            $error = new \WP_Error('codi_mcp_filesystem_credentials_required', 'WordPress requires interactive filesystem credentials and cannot delete this plugin through MCP.');
            $this->finalizeFailedMutation($error, $recovery, $root);
            return $error;
        }
        if ($result !== true) {
            $error = new \WP_Error('codi_mcp_plugin_delete_failed', 'WordPress could not delete the selected plugin.');
            $this->finalizeFailedMutation($error, $recovery, $root);
            return $error;
        }

        wp_clean_plugins_cache(true);
        $committed = $this->recoveries->commit((string) $recovery['recovery_id'], PackageFingerprint::absent(), '');
        if ($committed instanceof \WP_Error) {
            return $committed;
        }
        return [
            'plugin_file' => $pluginFile,
            'deleted' => true,
            'recovery_id' => (string) $committed['recovery_id'],
            'recovery_expires_at' => (string) $committed['expires_at'],
            'recovery_scope' => 'code',
        ];
    }

    public function update($input)
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $pluginFile = plugin_basename((string) (is_array($input) ? ($input['plugin_file'] ?? '') : ''));
        $plugins = get_plugins();
        if ($pluginFile === '' || !isset($plugins[$pluginFile])) {
            return new \WP_Error('codi_mcp_plugin_not_found', 'Installed plugin was not found.');
        }
        $updates = function_exists('get_site_transient') ? get_site_transient('update_plugins') : null;
        $responses = is_object($updates) && is_array($updates->response ?? null) ? $updates->response : array();
        if (!isset($responses[$pluginFile])) {
            return new \WP_Error('codi_mcp_update_unavailable', 'WordPress does not currently advertise an update for this plugin.');
        }
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        $slug = dirname($pluginFile);
        if ($slug === '.') {
            return new \WP_Error('codi_mcp_recovery_unsupported', 'Native updates through Codi require a directory-backed plugin so code recovery can be guaranteed.');
        }
        $root = WP_PLUGIN_DIR . '/' . $slug;
        return $this->folderReplacement->locked($root, function () use ($pluginFile, $slug, $root) {
            try {
                $before = $this->fingerprints->directory($root);
            } catch (\Throwable $error) {
                return new \WP_Error('codi_mcp_source_inspection', $error->getMessage());
            }
            $recovery = $this->recoveries->create('plugin', $slug, $root, $slug, $before, array(
                'plugin_file' => $pluginFile,
                'before_version' => $this->pluginVersion($pluginFile),
                'operation' => 'native_update',
            ));
            if ($recovery instanceof \WP_Error) {
                return $recovery;
            }
            $siteIds = (new NetworkAssetUsage())->pluginActiveSiteIds($pluginFile);
            $skin = new \Automatic_Upgrader_Skin();
            $upgrader = new \Plugin_Upgrader($skin);
            $bufferLevel = ob_get_level();
            ob_start();
            try {
                $raw = $upgrader->upgrade($pluginFile);
            } catch (\Throwable $error) {
                $raw = new \WP_Error('codi_mcp_update_failed', 'WordPress plugin update failed: ' . $error->getMessage());
            } finally {
                while (ob_get_level() > $bufferLevel) {
                    ob_end_clean();
                }
            }
            $error = UpgraderResult::error($raw, 'plugin');
            if ($error instanceof \WP_Error) {
                $this->finalizeFailedMutation($error, $recovery, $root);
                return $error;
            }
            wp_clean_plugins_cache(true);
            try {
                $after = $this->fingerprints->directory($root);
            } catch (\Throwable $exception) {
                return new \WP_Error('codi_mcp_recovery_required', 'Plugin update completed but the resulting source could not be fingerprinted. The recovery snapshot has been retained.', array('recovery_id' => $recovery['recovery_id']));
            }
            $version = $this->pluginVersion($pluginFile);
            $committed = $this->recoveries->commit((string) $recovery['recovery_id'], $after, $version);
            if ($committed instanceof \WP_Error) {
                return $committed;
            }
            if ($siteIds !== array()) {
                $verified = (new DeploymentRuntimeVerifier())->verify($siteIds);
                if ($verified !== true) {
                    $restored = $this->restoreCommittedUnlocked($committed, $root, $pluginFile, $siteIds);
                    if ($restored instanceof \WP_Error) {
                        return new \WP_Error('codi_mcp_recovery_required', 'Plugin update failed runtime verification and automatic code restoration did not complete.', array('recovery_id' => $committed['recovery_id'], 'cause' => $restored->get_error_code()));
                    }
                    return new \WP_Error('codi_mcp_update_rolled_back', 'Plugin update failed runtime verification; the previous code was restored.', array('recovery_id' => $committed['recovery_id']));
                }
            }
            return array(
                'action' => 'updated',
                'plugin_file' => $pluginFile,
                'version' => $version,
                'recovery_id' => (string) $committed['recovery_id'],
                'recovery_expires_at' => (string) $committed['expires_at'],
                'recovery_scope' => 'code',
            );
        });
    }

    public function restore($input)
    {
        $recoveryId = trim((string) (is_array($input) ? ($input['recovery_id'] ?? '') : ''));
        $artifact = $this->recoveries->artifact($recoveryId, 'plugin');
        if ($artifact instanceof \WP_Error) {
            return $artifact;
        }
        $record = (array) $artifact['record'];
        $slug = (string) ($record['identity'] ?? '');
        $pluginFile = (string) ($record['metadata']['plugin_file'] ?? '');
        $root = WP_PLUGIN_DIR . '/' . $slug;
        return $this->folderReplacement->locked($root, function () use ($record, $root, $pluginFile) {
            $siteIds = $pluginFile !== '' ? (new NetworkAssetUsage())->pluginActiveSiteIds($pluginFile) : array();
            if (($record['metadata']['scope'] ?? '') === 'file') {
                return $this->restoreFileCommittedUnlocked($record, $root, $pluginFile, $siteIds);
            }
            return $this->restoreCommittedUnlocked($record, $root, $pluginFile, $siteIds);
        });
    }

    /** Package policy stays here; Core only receives an already resolved root. */
    private function installedFolderTarget(string $slug, string $folder): array|\WP_Error
    {
        if (!ReplacementSelection::validFolder($folder)) {
            return new \WP_Error('codi_mcp_bad_replacement', 'Select one relative folder with an existing parent directory.');
        }
        if (defined('CODI_MCP_PLUGIN_BASENAME') && dirname(CODI_MCP_PLUGIN_BASENAME) === $slug) {
            return new \WP_Error('codi_mcp_self_replace', 'Codi MCP cannot replace its own folders.');
        }
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!function_exists('get_filesystem_method')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $plugins = get_plugins();
        ksort($plugins, SORT_STRING);
        $selected = null;
        foreach ($plugins as $file => $data) {
            if (!str_starts_with($file, $slug . '/')) {
                continue;
            }
            $relative = substr($file, strlen($slug) + 1);
            if (str_starts_with($relative, $folder . '/')) {
                return new \WP_Error('codi_mcp_plugin_entrypoint', 'A replacement folder cannot contain an installed plugin entry point.');
            }
            $selected ??= array(
                'root' => WP_PLUGIN_DIR . '/' . $slug,
                'plugin_file' => $file,
                'name' => (string) ($data['Name'] ?? ''),
                'version' => (string) ($data['Version'] ?? ''),
            );
        }
        return $selected ?? new \WP_Error('codi_mcp_plugin_not_found', 'Folder replacement requires an installed directory-backed plugin.');
    }

    private function installedFileTarget(string $slug, string $file): array|\WP_Error
    {
        if (!ReplacementSelection::validFile($file)) {
            return new \WP_Error('codi_mcp_bad_replacement', 'Select one relative existing file.');
        }
        if (defined('CODI_MCP_PLUGIN_BASENAME') && dirname(CODI_MCP_PLUGIN_BASENAME) === $slug) {
            return new \WP_Error('codi_mcp_self_replace', 'Codi MCP cannot replace its own files.');
        }
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!function_exists('get_filesystem_method')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $plugins = get_plugins();
        ksort($plugins, SORT_STRING);
        $selected = null;
        foreach ($plugins as $pluginFile => $data) {
            if (!str_starts_with($pluginFile, $slug . '/')) {
                continue;
            }
            $relative = substr($pluginFile, strlen($slug) + 1);
            if ($relative === $file) {
                return new \WP_Error('codi_mcp_plugin_entrypoint', 'An installed plugin entry point cannot be replaced as a single file.');
            }
            $selected ??= array(
                'root' => WP_PLUGIN_DIR . '/' . $slug,
                'plugin_file' => $pluginFile,
                'name' => (string) ($data['Name'] ?? ''),
                'version' => (string) ($data['Version'] ?? ''),
            );
        }
        return $selected ?? new \WP_Error('codi_mcp_plugin_not_found', 'File replacement requires an installed directory-backed plugin.');
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

    private function pluginFileForSlug(string $slug): string
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        foreach (get_plugins() as $file => $data) {
            if (str_starts_with((string) $file, $slug . '/')) {
                return (string) $file;
            }
        }
        return '';
    }

    private function pluginVersion(string $pluginFile): string
    {
        if ($pluginFile === '') {
            return '';
        }
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();
        return isset($plugins[$pluginFile]) && is_array($plugins[$pluginFile]) ? (string) ($plugins[$pluginFile]['Version'] ?? '') : '';
    }

    private function finalizeFailedMutation(\WP_Error $error, array $recovery, string $root): void
    {
        try {
            $current = $this->fingerprints->current($root);
            if (hash_equals((string) $recovery['before_fingerprint'], $current)) {
                $this->recoveries->abandon((string) $recovery['recovery_id']);
                return;
            }
            $committed = $this->recoveries->commit((string) $recovery['recovery_id'], $current, '');
            if (is_array($committed)) {
                $error->add_data(array('recovery_id' => $committed['recovery_id'], 'recovery_scope' => 'code'));
            }
        } catch (\Throwable) {
            $error->add_data(array('recovery_id' => (string) ($recovery['recovery_id'] ?? ''), 'recovery_scope' => 'code'));
        }
    }

    private function finalizeFailedFileMutation(\WP_Error $error, array $recovery, string $root, string $relativeFile): void
    {
        try {
            $current = $this->fingerprints->file($root . '/' . $relativeFile);
            if (hash_equals((string) $recovery['before_fingerprint'], $current)) {
                $this->recoveries->abandon((string) $recovery['recovery_id']);
                return;
            }
            $committed = $this->recoveries->commit((string) $recovery['recovery_id'], $current, '');
            if (is_array($committed)) {
                $error->add_data(array('recovery_id' => $committed['recovery_id'], 'recovery_scope' => 'code'));
            }
        } catch (\Throwable) {
            $error->add_data(array('recovery_id' => (string) ($recovery['recovery_id'] ?? ''), 'recovery_scope' => 'code'));
        }
    }

    private function restoreFileCommittedUnlocked(array $record, string $root, string $pluginFile, array $siteIds)
    {
        $recoveryId = (string) ($record['recovery_id'] ?? '');
        $relativeFile = (string) ($record['metadata']['file'] ?? '');
        if (!ReplacementSelection::validFile($relativeFile)) {
            return new \WP_Error('codi_mcp_recovery_integrity', 'File recovery metadata is invalid.');
        }
        $artifact = $this->recoveries->artifact($recoveryId, 'plugin');
        if ($artifact instanceof \WP_Error) {
            return $artifact;
        }
        $selection = array(
            'root' => (string) realpath($root),
            'file' => $relativeFile,
            'fingerprint' => (string) ($record['after_fingerprint'] ?? ''),
        );
        $runtimeVerifier = $siteIds === array() ? null : new DeploymentRuntimeVerifier();
        $restored = $this->folderReplacement->replaceFile(
            $root,
            $selection,
            (string) $artifact['path'],
            (string) ($record['identity'] ?? ''),
            bin2hex(random_bytes(16)),
            $runtimeVerifier === null ? null : static fn () => $runtimeVerifier->verify($siteIds)
        );
        if ($restored instanceof \WP_Error) {
            if ($restored->get_error_code() === 'codi_mcp_file_conflict') {
                return new \WP_Error('codi_mcp_restore_conflict', 'Selected plugin file has changed since this recovery point was created; restore was refused.');
            }
            return $restored;
        }
        wp_clean_plugins_cache(true);
        try {
            $fingerprint = $this->fingerprints->file($root . '/' . $relativeFile);
        } catch (\Throwable $error) {
            return new \WP_Error('codi_mcp_restore_verification', $error->getMessage());
        }
        if (!hash_equals((string) ($record['before_fingerprint'] ?? ''), $fingerprint)) {
            return new \WP_Error('codi_mcp_restore_verification', 'Restored plugin file does not match the recovery fingerprint.');
        }
        $this->recoveries->markRestored($recoveryId);
        return array(
            'plugin_file' => $pluginFile,
            'version' => $this->pluginVersion($pluginFile),
            'restored' => true,
            'recovery_id' => $recoveryId,
            'recovery_scope' => 'code',
        );
    }

    private function restoreCommittedUnlocked(array $record, string $root, string $pluginFile, array $siteIds)
    {
        $recoveryId = (string) ($record['recovery_id'] ?? '');
        $artifact = $this->recoveries->artifact($recoveryId, 'plugin');
        if ($artifact instanceof \WP_Error) {
            return $artifact;
        }
        try {
            $current = $this->fingerprints->current($root);
        } catch (\Throwable $error) {
            return new \WP_Error('codi_mcp_source_inspection', $error->getMessage());
        }
        if (!hash_equals((string) ($record['after_fingerprint'] ?? ''), $current)) {
            return new \WP_Error('codi_mcp_restore_conflict', 'Installed plugin source has changed since this recovery point was created; restore was refused.');
        }
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        $skin = new \Automatic_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader($skin);
        $runtimeVerifier = $siteIds === array() ? null : new DeploymentRuntimeVerifier();
        $result = (new CorePackageInstaller(
            $runtimeVerifier === null ? null : static fn () => $runtimeVerifier->verify($siteIds)
        ))->install($upgrader, (string) $artifact['path'], 'plugin', (string) ($record['identity'] ?? ''), WP_PLUGIN_DIR, $siteIds !== array());
        $error = UpgraderResult::error($result, 'plugin');
        if ($error instanceof \WP_Error) {
            return $error;
        }
        wp_clean_plugins_cache(true);
        try {
            $restored = $this->fingerprints->directory($root);
        } catch (\Throwable $exception) {
            return new \WP_Error('codi_mcp_restore_verification', $exception->getMessage());
        }
        if (!hash_equals((string) ($record['before_fingerprint'] ?? ''), $restored)) {
            return new \WP_Error('codi_mcp_restore_verification', 'Restored plugin source does not match the recovery fingerprint.');
        }
        $this->recoveries->markRestored($recoveryId);
        return array(
            'plugin_file' => $pluginFile,
            'version' => $this->pluginVersion($pluginFile),
            'restored' => true,
            'recovery_id' => $recoveryId,
            'recovery_scope' => 'code',
        );
    }

}
