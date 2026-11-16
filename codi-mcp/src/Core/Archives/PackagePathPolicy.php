<?php

declare(strict_types=1);

namespace CodiMcp\Core\Archives;

final class PackagePathPolicy
{
    public static function normalize(string $path): string
    {
        return str_replace('\\', '/', trim($path));
    }

    public static function isSafeArchiveEntry(string $path): bool
    {
        $path = self::normalize($path);
        if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/')
            || preg_match('#(?:^|/)\.\.(?:/|$)#', $path) || str_contains($path, ':')) {
            return false;
        }
        return true;
    }

    public static function isIgnorablePlatformMetadata(string $path): bool
    {
        $path = self::normalize($path);
        return 1 === preg_match('#(?:^|/)[^/]+:Zone\.Identifier(?::\$DATA)?$#i', $path);
    }
}