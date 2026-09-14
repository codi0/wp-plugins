<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Multisite;

final class FederationController
{
    private const HEADER = 'x-codi-mcp-federation';

    private SiteDirectory $sites;
    private FederationSigner $signer;
    private ReplayGuard $replay;
    private LocalAbilityRuntime $local;

    /** @var array<int,array{claims:array<string,mixed>,body:array<string,mixed>}> */
    private array $authorized = array();

    public function __construct(SiteDirectory $sites, FederationSigner $signer, ReplayGuard $replay, LocalAbilityRuntime $local)
    {
        $this->sites = $sites;
        $this->signer = $signer;
        $this->replay = $replay;
        $this->local = $local;
    }

    public function registerRoute(): void
    {
        if (!function_exists('register_rest_route') || !function_exists('is_multisite') || !is_multisite()) {
            return;
        }

        register_rest_route(CODI_MCP_OAUTH_REST_NAMESPACE, '/federation', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle'),
            'permission_callback' => array($this, 'authorize'),
        ));
    }

    public function authorize($request)
    {
        $requestKey = $this->requestKey($request);
        if (isset($this->authorized[$requestKey])) {
            return true;
        }
        if (!is_object($request) || !method_exists($request, 'get_body') || !method_exists($request, 'get_header')) {
            return $this->error('codi_multisite_bad_request', 'The federation request is invalid.', 400);
        }

        $rawBody = (string) $request->get_body();
        $body = json_decode($rawBody, true);
        if (!is_array($body)) {
            return $this->error('codi_multisite_bad_request', 'The federation request body must be valid JSON.', 400);
        }
        $operation = is_string($body['operation'] ?? null) ? trim((string) $body['operation']) : '';
        if (!in_array($operation, array('abilities', 'execute'), true)) {
            return $this->error('codi_multisite_bad_operation', 'The federation operation is not supported.', 400);
        }

        $token = trim((string) $request->get_header(self::HEADER));
        if ($token === '') {
            return $this->error('codi_multisite_assertion_required', 'A federation assertion is required.', 401);
        }

        try {
            $claims = $this->signer->verify($token, $this->sites->currentSiteId(), $operation, $rawBody);
        } catch (\Throwable $exception) {
            return $this->error('codi_multisite_signing_failed', $exception->getMessage(), 500);
        }
        if (is_wp_error($claims)) {
            return $claims;
        }

        $userId = (int) ($claims['user_id'] ?? 0);
        if (!$this->sites->canAccessSite($userId, $this->sites->currentSiteId())) {
            return $this->error('codi_multisite_site_forbidden', 'The asserted user does not have access to this site.', 403);
        }
        if (!$this->replay->consume((string) ($claims['nonce'] ?? ''), (int) ($claims['exp'] ?? 0))) {
            return $this->error('codi_multisite_replay', 'The federation assertion has already been used.', 409);
        }

        if (function_exists('wp_set_current_user')) {
            wp_set_current_user($userId);
        }
        $this->authorized[$requestKey] = array('claims' => $claims, 'body' => $body);
        return true;
    }

    public function handle($request)
    {
        $requestKey = $this->requestKey($request);
        if (!isset($this->authorized[$requestKey])) {
            $authorized = $this->authorize($request);
            if ($authorized !== true) {
                return $authorized;
            }
        }

        $context = $this->authorized[$requestKey];
        unset($this->authorized[$requestKey]);
        $body = $context['body'];
        $operation = (string) ($body['operation'] ?? '');
        $payload = is_array($body['payload'] ?? null) ? $body['payload'] : array();

        if ($operation === 'abilities') {
            return array(
                'site_id' => $this->sites->currentSiteId(),
                'abilities' => $this->local->catalog(),
            );
        }

        $abilityName = is_string($payload['ability'] ?? null) ? trim((string) $payload['ability']) : '';
        $input = array_key_exists('arguments', $payload) ? $payload['arguments'] : null;
        $result = $this->local->execute($abilityName, $input);
        if (is_wp_error($result)) {
            return $result;
        }

        return array(
            'site_id' => $this->sites->currentSiteId(),
            'ability' => $abilityName,
            'result' => $result,
        );
    }

    private function requestKey($request): int
    {
        return is_object($request) ? spl_object_id($request) : 0;
    }

    private function error(string $code, string $message, int $status): \WP_Error
    {
        return new \WP_Error($code, $message, array('status' => $status));
    }
}
