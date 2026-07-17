<?php

defined('ABSPATH') || exit;

final class CES_Callable_Condition {
    /**
     * @return callable|WP_Error
     */
    public static function resolve(string $name) {
        $name = trim($name);
        if ($name === '') {
            return new WP_Error('ces_callable_required', __('A callable function or static method is required.', 'codi-email-scheduler'));
        }

        if (strpos($name, '::') !== false) {
            if (!preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*::[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                return new WP_Error('ces_callable_invalid_format', __('The callable must be a function name or static ClassName::method name.', 'codi-email-scheduler'));
            }
            [$class, $method] = explode('::', $name, 2);
            try {
                $reflection = new ReflectionMethod($class, $method);
            } catch (ReflectionException $exception) {
                return new WP_Error('ces_callable_not_found', __('The configured function or static method is not callable.', 'codi-email-scheduler'));
            }
            if (!$reflection->isPublic() || !$reflection->isStatic()) {
                return new WP_Error('ces_callable_not_static', __('Configured class methods must be public and static.', 'codi-email-scheduler'));
            }
            $callable = [$class, $method];
        } else {
            if (!preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/', $name)) {
                return new WP_Error('ces_callable_invalid_format', __('The callable must be a function name or static ClassName::method name.', 'codi-email-scheduler'));
            }
            $callable = $name;
        }

        if (!is_callable($callable)) {
            return new WP_Error('ces_callable_not_found', __('The configured function or static method is not callable.', 'codi-email-scheduler'));
        }

        return $callable;
    }

    /**
     * @return bool|WP_Error
     */
    public static function execute(string $name, bool $pass_user_id, int $user_id) {
        $callable = self::resolve($name);
        if (is_wp_error($callable)) {
            return $callable;
        }

        if ($pass_user_id && $user_id <= 0) {
            return new WP_Error('ces_callable_user_required', __('The callable condition requires a user_id for this event.', 'codi-email-scheduler'));
        }

        try {
            $result = $pass_user_id ? call_user_func($callable, $user_id) : call_user_func($callable);
        } catch (Throwable $throwable) {
            return new WP_Error('ces_callable_failed', __('The callable condition could not be evaluated.', 'codi-email-scheduler'));
        }

        if (is_wp_error($result)) {
            return new WP_Error(
                'ces_callable_returned_error',
                __('The callable condition could not be evaluated.', 'codi-email-scheduler')
            );
        }
        if (!is_bool($result)) {
            return new WP_Error('ces_callable_invalid_result', __('The callable condition must return true, false, or WP_Error.', 'codi-email-scheduler'));
        }

        return $result;
    }
}
