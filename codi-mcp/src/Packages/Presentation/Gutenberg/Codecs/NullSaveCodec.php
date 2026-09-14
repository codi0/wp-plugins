<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation\Gutenberg\Codecs;

use CodiMcp\Packages\Presentation\Gutenberg\SupportSerializer;

/** Explicit codec for known core blocks whose current JavaScript save() returns null. */
final class NullSaveCodec extends AbstractCodec
{
    /** @param string[] $allowedAttributes */
    public function __construct(
        private string $blockName,
        private array $allowedAttributes,
        ?SupportSerializer $supports = null
    ) {
        parent::__construct($supports);
    }

    public function name(): string { return $this->blockName; }
    public function capabilities(): array { return array('create'); }

    public function create(array $state, array $innerBlocks = array()): array
    {
        if ($innerBlocks !== array()) { throw new \InvalidArgumentException(sprintf('%s cannot contain InnerBlocks.', $this->blockName)); }
        $attrs = is_array($state['attributes'] ?? null) ? $state['attributes'] : array();
        $this->assertAllowedAttributes($attrs, $this->allowedAttributes);
        return $this->block($attrs, array(), '', array());
    }

    public function rewrite(array $block, array $changes = array(), ?array $innerBlocks = null): array
    {
        if ($changes !== array() || (null !== $innerBlocks && $innerBlocks !== array())) {
            throw new \InvalidArgumentException(sprintf('%s has no public semantic rewrite contract.', $this->blockName));
        }
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        $this->assertAllowedAttributes($attrs, $this->allowedAttributes);
        return $this->block($attrs, array(), '', array());
    }
}
