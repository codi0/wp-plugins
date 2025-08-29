<?php
// File: vendor/Cef/Templating/Templating.php

namespace Cef\Templating;

defined('ABSPATH') || exit;

/**
 * Basic templating engine for replacing {{placeholders}} in strings
 * with values from the event/action context.
 *
 * Supports dot-notation for nested arrays: {{user.email}}
 */
class Templating
{
    /**
     * Render a template string with the given context.
     *
     * @param string $template
     * @param array  $context
     * @return string
     */
    public function render(string $template, array $context): string
    {
        if ($template === '' || empty($context)) {
            return $template;
        }
        
        //merge default context
        $context = array_merge($this->get_default_context(), $context);

        // Match {{ ... }} placeholders
        return preg_replace_callback('/{{\s*([a-zA-Z0-9_\.\-]+)\s*}}/', function ($matches) use ($context) {
            return $this->resolve_path($context, $matches[1]);
        }, $template);
    }

    /**
     * Resolve a dot-notated path into the context array.
     *
     * @param array  $context
     * @param string $path
     * @return mixed|null
     */
    protected function resolve_path(array $context, string $path)
    {
		$value = isset($context[$path]) ? $context[$path] : '';

		if($value && is_callable($value)) {
			$value = call_user_func($value, $context);
		}

        return is_scalar($value) ? (string) $value : '';
    }

	protected function get_default_context(): array {
		return [
			'site.name'         => fn($ctx) => get_bloginfo('name'),
			'site.url'          => fn($ctx) => home_url(),
			'user.email'        => fn($ctx) => $this->get_user_data($ctx, 'user_email'),
			'user.display_name' => fn($ctx) => $this->get_user_data($ctx, 'display_name'),
			'user.first_name'   => fn($ctx) => $this->get_user_data($ctx, 'first_name'),
			'user.last_name'    => fn($ctx) => $this->get_user_data($ctx, 'last_name'),
			'post.title'        => fn($ctx) => $this->get_user_data($ctx, 'post_title'),
			'post.url'          => fn($ctx) => !empty($ctx['post_id']) ? get_permalink($ctx['post_id']) : '',
			'basket.url'        => fn($ctx) => function_exists('wc_get_cart_url') ? wc_get_cart_url() : '',
			'basket.count'      => fn($ctx) => function_exists('WC') ? WC()->cart->get_cart_contents_count() : '',
			'basket.total'      => fn($ctx) => function_exists('WC') ? WC()->cart->get_total() : '',
		];
	}

	protected function get_user_data(array $context, $key) {
		$res = '';
		if(isset($context[$key])) {
			$res = $context[$key];
		} else if(isset($context['user_id']) && $context['user_id'] > 0) {
			if($obj = get_userdata($context['user_id'])) {
				$res = isset($obj->$key) ? $obj->$key : '';
			}
		}
		return $res;
	}

	protected function get_post_data(array $context, $key) {
		$res = '';
		if(isset($context[$key])) {
			$res = $context[$key];
		} else if(isset($context['post_id']) && $context['post_id'] > 0) {
			if($obj = get_post($context['post_id'])) {
				$res = isset($obj->$key) ? $obj->$key : '';
			}
		}
		return $res;
	}
}