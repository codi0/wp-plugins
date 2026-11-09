<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Themes;

use CodiMcp\Core\Abilities\AbilityMetadata;
use CodiMcp\Core\AbilityPackage;
use CodiMcp\Core\Deployment\ReplacementSelection;
use CodiMcp\Core\Downloads\DownloadCapabilityService;

final class Package implements AbilityPackage
{
    private const MAX_ZIP_BYTES = 20 * 1024 * 1024;
    private const EXPORT_CHUNK_BYTES = 256 * 1024;

    private ?ThemeInspection $inspection = null;
    private ?ThemeDeployment $deployment = null;

    public function key(): string { return 'themes'; }
    public function label(): string { return 'Themes'; }

    public function abilityNames(): array
    {
        return array(
            $this->abilityName('theme-upload'),
            $this->abilityName('theme-install'),
            $this->abilityName('theme-activate'),
            $this->abilityName('theme-delete'),
            $this->abilityName('theme-update'),
            $this->abilityName('theme-restore'),
            $this->abilityName('themes-list'),
            $this->abilityName('theme-info'),
            $this->abilityName('theme-export'),
        );
    }

    public function registerCategories(): void
    {
        if (!function_exists('wp_register_ability_category')) { return; }
        wp_register_ability_category($this->category(), array(
            'label' => 'Codi MCP — Themes',
            'description' => 'Installed-theme inspection, ZIP deployment/export, activation, and deletion abilities.',
        ));
    }

