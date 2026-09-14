<?php

declare(strict_types=1);

$testsDir = rtrim((string) getenv('WP_TESTS_DIR'), '/\\');
if ($testsDir === '' || !is_file($testsDir . '/includes/functions.php') || !is_file($testsDir . '/includes/bootstrap.php')) {
    throw new RuntimeException('WP_TESTS_DIR must point to the WordPress PHPUnit test library.');
}

require_once $testsDir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    foreach (array(
        'CODI_MCP_ABILITY_PREFIX' => 'codi',
        'CODI_MCP_OAUTH_REST_NAMESPACE' => 'codi-mcp/v1',
        'CODI_MCP_SERVER_REST_NAMESPACE' => 'mcp',
        'CODI_MCP_SERVER_REST_ROUTE' => 'codi',
        'CODI_MCP_SERVER_ID' => 'codi-mcp',
        'CODI_MCP_NETWORK_SERVER_ID' => 'codi-mcp-network',
        'CODI_MCP_NETWORK_SERVER_REST_ROUTE' => 'codi-network',
        'CODI_MCP_NETWORK_OAUTH_REST_NAMESPACE' => 'codi-mcp/v1/network',
    ) as $constant => $value) {
        if (!defined($constant)) {
            define($constant, $value);
        }
    }
    if (!defined('CODI_MCP_PLUGIN_BASENAME')) {
        define('CODI_MCP_PLUGIN_BASENAME', 'codi-mcp/codi-mcp.php');
    }
    spl_autoload_register(static function (string $className): void {
        $prefix = 'CodiMcp\\';
        if (!str_starts_with($className, $prefix)) {
            return;
        }
        $relative = substr($className, strlen($prefix));
        $path = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });
});

require_once $testsDir . '/includes/bootstrap.php';
