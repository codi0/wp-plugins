<?php

namespace CodiMcp;

use CodiMcp\Core\AbilityPackage;
use CodiMcp\Core\Plugin;

final class Bootstrap
{
    public static function init(): Plugin
    {
        $plugin = new Plugin(self::packageFactories());
        $plugin->register();

        return $plugin;
    }

    /** @return callable[] */
    private static function packageFactories(): array
    {
        $packageFiles = glob(__DIR__ . '/Packages/*/Package.php') ?: [];
        sort($packageFiles, SORT_STRING);

        $factories = [];
        foreach ($packageFiles as $packageFile) {
            $packageName = basename(dirname($packageFile));
            $className = __NAMESPACE__ . '\\Packages\\' . $packageName . '\\Package';

            if (!class_exists($className)) {
                throw new \RuntimeException(sprintf('Codi MCP package class %s could not be loaded.', $className));
            }
            if (!is_subclass_of($className, AbilityPackage::class)) {
                throw new \RuntimeException(sprintf('Codi MCP package %s must implement %s.', $className, AbilityPackage::class));
            }

            $factories[] = static function () use ($className): AbilityPackage {
                return new $className();
            };
        }

        return $factories;
    }
}
