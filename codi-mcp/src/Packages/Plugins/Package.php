<?php

namespace CodiMcp\Packages\Plugins;

use CodiMcp\Core\Abilities\AbilityMetadata;
use CodiMcp\Core\AbilityPackage;
use CodiMcp\Core\Deployment\ReplacementSelection;
use CodiMcp\Core\Downloads\DownloadCapabilityService;

final class Package implements AbilityPackage
{
    private const MAX_ZIP_BYTES = 20 * 1024 * 1024;
    private const EXPORT_CHUNK_BYTES = 256 * 1024;

    /** @var PluginDeployment|null */
    private $deployment = null;

    private ?PluginInspection $inspection = null;

    public function key(): string
    {
        return 'plugins';
    }

    public function label(): string
    {
        return 'Plugins';
    }

    public function abilityNames(): array
    {
        return [
            $this->abilityName('plugin-upload'),
            $this->abilityName('plugin-install'),
            $this->abilityName('plugin-activate'),
            $this->abilityName('plugin-deactivate'),
            $this->abilityName('plugin-delete'),
            $this->abilityName('plugin-update'),
            $this->abilityName('plugin-restore'),
            $this->abilityName('plugins-list'),
            $this->abilityName('plugin-info'),
            $this->abilityName('plugin-export'),
        ];
    }

    public function registerCategories(): void
    {
        $category = $this->category();
        if (!function_exists('wp_register_ability_category')) {
            return;
        }

        wp_register_ability_category(
            $category,
            [
                'label'       => 'Codi MCP — Plugins',
                'description' => 'Privileged WordPress plugin deployment abilities provided by Codi MCP.',
            ]
        );
    }

