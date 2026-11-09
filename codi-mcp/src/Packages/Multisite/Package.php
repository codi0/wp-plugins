<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Multisite;

use CodiMcp\Core\Abilities\AbilityMetadata;
use CodiMcp\Core\AbilityPackage;
use CodiMcp\Core\ManagedExposurePackage;
use CodiMcp\Core\PackageRuntime;
use CodiMcp\Core\RuntimePackage;

final class Package implements AbilityPackage, RuntimePackage, ManagedExposurePackage
{
    private const SLUGS = array('sites', 'site-abilities', 'site-call');

    private ?PackageRuntime $runtime = null;
    private ?SiteDirectory $sites = null;
    private ?LocalAbilityRuntime $local = null;
    private ?FederationSigner $signer = null;
    private ?ReplayGuard $replay = null;
    private ?FederationClient $client = null;
    private ?FederationController $controller = null;
    private ?Gateway $gateway = null;

    public function key(): string
    {
        return 'multisite';
    }

    public function label(): string
    {
        return 'Multisite';
    }

    public function abilityNames(): array
    {
        return array_map(fn (string $slug): string => $this->abilityName($slug), self::SLUGS);
    }

    public function managedExposureAbilityNames(): array
    {
        return $this->sites()->isGatewaySite() ? $this->abilityNames() : array();
    }

    public function registerRuntime(PackageRuntime $runtime): void
    {
        $this->runtime = $runtime;
        if (!function_exists('is_multisite') || !is_multisite()) {
            return;
        }

        if (function_exists('add_action')) {
            add_action('rest_api_init', array($this->controller(), 'registerRoute'));
        }
        if (function_exists('add_filter')) {
            add_filter('codi_mcp_transport_permission', array($this->gateway(), 'extendTransportPermission'), 10, 2);
        }
    }

    public function registerCategories(): void
    {
        if (!$this->sites()->isGatewaySite() || !function_exists('wp_register_ability_category')) {
            return;
        }
        wp_register_ability_category($this->category(), array(
            'label' => 'Codi MCP — Multisite',
            'description' => 'Network site discovery and secure routing into each target site\'s own Codi ability runtime.',
        ));
    }

    public function registerAbilities(): void
    {
        if (!$this->sites()->isGatewaySite() || !function_exists('wp_register_ability')) {
            return;
        }

        wp_register_ability($this->abilityName('sites'), array(
            'label' => 'List accessible network sites',
            'description' => 'List accessible sites and IDs. Use only when the target ID is unknown.',
            'category' => $this->category(),
            'input_schema' => $this->emptyInputSchema(),
            'output_schema' => $this->sitesOutputSchema(),
            'execute_callback' => array($this->gateway(), 'sites'),
            'permission_callback' => array($this->gateway(), 'canUse'),
            'meta' => $this->meta(true, false, true),
        ));

        wp_register_ability($this->abilityName('site-abilities'), array(
            'label' => 'List target-site abilities',
            'description' => 'Find a site\'s exposed abilities by name or purpose. Prefer search; exact names win, otherwise all terms must match. Reuse results.',
            'category' => $this->category(),
            'input_schema' => $this->siteInputSchema(),
            'output_schema' => $this->abilitiesOutputSchema(),
            'execute_callback' => array($this->gateway(), 'siteAbilities'),
            'permission_callback' => array($this->gateway(), 'canTarget'),
            'meta' => $this->meta(true, false, true),
        ));

        wp_register_ability($this->abilityName('site-call'), array(
            'label' => 'Call target-site ability',
            'description' => 'Run a known ability on an explicit site. Target failures are returned in the structured error field.',
            'category' => $this->category(),
            'input_schema' => $this->callInputSchema(),
            'output_schema' => $this->callOutputSchema(),
            'execute_callback' => array($this->gateway(), 'siteCall'),
            'permission_callback' => array($this->gateway(), 'canTarget'),
            'meta' => $this->meta(false, true, false),
        ));
    }

    private function sites(): SiteDirectory
    {
        return $this->sites ??= new SiteDirectory();
    }

    private function local(): LocalAbilityRuntime
    {
        if (!$this->runtime instanceof PackageRuntime) {
            throw new \RuntimeException('Multisite package runtime has not been registered.');
        }
        return $this->local ??= new LocalAbilityRuntime($this->runtime, $this->abilityNames());
    }

