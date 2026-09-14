<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation\Gutenberg\Codecs;

use CodiMcp\Packages\Presentation\Gutenberg\Html;

final class GroupCodec extends AbstractCodec
{
    public function name(): string { return 'core/group'; }
    public function capabilities(): array { return array('create','insert_child','remove_child','reorder_child','update_text_color','update_background_color','update_font_size','update_line_height','update_margin_block','update_padding'); }
    public function supportsChildren(): bool { return true; }
    public function assertChildAllowed(string $blockName): void { if ('' === trim($blockName)) { throw new \InvalidArgumentException('Group child block name is required.'); } }

    public function create(array $state, array $innerBlocks = array()): array
    {
        $attrs = is_array($state['attributes'] ?? null) ? $state['attributes'] : array();
        return $this->save($attrs, $innerBlocks);
    }

    public function rewrite(array $block, array $changes = array(), ?array $innerBlocks = null): array
    {
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        if (isset($changes['attributes']) && is_array($changes['attributes'])) { $attrs = array_replace($attrs, $changes['attributes']); }
        return $this->save($attrs, null === $innerBlocks ? array_values((array) ($block['innerBlocks'] ?? array())) : $innerBlocks);
    }

    private function save(array $attrs, array $children): array
    {
        $this->assertAllowedAttributes($attrs, array('tagName','templateLock','align','anchor','ariaLabel','className','backgroundColor','textColor','gradient','fontSize','style','layout','allowedBlocks','metadata','lock'));
        $tag = strtolower(trim((string) ($attrs['tagName'] ?? 'div')));
        if (!in_array($tag, array('div','section','main','aside','header','footer','article'), true)) { throw new \InvalidArgumentException('Unsupported core/group tagName for PHP save codec.'); }
        $wrapper = $this->supports->wrapper($attrs, array('wp-block-group'), true, true, true, array(
            'color' => array('text','background','gradient'),
            'typography' => array('fontSize','lineHeight'),
            'dimensions' => array('minHeight','minWidth'),
            'spacing' => array('margin' => array('top','bottom'), 'padding' => true),
        ));
        $open = '<' . $tag . Html::attributes($wrapper) . '>';
        $close = '</' . $tag . '>';
        $segments = array($open);
        foreach ($children as $_) { $segments[] = null; $segments[] = ''; }
        $segments[count($segments) - 1] .= $close;
        return $this->block($attrs, $children, $open . $close, $segments);
    }
}
