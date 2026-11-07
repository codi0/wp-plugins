<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Multisite;

final class Gateway
{
    private SiteDirectory $sites;
    private FederationClient $client;

    public function __construct(SiteDirectory $sites, FederationClient $client)
    {
        $this->sites = $sites;
        $this->client = $client;
    }

    public function canUse(): bool
    {
        $userId = $this->currentUserId();
        return $this->sites->isGatewaySite() && $userId > 0 && $this->sites->accessibleSites($userId) !== array();
    }

    public function canTarget(array $input = array()): bool
    {
        $siteId = (int) ($input['site_id'] ?? 0);
        $userId = $this->currentUserId();
        return $this->sites->isGatewaySite() && $userId > 0 && $this->sites->canAccessSite($userId, $siteId);
    }

    /** @return array{items:array<int,array{site_id:int,name:string,url:string,is_main:bool}>} */
    public function sites(array $input = array()): array
    {
        $items = $this->sites->accessibleSites($this->currentUserId());
        return array('items' => $items);
    }

    public function siteAbilities(array $input = array())
    {
        $siteId = (int) ($input['site_id'] ?? 0);
        $search = strtolower(trim((string) ($input['search'] ?? '')));
        $detail = $input['detail'] ?? 'auto';
        if (!in_array($detail, array('auto', 'summary', 'schema'), true)) {
            return new \WP_Error('codi_multisite_invalid_detail', 'detail must be auto, summary, or schema.', array('status' => 400));
        }
        $abilities = $this->client->abilities($siteId, $this->currentUserId());
        if (is_wp_error($abilities)) {
            return $abilities;
        }
        if ($search !== '') {
            $exact = array_values(array_filter($abilities, static fn (array $ability): bool => strtolower((string) $ability['name']) === $search));
            if ($exact === array()) {
                $short = array_values(array_filter($abilities, static function (array $ability) use ($search): bool {
                    $name = strtolower((string) $ability['name']);
                    return substr($name, (int) strrpos($name, '/') + 1) === $search;
                }));
                $exact = count($short) === 1 ? $short : array();
            }
            if ($exact !== array()) {
                $abilities = $exact;
            } else {
                $terms = preg_split('/\s+/', $search) ?: array();
                $abilities = array_values(array_filter($abilities, static function (array $ability) use ($terms): bool {
                    $text = strtolower((string) $ability['name'] . ' ' . (string) $ability['description']);
                    foreach ($terms as $term) {
                        if (!str_contains($text, $term)) {
                            return false;
                        }
                    }
                    return true;
                }));
            }
        }
        if ($detail === 'summary' || ($detail === 'auto' && count($abilities) !== 1)) {
            foreach ($abilities as &$ability) {
                unset($ability['input_schema']);
            }
            unset($ability);
        }
        return array('abilities' => $abilities);
    }

    public function siteCall(array $input = array())
    {
        $siteId = (int) ($input['site_id'] ?? 0);
        $ability = is_string($input['ability'] ?? null) ? trim((string) $input['ability']) : '';
        $arguments = array_key_exists('arguments', $input) ? $input['arguments'] : null;
        $result = $this->client->execute($siteId, $this->currentUserId(), $ability, $arguments);
        if (is_wp_error($result)) {
            return $result;
        }
        return array('result' => $result);
    }

    public function extendTransportPermission(bool $allowed, $request = null): bool
    {
        if ($allowed) {
            return true;
        }
        return $this->canUse();
    }

    private function currentUserId(): int
    {
        if (!function_exists('wp_get_current_user')) {
            return 0;
        }
        return max(0, (int) (wp_get_current_user()->ID ?? 0));
    }
}
