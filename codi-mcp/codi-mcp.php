<?php
/**
 * Plugin Name: Codi MCP
 * Description: OAuth, MCP governance, and packaged WordPress abilities for the official MCP Adapter.
 * Version: 0.6.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Requires Plugins: mcp-adapter
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CODI_MCP_VERSION')) {
    define('CODI_MCP_VERSION', '0.6.0');
}
if (!defined('CODI_MCP_ABILITY_PREFIX')) {
    define('CODI_MCP_ABILITY_PREFIX', 'codi');
}
if (!defined('CODI_MCP_OAUTH_REST_NAMESPACE')) {
    define('CODI_MCP_OAUTH_REST_NAMESPACE', 'codi-mcp/v1');
}
if (!defined('CODI_MCP_SERVER_ID')) {
    define('CODI_MCP_SERVER_ID', 'codi-mcp');
}
if (!defined('CODI_MCP_NETWORK_SERVER_ID')) {
    define('CODI_MCP_NETWORK_SERVER_ID', 'codi-mcp-network');
}
if (!defined('CODI_MCP_SERVER_REST_NAMESPACE')) {
    define('CODI_MCP_SERVER_REST_NAMESPACE', 'mcp');
}
if (!defined('CODI_MCP_SERVER_REST_ROUTE')) {
    define('CODI_MCP_SERVER_REST_ROUTE', 'codi');
}
if (!defined('CODI_MCP_NETWORK_SERVER_REST_ROUTE')) {
    define('CODI_MCP_NETWORK_SERVER_REST_ROUTE', 'codi-network');
}
if (!defined('CODI_MCP_NETWORK_OAUTH_REST_NAMESPACE')) {
    define('CODI_MCP_NETWORK_OAUTH_REST_NAMESPACE', 'codi-mcp/v1/network');
}
if (!defined('CODI_MCP_PLUGIN_BASENAME')) {
    define('CODI_MCP_PLUGIN_BASENAME', plugin_basename(__FILE__));
}

spl_autoload_register(
    static function (string $className): void {
        $prefix = 'CodiMcp\\';
        if (strpos($className, $prefix) !== 0) {
            return;
        }

        $relative = substr($className, strlen($prefix));
        $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
);

if (function_exists('register_deactivation_hook')) {
    register_deactivation_hook(__FILE__, array(CodiMcp\Core\Uploads\UploadCapabilityService::class, 'clearCleanupCron'));
}

CodiMcp\Bootstrap::init();
