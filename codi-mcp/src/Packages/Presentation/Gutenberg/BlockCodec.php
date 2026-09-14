<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation\Gutenberg;

interface BlockCodec
{
    public function name(): string;

    /** @return string[] */
    public function capabilities(): array;

    public function supportsChildren(): bool;

    public function assertChildAllowed(string $blockName): void;

    /**
     * @param array<string,mixed> $state Semantic state understood by this codec.
     * @param array<int,array<string,mixed>> $innerBlocks
     * @return array<string,mixed>
     */
    public function create(array $state, array $innerBlocks = array()): array;

    /**
     * @param array<string,mixed> $block Parsed WordPress block.
     * @param array<string,mixed> $changes Semantic changes understood by this codec.
     * @param array<int,array<string,mixed>>|null $innerBlocks Replacement children, or null to preserve current children.
     * @return array<string,mixed>
     */
    public function rewrite(array $block, array $changes = array(), ?array $innerBlocks = null): array;
}
