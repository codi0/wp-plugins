<?php

namespace CodiMcp\Core;

interface AbilityPackage
{
    public function key(): string;

    public function label(): string;

    /** @return string[] */
    public function abilityNames(): array;

    public function registerCategories(): void;

    public function registerAbilities(): void;
}
