<?php

declare(strict_types=1);

namespace CodiMcp\Adapter;

use CodiMcp\Core\Abilities\AbilityCatalogue;
use CodiMcp\Exposure\ExposurePolicy;

final class DirectMcpPresentation implements McpPresentation
{
    public function __construct(
        private AbilityCatalogue $catalogue,
        private ExposurePolicy $exposure
    ) {
    }

    public function components(array $excludedAbilities = array(), ?array $includedAbilities = null): array
    {
        $names = array_fill_keys($this->exposure->enabledNames(), true);

        if ($includedAbilities !== null) {
            $included = array_fill_keys(array_values(array_filter(array_map('strval', $includedAbilities), 'strlen')), true);
            $names = array_intersect_key($names, $included);
        }

        foreach ($excludedAbilities as $name) {
            unset($names[trim((string) $name)]);
        }

        return $this->catalogue->components(array_keys($names));
    }
}