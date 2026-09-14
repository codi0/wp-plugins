<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Themes;

use CodiMcp\Core\Abilities\AbilityMetadata;
use CodiMcp\Core\AbilityPackage;

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
            'Create a temporary HTTPS PUT destination for a theme ZIP. This tool never accepts theme bytes. Use a file-source tool on the machine holding the ZIP to stream the exact file directly to the returned upload.url, then pass upload_id to codi/theme-install.',
            array(
                'type' => 'object', 'additionalProperties' => false,
                'properties' => array(
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
            'Verify a completed staged ZIP and install or overwrite the matching installed theme through WordPress Theme_Upgrader. This is the supplied-ZIP update path and does not intentionally change the active theme.',
            $this->strictObject(array('upload_id' => $this->idSchema())),
            $this->strictObject(array(
                'action' => array('type' => 'string', 'enum' => array('installed', 'updated')),
                'stylesheet' => array('type' => 'string'), 'name' => array('type' => 'string'), 'version' => array('type' => 'string'), 'active' => array('type' => 'boolean'),
            )),
            [$this, 'install'], [$this, 'canInstallThemes'], true, false
        );

        $this->registerMutation(
            'theme-activate',
            'Activate installed theme',
            'Activate one exact installed theme. On multisite the theme must already be enabled for the current site; this ability never changes network theme allowance.',
            $this->strictObject(array('stylesheet' => ThemeInspection::stylesheetSchema())),
            $this->strictObject(array('stylesheet' => array('type' => 'string'), 'name' => array('type' => 'string'), 'active' => array('type' => 'boolean'), 'changed' => array('type' => 'boolean'))),
            [$this, 'activate'], [$this, 'canActivateThemes'], true, true
        );

        $this->registerMutation(
            'theme-delete',
            'Delete installed theme',
            'Permanently delete one exact inactive theme through WordPress native theme deletion. The active theme, its active parent, and any parent required by an installed child theme are protected. Deletion is unavailable on multisite because theme files are shared.',
            $this->strictObject(array('stylesheet' => ThemeInspection::stylesheetSchema())),
            $this->strictObject(array('stylesheet' => array('type' => 'string'), 'deleted' => array('type' => 'boolean'))),
            [$this, 'delete'], [$this, 'canDeleteThemes'], true, false
        );

        $this->registerReadOnly('themes-list', 'List installed themes', 'List installed themes with requirements, parent/child state, block-theme state, current-site allowance, WordPress-detected errors, and cached update availability.', ThemeInspection::listInputSchema(), ThemeInspection::listOutputSchema(), [$this, 'themesList'], [$this, 'canInspectThemes']);
        $this->registerReadOnly('theme-info', 'Inspect installed theme', 'Return detailed metadata for one exact installed theme without reading source files.', ThemeInspection::infoInputSchema(), ThemeInspection::infoOutputSchema(), [$this, 'themeInfo'], [$this, 'canInspectThemes']);

        $this->registerMutation(
            'theme-export',
            'Export installed theme ZIP',
            'Package one exact installed theme directory as a bounded ZIP and return it in sequential base64 chunks. On the first call omit download_id and use offset 0; subsequent calls repeat stylesheet and pass download_id with next_offset. Symbolic links and out-of-root files are rejected.',
            array(
                'type' => 'object', 'additionalProperties' => false,
                'properties' => array('stylesheet' => ThemeInspection::stylesheetSchema(), 'download_id' => $this->idSchema(), 'offset' => array('type' => 'integer', 'minimum' => 0)),
                'required' => array('stylesheet', 'offset'),
            ),
            $this->exportOutputSchema('stylesheet'),
            [$this, 'export'], [$this, 'canExportThemes'], false, false
        );
    }

    public function themesList($input = array()) { return $this->inspection()->list(is_array($input) ? $input : array()); }
    public function themeInfo($input = array()) { return $this->inspection()->info(is_array($input) ? $input : array()); }
    public function upload($input = array()) { return $this->deployment()->upload($input); }
    public function install($input = array()) { return $this->deployment()->install($input); }
    public function activate($input = array()) { return $this->deployment()->activate($input); }
    public function delete($input = array()) { return $this->deployment()->delete($input); }
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

    private function exportOutputSchema(string $identityField): array
    {
        return $this->strictObject(array(
            'download_id' => array('type' => 'string'), $identityField => array('type' => 'string'), 'filename' => array('type' => 'string'),
            'size' => array('type' => 'integer'), 'sha256' => array('type' => 'string'), 'offset' => array('type' => 'integer'), 'next_offset' => array('type' => 'integer'),
            'chunk_size' => array('type' => 'integer'), 'data' => array('type' => 'string'), 'complete' => array('type' => 'boolean'),
        ));
    }
}
