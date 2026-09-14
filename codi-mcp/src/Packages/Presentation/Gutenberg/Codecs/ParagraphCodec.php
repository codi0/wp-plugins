<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation\Gutenberg\Codecs;

use CodiMcp\Packages\Presentation\Gutenberg\Html;

final class ParagraphCodec extends AbstractCodec
{
    public function name(): string { return 'core/paragraph'; }
    public function capabilities(): array { return array('create','update_text','update_text_color','update_background_color','update_font_size','update_line_height','update_margin_block','update_padding'); }

    public function create(array $state, array $innerBlocks = array()): array
    {
        if ($innerBlocks !== array()) { throw new \InvalidArgumentException('Paragraph cannot contain InnerBlocks.'); }
        $attrs = is_array($state['attributes'] ?? null) ? $state['attributes'] : array();
        $this->assertAllowedAttributes($attrs, array('dropCap','direction','placeholder','align','anchor','backgroundColor','textColor','gradient','fontSize','style','metadata','lock'));
        $html = Html::plainText((string) ($state['text'] ?? ''));
        return $this->save($attrs, $html);
    }

    public function rewrite(array $block, array $changes = array(), ?array $innerBlocks = null): array
    {
        if (null !== $innerBlocks && $innerBlocks !== array()) { throw new \InvalidArgumentException('Paragraph cannot contain InnerBlocks.'); }
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        $this->assertAllowedAttributes($attrs, array('dropCap','direction','placeholder','align','anchor','backgroundColor','textColor','gradient','fontSize','style','metadata','lock'));
        $existing = Html::singleElement((string) ($block['innerHTML'] ?? ''), 'p');
        $html = array_key_exists('text', $changes) ? Html::plainText((string) $changes['text']) : $existing['content'];
        return $this->save($attrs, $html);
    }

    private function save(array $attrs, string $content): array
    {
        $wrapper = $this->supports->wrapper($attrs, !empty($attrs['dropCap']) ? array('has-drop-cap') : array(), false, true, true, array(
            'color' => array('text','background','gradient'),
            'typography' => array('fontSize','lineHeight'),
            'spacing' => array('margin' => true, 'padding' => true),
        ));
        if ('' !== trim((string) ($attrs['direction'] ?? ''))) { $wrapper['dir'] = (string) $attrs['direction']; }
        $html = '<p' . Html::attributes($wrapper) . '>' . $content . '</p>';
        return $this->block($attrs, array(), $html, array($html));
    }
}
