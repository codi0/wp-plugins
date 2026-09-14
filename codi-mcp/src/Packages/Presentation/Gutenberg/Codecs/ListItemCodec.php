<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation\Gutenberg\Codecs;

use CodiMcp\Packages\Presentation\Gutenberg\Html;

final class ListItemCodec extends AbstractCodec
{
    public function name(): string { return 'core/list-item'; }
    public function capabilities(): array { return array('create','update_text','insert_child','remove_child','reorder_child','update_text_color','update_background_color','update_font_size','update_line_height','update_margin_block','update_padding'); }
    public function supportsChildren(): bool { return true; }

    public function assertChildAllowed(string $blockName): void
    {
        if ('core/list' !== strtolower(trim($blockName))) {
            throw new \InvalidArgumentException('core/list-item only accepts a nested core/list through its Presentation codec.');
        }
    }

    public function create(array $state, array $innerBlocks = array()): array
    {
        $attrs = is_array($state['attributes'] ?? null) ? $state['attributes'] : array();
        return $this->save($attrs, Html::plainText((string) ($state['text'] ?? '')), $innerBlocks);
    }

    public function rewrite(array $block, array $changes = array(), ?array $innerBlocks = null): array
    {
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        $existing = Html::singleElement((string) ($block['innerHTML'] ?? ''), 'li');
        $content = array_key_exists('text', $changes) ? Html::plainText((string) $changes['text']) : $existing['content'];
        return $this->save($attrs, $content, null === $innerBlocks ? array_values((array) ($block['innerBlocks'] ?? array())) : $innerBlocks);
    }

    /** @param array<string,mixed> $attrs @param array<int,array<string,mixed>> $children @return array<string,mixed> */
    private function save(array $attrs, string $content, array $children): array
    {
        $this->assertAllowedAttributes($attrs, array('placeholder','anchor','backgroundColor','textColor','gradient','fontSize','style','metadata','lock'));
        foreach ($children as $child) { $this->assertChildAllowed((string) ($child['blockName'] ?? '')); }
        $wrapper = $this->supports->wrapper($attrs, array(), false, true, true, array(
            'color' => array('text','background','gradient'),
            'typography' => array('fontSize','lineHeight'),
            'spacing' => array('margin' => true, 'padding' => true),
        ));
        $open = '<li' . Html::attributes($wrapper) . '>' . $content;
        $segments = array($open);
        foreach ($children as $_) { $segments[] = null; $segments[] = ''; }
        $segments[count($segments) - 1] .= '</li>';
        return $this->block($attrs, $children, '<li' . Html::attributes($wrapper) . '>' . $content . '</li>', $segments);
    }
}
