<?php

declare(strict_types=1);

namespace CodiMcp\Core\Abilities;

final class AbilityMetadata
{
    public static function owned(
        string $package,
        bool $readonly,
        bool $destructive,
        bool $idempotent,
        bool $openWorld = false,
        string $type = 'tool'
    ): array {
        return array(
            'public' => false,
            'show_in_rest' => false,
            'mcp' => array(
                'public' => false,
                'type' => $type,
            ),
            'annotations' => array(
                'readonly' => $readonly,
                'destructive' => $destructive,
                'idempotent' => $idempotent,
                'openWorldHint' => $openWorld,
            ),
            'codi_mcp' => array(
                'owned' => true,
                'package' => trim($package),
            ),
        );
    }
}