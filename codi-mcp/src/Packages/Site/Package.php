<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Site;

use CodiMcp\Core\Abilities\AbilityMetadata;
use CodiMcp\Core\AbilityPackage;

final class Package implements AbilityPackage
{
    private ?SiteManager $manager = null;

    public function key(): string
    {
        return 'site';
    }

    public function label(): string
    {
        return 'Site';
    }

    public function abilityNames(): array
    {
        return array(
            $this->abilityName('site-config'),
            $this->abilityName('site-config-update'),
        );
    }

    public function registerCategories(): void
    {
        if (!function_exists('wp_register_ability_category')) {
            return;
        }
        wp_register_ability_category($this->category(), array(
            'label' => 'Codi MCP — Site',
            'description' => 'Typed, explicitly allowlisted WordPress site configuration abilities.',
        ));
    }

    public function registerAbilities(): void
    {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        $this->registerReadOnly(
            'site-config',
            'Inspect site configuration',
            'Return a typed allowlist of development-relevant WordPress site settings. Sensitive settings such as admin email, registration policy, home/site URLs, and arbitrary options are intentionally excluded.',
            $this->emptyInputSchema(),
            $this->configSchema(),
            [$this, 'siteConfig'],
            [$this, 'canManageOptions']
        );
        $this->registerMutation(
            'site-config-update',
            'Update site configuration',
            'Update only the explicitly supported typed site settings. This is not a generic option setter. Front/posts pages are validated as pages, locales must be installed, and permalink changes trigger a soft rewrite flush.',
            $this->configUpdateInputSchema(),
            $this->strictObject(array('changed' => $this->stringArraySchema(), 'config' => $this->configSchema())),
            [$this, 'siteConfigUpdate'],
            [$this, 'canManageOptions'],
            true,
            true
        );
    }

    public function siteConfig($input = array()) { return $this->manager()->config(is_array($input) ? $input : array()); }
    public function siteConfigUpdate($input = array()) { return $this->manager()->updateConfig(is_array($input) ? $input : array()); }
    public function canManageOptions($input = array()): bool { return $this->manager()->canManageOptions($input); }

    private function manager(): SiteManager
    {
        return $this->manager ??= new SiteManager();
    }

    private function registerReadOnly(string $slug, string $label, string $description, array $inputSchema, array $outputSchema, callable $callback, callable $permissionCallback): void
    {
        wp_register_ability($this->abilityName($slug), array(
            'label' => $label,
            'description' => $description,
            'category' => $this->category(),
            'input_schema' => $inputSchema,
            'output_schema' => $outputSchema,
            'execute_callback' => $callback,
            'permission_callback' => $permissionCallback,
            'meta' => AbilityMetadata::owned($this->key(), true, false, true),

        ));
    }

    private function registerMutation(string $slug, string $label, string $description, array $inputSchema, array $outputSchema, callable $callback, callable $permissionCallback, bool $destructive, bool $idempotent): void
    {
        wp_register_ability($this->abilityName($slug), array(
            'label' => $label,
            'description' => $description,
            'category' => $this->category(),
            'input_schema' => $inputSchema,
            'output_schema' => $outputSchema,
            'execute_callback' => $callback,
            'permission_callback' => $permissionCallback,
            'meta' => AbilityMetadata::owned($this->key(), false, $destructive, $idempotent),

        ));
    }

    private function abilityName(string $slug): string
    {
        return rtrim(CODI_MCP_ABILITY_PREFIX, '/') . '/' . ltrim($slug, '/');
    }

    private function category(): string
    {
        return rtrim(CODI_MCP_ABILITY_PREFIX, '/') . '-site';
    }

    private function emptyInputSchema(): array
    {
        return array('type' => 'object', 'additionalProperties' => false, 'properties' => array());
    }

    private function configUpdateInputSchema(): array
    {
        return array('type' => 'object', 'additionalProperties' => false, 'properties' => array(
            'site_title' => array('type' => 'string', 'maxLength' => 200),
            'tagline' => array('type' => 'string', 'maxLength' => 500),
            'front_page_mode' => array('type' => 'string', 'enum' => array('posts', 'page')),
            'page_on_front' => array('type' => 'integer', 'minimum' => 0),
            'page_for_posts' => array('type' => 'integer', 'minimum' => 0),
            'timezone' => array('type' => 'string', 'maxLength' => 100),
            'permalink_structure' => array('type' => 'string', 'maxLength' => 200),
            'posts_per_page' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100),
            'search_engine_visibility' => array('type' => 'boolean'),
            'locale' => array('type' => 'string', 'minLength' => 2, 'maxLength' => 20, 'pattern' => '^[A-Za-z0-9_@.-]+$'),
            'date_format' => array('type' => 'string', 'maxLength' => 100),
            'time_format' => array('type' => 'string', 'maxLength' => 100),
            'start_of_week' => array('type' => 'integer', 'minimum' => 0, 'maximum' => 6),
        ));
    }

    private function configSchema(): array
    {
        return $this->strictObject(array(
            'site_title' => array('type' => 'string'),
            'tagline' => array('type' => 'string'),
            'front_page_mode' => array('type' => 'string'),
            'page_on_front' => array('type' => 'integer'),
            'page_for_posts' => array('type' => 'integer'),
            'timezone' => array('type' => 'string'),
            'permalink_structure' => array('type' => 'string'),
            'posts_per_page' => array('type' => 'integer'),
            'search_engine_visibility' => array('type' => 'boolean'),
            'locale' => array('type' => 'string'),
            'date_format' => array('type' => 'string'),
            'time_format' => array('type' => 'string'),
            'start_of_week' => array('type' => 'integer'),
        ));
    }


    private function strictObject(array $properties): array
    {
        return array('type' => 'object', 'additionalProperties' => false, 'properties' => $properties, 'required' => array_keys($properties));
    }

    private function stringArraySchema(): array
    {
        return array('type' => 'array', 'items' => array('type' => 'string'));
    }
}
