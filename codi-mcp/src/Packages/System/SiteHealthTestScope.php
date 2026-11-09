<?php

declare(strict_types=1);

namespace CodiMcp\Packages\System;

final class SiteHealthTestScope
{
    public static function resolve($callback, object $instance)
    {
        if (is_string($callback)) {
            $method = 'get_test_' . $callback;
            return method_exists($instance, $method) && is_callable(array($instance, $method))
                ? array($instance, $method)
                : null;
        }

        return is_callable($callback) ? $callback : null;
    }

    public static function isCore($callback, object $instance): bool
    {
        return is_array($callback)
            && ($callback[0] ?? null) === $instance
            && is_string($callback[1] ?? null)
            && str_starts_with((string) $callback[1], 'get_test_')
            && method_exists($instance, (string) $callback[1]);
    }
}
