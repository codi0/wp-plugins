<?php

declare(strict_types=1);

namespace CodiMcp\Core;

use CodiMcp\Audit\AuditLog;
use CodiMcp\Core\Abilities\AbilityCatalogue;
use CodiMcp\Exposure\ExposurePolicy;

final class PackageRuntime
{
    public function __construct(
        private AbilityCatalogue $catalogue,
        private ExposurePolicy $exposure,
        private AuditLog $audit
    ) {
    }

    public function catalogue(): AbilityCatalogue
    {
        return $this->catalogue;
    }

    public function audit(): AuditLog
    {
        return $this->audit;
    }

    public function isAbilityExposed(string $abilityName): bool
    {
        return $this->exposure->isEnabled($abilityName);
    }
}