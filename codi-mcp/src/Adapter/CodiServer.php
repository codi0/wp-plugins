<?php

declare(strict_types=1);

namespace CodiMcp\Adapter;

use CodiMcp\Audit\McpObservabilityHandler;
use CodiMcp\Core\Abilities\SchemaPresentation;

final class CodiServer
{
    private string $serverId;
    private string $route;
    private string $title;
    private string $description;
    /** @var array<string,bool>|null */
    private ?array $includedAbilities = null;
    /** @var array<string,bool> */
    private array $excludedAbilities = array();

    /** @param string[] $excludedAbilities @param string[]|null $includedAbilities */
    public function __construct(
        private McpPresentation $presentation,
        ?string $serverId = null,
        ?string $route = null,
        string $title = 'Codi MCP',
        string $description = 'WordPress abilities explicitly enabled by Codi MCP.',
        array $excludedAbilities = array(),
        ?array $includedAbilities = null,
        private $transportAuthenticator = null
    ) {
        $this->serverId = trim((string) ($serverId ?? CODI_MCP_SERVER_ID));
        $this->route = trim((string) ($route ?? CODI_MCP_SERVER_REST_ROUTE), '/');
        $this->title = $title;
        $this->description = $description;
        if ($includedAbilities !== null) {
            $this->includedAbilities = array();
            foreach ($includedAbilities as $abilityName) {
                $abilityName = trim((string) $abilityName);
                if ($abilityName !== '') {
                    $this->includedAbilities[$abilityName] = true;
                }
            }
        }
        foreach ($excludedAbilities as $abilityName) {
            $abilityName = trim((string) $abilityName);
            if ($abilityName !== '') {
                $this->excludedAbilities[$abilityName] = true;
            }
        }
    }

    public function register($adapter): void
    {
        if (!is_object($adapter) || !method_exists($adapter, 'create_server')) {
            return;
        }
        if ($this->serverId === '' || $this->route === '') {
            return;
        }
        if (method_exists($adapter, 'get_server') && $adapter->get_server($this->serverId) !== null) {
            return;
        }

        $components = $this->presentation->components(
            array_keys($this->excludedAbilities),
            $this->includedAbilities === null ? null : array_keys($this->includedAbilities)
        );

        if (function_exists('add_filter')) {
            add_filter('mcp_adapter_tools_list', [$this, 'presentTools'], 10, 3);
        }

        $adapter->create_server(
            $this->serverId,
            CODI_MCP_SERVER_REST_NAMESPACE,
            $this->route,
            $this->title,
            $this->description,
            CODI_MCP_VERSION,
            [\WP\MCP\Transport\HttpTransport::class],
            \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
            McpObservabilityHandler::class,
            $components['tools'],
            $components['resources'],
            $components['prompts'],
            [$this, 'transportPermission']
        );
    }

    /** Strip advertised output contracts only on this Codi server, not native validation. */
    public function presentTools(array $tools, $server, $schema): array
    {
        if (!is_object($server) || !method_exists($server, 'get_server_id') || $server->get_server_id() !== $this->serverId) {
            return $tools;
        }
        return array_map(static function ($tool) use ($schema) {
            $data = (array) $tool->jsonSerialize();
            unset($data['outputSchema']);
            if (isset($data['inputSchema'])) {
                $data['inputSchema'] = SchemaPresentation::normalize($data['inputSchema']);
            }
            return $schema->fromArray(\WP\McpSchema\Record\Tool::class, $data);
        }, $tools);
    }

    public function transportPermission($request): bool
    {
        if (is_callable($this->transportAuthenticator)) {
            try {
                if (!call_user_func($this->transportAuthenticator, $request)) {
                    return false;
                }
            } catch (\Throwable) {
                return false;
            }
        }
        if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
            return false;
        }

        $allowed = function_exists('current_user_can') && current_user_can('read');
        if (!function_exists('apply_filters')) {
            return $allowed;
        }

        return (bool) apply_filters(
            'codi_mcp_transport_permission',
            $allowed,
            $request
        );
    }
}