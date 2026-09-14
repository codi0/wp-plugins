<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation\Gutenberg\Codecs;

use CodiMcp\Packages\Presentation\Gutenberg\Html;

final class HeadingCodec extends AbstractCodec
{
    public function name(): string { return 'core/heading'; }
    public function capabilities(): array { return array('create','update_text','update_text_color','update_background_color','update_font_size','update_line_height','update_margin_block','update_padding'); }

    public function create(array $state, array $innerBlocks = array()): array
    {
        if ($innerBlocks !== array()) { throw new \InvalidArgumentException('Heading cannot contain InnerBlocks.'); }
        $level = max(1, min(6, (int) ($state['level'] ?? 2)));
        $attrs = is_array($state['attributes'] ?? null) ? $state['attributes'] : array();
        if (2 !== $level) { $attrs['level'] = $level; }
        $this->assertAllowedAttributes($attrs, array('level','levelOptions','placeholder','align','anchor','className','backgroundColor','textColor','gradient','fontSize','style','metadata','lock'));
        return $this->save($attrs, Html::plainText((string) ($state['text'] ?? '')));
    }

    public function rewrite(array $block, array $changes = array(), ?array $innerBlocks = null): array
    {
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        $this->assertAllowedAttributes($attrs, array('level','levelOptions','placeholder','align','anchor','className','backgroundColor','textColor','gradient','fontSize','style','metadata','lock'));
        $level = max(1, min(6, (int) ($attrs['level'] ?? 2)));
        $existing = Html::singleElement((string) ($block['innerHTML'] ?? ''), 'h[1-6]');
        $html = array_key_exists('text', $changes) ? Html::plainText((string) $changes['text']) : $existing['content'];
        return $this->save($attrs, $html);
    }

    private function save(array $attrs, string $content): array
    {
        $level = max(1, min(6, (int) ($attrs['level'] ?? 2)));
        $wrapper = $this->supports->wrapper($attrs, array('wp-block-heading'), true, true, true, array(
            'color' => array('text','background','gradient'),
            'typography' => array('fontSize','lineHeight'),
            'spacing' => array('margin' => true, 'padding' => true),
        ));
        $html = '<h' . $level . Html::attributes($wrapper) . '>' . $content . '</h' . $level . '>';
        return $this->block($attrs, array(), $html, array($html));
    }
}
