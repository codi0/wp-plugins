<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Site;

final class SiteManager
{
    public function canManageOptions($input = array()): bool
    {
        return function_exists('current_user_can') && current_user_can('manage_options');
    }

    public function config(array $input = array()): array
    {
        return array(
            'site_title' => (string) get_option('blogname', ''),
            'tagline' => (string) get_option('blogdescription', ''),
            'front_page_mode' => (string) get_option('show_on_front', 'posts'),
            'page_on_front' => max(0, (int) get_option('page_on_front', 0)),
            'page_for_posts' => max(0, (int) get_option('page_for_posts', 0)),
            'timezone' => (string) get_option('timezone_string', '') !== ''
                ? (string) get_option('timezone_string', '')
                : (function_exists('wp_timezone_string') ? (string) wp_timezone_string() : ''),
            'permalink_structure' => (string) get_option('permalink_structure', ''),
            'posts_per_page' => max(1, (int) get_option('posts_per_page', 10)),
            'search_engine_visibility' => (bool) get_option('blog_public', 1),
            'locale' => function_exists('get_locale') ? (string) get_locale() : (string) get_option('WPLANG', 'en_US'),
            'date_format' => (string) get_option('date_format', 'F j, Y'),
            'time_format' => (string) get_option('time_format', 'g:i a'),
            'start_of_week' => min(6, max(0, (int) get_option('start_of_week', 1))),
        );
    }

    public function updateConfig(array $input)
    {
        if ($input === array()) {
            return new \WP_Error('codi_mcp_site_config_empty', 'At least one supported site setting must be supplied.');
        }

        $current = $this->config();
        $next = array_merge($current, $input);
        $validation = $this->validateConfig($next, $input);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $map = array(
            'site_title' => 'blogname',
            'tagline' => 'blogdescription',
            'front_page_mode' => 'show_on_front',
            'page_on_front' => 'page_on_front',
            'page_for_posts' => 'page_for_posts',
            'timezone' => 'timezone_string',
            'permalink_structure' => 'permalink_structure',
            'posts_per_page' => 'posts_per_page',
            'search_engine_visibility' => 'blog_public',
            'locale' => 'WPLANG',
            'date_format' => 'date_format',
            'time_format' => 'time_format',
            'start_of_week' => 'start_of_week',
        );

        $changed = array();
        $written = array();
        foreach ($map as $field => $option) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $value = $input[$field];
            if ($field === 'search_engine_visibility') {
                $value = $value ? 1 : 0;
            }
            $before = get_option($option, '');
            if ((string) $before === (string) $value) {
                continue;
            }

            $written[] = array('option' => $option, 'before' => $before);
            update_option($option, $value, false);
            if ((string) get_option($option, '') !== (string) $value) {
                $rollbackFailed = false;
                foreach (array_reverse($written) as $entry) {
                    update_option((string) $entry['option'], $entry['before'], false);
                    if ((string) get_option((string) $entry['option'], '') !== (string) $entry['before']) {
                        $rollbackFailed = true;
                    }
                }
                return new \WP_Error(
                    $rollbackFailed ? 'codi_mcp_site_config_rollback_failed' : 'codi_mcp_site_config_write_failed',
                    $rollbackFailed
                        ? 'A site setting failed to persist and the previous configuration could not be fully restored.'
                        : 'A site setting failed to persist; previous changes from this request were restored.'
                );
            }
            $changed[] = $field;
        }

        if (in_array('permalink_structure', $changed, true) && function_exists('flush_rewrite_rules')) {
            flush_rewrite_rules(false);
        }

        return array('changed' => $changed, 'config' => $this->config());
    }

    private function validateConfig(array $next, array $input)
    {
        if (!in_array((string) $next['front_page_mode'], array('posts', 'page'), true)) {
            return new \WP_Error('codi_mcp_front_page_mode_invalid', 'front_page_mode must be posts or page.');
        }
        if ((string) $next['front_page_mode'] === 'page' && (int) $next['page_on_front'] <= 0) {
            return new \WP_Error('codi_mcp_front_page_required', 'page_on_front must identify a page when front_page_mode is page.');
        }
        foreach (array('page_on_front', 'page_for_posts') as $field) {
            $pageId = (int) $next[$field];
            if ($pageId > 0 && !$this->isUsablePage($pageId)) {
                return new \WP_Error('codi_mcp_page_setting_invalid', $field . ' must identify an existing non-trashed page.');
            }
        }
        if ((int) $next['page_on_front'] > 0 && (int) $next['page_on_front'] === (int) $next['page_for_posts']) {
            return new \WP_Error('codi_mcp_page_settings_conflict', 'page_on_front and page_for_posts must be different pages.');
        }

        if (array_key_exists('timezone', $input)) {
            $timezone = (string) $next['timezone'];
            if ($timezone !== '') {
                try {
                    new \DateTimeZone($timezone);
                } catch (\Throwable $throwable) {
                    return new \WP_Error('codi_mcp_timezone_invalid', 'timezone must be a valid PHP/WordPress timezone identifier.');
                }
            }
        }
        if (array_key_exists('locale', $input)) {
            $locale = (string) $next['locale'];
            $available = function_exists('get_available_languages') ? array_values(array_map('strval', (array) get_available_languages())) : array();
            $allowed = array_values(array_unique(array_merge(array('en_US', function_exists('get_locale') ? (string) get_locale() : ''), $available)));
            if (!in_array($locale, $allowed, true)) {
                return new \WP_Error('codi_mcp_locale_unavailable', 'locale must be installed and available on this WordPress site.');
            }
        }
        if (array_key_exists('permalink_structure', $input) && !$this->validPermalinkStructure((string) $next['permalink_structure'])) {
            return new \WP_Error('codi_mcp_permalink_invalid', 'permalink_structure contains unsupported rewrite tags or unsafe characters.');
        }

        return true;
    }

    private function isUsablePage(int $postId): bool
    {
        $post = get_post($postId);
        return is_object($post)
            && (string) ($post->post_type ?? '') === 'page'
            && !in_array((string) ($post->post_status ?? ''), array('trash', 'auto-draft'), true);
    }

    private function validPermalinkStructure(string $structure): bool
    {
        if (strlen($structure) > 200 || preg_match('/[\x00-\x1F\x7F]/', $structure) || str_contains($structure, '?')) {
            return false;
        }
        if ($structure === '') {
            return true;
        }
        preg_match_all('/%[^%]+%/', $structure, $matches);
        $allowed = array('%year%', '%monthnum%', '%day%', '%hour%', '%minute%', '%second%', '%post_id%', '%postname%', '%category%', '%author%');
        global $wp_rewrite;
        if (is_object($wp_rewrite) && is_array($wp_rewrite->rewritecode ?? null)) {
            $allowed = array_values(array_unique(array_merge($allowed, array_filter(array_map('strval', $wp_rewrite->rewritecode)))));
        }
        foreach ((array) ($matches[0] ?? array()) as $tag) {
            if (!in_array($tag, $allowed, true)) {
                return false;
            }
        }
        return true;
    }

}
