<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation;

final class BlockSchemaGuard
{
    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    public function assertSafeMutation(array $before, array $after): void
    {
        $beforeName = strtolower(trim((string) ($before['blockName'] ?? '')));
        $afterName = strtolower(trim((string) ($after['blockName'] ?? '')));
        if ($beforeName !== $afterName) {
            throw new \InvalidArgumentException('A block mutation may not change the registered block type in place.');
        }
        if ('' === $afterName) {
            return;
        }

        $beforeAttrs = is_array($before['attrs'] ?? null) ? $before['attrs'] : array();
        $afterAttrs = is_array($after['attrs'] ?? null) ? $after['attrs'] : array();
        $introduced = array_diff(array_keys($afterAttrs), array_keys($beforeAttrs));
        $changed = array();
        foreach ($afterAttrs as $key => $value) {
            if (!array_key_exists($key, $beforeAttrs) || $beforeAttrs[$key] !== $value) {
                $changed[$key] = $value;
            }
        }
        $this->assertDeclaredAttributes($afterName, $introduced, $changed);
    }


    /** @param array<int,array<string,mixed>> $expected @param array<int,array<string,mixed>> $actual */
    public function assertEquivalentRoundTrip(array $expected, array $actual): void
    {
        $expectedFingerprint = $this->fingerprint($expected);
        $actualFingerprint = $this->fingerprint($actual);
        if (!hash_equals($expectedFingerprint, $actualFingerprint)) {
            throw new \InvalidArgumentException('WordPress PHP block serialization did not round-trip the proposed block tree exactly; refusing structurally unstable block markup.');
        }
    }

    /** @param string[] $introduced @param array<string,mixed> $changed */
    private function assertDeclaredAttributes(string $blockName, array $introduced, array $changed): void
    {
        $schema = $this->registeredAttributes($blockName);
        if (null === $schema) {
            if ($introduced !== array() || $changed !== array()) {
                throw new \InvalidArgumentException(sprintf('Cannot safely mutate attributes for unregistered block type [%s].', $blockName));
            }
            return;
        }

        foreach ($introduced as $attribute) {
            $attribute = (string) $attribute;
            if (!array_key_exists($attribute, $schema)) {
                throw new \InvalidArgumentException(sprintf('Block [%s] does not declare attribute [%s]; refusing to add an editor-unsafe property.', $blockName, $attribute));
            }
        }
        foreach ($changed as $attribute => $value) {
            if (!array_key_exists((string) $attribute, $schema)) {
                // Pre-existing unknown attributes may survive untouched, but may not be changed.
                throw new \InvalidArgumentException(sprintf('Block [%s] does not declare changed attribute [%s].', $blockName, (string) $attribute));
            }
            $definition = is_array($schema[$attribute] ?? null) ? $schema[$attribute] : array();
            $this->assertAttributeValue((string) $attribute, $value, $definition);
        }
    }


    /** @return array<string,mixed>|null */
    private function registeredAttributes(string $blockName): ?array
    {
        if (!class_exists('WP_Block_Type_Registry') || !method_exists('WP_Block_Type_Registry', 'get_instance')) {
            return null;
        }
        $registry = \WP_Block_Type_Registry::get_instance();
        if (!is_object($registry) || !method_exists($registry, 'get_registered')) {
            return null;
        }
        $blockType = $registry->get_registered($blockName);
        if (!is_object($blockType)) {
            return null;
        }
        $attributes = method_exists($blockType, 'get_attributes') ? $blockType->get_attributes() : ($blockType->attributes ?? array());
        return is_array($attributes) ? $attributes : array();
    }

    /** @param array<string,mixed> $definition */
    private function assertAttributeValue(string $attribute, mixed $value, array $definition): void
    {
        $type = strtolower(trim((string) ($definition['type'] ?? '')));
        if ('' === $type || null === $value) {
            return;
        }
        $valid = match ($type) {
            'string' => is_string($value),
            'boolean' => is_bool($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'array' => is_array($value) && array_keys($value) === range(0, count($value) - 1),
            'object' => is_array($value) && (array_keys($value) !== range(0, count($value) - 1) || $value === array()),
            default => true,
        };
        if (!$valid) {
            throw new \InvalidArgumentException(sprintf('Block attribute [%s] does not match its registered type [%s].', $attribute, $type));
        }
        if (isset($definition['enum']) && is_array($definition['enum']) && !in_array($value, $definition['enum'], true)) {
            throw new \InvalidArgumentException(sprintf('Block attribute [%s] is outside its registered enum.', $attribute));
        }
    }

    /** @param array<int,array<string,mixed>> $blocks */
    private function fingerprint(array $blocks): string
    {
        $normalized = array_map([$this, 'normalizedBlock'], array_values(array_filter($blocks, 'is_array')));
        return hash('sha256', (string) json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string,mixed> $block @return array<string,mixed> */
    private function normalizedBlock(array $block): array
    {
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        $attrs = $this->sortRecursive($attrs);
        $children = array_values(array_filter((array) ($block['innerBlocks'] ?? array()), 'is_array'));
        return array(
            'name' => (string) ($block['blockName'] ?? ''),
            'attrs' => $attrs,
            'innerHTML' => (string) ($block['innerHTML'] ?? ''),
            'children' => array_map([$this, 'normalizedBlock'], $children),
        );
    }

    /** @param array<mixed> $value @return array<mixed> */
    private function sortRecursive(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursive($item);
            }
        }
        if ($value !== array() && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }
        return $value;
    }
}
