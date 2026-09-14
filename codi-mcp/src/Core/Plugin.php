<?php

namespace CodiMcp\Core;

use CodiMcp\Adapter\CodiServer;
use CodiMcp\Adapter\DirectMcpPresentation;
use CodiMcp\Core\Abilities\AbilityCatalogue;
use CodiMcp\Audit\AuditedAbility;
use CodiMcp\Audit\AuditLog;
use CodiMcp\Admin\AdminPage;
use CodiMcp\Admin\NetworkAdminPage;
use CodiMcp\Auth\AuthHeader;
use CodiMcp\Auth\McpAuthenticator;
use CodiMcp\Auth\OAuthController;
use CodiMcp\Auth\OAuthProfile;
use CodiMcp\Auth\OAuthServer;
use CodiMcp\Auth\OAuthStore;
use CodiMcp\Exposure\ExposurePolicy;
use CodiMcp\Core\Uploads\UploadCapabilityService;

final class Plugin
{
    /** @var callable[] */
    private array $packageFactories;

    /** @var AbilityPackage[]|null */
    private ?array $packages = null;

    private bool $registered = false;
    private ?CodiServer $mcpServer = null;
    private ?CodiServer $networkMcpServer = null;
    private ?AbilityCatalogue $catalogue = null;
    private ?ExposurePolicy $exposure = null;
    private ?DirectMcpPresentation $presentation = null;
    private ?PackageRuntime $packageRuntime = null;
    private ?OAuthProfile $oauthProfile = null;
    private ?OAuthProfile $networkOAuthProfile = null;
    private ?OAuthStore $oauthStore = null;
    private ?OAuthStore $networkOAuthStore = null;
    private ?OAuthServer $oauthServer = null;
    private ?OAuthServer $networkOAuthServer = null;
    private ?OAuthController $oauthController = null;
    private ?OAuthController $networkOAuthController = null;
    private ?McpAuthenticator $authenticator = null;
    private ?McpAuthenticator $networkAuthenticator = null;
    private ?AdminPage $adminPage = null;
    private ?NetworkAdminPage $networkAdminPage = null;
    private ?PackageAvailabilityPolicy $availability = null;
    private ?AuditLog $auditLog = null;

    /** @param callable[] $packageFactories */
    public function __construct(array $packageFactories)
    {
        $this->packageFactories = array_values(array_filter($packageFactories, 'is_callable'));
    }

    public function register(): void
    {
        if ($this->registered || !function_exists('add_action')) {
            return;
        }

        $this->registered = true;
        $this->registerPackageRuntimes();
        UploadCapabilityService::registerCleanupCron();

        add_action('wp_abilities_api_categories_init', [$this, 'registerCategories']);
        add_action('wp_abilities_api_init', [$this, 'registerAbilities']);
        add_action('mcp_adapter_init', [$this, 'registerMcpServer'], 10, 1);
        add_action('rest_api_init', [$this, 'registerOAuthRoutes']);
        add_action('parse_request', [$this, 'serveWellKnownMetadata'], 1);
        if (function_exists('add_filter')) {
            add_filter('wp_register_ability_args', [$this, 'filterAbilityArgs'], 10, 2);
            add_filter('determine_current_user', [$this, 'determineCurrentUser'], 20, 1);
            add_filter('rest_pre_dispatch', [$this, 'preDispatch'], 10, 3);
            add_filter('rest_post_dispatch', [$this, 'postDispatch'], 20, 3);
            add_filter('rest_pre_serve_request', [$this, 'serveRawOAuthResponse'], 20, 4);
            add_filter('rest_exposed_cors_headers', [$this, 'filterExposedCorsHeaders'], 10, 1);
            add_filter('rest_allowed_cors_headers', [$this, 'filterAllowedCorsHeaders'], 10, 1);
        }

        if (function_exists('is_admin') && is_admin()) {
            add_action('admin_menu', [$this, 'registerAdminMenu']);
            add_action('admin_post_codi_mcp_save_exposure', [$this, 'saveExposure']);
            add_action('admin_post_codi_mcp_save_packages', [$this, 'saveSitePackages']);
            if (function_exists('is_multisite') && is_multisite()) {
                add_action('network_admin_menu', [$this, 'registerNetworkAdminMenu']);
                add_action('network_admin_edit_codi_mcp_save_network_packages', [$this, 'saveNetworkPackages']);
            }
        }
    }

