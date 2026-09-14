<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation\Gutenberg;

final class SaveRuntime
{
    public function __construct(private ?BlockCodecRegistry $registry = null)
    {
        $this->registry ??= new BlockCodecRegistry();
    }

    public function registry(): BlockCodecRegistry
    {
        return $this->registry;
    }

    /** @param array<string,mixed> $state @param array<int,array<string,mixed>> $children @return array<string,mixed> */
    public function create(string $blockName, array $state = array(), array $children = array()): array
    {
        $this->assertRegistered($blockName);
        return $this->registry->get($blockName)->create($state, $children);
    }

    /** @param array<string,mixed> $block @param array<string,mixed> $changes @param array<int,array<string,mixed>>|null $children @return array<string,mixed> */
    public function rewrite(array $block, array $changes = array(), ?array $children = null): array
    {
        $blockName = strtolower(trim((string) ($block['blockName'] ?? '')));
        if ('' === $blockName) {
            throw new \InvalidArgumentException('Cannot rewrite a freeform/non-block node through the Gutenberg save runtime.');
        }
        $this->assertRegistered($blockName);
        return $this->registry->get($blockName)->rewrite($block, $changes, $children);
    }

    public function supportsChildren(string $blockName): bool
    {
        return $this->registry->has($blockName) && $this->registry->get($blockName)->supportsChildren();
    }

    public function assertChildAllowed(string $parentBlockName, string $childBlockName): void
    {
        $codec = $this->registry->get($parentBlockName);
        if (!$codec->supportsChildren()) {
            throw new \InvalidArgumentException(sprintf('Block [%s] has no container save codec.', $parentBlockName));
        }
        $codec->assertChildAllowed($childBlockName);
    }

    private function assertRegistered(string $blockName): void
    {
        $blockName = strtolower(trim($blockName));
        if (!class_exists('WP_Block_Type_Registry') || !method_exists('WP_Block_Type_Registry', 'get_instance')) {
            throw new \RuntimeException('WordPress block registry is unavailable for Gutenberg save codecs.');
        }
        $registry = \WP_Block_Type_Registry::get_instance();
        if (!is_object($registry) || !method_exists($registry, 'get_registered') || !is_object($registry->get_registered($blockName))) {
            throw new \InvalidArgumentException(sprintf('Block type [%s] is not registered in this WordPress runtime.', $blockName));
        }
    }
}
