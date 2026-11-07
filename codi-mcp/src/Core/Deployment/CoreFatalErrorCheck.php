<?php

declare(strict_types=1);

namespace CodiMcp\Core\Deployment;

/** Exposes Core's protected probe without replacing its request or scraping machinery. */
final class CoreFatalErrorCheck extends \WP_Automatic_Updater
{
    public function verify(): true|\WP_Error
    {
        $confirmed = false;
        $observe = static function ($response, $args, $url) use (&$confirmed) {
            $query = [];
            parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);
            $key = $query['wp_scrape_key'] ?? null;
            if (!is_string($key) || $key !== md5((string) ($GLOBALS['upgrading'] ?? ''))
                || (int) ($response['response']['code'] ?? 0) !== 200) {
                return $response;
            }
            $body = wp_remote_retrieve_body($response);
            $start = "###### wp_scraping_result_start:$key ######";
            $end = "###### wp_scraping_result_end:$key ######";
            $offset = strpos($body, $start);
            if ($offset !== false) {
                $payload = substr($body, $offset + strlen($start));
                $finish = strpos($payload, $end);
                $confirmed = $finish !== false && json_decode(trim(substr($payload, 0, $finish)), true) === true;
            }
            return $response;
        };
        // Core treats a missing scrape marker as no fatal error. Require positive proof as well.
        add_filter('http_response', $observe, PHP_INT_MAX, 3);
        try {
            if ($this->has_fatal_error()) {
                return new \WP_Error('codi_mcp_update_health_failed', 'WordPress detected a fatal error or its loopback request failed.');
            }
            return $confirmed ? true : new \WP_Error('codi_mcp_update_health_unconfirmed', 'WordPress could not confirm a successful fresh request.');
        } finally {
            remove_filter('http_response', $observe, PHP_INT_MAX);
        }
    }
}
