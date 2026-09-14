<?php

declare(strict_types=1);

namespace CodiMcp\Audit;

final class AuditedAbility extends \WP_Ability
{
    protected function prepare_properties(array $args): array
    {
        $properties = parent::prepare_properties($args);
        if (empty($properties['execute_callback']) || !is_callable($properties['execute_callback'])) {
            throw new \InvalidArgumentException('Codi MCP audited abilities require a valid execute_callback.');
        }
        if (empty($properties['permission_callback']) || !is_callable($properties['permission_callback'])) {
            throw new \InvalidArgumentException('Codi MCP audited abilities require a valid permission_callback.');
        }
        return $properties;
    }

    public function execute($input = null)
    {
        $audit = new AuditLog();
        try {
            $eventId = $audit->beginAbility($this->get_name(), $input, $this->source());
        } catch (\Throwable) {
            return new \WP_Error('codi_mcp_audit_unavailable', 'Codi MCP audit storage is unavailable.');
        }

        try {
            $result = parent::execute($input);
        } catch (\Throwable $throwable) {
            $this->completeAudit($audit, $eventId, 'failed', 'exception');
            throw $throwable;
        }

        if (function_exists('is_wp_error') && is_wp_error($result)) {
            $reasonCode = method_exists($result, 'get_error_code') ? (string) $result->get_error_code() : 'wp_error';
            $this->completeAudit($audit, $eventId, 'failed', $reasonCode);
        } else {
            $this->completeAudit($audit, $eventId, 'succeeded');
        }

        return $result;
    }

    private function completeAudit(AuditLog $audit, string $eventId, string $status, string $reasonCode = ''): void
    {
        try {
            $audit->completeAbility($eventId, $status, $reasonCode);
        } catch (\Throwable) {
            if (function_exists('error_log')) {
                error_log('Codi MCP could not persist a terminal ability audit status.');
            }
        }
    }

    private function source(): string
    {
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = (string) (parse_url($requestUri, PHP_URL_PATH) ?: '');
        $routes = array(
            trim((string) CODI_MCP_SERVER_REST_NAMESPACE, '/') . '/' . trim((string) CODI_MCP_SERVER_REST_ROUTE, '/'),
        );
        if (defined('CODI_MCP_NETWORK_SERVER_REST_ROUTE')) {
            $routes[] = trim((string) CODI_MCP_SERVER_REST_NAMESPACE, '/') . '/' . trim((string) CODI_MCP_NETWORK_SERVER_REST_ROUTE, '/');
        }
        $queryRoute = trim((string) ($_GET['rest_route'] ?? ''), '/');

        foreach (array_values(array_unique($routes)) as $mcpRoute) {
            $mcpPath = function_exists('rest_url')
                ? (string) (parse_url((string) rest_url($mcpRoute), PHP_URL_PATH) ?: '')
                : '/wp-json/' . $mcpRoute;
            if (($path !== '' && $mcpPath !== '' && ($path === $mcpPath || str_starts_with($path, rtrim($mcpPath, '/') . '/')))
                || ($queryRoute !== '' && ($queryRoute === $mcpRoute || str_starts_with($queryRoute, $mcpRoute . '/')))) {
                return 'mcp';
            }
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return 'rest';
        }
        return 'wordpress';
    }
}
