<?php

declare(strict_types=1);

namespace CodiMcp\Core\Deployment;

final class UpgraderResult
{
    public static function error($result, string $artifact): ?\WP_Error
    {
        if ($result instanceof \WP_Error || (function_exists('is_wp_error') && is_wp_error($result))) {
            return $result;
        }
        if (false !== $result) {
            return null;
        }

        $artifact = strtolower(trim($artifact));
        if (!in_array($artifact, array('plugin', 'theme'), true)) {
            throw new \InvalidArgumentException('Unsupported upgrader artifact.');
        }
        $label = 'theme' === $artifact ? 'themes' : 'plugins';

        return new \WP_Error(
            'fs_unavailable',
            sprintf(
                'The WordPress filesystem is currently unavailable for managing %s. Configure direct filesystem access or stored SSH/FTP credentials, and check WordPress file ownership and permissions.',
                $label
            ),
            array('status' => 500)
        );
    }
}
