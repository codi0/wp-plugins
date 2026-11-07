<?php

declare(strict_types=1);

namespace CodiMcp\Core;

final class SourceAttribution
{
    /** @return array{component_type:string,component:string,file:string,line:int,callable:string,hook:string} */
    public function fromBacktrace(?array $trace = null): array
    {
        $trace ??= debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30);
        $fallback = null;

        foreach ($trace as $frame) {
            if (!is_array($frame)) {
                continue;
            }
            $class = (string) ($frame['class'] ?? '');
            if ($class !== '' && str_starts_with($class, 'CodiMcp\\')) {
                continue;
            }

            $file = (string) ($frame['file'] ?? '');
            if ($file === '') {
                continue;
            }

            $source = $this->describeFile($file, (int) ($frame['line'] ?? 0));
            $source['callable'] = $this->frameCallable($frame);
            $source['hook'] = $this->outerHook();
            $fallback ??= $source;

            if (in_array($source['component_type'], array('plugin', 'mu-plugin', 'theme'), true)) {
                return $source;
            }
        }

        return $fallback ?? $this->emptySource();
    }

    /** @return array{component_type:string,component:string,file:string,line:int,callable:string,hook:string} */
    public function fromCallable(mixed $callback): array
    {
        try {
            if ($callback instanceof \Closure) {
                $reflection = new \ReflectionFunction($callback);
                $source = $this->describeFile((string) $reflection->getFileName(), (int) $reflection->getStartLine());
                $source['callable'] = '{closure}';
                return $source;
            }

            if (is_string($callback)) {
                if (str_contains($callback, '::')) {
                    [$class, $method] = explode('::', $callback, 2);
                    $reflection = new \ReflectionMethod($class, $method);
                    $source = $this->describeFile((string) $reflection->getFileName(), (int) $reflection->getStartLine());
                    $source['callable'] = $class . '::' . $method;
                    return $source;
                }
                if (function_exists($callback)) {
                    $reflection = new \ReflectionFunction($callback);
                    $source = $this->describeFile((string) $reflection->getFileName(), (int) $reflection->getStartLine());
                    $source['callable'] = $callback;
                    return $source;
                }
            }

            if (is_array($callback) && count($callback) >= 2) {
                $target = $callback[0];
                $method = (string) $callback[1];
                $class = is_object($target) ? get_class($target) : (string) $target;
                $reflection = new \ReflectionMethod($class, $method);
                $source = $this->describeFile((string) $reflection->getFileName(), (int) $reflection->getStartLine());
                $source['callable'] = $class . (is_object($target) ? '->' : '::') . $method;
                return $source;
            }

            if (is_object($callback) && method_exists($callback, '__invoke')) {
                $reflection = new \ReflectionMethod($callback, '__invoke');
                $source = $this->describeFile((string) $reflection->getFileName(), (int) $reflection->getStartLine());
                $source['callable'] = get_class($callback) . '->__invoke';
                return $source;
            }
        } catch (\Throwable) {
        }

        return $this->emptySource();
    }

    /** @return array{component_type:string,component:string,file:string,line:int,callable:string,hook:string} */
    public function describeFile(string $file, int $line = 0): array
    {
        $normalized = str_replace('\\', '/', $file);

        $pluginDir = defined('WP_PLUGIN_DIR') ? str_replace('\\', '/', (string) WP_PLUGIN_DIR) : '';
        if ($pluginDir !== '' && $this->inside($normalized, $pluginDir)) {
            $relative = ltrim(substr($normalized, strlen(rtrim($pluginDir, '/'))), '/');
            return $this->source('plugin', $this->firstSegment($relative), $relative, $line);
        }

        $muDir = defined('WPMU_PLUGIN_DIR') ? str_replace('\\', '/', (string) WPMU_PLUGIN_DIR) : '';
        if ($muDir !== '' && $this->inside($normalized, $muDir)) {
            $relative = ltrim(substr($normalized, strlen(rtrim($muDir, '/'))), '/');
            $component = str_contains($relative, '/') ? $this->firstSegment($relative) : pathinfo($relative, PATHINFO_FILENAME);
            return $this->source('mu-plugin', $component, $relative, $line);
        }

        $themeRoot = function_exists('get_theme_root') ? (string) get_theme_root() : '';
        $themeRoot = str_replace('\\', '/', $themeRoot);
        if ($themeRoot !== '' && $this->inside($normalized, $themeRoot)) {
            $relative = ltrim(substr($normalized, strlen(rtrim($themeRoot, '/'))), '/');
            return $this->source('theme', $this->firstSegment($relative), $relative, $line);
        }

        $abspath = defined('ABSPATH') ? str_replace('\\', '/', (string) ABSPATH) : '';
        if ($abspath !== '' && $this->inside($normalized, $abspath)) {
            $relative = ltrim(substr($normalized, strlen(rtrim($abspath, '/'))), '/');
            return $this->source('core', 'wordpress-core', $relative, $line);
        }

        return $this->source('other', '', basename($normalized), $line);
    }

    /** @return array{component_type:string,component:string,file:string,line:int,callable:string,hook:string} */
    private function source(string $type, string $component, string $file, int $line): array
    {
        return array(
            'component_type' => $type,
            'component' => $component,
            'file' => substr($file, 0, 500),
            'line' => max(0, $line),
            'callable' => '',
            'hook' => '',
        );
    }

    /** @return array{component_type:string,component:string,file:string,line:int,callable:string,hook:string} */
    private function emptySource(): array
    {
        return $this->source('unknown', '', '', 0);
    }

    private function outerHook(): string
    {
        global $wp_current_filter;
        if (!is_array($wp_current_filter) || count($wp_current_filter) < 2) {
            return '';
        }
        $outer = (string) $wp_current_filter[count($wp_current_filter) - 2];
        return substr($outer, 0, 191);
    }

    private function frameCallable(array $frame): string
    {
        $function = (string) ($frame['function'] ?? '');
        if ($function === '') {
            return '';
        }
        $class = (string) ($frame['class'] ?? '');
        $type = (string) ($frame['type'] ?? '');
        return substr($class . $type . $function, 0, 500);
    }

    private function inside(string $file, string $root): bool
    {
        $root = rtrim($root, '/');
        return $file === $root || str_starts_with($file, $root . '/');
    }

    private function firstSegment(string $path): string
    {
        return (string) (explode('/', ltrim($path, '/'), 2)[0] ?? '');
    }
}
