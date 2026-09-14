<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation\Gutenberg\Codecs;

use CodiMcp\Packages\Presentation\Gutenberg\Html;

final class ColumnsCodec extends AbstractCodec
{
    public function name(): string { return 'core/columns'; }
    public function capabilities(): array { return array('create','insert_child','remove_child','reorder_child','update_layout','update_text_color','update_background_color','update_font_size','update_line_height','update_margin_block','update_padding'); }
    public function supportsChildren(): bool { return true; }

    public function assertChildAllowed(string $blockName): void
    {
        if ('core/column' !== strtolower(trim($blockName))) {
            throw new \InvalidArgumentException('core/columns only accepts core/column children.');
        }
    }

    public function create(array $state, array $innerBlocks = array()): array
    {
        $attrs = is_array($state['attributes'] ?? null) ? $state['attributes'] : array();
        if (array_key_exists('vertical_alignment', $state) && '' !== trim((string) $state['vertical_alignment'])) {
            $attrs['verticalAlignment'] = (string) $state['vertical_alignment'];
        }
        if (array_key_exists('is_stacked_on_mobile', $state)) {
            $attrs['isStackedOnMobile'] = (bool) $state['is_stacked_on_mobile'];
        }
        return $this->save($attrs, $innerBlocks);
    }

    public function rewrite(array $block, array $changes = array(), ?array $innerBlocks = null): array
    {
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        if (array_key_exists('vertical_alignment', $changes)) {
            $value = trim((string) $changes['vertical_alignment']);
            if ('' === $value) { unset($attrs['verticalAlignment']); } else { $attrs['verticalAlignment'] = $value; }
        }
        if (array_key_exists('is_stacked_on_mobile', $changes)) {
            $attrs['isStackedOnMobile'] = (bool) $changes['is_stacked_on_mobile'];
        }
        return $this->save($attrs, null === $innerBlocks ? array_values((array) ($block['innerBlocks'] ?? array())) : $innerBlocks);
    }

    private function save(array $attrs, array $children): array
    {
        $this->assertAllowedAttributes($attrs, array('verticalAlignment','isStackedOnMobile','templateLock','align','anchor','className','backgroundColor','textColor','gradient','fontSize','style','layout','metadata','lock'));
        foreach ($children as $child) { $this->assertChildAllowed((string) ($child['blockName'] ?? '')); }

        $vertical = strtolower(trim((string) ($attrs['verticalAlignment'] ?? '')));
        if ('' !== $vertical && !in_array($vertical, array('top','center','bottom','stretch'), true)) {
            throw new \InvalidArgumentException('Unsupported core/columns verticalAlignment for PHP save codec.');
        }
        $classes = array('wp-block-columns');
        if ('' !== $vertical) { $classes[] = 'are-vertically-aligned-' . $vertical; }
        if (array_key_exists('isStackedOnMobile', $attrs) && false === $attrs['isStackedOnMobile']) { $classes[] = 'is-not-stacked-on-mobile'; }

        $wrapper = $this->supports->wrapper($attrs, $classes, true, true, true, array(
            'color' => array('text','background','gradient'),
            'typography' => array('fontSize','lineHeight'),
            'spacing' => array('margin' => array('top','bottom'), 'padding' => true),
        ));
        $open = '<div' . Html::attributes($wrapper) . '>';
        $segments = array($open);
        foreach ($children as $_) { $segments[] = null; $segments[] = ''; }
        $segments[count($segments) - 1] .= '</div>';
        $attrs = $this->withoutDefault($attrs, 'isStackedOnMobile', true);
        return $this->block($attrs, $children, $open . '</div>', $segments);
    }
}
