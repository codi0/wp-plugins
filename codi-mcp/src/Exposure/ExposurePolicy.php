<?php

declare(strict_types=1);

namespace CodiMcp\Exposure;

use CodiMcp\Core\Abilities\AbilityCatalogue;

final class ExposurePolicy
{
    private const OPTION = 'codi_mcp_exposure_overrides';

    /** @var array<string,bool> */
    private array $managed = array();

    /** @var array<string,bool>|null */
    private ?array $selected = null;

    /** @param string[] $managedEnabled */
    public function __construct(private AbilityCatalogue $catalogue, array $managedEnabled = array())
    {
        foreach ($managedEnabled as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $this->managed[$name] = true;
            }
        }
    }

    public function isEnabled(string $abilityName): bool
    {
        $abilityName = trim($abilityName);
        $descriptor = $this->catalogue->get($abilityName);
        if ($descriptor === null || !$this->isEligible($descriptor)) {
            return false;
        }
        if (isset($this->managed[$abilityName])) {
            return true;
        }
        return isset($this->selected()[$abilityName]);
    }

    /** @return string[] */
    public function enabledNames(): array
    {
        $names = array();
        foreach ($this->rows() as $row) {
            if (!empty($row['enabled'])) {
                $names[] = (string) $row['name'];
            }
        }
        return $names;
    }

    /** @return array<int,array<string,mixed>> */
    public function rows(): array
    {
        $rows = array();
        foreach ($this->catalogue->all() as $descriptor) {
            if (!$this->isEligible($descriptor)) {
                continue;
            }
            $name = (string) $descriptor['name'];
            $descriptor['enabled'] = isset($this->managed[$name]) || isset($this->selected()[$name]);
            $descriptor['managed'] = isset($this->managed[$name]);
            $rows[] = $descriptor;
        }
        return $rows;
    }

    /** @param string[] $enabledNames */
    public function saveSelection(array $enabledNames): bool
    {
        $requested = array_fill_keys(array_values(array_filter(array_map('strval', $enabledNames), 'strlen')), true);
        $selected = array();
        foreach ($this->rows() as $row) {
            $name = (string) $row['name'];
            if (!empty($row['managed'])) {
                continue;
            }
            if (isset($requested[$name])) {
                $selected[$name] = true;
            }
        }

        if (!function_exists('update_option') || !function_exists('get_option')) {
            return false;
        }
        update_option(self::OPTION, $selected, false);
        $stored = $this->normalizeStored(get_option(self::OPTION, array()));
        if ($stored !== $selected) {
            $this->selected = null;
            return false;
        }
        $this->selected = $selected;
        return true;
    }

    /** @param array<string,mixed> $descriptor */
    private function isEligible(array $descriptor): bool
    {
        return !empty($descriptor['owned']) || !empty($descriptor['adoptable']);
    }

    /** @return array<string,bool> */
    private function selected(): array
    {
        if ($this->selected !== null) {
            return $this->selected;
        }
        $raw = function_exists('get_option') ? get_option(self::OPTION, array()) : array();
        $this->selected = $this->normalizeStored($raw);
        return $this->selected;
    }

    /** @return array<string,bool> */
    private function normalizeStored($raw): array
    {
        $selected = array();
        if (!is_array($raw)) {
            return $selected;
        }
        foreach ($raw as $name => $enabled) {
            if (is_string($name) && trim($name) !== '' && $enabled === true) {
                $selected[trim($name)] = true;
            }
        }
        return $selected;
    }
}