    public function registerCategories(): void
    {
        foreach ($this->enabledPackages() as $package) {
            $package->registerCategories();
        }
    }

    public function registerAbilities(): void
    {
        foreach ($this->enabledPackages() as $package) {
            $package->registerAbilities();
        }
    }

    public function filterAbilityArgs(array $args, string $abilityName): array
    {
        $prefix = rtrim(CODI_MCP_ABILITY_PREFIX, '/') . '/';
        $codiMeta = is_array($args['meta']['codi_mcp'] ?? null) ? $args['meta']['codi_mcp'] : array();
        $package = $codiMeta['package'] ?? '';
        if (!str_starts_with($abilityName, $prefix)
            || $abilityName === $prefix . 'audit-log'
            || ($codiMeta['owned'] ?? false) !== true
            || !is_scalar($package)
            || trim((string) $package) === '') {
            return $args;
        }

        $args['ability_class'] = AuditedAbility::class;
        return $args;
    }

    public function registerMcpServer($adapter): void
    {
        $this->mcpServer()->register($adapter);
        if ($this->networkGatewayEnabled()) {
            $this->networkMcpServer()->register($adapter);
        }
    }

    public function registerOAuthRoutes(): void
    {
        if (!function_exists('register_rest_route')) {
            return;
        }

        $this->registerOAuthRoutesFor($this->oauthProfile(), $this->oauthController(), false);
        if ($this->networkGatewayEnabled()) {
            $this->registerOAuthRoutesFor($this->networkOAuthProfile(), $this->networkOAuthController(), true);
        }

        UploadCapabilityService::registerRoute();

        foreach ($this->enabledPackages() as $package) {
            if (method_exists($package, 'registerRoutes')) {
                $package->registerRoutes();
            }
        }
    }

    private function registerOAuthRoutesFor(OAuthProfile $profile, OAuthController $controller, bool $networkScoped): void
    {
        $namespace = $profile->oauthNamespace();
        register_rest_route($namespace, '/oauth/register', [
            'methods' => 'POST',
            'callback' => static function ($request) use ($controller) {
                return $controller->registerClient($request);
            },
            'permission_callback' => function ($request) use ($networkScoped): bool {
                return $this->canRegisterOAuthClient($request, $networkScoped);
            },
        ]);

        register_rest_route($namespace, '/oauth/authorize', [
            'methods' => 'GET,POST',
            'callback' => static function ($request) use ($controller) {
                return $controller->authorize($request);
            },
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($namespace, '/oauth/token', [
            'methods' => 'POST',
            'callback' => static function ($request) use ($controller) {
                return $controller->token($request);
            },
            'permission_callback' => '__return_true',
        ]);
    }

    public function canRegisterOAuthClient($request = null, bool $networkScoped = false): bool
    {
        $allowed = function_exists('apply_filters')
            ? apply_filters('codi_mcp_allow_dynamic_oauth_client_registration', true, $request, $networkScoped ? 'network' : 'site')
            : true;

        if ($allowed) {
            return true;
        }

        $capability = $networkScoped ? 'manage_network_options' : 'manage_options';
        return function_exists('current_user_can') && current_user_can($capability);
    }

    public function determineCurrentUser($userId)
    {
        if ((int) $userId > 0 || !$this->requestHasBearer()) {
            return $userId;
        }

        foreach ($this->authenticators() as $authenticator) {
            if ($authenticator->isMcpRequestUri()) {
                return $authenticator->determineCurrentUser($userId);
            }
        }
        return $userId;
    }

    public function preDispatch($result, $server = null, $request = null)
    {
        $authenticator = $this->authenticatorForRequest($request);
        return $authenticator === null ? $result : $authenticator->preDispatch($result, $server, $request);
    }

    public function postDispatch($response, $server = null, $request = null)
    {
        $authenticator = $this->authenticatorForRequest($request);
        return $authenticator === null ? $response : $authenticator->postDispatch($response, $server, $request);
    }

    public function filterExposedCorsHeaders(array $headers): array
    {
        foreach (['WWW-Authenticate', 'Mcp-Session-Id', 'MCP-Protocol-Version'] as $header) {
            $headers[] = $header;
        }
        return array_values(array_unique($headers));
    }

    public function filterAllowedCorsHeaders(array $headers): array
    {
        foreach (['Mcp-Session-Id', 'MCP-Protocol-Version'] as $header) {
            $headers[] = $header;
        }
        return array_values(array_unique($headers));
    }

    public function serveWellKnownMetadata(): void
    {
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if (strpos($requestUri, '/.well-known/oauth-') === false) {
            return;
        }

        $path = rtrim((string) parse_url($requestUri, PHP_URL_PATH), '/');
        $payload = null;
        foreach ($this->oauthProfiles() as $profile) {
            $authorizationPath = rtrim((string) parse_url($profile->authorizationServerMetadataPath(), PHP_URL_PATH), '/');
            $resourcePath = rtrim((string) parse_url($profile->protectedResourceMetadataPath(), PHP_URL_PATH), '/');
            if ($path === $authorizationPath) {
                $payload = $profile->authorizationServerMetadata();
                break;
            }
            if ($path === $resourcePath) {
                $payload = $profile->protectedResourceMetadata();
                break;
            }
        }
        if (!is_array($payload)) {
            return;
        }

        if (function_exists('status_header')) {
            status_header(200);
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store');
            header('Pragma: no-cache');
        }

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
            echo function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);
        }
        exit;
    }

