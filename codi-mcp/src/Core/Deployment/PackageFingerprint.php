<?php

declare(strict_types=1);

namespace CodiMcp\Core\Deployment;

final class PackageFingerprint
{
    public static function absent(): string
    {
        return hash('sha256', "absent-directory\0");
    }

    public function current(string $root): string
    {
        if (!file_exists($root) && !is_link($root)) {
            return self::absent();
        }
        return $this->directory($root);
    }

    public function file(string $path): string
    {
        if (is_link($path) || !is_file($path)) {
            throw new \RuntimeException('Package file must be an existing regular file without symbolic links.');
        }
        $size = filesize($path);
        if ($size === false || $size > 100 * 1024 * 1024) {
            throw new \RuntimeException('Package file exceeds the 100 MiB inspection limit.');
        }
        $hash = hash_file('sha256', $path);
        if (!is_string($hash)) {
            throw new \RuntimeException('Could not hash package file.');
        }
        return $hash;
    }

    public function directory(string $root): string
    {
        if (is_link($root) || !is_dir($root)) {
            throw new \RuntimeException('Package source must be a directory without symbolic links.');
        }
        $context = hash_init('sha256');
        $entries = 0;
        $bytes = 0;
        $walk = function (string $path, string $relative) use (&$walk, $context, &$entries, &$bytes): void {
            if (++$entries > 5000 || is_link($path)) {
                throw new \RuntimeException('Package contains links or exceeds the 5000-entry inspection limit.');
            }
            if (is_dir($path)) {
                hash_update($context, "d\0" . $relative . "\0");
                $names = scandir($path);
                if ($names === false) {
                    throw new \RuntimeException('Could not read package source during verification.');
                }
                foreach ($names as $name) {
                    if ($name !== '.' && $name !== '..') {
                        $walk($path . '/' . $name, $relative . '/' . $name);
                    }
                }
                return;
            }
            if (is_file($path)) {
                $size = filesize($path);
                if ($size === false || ($bytes += $size) > 100 * 1024 * 1024) {
                    throw new \RuntimeException('Package exceeds the 100 MiB inspection limit.');
                }
                $hash = hash_file('sha256', $path);
                if (!is_string($hash)) {
                    throw new \RuntimeException('Could not hash package source.');
                }
                hash_update($context, "f\0" . $relative . "\0" . $size . "\0" . $hash . "\0");
                return;
            }
            throw new \RuntimeException('Package contains an unsupported filesystem entry.');
        };
        $walk($root, '');
        return hash_final($context);
    }
}