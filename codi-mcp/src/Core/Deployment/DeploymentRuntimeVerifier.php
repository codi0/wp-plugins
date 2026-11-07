<?php

declare(strict_types=1);

namespace CodiMcp\Core\Deployment;

/** Verifies freshly installed shared code in each WordPress runtime context selected by the package layer. */
final class DeploymentRuntimeVerifier
{
    private \Closure $verifyCurrentSite;

    public function __construct(?callable $verifyCurrentSite = null)
    {
        if ($verifyCurrentSite !== null) {
            $this->verifyCurrentSite = \Closure::fromCallable($verifyCurrentSite);
            return;
        }
        if (!class_exists('WP_Automatic_Updater')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
        }
        $probe = new CoreFatalErrorCheck();
        $this->verifyCurrentSite = static fn (int $siteId): true|\WP_Error => $probe->verify();
    }

    /** @param int[] $siteIds */
    public function verify(array $siteIds): true|\WP_Error
    {
        $siteIds = array_values(array_unique(array_filter(array_map('intval', $siteIds), static fn (int $siteId): bool => $siteId > 0)));
        sort($siteIds, SORT_NUMERIC);
        if ($siteIds === array()) {
            return true;
        }

        $multisite = function_exists('is_multisite') && is_multisite();
        $origin = function_exists('get_current_blog_id') ? max(1, (int) get_current_blog_id()) : 1;
        foreach ($siteIds as $siteId) {
            $switched = false;
            if ($multisite && $siteId !== $origin) {
                if (!function_exists('switch_to_blog') || !function_exists('restore_current_blog')) {
                    return new \WP_Error('codi_mcp_update_health_context_unavailable', 'WordPress cannot switch to a required runtime verification context.');
                }
                switch_to_blog($siteId);
                $switched = true;
            }
            try {
                $result = ($this->verifyCurrentSite)($siteId);
            } catch (\Throwable) {
                $result = new \WP_Error('codi_mcp_update_health_failed', 'WordPress runtime verification did not complete.');
            } finally {
                if ($switched) {
                    restore_current_blog();
                }
            }
            if ($result !== true) {
                $cause = $result instanceof \WP_Error ? $result->get_error_code() : 'unconfirmed';
                return new \WP_Error(
                    'codi_mcp_update_health_failed',
                    'WordPress runtime verification failed for an active site.',
                    array('site_id' => $siteId, 'cause' => $cause)
                );
            }
        }
        return true;
    }
}