    private function signer(): FederationSigner
    {
        return $this->signer ??= new FederationSigner($this->sites());
    }

    private function replay(): ReplayGuard
    {
        return $this->replay ??= new ReplayGuard($this->sites());
    }

    private function client(): FederationClient
    {
        return $this->client ??= new FederationClient($this->sites(), $this->signer(), $this->local());
    }

    private function controller(): FederationController
    {
        return $this->controller ??= new FederationController($this->sites(), $this->signer(), $this->replay(), $this->local());
    }

    private function gateway(): Gateway
    {
        return $this->gateway ??= new Gateway($this->sites(), $this->client());
    }

    private function abilityName(string $slug): string
    {
        $prefix = defined('CODI_MCP_ABILITY_PREFIX') ? (string) CODI_MCP_ABILITY_PREFIX : 'codi';
        return rtrim($prefix, '/') . '/' . ltrim($slug, '/');
    }

    private function category(): string
    {
        $prefix = defined('CODI_MCP_ABILITY_PREFIX') ? (string) CODI_MCP_ABILITY_PREFIX : 'codi';
        return rtrim($prefix, '/') . '-multisite';
    }

    private function emptyInputSchema(): array
    {
        return array('type' => 'object', 'additionalProperties' => false, 'properties' => array());
    }

    private function siteInputSchema(): array
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'site_id' => array('type' => 'integer', 'minimum' => 1),
                'search' => array('type' => 'string', 'maxLength' => 100),
                'detail' => array('type' => 'string', 'enum' => array('auto', 'summary', 'schema'), 'default' => 'auto', 'description' => 'Auto includes input schemas for a single match and omits them for multiple matches. Use schema or summary to force a view.'),
            ),
            'required' => array('site_id'),
        );
    }

    private function callInputSchema(): array
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'site_id' => array('type' => 'integer', 'minimum' => 1),
                'ability' => array('type' => 'string', 'minLength' => 3, 'maxLength' => 191, 'pattern' => '^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$'),
                'arguments' => $this->jsonValueSchema(),
            ),
            'required' => array('site_id', 'ability'),
        );
    }

    private function sitesOutputSchema(): array
    {
        $site = $this->strictObject(array(
            'site_id' => array('type' => 'integer'),
            'name' => array('type' => 'string'),
            'url' => array('type' => 'string'),
            'is_main' => array('type' => 'boolean'),
        ));
        return $this->strictObject(array(
            'items' => array('type' => 'array', 'items' => $site),
        ));
    }

    private function abilitiesOutputSchema(): array
    {
        $descriptor = $this->strictObject(array(
            'name' => array('type' => 'string'),
            'description' => array('type' => 'string'),
            'input_schema' => array('type' => array('object', 'null'), 'additionalProperties' => true),
            'annotations' => $this->strictObject(array(
                'readonly' => array('type' => 'boolean'),
                'destructive' => array('type' => 'boolean'),
                'idempotent' => array('type' => 'boolean'),
            )),
        ));
        $descriptor['required'] = array('name', 'description', 'annotations');
        return $this->strictObject(array(
            'abilities' => array('type' => 'array', 'items' => $descriptor),
        ));
    }

    private function callOutputSchema(): array
    {
        $error = $this->strictObject(array(
            'code' => array('type' => 'string'),
            'message' => array('type' => 'string'),
            'status' => array('type' => array('integer', 'null'), 'minimum' => 400, 'maximum' => 599),
            'details' => array('type' => 'object', 'additionalProperties' => true),
        ));
        $error['required'] = array('code', 'message', 'status', 'details');
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'result' => $this->jsonValueSchema(),
                'error' => $error,
            ),
            'required' => array('result'),
        );
    }

    private function jsonValueSchema(): array
    {
        return array('type' => array('object', 'array', 'string', 'number', 'boolean', 'null'));
    }

    private function strictObject(array $properties): array
    {
        return array('type' => 'object', 'additionalProperties' => false, 'properties' => $properties, 'required' => array_keys($properties));
    }

    private function meta(bool $readonly, bool $destructive, bool $idempotent): array
    {
        return AbilityMetadata::owned($this->key(), $readonly, $destructive, $idempotent);
    }
}
