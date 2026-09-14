<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation\Gutenberg\Codecs;

use CodiMcp\Packages\Presentation\Gutenberg\Html;

final class ButtonsCodec extends AbstractCodec
{
    public function name(): string { return 'core/buttons'; }
    public function capabilities(): array { return array('create','insert_child','remove_child','reorder_child','update_background_color','update_font_size','update_line_height','update_margin_block','update_padding'); }
    public function supportsChildren(): bool { return true; }
    public function assertChildAllowed(string $blockName): void { if ('core/button' !== strtolower(trim($blockName))) { throw new \InvalidArgumentException('core/buttons only accepts core/button children.'); } }

    public function create(array $state, array $innerBlocks = array()): array { return $this->save(is_array($state['attributes'] ?? null) ? $state['attributes'] : array(), $innerBlocks); }
    public function rewrite(array $block, array $changes = array(), ?array $innerBlocks = null): array { return $this->save(is_array($block['attrs'] ?? null) ? $block['attrs'] : array(), null === $innerBlocks ? array_values((array) ($block['innerBlocks'] ?? array())) : $innerBlocks); }

    private function save(array $attrs, array $children): array
    {
        $this->assertAllowedAttributes($attrs, array('align','anchor','className','backgroundColor','gradient','fontSize','style','layout','metadata','lock'));
        foreach ($children as $child) { $this->assertChildAllowed((string) ($child['blockName'] ?? '')); }
        $wrapper = $this->supports->wrapper($attrs, array('wp-block-buttons'), true, true, true, array(
            'color' => array('background','gradient'),
            'typography' => array('fontSize','lineHeight'),
            'spacing' => array('margin' => array('top','bottom'), 'padding' => true),
        ));
        $open = '<div' . Html::attributes($wrapper) . '>';
        $segments = array($open);
        foreach ($children as $_) { $segments[] = null; $segments[] = ''; }
        $segments[count($segments) - 1] .= '</div>';
        return $this->block($attrs, $children, $open . '</div>', $segments);
    }
}
