<?php

namespace CodiMcp\Packages\Plugins;

use CodiMcp\Core\Abilities\AbilityMetadata;
use CodiMcp\Core\AbilityPackage;

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
                'description'         => 'Create a temporary HTTPS PUT destination for a plugin ZIP. This tool never accepts plugin bytes. Use a file-source tool on the machine holding the ZIP to stream the exact file directly to the returned upload.url, then pass upload_id to ' . $installAbility . '.',
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
                'description'         => 'Verify a completed upload and install it into the WordPress plugins directory. If that plugin directory already exists, overwrite it using WordPress Plugin_Upgrader. This does not intentionally change activation state. Use the returned plugin_file with ' . $activateAbility . ' or ' . $deactivateAbility . '.',
                'category'            => $category,
                'input_schema'        => [
                    'type'       => 'object',
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
                'description'         => 'Activate an installed plugin. Pass the exact plugin_file returned by ' . $installAbility . ' or WordPress, and choose site or network scope. Network scope is only valid on multisite.',
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
                'description'         => 'Deactivate an installed plugin at the requested site or network scope. Pass the exact plugin_file. A network-active plugin cannot be deactivated only for one site; use network scope.',
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
                'description' => 'Permanently uninstall and delete one exact inactive standard plugin through WordPress native plugin deletion. Codi MCP cannot delete itself. This ability is intentionally unavailable on multisite because plugin files are shared across sites.',
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
                    ],
                    'required' => ['plugin_file', 'deleted'],
                ],
                'execute_callback' => [$this, 'delete'],
                'permission_callback' => [$this, 'canDeletePlugins'],
                'meta' => $this->abilityMeta(true, false),
            ]
        );

        wp_register_ability(
            $pluginsListAbility,
            [
                'label' => 'List installed plugins',
                'description' => 'List installed WordPress plugins with versions, activation scope, requirements, and cached update availability.',
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
                'description' => 'Package one exact directory-backed standard plugin as a bounded ZIP and return it in sequential base64 chunks. On the first call omit download_id and use offset 0; subsequent calls repeat plugin_file and pass download_id with the returned next_offset. Codi MCP cannot export itself.',
                'category' => $category,
                'input_schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'plugin_file' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                        'download_id' => ['type' => 'string', 'minLength' => 32, 'maxLength' => 32, 'pattern' => '^[a-f0-9]{32}$'],
                        'offset' => ['type' => 'integer', 'minimum' => 0],
                    ],
                    'required' => ['plugin_file', 'offset'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'download_id' => ['type' => 'string'],
                        'plugin_file' => ['type' => 'string'],
                        'filename' => ['type' => 'string'],
                        'size' => ['type' => 'integer'],
                        'sha256' => ['type' => 'string'],
                        'offset' => ['type' => 'integer'],
                        'next_offset' => ['type' => 'integer'],
                        'chunk_size' => ['type' => 'integer'],
                        'data' => ['type' => 'string'],
                        'complete' => ['type' => 'boolean'],
                    ],
                    'required' => ['download_id', 'plugin_file', 'filename', 'size', 'sha256', 'offset', 'next_offset', 'chunk_size', 'data', 'complete'],
                ],
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
            'properties' => [
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