    public function registerAbilities(): void
    {
        if (!function_exists('wp_register_ability')) { return; }

        $this->registerMutation(
            'theme-upload',
            'Create theme upload',
            'Stage a theme ZIP by one-time PUT, then call theme-install. replace selects the whole package, one folder, or one existing file.',
            array(
                'type' => 'object', 'additionalProperties' => false,
                'properties' => ReplacementSelection::schemaProperties() + array(
                    'stylesheet' => ThemeInspection::stylesheetSchema(),
                    'size' => array('type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_ZIP_BYTES),
                    'sha256' => array('type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$'),
                ),
                'required' => array('stylesheet', 'size', 'sha256'),
            ),
            $this->uploadOutputSchema(),
            [$this, 'upload'], [$this, 'canInstallThemes'], false, false
        );

        $this->registerMutation(
            'theme-install',
            'Install uploaded theme',
            'Install an uploaded ZIP with its bound entire/folder/file selection. Does not switch the active theme.',
            $this->strictObject(array('upload_id' => $this->idSchema())),
            array(
                'type' => 'object', 'additionalProperties' => false,
                'properties' => array(
                'action' => array('type' => 'string', 'enum' => array('installed', 'updated')),
                'replace' => array('type' => 'string', 'enum' => array('entire', 'folder', 'file')), 'folder' => array('type' => 'string'), 'file' => array('type' => 'string'),
                'stylesheet' => array('type' => 'string'), 'name' => array('type' => 'string'), 'version' => array('type' => 'string'), 'active' => array('type' => 'boolean'),
                'recovery_id' => array('type' => 'string'), 'recovery_expires_at' => array('type' => 'string'), 'recovery_scope' => array('type' => 'string', 'enum' => array('code')),
                ),
                'required' => array('action', 'replace', 'folder', 'stylesheet', 'name', 'version', 'active'),
            ),
            [$this, 'install'], [$this, 'canInstallThemes'], true, false
        );

        $this->registerMutation(
            'theme-activate',
            'Activate installed theme',
            'Switch to an installed theme already allowed on this site.',
            $this->strictObject(array('stylesheet' => ThemeInspection::stylesheetSchema())),
            $this->strictObject(array('stylesheet' => array('type' => 'string'), 'name' => array('type' => 'string'), 'active' => array('type' => 'boolean'), 'changed' => array('type' => 'boolean'))),
            [$this, 'activate'], [$this, 'canActivateThemes'], true, true
        );

        $this->registerMutation(
            'theme-delete',
            'Delete installed theme',
            'Delete an inactive theme; refuses if it or a child theme is active anywhere.',
            $this->strictObject(array('stylesheet' => ThemeInspection::stylesheetSchema())),
            $this->strictObject(array(
                'stylesheet' => array('type' => 'string'), 'deleted' => array('type' => 'boolean'),
                'recovery_id' => array('type' => 'string'), 'recovery_expires_at' => array('type' => 'string'), 'recovery_scope' => array('type' => 'string', 'enum' => array('code')),
            )),
            [$this, 'delete'], [$this, 'canDeleteThemes'], true, false
        );

        $this->registerMutation(
            'theme-update',
            'Update theme from WordPress metadata',
            'Install the exact theme update currently advertised by WordPress. Creates a code recovery point and verifies active sites.',
            $this->strictObject(array('stylesheet' => ThemeInspection::stylesheetSchema())),
            $this->strictObject(array(
                'action' => array('type' => 'string', 'enum' => array('updated')),
                'stylesheet' => array('type' => 'string'),
                'version' => array('type' => 'string'),
                'recovery_id' => array('type' => 'string'),
                'recovery_expires_at' => array('type' => 'string'),
                'recovery_scope' => array('type' => 'string', 'enum' => array('code')),
            )),
            [$this, 'update'], [$this, 'canInstallThemes'], true, false
        );

        $this->registerMutation(
            'theme-restore',
            'Restore theme code recovery point',
            'Restore a Codi theme code recovery point only if current source still matches the recorded post-change fingerprint. Does not restore database state.',
            $this->strictObject(array('recovery_id' => $this->idSchema())),
            $this->strictObject(array(
                'stylesheet' => array('type' => 'string'),
                'version' => array('type' => 'string'),
                'restored' => array('type' => 'boolean'),
                'recovery_id' => array('type' => 'string'),
                'recovery_scope' => array('type' => 'string', 'enum' => array('code')),
            )),
            [$this, 'restore'], [$this, 'canInstallThemes'], true, false
        );

        $this->registerReadOnly('themes-list', 'List installed themes', 'List installed themes compactly by default; detail=full returns requirements, metadata, and recovery details.', ThemeInspection::listInputSchema(), ThemeInspection::listOutputSchema(), [$this, 'themesList'], [$this, 'canInspectThemes']);
        $this->registerReadOnly('theme-info', 'Inspect installed theme', 'Return detailed metadata for one exact installed theme without reading source files.', ThemeInspection::infoInputSchema(), ThemeInspection::infoOutputSchema(), [$this, 'themeInfo'], [$this, 'canInspectThemes']);

        $this->registerMutation(
            'theme-export',
            'Export installed theme ZIP',
            'Export a theme ZIP by HTTPS; block themes include saved templates and Global Styles. chunks enables sequential base64 transfer.',
            array(
                'type' => 'object', 'additionalProperties' => false,
                'properties' => array('stylesheet' => ThemeInspection::stylesheetSchema(), 'download_id' => $this->idSchema(), 'offset' => array('type' => 'integer', 'minimum' => 0), 'transfer' => DownloadCapabilityService::transferSchema()),
                'required' => array('stylesheet'),
            ),
            DownloadCapabilityService::outputSchema('stylesheet'),
            [$this, 'export'], [$this, 'canExportThemes'], false, false
        );
    }

    public function themesList($input = array()) { return $this->inspection()->list(is_array($input) ? $input : array()); }
    public function themeInfo($input = array()) { return $this->inspection()->info(is_array($input) ? $input : array()); }
    public function upload($input = array()) { return $this->deployment()->upload($input); }
    public function install($input = array()) { return $this->deployment()->install($input); }
    public function activate($input = array()) { return $this->deployment()->activate($input); }
    public function delete($input = array()) { return $this->deployment()->delete($input); }
    public function update($input = array()) { return $this->deployment()->update($input); }
    public function restore($input = array()) { return $this->deployment()->restore($input); }
    public function export($input = array()) { return $this->deployment()->export($input); }
    public function canInspectThemes(): bool { return $this->inspection()->canInspect(); }
    public function canInstallThemes(): bool { return $this->deployment()->canInstallThemes(); }
    public function canActivateThemes(): bool { return $this->deployment()->canActivateThemes(); }
    public function canDeleteThemes(): bool { return $this->deployment()->canDeleteThemes(); }
    public function canExportThemes(): bool { return $this->deployment()->canExportThemes(); }

    private function inspection(): ThemeInspection { return $this->inspection ??= new ThemeInspection(); }
    private function deployment(): ThemeDeployment { return $this->deployment ??= new ThemeDeployment(self::MAX_ZIP_BYTES, self::EXPORT_CHUNK_BYTES); }
    private function abilityName(string $slug): string { return rtrim(CODI_MCP_ABILITY_PREFIX, '/') . '/' . ltrim($slug, '/'); }
    private function category(): string { return rtrim(CODI_MCP_ABILITY_PREFIX, '/') . '-themes'; }

    private function registerReadOnly(string $slug, string $label, string $description, array $inputSchema, array $outputSchema, callable $callback, callable $permission): void
    {
        wp_register_ability($this->abilityName($slug), array(
            'label' => $label, 'description' => $description, 'category' => $this->category(),
            'input_schema' => $inputSchema, 'output_schema' => $outputSchema,
            'execute_callback' => $callback, 'permission_callback' => $permission,
            'meta' => AbilityMetadata::owned($this->key(), true, false, true),
        ));
    }

    private function registerMutation(string $slug, string $label, string $description, array $inputSchema, array $outputSchema, callable $callback, callable $permission, bool $destructive, bool $idempotent): void
    {
        wp_register_ability($this->abilityName($slug), array(
            'label' => $label, 'description' => $description, 'category' => $this->category(),
            'input_schema' => $inputSchema, 'output_schema' => $outputSchema,
            'execute_callback' => $callback, 'permission_callback' => $permission,
            'meta' => AbilityMetadata::owned($this->key(), false, $destructive, $idempotent),
        ));
    }

    private function idSchema(): array { return array('type' => 'string', 'minLength' => 32, 'maxLength' => 32, 'pattern' => '^[a-f0-9]{32}$'); }
    private function strictObject(array $properties): array { return array('type' => 'object', 'additionalProperties' => false, 'properties' => $properties, 'required' => array_keys($properties)); }

    private function uploadOutputSchema(): array
    {
        return $this->strictObject(array(
            'upload_id' => array('type' => 'string'),
            'complete' => array('type' => 'boolean'),
            'replace' => array('type' => 'string', 'enum' => array('entire', 'folder', 'file')),
            'folder' => array('type' => 'string'),
            'file' => array('type' => 'string'),
            'upload' => $this->strictObject(array(
                'method' => array('type' => 'string', 'enum' => array('PUT')),
                'url' => array('type' => 'string'),
                'content_type' => array('type' => 'string'),
                'size' => array('type' => 'integer'),
                'sha256' => array('type' => 'string'),
                'expires_at' => array('type' => 'string'),
            )),
            'instruction' => array('type' => 'string'),
        ));
    }

}