    public function serveRawOAuthResponse($served, $result = null, $request = null, $server = null)
    {
        if ($served || !is_object($request) || !method_exists($request, 'get_route')) {
            return $served;
        }

        $route = rtrim((string) $request->get_route(), '/');
        $authorizeRoute = false;
        foreach ($this->oauthProfiles() as $profile) {
            if ($route === '/' . $profile->oauthNamespace() . '/oauth/authorize') {
                $authorizeRoute = true;
                break;
            }
        }
        if (!$authorizeRoute) {
            return $served;
        }
        if (!is_object($result) || !method_exists($result, 'get_headers') || !method_exists($result, 'get_data')) {
            return $served;
        }

        $headers = (array) $result->get_headers();
        $contentType = '';
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'content-type') {
                $contentType = is_scalar($value) ? (string) $value : '';
                break;
            }
        }
        if (stripos($contentType, 'text/html') === false) {
            return $served;
        }

        $body = $result->get_data();
        if (!is_string($body)) {
            return $served;
        }

        echo $body;
        return true;
    }

    public function registerAdminMenu(): void
    {
        if (!function_exists('add_menu_page')) {
            return;
        }

        add_menu_page(
            'Codi MCP',
            'Codi MCP',
            'manage_options',
            'codi-mcp',
            [$this, 'renderAdminPage'],
            'dashicons-admin-links'
        );
    }

    public function renderAdminPage(): void
    {
        $this->adminPage()->render();
    }

    public function saveExposure(): void
    {
        $this->adminPage()->saveExposure();
    }

    public function saveSitePackages(): void
    {
        $this->adminPage()->savePackages();
    }

    public function registerNetworkAdminMenu(): void
    {
        $this->networkAdminPage()->registerMenu();
    }

    public function saveNetworkPackages(): void
    {
        $this->networkAdminPage()->save();
    }

    /** @return AbilityPackage[] */
    public function packages(): array
    {
        if ($this->packages !== null) {
            return $this->packages;
        }

        $this->packages = [];
        foreach ($this->packageFactories as $factory) {
            $package = $factory();
            if ($package instanceof AbilityPackage) {
                $this->packages[] = $package;
            }
        }

        return $this->packages;
    }

    private function registerPackageRuntimes(): void
    {
        $runtime = $this->packageRuntime();
        foreach ($this->enabledPackages() as $package) {
            if ($package instanceof RuntimePackage) {
                $package->registerRuntime($runtime);
            }
        }
    }

    /** @return AbilityPackage[] */
    private function enabledPackages(): array
    {
        return array_values(array_filter(
            $this->packages(),
            fn (AbilityPackage $package): bool => $this->availability()->isPackageEnabled($package->key())
        ));
    }

    private function packageRuntime(): PackageRuntime
    {
        if ($this->packageRuntime === null) {
            $this->packageRuntime = new PackageRuntime($this->catalogue(), $this->exposure(), $this->auditLog());
        }

        return $this->packageRuntime;
    }

    private function mcpServer(): CodiServer
    {
        if ($this->mcpServer === null) {
            $this->mcpServer = new CodiServer(
                $this->presentation(),
                CODI_MCP_SERVER_ID,
                CODI_MCP_SERVER_REST_ROUTE,
                'Codi MCP',
                'Site-local WordPress abilities exposed by Codi MCP.',
                $this->multisiteAbilityNames()
            );
        }
        return $this->mcpServer;
    }

    private function networkMcpServer(): CodiServer
    {
        if ($this->networkMcpServer === null) {
            $this->networkMcpServer = new CodiServer(
                $this->presentation(),
                CODI_MCP_NETWORK_SERVER_ID,
                CODI_MCP_NETWORK_SERVER_REST_ROUTE,
                'Codi MCP — Network',
                'Network-managed Codi abilities and multisite gateway routing.',
                array(),
                $this->networkServerAbilityNames()
            );
        }
        return $this->networkMcpServer;
    }

    /** @return string[] */
    private function multisiteAbilityNames(): array
    {
        foreach ($this->packages() as $package) {
            if ($package->key() === 'multisite') {
                return array_values(array_filter(array_map('strval', $package->abilityNames()), 'strlen'));
            }
        }
        return array();
    }

    /** @return string[] */
    private function networkServerAbilityNames(): array
    {
        $names = array();
        foreach ($this->enabledPackages() as $package) {
            if (!$this->availability()->isNetworkManaged($package->key())) {
                continue;
            }
            foreach ($package->abilityNames() as $abilityName) {
                $abilityName = trim((string) $abilityName);
                if ($abilityName !== '') {
                    $names[$abilityName] = true;
                }
            }
        }
        return array_keys($names);
    }

    private function exposure(): ExposurePolicy
    {
        if ($this->exposure === null) {
            $managed = [];
            foreach ($this->enabledPackages() as $package) {
                $abilityNames = $this->availability()->isNetworkManaged($package->key())
                    ? $package->abilityNames()
                    : array();
                if ($package instanceof ManagedExposurePackage) {
                    $abilityNames = array_merge($abilityNames, $package->managedExposureAbilityNames());
                }
                foreach ($abilityNames as $abilityName) {
                    $abilityName = trim((string) $abilityName);
                    if ($abilityName !== '') {
                        $managed[$abilityName] = true;
                    }
                }
            }

            $this->exposure = new ExposurePolicy($this->catalogue(), array_keys($managed));
        }

        return $this->exposure;
    }

    private function oauthProfile(): OAuthProfile
    {
        if ($this->oauthProfile === null) {
            $this->oauthProfile = new OAuthProfile(CODI_MCP_SERVER_REST_ROUTE, CODI_MCP_OAUTH_REST_NAMESPACE, false);
        }
        return $this->oauthProfile;
    }

    private function networkOAuthProfile(): OAuthProfile
    {
        if ($this->networkOAuthProfile === null) {
            $this->networkOAuthProfile = new OAuthProfile(
                CODI_MCP_NETWORK_SERVER_REST_ROUTE,
                CODI_MCP_NETWORK_OAUTH_REST_NAMESPACE,
                true
            );
        }
        return $this->networkOAuthProfile;
    }

    private function oauthStore(): OAuthStore
    {
        if ($this->oauthStore === null) {
            $this->oauthStore = new OAuthStore(false);
        }
        return $this->oauthStore;
    }

    private function networkOAuthStore(): OAuthStore
    {
        if ($this->networkOAuthStore === null) {
            $this->networkOAuthStore = new OAuthStore(true);
        }
        return $this->networkOAuthStore;
    }

    private function networkGatewayEnabled(): bool
    {
        if (!function_exists('is_multisite') || !is_multisite()) {
            return false;
        }
        if ($this->availability()->networkState('multisite') !== PackageAvailabilityPolicy::ENABLED) {
            return false;
        }

        $networkId = function_exists('get_current_network_id') ? max(1, (int) get_current_network_id()) : 1;
        $mainSiteId = function_exists('get_main_site_id')
            ? max(1, (int) get_main_site_id($networkId))
            : (defined('BLOG_ID_CURRENT_SITE') ? max(1, (int) BLOG_ID_CURRENT_SITE) : 1);
        $currentSiteId = function_exists('get_current_blog_id') ? max(1, (int) get_current_blog_id()) : 1;
        return $currentSiteId === $mainSiteId;
    }

    private function oauthServer(): OAuthServer
    {
        if ($this->oauthServer === null) {
            $this->oauthServer = new OAuthServer($this->oauthStore(), $this->oauthProfile(), $this->auditLog());
        }
        return $this->oauthServer;
    }

    private function networkOAuthServer(): OAuthServer
    {
        if ($this->networkOAuthServer === null) {
            $this->networkOAuthServer = new OAuthServer($this->networkOAuthStore(), $this->networkOAuthProfile(), $this->auditLog());
        }
        return $this->networkOAuthServer;
    }

    private function oauthController(): OAuthController
    {
        if ($this->oauthController === null) {
            $this->oauthController = new OAuthController($this->oauthServer(), $this->oauthProfile());
        }
        return $this->oauthController;
    }

    private function networkOAuthController(): OAuthController
    {
        if ($this->networkOAuthController === null) {
            $this->networkOAuthController = new OAuthController($this->networkOAuthServer(), $this->networkOAuthProfile());
        }
        return $this->networkOAuthController;
    }

    private function authenticator(): McpAuthenticator
    {
        if ($this->authenticator === null) {
            $this->authenticator = new McpAuthenticator($this->oauthServer(), $this->oauthProfile(), new AuthHeader());
        }
        return $this->authenticator;
    }

    private function networkAuthenticator(): McpAuthenticator
    {
        if ($this->networkAuthenticator === null) {
            $this->networkAuthenticator = new McpAuthenticator(
                $this->networkOAuthServer(),
                $this->networkOAuthProfile(),
                new AuthHeader()
            );
        }
        return $this->networkAuthenticator;
    }

    /** @return OAuthProfile[] */
    private function oauthProfiles(): array
    {
        $profiles = array($this->oauthProfile());
        if ($this->networkGatewayEnabled()) {
            $profiles[] = $this->networkOAuthProfile();
        }
        return $profiles;
    }

    /** @return McpAuthenticator[] */
    private function authenticators(): array
    {
        $authenticators = array($this->authenticator());
        if ($this->networkGatewayEnabled()) {
            $authenticators[] = $this->networkAuthenticator();
        }
        return $authenticators;
    }

    private function authenticatorForRequest($request): ?McpAuthenticator
    {
        foreach ($this->authenticators() as $authenticator) {
            if ($authenticator->isMcpRequest($request)) {
                return $authenticator;
            }
        }
        return null;
    }

    private function adminPage(): AdminPage
    {
        if ($this->adminPage === null) {
            $this->adminPage = new AdminPage($this->exposure(), $this->oauthProfile(), $this->availability(), $this->packages());
        }
        return $this->adminPage;
    }

    private function networkAdminPage(): NetworkAdminPage
    {
        if ($this->networkAdminPage === null) {
            $this->networkAdminPage = new NetworkAdminPage(
                $this->availability(),
                $this->packages(),
                $this->networkOAuthProfile()
            );
        }
        return $this->networkAdminPage;
    }

    private function catalogue(): AbilityCatalogue
    {
        if ($this->catalogue === null) {
            $this->catalogue = new AbilityCatalogue();
        }
        return $this->catalogue;
    }

    private function presentation(): DirectMcpPresentation
    {
        if ($this->presentation === null) {
            $this->presentation = new DirectMcpPresentation($this->catalogue(), $this->exposure());
        }
        return $this->presentation;
    }
    private function auditLog(): AuditLog
    {
        if ($this->auditLog === null) {
            $this->auditLog = new AuditLog();
        }
        return $this->auditLog;
    }

    private function availability(): PackageAvailabilityPolicy
    {
        if ($this->availability === null) {
            $this->availability = new PackageAvailabilityPolicy();
        }
        return $this->availability;
    }

    private function requestHasBearer(): bool
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'AUTHORIZATION'] as $key) {
            if (stripos(trim((string) ($_SERVER[$key] ?? '')), 'Bearer ') === 0) {
                return true;
            }
        }
        return false;
    }

}
