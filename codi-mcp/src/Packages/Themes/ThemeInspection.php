<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Themes;

final class ThemeInspection
{
    private const MAX_PER_PAGE = 100;

    public function canInspect(): bool
    {
        return function_exists('current_user_can') && current_user_can('switch_themes');
    }

    public function list(array $input = array()): array
    {
        $rows = $this->themes();
        $search = strtolower(trim((string) ($input['search'] ?? '')));
        $status = (string) ($input['status'] ?? 'all');
        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($input['per_page'] ?? 50)));

        $rows = array_values(array_filter($rows, static function (array $theme) use ($search, $status): bool {
            if ($search !== '') {
                $haystack = strtolower($theme['stylesheet'] . ' ' . $theme['name'] . ' ' . $theme['description']);
                if (strpos($haystack, $search) === false) {
                    return false;
                }
            }
            return match ($status) {
                'active' => $theme['active'],
                'inactive' => !$theme['active'],
                'allowed' => $theme['allowed'],
                'blocked' => !$theme['allowed'],
                'update-available' => $theme['update_available'],
                'block-theme' => $theme['block_theme'],
                'classic-theme' => !$theme['block_theme'],
                default => true,
            };
        }));
        usort($rows, static fn (array $left, array $right): int => strnatcasecmp($left['stylesheet'], $right['stylesheet']));

        $total = count($rows);
        $items = array_slice($rows, ($page - 1) * $perPage, $perPage);
        return array('items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'returned' => count($items));
    }

    public function info(array $input)
    {
        $stylesheet = trim((string) ($input['stylesheet'] ?? ''));
        if ($stylesheet === '') {
            return new \WP_Error('codi_mcp_theme_required', 'stylesheet is required.');
        }
        foreach ($this->themes() as $theme) {
            if ($theme['stylesheet'] === $stylesheet) {
                return $theme;
            }
        }
        return new \WP_Error('codi_mcp_theme_not_found', 'Installed theme was not found.');
    }

    /** @return array<int,array<string,mixed>> */
    private function themes(): array
    {
        if (!function_exists('wp_get_themes')) {
            return array();
        }
        $installed = (array) wp_get_themes(array('errors' => null, 'allowed' => null));
        $allowed = function_exists('is_multisite') && is_multisite()
            ? array_fill_keys(array_keys((array) wp_get_themes(array('allowed' => true))), true)
            : array_fill_keys(array_keys($installed), true);
        $active = function_exists('get_stylesheet') ? (string) get_stylesheet() : (string) get_option('stylesheet', '');
        $updates = function_exists('get_site_transient') ? get_site_transient('update_themes') : null;
        $updateResponses = is_object($updates) && is_array($updates->response ?? null) ? $updates->response : array();

        $rows = array();
        foreach ($installed as $stylesheet => $theme) {
            if (!is_object($theme)) {
                continue;
            }
            $stylesheet = (string) $stylesheet;
            $parent = method_exists($theme, 'parent') ? $theme->parent() : null;
            $errors = method_exists($theme, 'errors') ? $theme->errors() : false;
            $update = $updateResponses[$stylesheet] ?? null;
            $rows[] = array(
                'stylesheet' => $stylesheet,
                'template' => method_exists($theme, 'get_template') ? (string) $theme->get_template() : '',
                'name' => method_exists($theme, 'get') ? (string) $theme->get('Name') : $stylesheet,
                'description' => method_exists($theme, 'get') ? wp_strip_all_tags((string) $theme->get('Description')) : '',
                'version' => method_exists($theme, 'get') ? (string) $theme->get('Version') : '',
                'author' => method_exists($theme, 'get') ? wp_strip_all_tags((string) $theme->get('Author')) : '',
                'theme_uri' => method_exists($theme, 'get') ? (string) $theme->get('ThemeURI') : '',
                'requires_wp' => method_exists($theme, 'get') ? (string) $theme->get('RequiresWP') : '',
                'requires_php' => method_exists($theme, 'get') ? (string) $theme->get('RequiresPHP') : '',
                'parent' => is_object($parent) && method_exists($parent, 'get_stylesheet') ? (string) $parent->get_stylesheet() : '',
                'block_theme' => method_exists($theme, 'is_block_theme') && (bool) $theme->is_block_theme(),
                'active' => $stylesheet === $active,
                'allowed' => isset($allowed[$stylesheet]) || $stylesheet === $active,
                'has_errors' => is_wp_error($errors),
                'update_available' => is_array($update) || is_object($update),
                'update_version' => is_array($update) ? (string) ($update['new_version'] ?? '') : (is_object($update) ? (string) ($update->new_version ?? '') : ''),
            );
        }
        return $rows;
    }

    public static function listInputSchema(): array
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'search' => array('type' => 'string', 'maxLength' => 200),
                'status' => array('type' => 'string', 'enum' => array('all', 'active', 'inactive', 'allowed', 'blocked', 'update-available', 'block-theme', 'classic-theme')),
                'page' => array('type' => 'integer', 'minimum' => 1),
                'per_page' => array('type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE),
            ),
        );
    }

    public static function infoInputSchema(): array
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array('stylesheet' => self::stylesheetSchema()),
            'required' => array('stylesheet'),
        );
    }

    public static function listOutputSchema(): array
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'items' => array('type' => 'array', 'items' => self::themeSchema()),
                'total' => array('type' => 'integer'),
                'page' => array('type' => 'integer'),
                'per_page' => array('type' => 'integer'),
                'returned' => array('type' => 'integer'),
            ),
            'required' => array('items', 'total', 'page', 'per_page', 'returned'),
        );
    }

    public static function infoOutputSchema(): array
    {
        return self::themeSchema();
    }

    public static function stylesheetSchema(): array
    {
        return array('type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'pattern' => '^[A-Za-z0-9][A-Za-z0-9._-]*$');
    }

    private static function themeSchema(): array
    {
        $properties = array();
        foreach (array('stylesheet', 'template', 'name', 'description', 'version', 'author', 'theme_uri', 'requires_wp', 'requires_php', 'parent', 'update_version') as $field) {
            $properties[$field] = array('type' => 'string');
        }
        foreach (array('block_theme', 'active', 'allowed', 'has_errors', 'update_available') as $field) {
            $properties[$field] = array('type' => 'boolean');
        }
        return array('type' => 'object', 'additionalProperties' => false, 'properties' => $properties, 'required' => array_keys($properties));
    }
}
