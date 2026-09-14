<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation;

final class GlobalStylesGateway
{
    /** @param array<string,mixed> $config */
    public function update(int $postId, array $config): object
    {
        if ($postId < 1) {
            throw new \InvalidArgumentException('A valid wp_global_styles post ID is required.');
        }
        if (!class_exists('WP_REST_Global_Styles_Controller') || !class_exists('WP_REST_Request')) {
            throw new \RuntimeException('WordPress native Global Styles persistence is unavailable.');
        }
        $controller = new \WP_REST_Global_Styles_Controller('wp_global_styles');
        $request = new \WP_REST_Request('POST');
        $request->set_param('id', $postId);
        $request->set_param('context', 'edit');
        if (array_key_exists('styles', $config)) {
            if (!is_array($config['styles'])) {
                throw new \InvalidArgumentException('Global Styles styles must be an object.');
            }
            $request->set_param('styles', $config['styles']);
        }
        if (array_key_exists('settings', $config)) {
            if (!is_array($config['settings'])) {
                throw new \InvalidArgumentException('Global Styles settings must be an object.');
            }
            $request->set_param('settings', $config['settings']);
        }
        $result = $controller->update_item($request);
        if (function_exists('is_wp_error') && is_wp_error($result)) {
            $message = method_exists($result, 'get_error_message') ? (string) $result->get_error_message() : 'Unknown WordPress error.';
            throw new \RuntimeException('WordPress rejected the Global Styles update: ' . $message);
        }
        $post = function_exists('get_post') ? get_post($postId) : null;
        if (!is_object($post) || 'wp_global_styles' !== (string) ($post->post_type ?? '')) {
            throw new \RuntimeException('WordPress updated Global Styles but the native record could not be reloaded.');
        }
        return $post;
    }
}
