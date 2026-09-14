<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation\Gutenberg\Codecs;

use CodiMcp\Packages\Presentation\Gutenberg\Html;

final class ColumnCodec extends AbstractCodec
{
    public function name(): string { return 'core/column'; }
    public function capabilities(): array { return array('create','insert_child','remove_child','reorder_child','update_width','update_vertical_alignment','update_text_color','update_background_color','update_font_size','update_line_height','update_padding'); }
    public function supportsChildren(): bool { return true; }
    public function assertChildAllowed(string $blockName): void { if ('' === trim($blockName)) { throw new \InvalidArgumentException('Column child block name is required.'); } }

    public function create(array $state, array $innerBlocks = array()): array
    {
        $attrs = is_array($state['attributes'] ?? null) ? $state['attributes'] : array();
        foreach (array('width' => 'width', 'vertical_alignment' => 'verticalAlignment') as $field => $attribute) {
            if (array_key_exists($field, $state) && '' !== trim((string) $state[$field])) { $attrs[$attribute] = (string) $state[$field]; }
        }
        return $this->save($attrs, $innerBlocks);
    }

    public function rewrite(array $block, array $changes = array(), ?array $innerBlocks = null): array
    {
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        foreach (array('width' => 'width', 'vertical_alignment' => 'verticalAlignment') as $field => $attribute) {
            if (!array_key_exists($field, $changes)) { continue; }
            $value = trim((string) $changes[$field]);
            if ('' === $value) { unset($attrs[$attribute]); } else { $attrs[$attribute] = $value; }
        }
        return $this->save($attrs, null === $innerBlocks ? array_values((array) ($block['innerBlocks'] ?? array())) : $innerBlocks);
    }

    private function save(array $attrs, array $children): array
    {
        $this->assertAllowedAttributes($attrs, array('verticalAlignment','width','templateLock','anchor','className','backgroundColor','textColor','gradient','fontSize','style','layout','allowedBlocks','metadata','lock'));
        $vertical = strtolower(trim((string) ($attrs['verticalAlignment'] ?? '')));
        if ('' !== $vertical && !in_array($vertical, array('top','center','bottom','stretch'), true)) {
            throw new \InvalidArgumentException('Unsupported core/column verticalAlignment for PHP save codec.');
        }
        $classes = array('wp-block-column');
        if ('' !== $vertical) { $classes[] = 'is-vertically-aligned-' . $vertical; }
        $wrapper = $this->supports->wrapper($attrs, $classes, true, true, true, array(
            'color' => array('text','background','gradient'),
            'typography' => array('fontSize','lineHeight'),
            'spacing' => array('padding' => true),
        ));
        $width = $this->flexBasis($attrs['width'] ?? null);
        if (null !== $width) {
            $existing = trim((string) ($wrapper['style'] ?? ''));
            $wrapper['style'] = ('' === $existing ? '' : rtrim($existing, ';') . ';') . 'flex-basis:' . $width;
        }
        $open = '<div' . Html::attributes($wrapper) . '>';
        $segments = array($open);
        foreach ($children as $_) { $segments[] = null; $segments[] = ''; }
        $segments[count($segments) - 1] .= '</div>';
        return $this->block($attrs, $children, $open . '</div>', $segments);
    }

    private function flexBasis(mixed $width): ?string
    {
        if (null === $width || '' === trim((string) $width) || !preg_match('/\d/', (string) $width)) { return null; }
        if (is_int($width) || is_float($width)) { return $width . '%'; }
        $width = trim((string) $width);
        if (str_ends_with($width, '%') && is_numeric(substr($width, 0, -1))) {
            $numeric = round((float) substr($width, 0, -1), 12);
            return rtrim(rtrim(sprintf('%.12F', $numeric), '0'), '.') . '%';
        }
        if (strlen($width) > 100 || preg_match('/[;<>{}\x00]/', $width)) {
            throw new \InvalidArgumentException('Unsafe core/column width value.');
        }
        return $width;
    }
}
