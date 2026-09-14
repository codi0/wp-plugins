<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation\Gutenberg\Codecs;

use CodiMcp\Packages\Presentation\Gutenberg\BlockCodec;
use CodiMcp\Packages\Presentation\Gutenberg\SupportSerializer;

abstract class AbstractCodec implements BlockCodec
{
    public function __construct(protected ?SupportSerializer $supports = null)
    {
        $this->supports ??= new SupportSerializer();
    }

    public function supportsChildren(): bool
    {
        return false;
    }

    public function assertChildAllowed(string $blockName): void
    {
        throw new \InvalidArgumentException(sprintf('Block [%s] does not support nested insertion through its Presentation codec.', $this->name()));
    }

    /** @param array<string,mixed> $attrs @param string[] $allowed */
    protected function assertAllowedAttributes(array $attrs, array $allowed): void
    {
        foreach (array_keys($attrs) as $attribute) {
            if (!in_array((string) $attribute, $allowed, true)) {
                if ('style' === (string) $attribute) {
                    throw new \InvalidArgumentException(sprintf('Block [%s] uses style block-support state that the PHP save runtime does not yet reproduce; refusing to regenerate it.', $this->name()));
                }
                throw new \InvalidArgumentException(sprintf('Block [%s] attribute [%s] is not covered by the current PHP save codec.', $this->name(), (string) $attribute));
            }
        }
    }

    /**
     * @param array<string,mixed> $attrs
     * @param array<int,array<string,mixed>> $children
     * @param array<int,string|null> $innerContent
     * @return array<string,mixed>
     */
    protected function block(array $attrs, array $children, string $innerHtml, array $innerContent): array
    {
        return array(
            'blockName' => $this->name(),
            'attrs' => $attrs,
            'innerBlocks' => array_values($children),
            'innerHTML' => $innerHtml,
            'innerContent' => array_values($innerContent),
        );
    }

    /** @param array<string,mixed> $attrs */
    protected function withoutDefault(array $attrs, string $key, mixed $default): array
    {
        if (array_key_exists($key, $attrs) && $attrs[$key] === $default) {
            unset($attrs[$key]);
        }
        return $attrs;
    }
}
