<?php

namespace CodiMcp\Core;

interface RuntimePackage
{
    /**
     * Register package-owned runtime infrastructure that is not a WordPress Ability.
     */
    public function registerRuntime(PackageRuntime $runtime): void;
}