    public function registerAbilities(): void
    {
        $category = $this->category();
        $uploadAbility = $this->abilityName('plugin-upload');
        $installAbility = $this->abilityName('plugin-install');
        $activateAbility = $this->abilityName('plugin-activate');
        $deactivateAbility = $this->abilityName('plugin-deactivate');
        $deleteAbility = $this->abilityName('plugin-delete');
        $updateAbility = $this->abilityName('plugin-update');
        $restoreAbility = $this->abilityName('plugin-restore');
        $pluginsListAbility = $this->abilityName('plugins-list');
        $pluginInfoAbility = $this->abilityName('plugin-info');
        $exportAbility = $this->abilityName('plugin-export');
        if (!function_exists('wp_register_ability')) {
            return;
        }

        wp_register_ability(
            $uploadAbility,
            [
                'label'               => 'Create plugin upload',
                'description' => 'Stage a plugin ZIP by one-time PUT, then call plugin-install. replace selects the whole package, one folder, or one existing file.',
                'category'            => $category,
                'input_schema'        => $this->uploadInputSchema(),
                'output_schema'       => $this->uploadOutputSchema(),
                'execute_callback'    => [$this, 'upload'],
                'permission_callback' => [$this, 'canInstallPlugins'],
                'meta'                => $this->abilityMeta(false),
            ]
        );

        wp_register_ability(
            $installAbility,
            [
                'label'               => 'Install uploaded plugin',
                'description' => 'Install an uploaded ZIP with its bound entire/folder/file selection. Activate separately with plugin-activate.',
                'category'            => $category,
                'input_schema'        => [
                    'type'       => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'upload_id' => [
                            'type'      => 'string',
                            'minLength' => 32,
                            'maxLength' => 32,
                            'pattern'   => '^[a-f0-9]{32}$',
                        ],
                    ],
                    'required' => ['upload_id'],
                ],
                'output_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'action'         => ['type' => 'string'],
                        'plugin_slug'    => ['type' => 'string'],
                        'plugin_file'    => ['type' => 'string'],
                        'name'           => ['type' => 'string'],
                        'version'        => ['type' => 'string'],
                        'active'         => ['type' => 'boolean'],
                        'network_active' => ['type' => 'boolean'],
                        'replace' => ['type' => 'string', 'enum' => ['entire', 'folder', 'file']],
                        'folder' => ['type' => 'string'],
                        'file' => ['type' => 'string'],
                        'recovery_id' => ['type' => 'string'],
                        'recovery_expires_at' => ['type' => 'string'],
                        'recovery_scope' => ['type' => 'string', 'enum' => ['code']],
                    ],
                    'required' => ['action', 'plugin_slug', 'plugin_file', 'name', 'version', 'active', 'network_active'],
                ],
                'execute_callback'    => [$this, 'install'],
                'permission_callback' => [$this, 'canInstallPlugins'],
                'meta'                => $this->abilityMeta(true),
            ]
        );

        wp_register_ability(
            $activateAbility,
            [
                'label'               => 'Activate plugin',
                'description' => 'Activate a plugin at site or network scope using its exact plugin_file. Network scope requires multisite.',
                'category'            => $category,
                'input_schema'        => [
                    'type'       => 'object',
                    'properties' => [
                        'plugin_file' => [
                            'type'      => 'string',
                            'minLength' => 1,
                            'maxLength' => 255,
                        ],
                        'scope' => [
                            'type' => 'string',
                            'enum' => ['site', 'network'],
                        ],
                    ],
                    'required' => ['plugin_file', 'scope'],
                ],
                'output_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'plugin_file'    => ['type' => 'string'],
                        'active'         => ['type' => 'boolean'],
                        'network_active' => ['type' => 'boolean'],
                        'scope'          => ['type' => 'string'],
                    ],
                    'required' => ['plugin_file', 'active', 'network_active', 'scope'],
                ],
                'execute_callback'    => [$this, 'activate'],
                'permission_callback' => [$this, 'canActivatePlugins'],
                'meta'                => $this->abilityMeta(true, true),
            ]
        );

        wp_register_ability(
            $deactivateAbility,
            [
                'label'               => 'Deactivate plugin',
                'description' => 'Deactivate a plugin at site or network scope; a network-active plugin requires network scope.',
                'category'            => $category,
                'input_schema'        => [
                    'type'       => 'object',
                    'properties' => [
                        'plugin_file' => [
                            'type'      => 'string',
                            'minLength' => 1,
                            'maxLength' => 255,
                        ],
                        'scope' => [
                            'type' => 'string',
                            'enum' => ['site', 'network'],
                        ],
                    ],
                    'required' => ['plugin_file', 'scope'],
                ],
                'output_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'plugin_file'    => ['type' => 'string'],
                        'active'         => ['type' => 'boolean'],
                        'network_active' => ['type' => 'boolean'],
                        'scope'          => ['type' => 'string'],
                    ],
                    'required' => ['plugin_file', 'active', 'network_active', 'scope'],
                ],
                'execute_callback'    => [$this, 'deactivate'],
                'permission_callback' => [$this, 'canActivatePlugins'],
                'meta'                => $this->abilityMeta(true, true),
            ]
        );

        wp_register_ability(
            $deleteAbility,
            [
                'label' => 'Delete installed plugin',
                'description' => 'Uninstall and delete an inactive plugin. Multisite requires inactivity everywhere; refuses Codi MCP.',
                'category' => $category,
                'input_schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'plugin_file' => [
                            'type' => 'string',
                            'minLength' => 1,
                            'maxLength' => 255,
                        ],
                    ],
                    'required' => ['plugin_file'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'plugin_file' => ['type' => 'string'],
                        'deleted' => ['type' => 'boolean'],
                        'recovery_id' => ['type' => 'string'],
                        'recovery_expires_at' => ['type' => 'string'],
                        'recovery_scope' => ['type' => 'string', 'enum' => ['code']],
                    ],
                    'required' => ['plugin_file', 'deleted', 'recovery_id', 'recovery_expires_at', 'recovery_scope'],
                ],
                'execute_callback' => [$this, 'delete'],
                'permission_callback' => [$this, 'canDeletePlugins'],
                'meta' => $this->abilityMeta(true, false),
            ]
        );

        wp_register_ability(
            $updateAbility,
            [
                'label' => 'Update plugin from WordPress metadata',
                'description' => 'Install the exact plugin update currently advertised by WordPress. Creates a code recovery point and verifies active sites.',
                'category' => $category,
                'input_schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'plugin_file' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                    ],
                    'required' => ['plugin_file'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'action' => ['type' => 'string', 'enum' => ['updated']],
                        'plugin_file' => ['type' => 'string'],
                        'version' => ['type' => 'string'],
                        'recovery_id' => ['type' => 'string'],
                        'recovery_expires_at' => ['type' => 'string'],
                        'recovery_scope' => ['type' => 'string', 'enum' => ['code']],
                    ],
                    'required' => ['action', 'plugin_file', 'version', 'recovery_id', 'recovery_expires_at', 'recovery_scope'],
                ],
                'execute_callback' => [$this, 'update'],
                'permission_callback' => [$this, 'canInstallPlugins'],
                'meta' => $this->abilityMeta(true, false),
            ]
        );

        wp_register_ability(
            $restoreAbility,
            [
                'label' => 'Restore plugin code recovery point',
                'description' => 'Restore a Codi plugin code recovery point only if current source still matches the recorded post-change fingerprint. Does not restore database state.',
                'category' => $category,
                'input_schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'recovery_id' => ['type' => 'string', 'minLength' => 32, 'maxLength' => 32, 'pattern' => '^[a-f0-9]{32}$'],
                    ],
                    'required' => ['recovery_id'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'plugin_file' => ['type' => 'string'],
                        'version' => ['type' => 'string'],
                        'restored' => ['type' => 'boolean'],
                        'recovery_id' => ['type' => 'string'],
                        'recovery_scope' => ['type' => 'string', 'enum' => ['code']],
                    ],
                    'required' => ['plugin_file', 'version', 'restored', 'recovery_id', 'recovery_scope'],
                ],
                'execute_callback' => [$this, 'restore'],
                'permission_callback' => [$this, 'canInstallPlugins'],
                'meta' => $this->abilityMeta(true, false),
            ]
        );

        wp_register_ability(
            $pluginsListAbility,
            [
                'label' => 'List installed plugins',
                'description' => 'List installed plugins compactly by default; detail=full returns requirements, metadata, and recovery details.',
                'category' => $category,
                'input_schema' => PluginInspection::listInputSchema(),
                'output_schema' => PluginInspection::listOutputSchema(),
                'execute_callback' => [$this, 'pluginsList'],
                'permission_callback' => [$this, 'canInspectPlugins'],
                'meta' => $this->readOnlyMeta(),
            ]
        );

        wp_register_ability(
            $pluginInfoAbility,
            [
                'label' => 'Inspect installed plugin',
                'description' => 'Return details for one installed WordPress plugin, including version, requirements, activation scope, and cached update availability.',
                'category' => $category,
                'input_schema' => PluginInspection::infoInputSchema(),
                'output_schema' => PluginInspection::infoOutputSchema(),
                'execute_callback' => [$this, 'pluginInfo'],
                'permission_callback' => [$this, 'canInspectPlugins'],
                'meta' => $this->readOnlyMeta(),
            ]
        );

        wp_register_ability(
            $exportAbility,
            [
                'label' => 'Export installed plugin ZIP',
                'description' => 'Export a directory-backed plugin ZIP. Signed HTTPS URLs last five minutes and can be renewed with download_id for up to one hour; chunks enables sequential base64 transfer. Codi MCP itself cannot be exported.',
                'category' => $category,
                'input_schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'plugin_file' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                        'download_id' => [
                            'type' => 'string',
                            'minLength' => 32,
                            'maxLength' => 32,
                            'pattern' => '^[a-f0-9]{32}$',
                            'description' => 'Reuse a prior export snapshot to renew its signed URL or continue it in chunk mode; snapshots remain available for up to one hour.',
                        ],
                        'offset' => ['type' => 'integer', 'minimum' => 0],
                        'transfer' => DownloadCapabilityService::transferSchema(),
                    ],
                    'required' => ['plugin_file'],
                ],
                'output_schema' => DownloadCapabilityService::outputSchema('plugin_file'),
                'execute_callback' => [$this, 'export'],
                'permission_callback' => [$this, 'canExportPlugins'],
                'meta' => $this->abilityMeta(false, false),
            ]
        );
    }


    public function pluginsList($input)
    {
        return $this->inspection()->list(is_array($input) ? $input : []);
    }

    public function pluginInfo($input)
    {
        return $this->inspection()->info(is_array($input) ? $input : []);
    }

    public function export($input)
    {
        return $this->deployment()->export($input);
    }

    public function canInspectPlugins(): bool
    {
        return $this->inspection()->canInspect();
    }

    public function upload($input)
    {
        return $this->deployment()->upload($input);
    }

    public function install($input)
    {
        return $this->deployment()->install($input);
    }

    public function activate($input)
    {
        return $this->deployment()->activate($input);
    }

    public function deactivate($input)
    {
        return $this->deployment()->deactivate($input);
    }

    public function delete($input)
    {
        return $this->deployment()->delete($input);
    }

    public function update($input)
    {
        return $this->deployment()->update($input);
    }

    public function restore($input)
    {
        return $this->deployment()->restore($input);
    }

    public function canInstallPlugins(): bool
    {
        return $this->deployment()->canInstallPlugins();
    }

    public function canActivatePlugins(): bool
    {
        return $this->deployment()->canActivatePlugins();
    }

    public function canDeletePlugins(): bool
    {
        return $this->deployment()->canDeletePlugins();
    }

    public function canExportPlugins(): bool
    {
        return $this->deployment()->canExportPlugins();
    }

    private function abilityName(string $name): string
    {
        return rtrim(CODI_MCP_ABILITY_PREFIX, '/') . '/' . ltrim($name, '/');
    }

    private function category(): string
    {
        return rtrim(CODI_MCP_ABILITY_PREFIX, '/') . '-plugins';
    }

    private function uploadInputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => ReplacementSelection::schemaProperties() + [
                'plugin_slug' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 100,
                    'pattern' => '^[a-z0-9][a-z0-9-]*$',
                ],
                'size' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_ZIP_BYTES,
                ],
                'sha256' => [
                    'type' => 'string',
                    'minLength' => 64,
                    'maxLength' => 64,
                    'pattern' => '^[A-Fa-f0-9]{64}$',
                ],
            ],
            'required' => ['plugin_slug', 'size', 'sha256'],
        ];
    }

    private function uploadOutputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'upload_id' => ['type' => 'string'],
                'replace' => ['type' => 'string', 'enum' => ['entire', 'folder', 'file']],
                'folder' => ['type' => 'string'],
                'file' => ['type' => 'string'],
                'complete' => ['type' => 'boolean'],
                'upload' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'method' => ['type' => 'string', 'enum' => ['PUT']],
                        'url' => ['type' => 'string'],
                        'content_type' => ['type' => 'string'],
                        'size' => ['type' => 'integer'],
                        'sha256' => ['type' => 'string'],
                        'expires_at' => ['type' => 'string'],
                    ],
                    'required' => ['method', 'url', 'content_type', 'size', 'sha256', 'expires_at'],
                ],
                'instruction' => ['type' => 'string'],
            ],
            'required' => ['upload_id', 'complete', 'upload', 'instruction'],
        ];
    }

    private function deployment(): PluginDeployment
    {
        if ($this->deployment === null) {
            $this->deployment = new PluginDeployment(self::MAX_ZIP_BYTES, self::EXPORT_CHUNK_BYTES);
        }

        return $this->deployment;
    }

    private function inspection(): PluginInspection
    {
        return $this->inspection ??= new PluginInspection();
    }

    private function readOnlyMeta(): array
    {
        return AbilityMetadata::owned($this->key(), true, false, true);
    }

    private function abilityMeta(bool $destructive, bool $idempotent = false): array
    {
        return AbilityMetadata::owned($this->key(), false, $destructive, $idempotent);
    }
}
