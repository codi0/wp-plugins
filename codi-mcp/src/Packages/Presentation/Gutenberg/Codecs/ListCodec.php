<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation\Gutenberg\Codecs;

use CodiMcp\Packages\Presentation\Gutenberg\Html;

final class ListCodec extends AbstractCodec
{
    public function name(): string { return 'core/list'; }
    public function capabilities(): array { return array('create','insert_child','remove_child','reorder_child','update_text_color','update_background_color','update_font_size','update_line_height','update_margin_block','update_padding'); }
    public function supportsChildren(): bool { return true; }

    public function assertChildAllowed(string $blockName): void
    {
        if ('core/list-item' !== strtolower(trim($blockName))) {
            throw new \InvalidArgumentException('core/list only accepts core/list-item children through its Presentation codec.');
        }
    }

    public function create(array $state, array $innerBlocks = array()): array
    {
        $attrs = is_array($state['attributes'] ?? null) ? $state['attributes'] : array();
        if (array_key_exists('ordered', $state) && (bool) $state['ordered']) { $attrs['ordered'] = true; }
        return $this->save($attrs, $innerBlocks);
    }

    public function rewrite(array $block, array $changes = array(), ?array $innerBlocks = null): array
    {
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        if (array_key_exists('ordered', $changes)) {
            if ((bool) $changes['ordered']) { $attrs['ordered'] = true; } else { unset($attrs['ordered']); }
        }
        return $this->save($attrs, null === $innerBlocks ? array_values((array) ($block['innerBlocks'] ?? array())) : $innerBlocks);
    }

    /** @param array<string,mixed> $attrs @param array<int,array<string,mixed>> $children @return array<string,mixed> */
    private function save(array $attrs, array $children): array
    {
        $this->assertAllowedAttributes($attrs, array('ordered','placeholder','align','anchor','className','backgroundColor','textColor','gradient','fontSize','style','metadata','lock'));
        foreach ($children as $child) { $this->assertChildAllowed((string) ($child['blockName'] ?? '')); }
        $tag = !empty($attrs['ordered']) ? 'ol' : 'ul';
        $wrapper = $this->supports->wrapper($attrs, array('wp-block-list'), true, true, true, array(
            'color' => array('text','background','gradient'),
            'typography' => array('fontSize','lineHeight'),
            'spacing' => array('margin' => true, 'padding' => true),
        ));
        $open = '<' . $tag . Html::attributes($wrapper) . '>';
        $segments = array($open);
        foreach ($children as $_) { $segments[] = null; $segments[] = ''; }
        $segments[count($segments) - 1] .= '</' . $tag . '>';
        return $this->block($attrs, $children, $open . '</' . $tag . '>', $segments);
    }
}
