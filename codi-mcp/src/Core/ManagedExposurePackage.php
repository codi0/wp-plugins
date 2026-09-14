<?php

namespace CodiMcp\Core;

/**
 * Optional contract for bundled packages whose infrastructure abilities are
 * exposed by core policy rather than by a site's editable exposure selection.
 */
interface ManagedExposurePackage
{
    /** @return string[] */
    public function managedExposureAbilityNames(): array;
}
