<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation\Gutenberg\Codecs;

use CodiMcp\Packages\Presentation\Gutenberg\SupportSerializer;

/** Explicit codec for core blocks whose save() output is InnerBlocks.Content only. */
final class InnerBlocksOnlyCodec extends AbstractCodec
{
    /** @param string[] $allowedAttributes @param string[]|null $allowedChildren */
    public function __construct(
        private string $blockName,
        private array $allowedAttributes,
        private ?array $allowedChildren = null,
        ?SupportSerializer $supports = null
    ) {
        parent::__construct($supports);
    }

    public function name(): string { return $this->blockName; }
    public function capabilities(): array { return array('create','insert_child','remove_child','reorder_child'); }
    public function supportsChildren(): bool { return true; }

    public function assertChildAllowed(string $blockName): void
    {
        $blockName = strtolower(trim($blockName));
        if ('' === $blockName) { throw new \InvalidArgumentException(sprintf('%s child block name is required.', $this->blockName)); }
        if (null !== $this->allowedChildren && !in_array($blockName, $this->allowedChildren, true)) {
            throw new \InvalidArgumentException(sprintf('%s does not accept child block [%s] through its Presentation codec.', $this->blockName, $blockName));
        }
    }

    public function create(array $state, array $innerBlocks = array()): array
    {
        $attrs = is_array($state['attributes'] ?? null) ? $state['attributes'] : array();
        return $this->save($attrs, $innerBlocks);
    }

    public function rewrite(array $block, array $changes = array(), ?array $innerBlocks = null): array
    {
        if ($changes !== array()) {
            throw new \InvalidArgumentException(sprintf('%s currently supports structural child edits only.', $this->blockName));
        }
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        return $this->save($attrs, null === $innerBlocks ? array_values((array) ($block['innerBlocks'] ?? array())) : $innerBlocks);
    }

    /** @param array<string,mixed> $attrs @param array<int,array<string,mixed>> $children @return array<string,mixed> */
    private function save(array $attrs, array $children): array
    {
        $this->assertAllowedAttributes($attrs, $this->allowedAttributes);
        $segments = array();
        foreach ($children as $child) {
            $this->assertChildAllowed((string) ($child['blockName'] ?? ''));
            $segments[] = null;
            $segments[] = '';
        }
        return $this->block($attrs, $children, '', $segments);
    }
}
