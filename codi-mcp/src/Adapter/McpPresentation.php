<?php

declare(strict_types=1);

namespace CodiMcp\Adapter;

interface McpPresentation
{
    /** @param string[] $excludedAbilities @param string[]|null $includedAbilities @return array{tools:string[],resources:string[],prompts:string[]} */
    public function components(array $excludedAbilities = array(), ?array $includedAbilities = null): array;
}