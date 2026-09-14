<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation\Gutenberg\Codecs;

use CodiMcp\Packages\Presentation\Gutenberg\Html;

/** Current core/gallery nested-image save contract. */
final class GalleryCodec extends AbstractCodec
{
    public function name(): string { return 'core/gallery'; }
    public function capabilities(): array { return array('create','insert_child','remove_child','reorder_child','update_columns','update_crop','update_caption','update_background_color','update_margin_block','update_padding'); }
    public function supportsChildren(): bool { return true; }

    public function assertChildAllowed(string $blockName): void
    {
        if ('core/image' !== strtolower(trim($blockName))) {
            throw new \InvalidArgumentException('core/gallery only accepts core/image children.');
        }
    }

    public function create(array $state, array $innerBlocks = array()): array
    {
        $attrs = is_array($state['attributes'] ?? null) ? $state['attributes'] : array();
        $semantic = array('caption_html' => Html::plainText((string) ($state['caption'] ?? '')));
        $attrs = $this->applySemanticState($attrs, $state);
        return $this->save($attrs, $semantic, $innerBlocks);
    }

    public function rewrite(array $block, array $changes = array(), ?array $innerBlocks = null): array
    {
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        $semantic = $this->decode((string) ($block['innerHTML'] ?? ''));
        if (array_key_exists('caption', $changes)) {
            $semantic['caption_html'] = Html::plainText((string) $changes['caption']);
        }
        $attrs = $this->applySemanticState($attrs, $changes);
        return $this->save($attrs, $semantic, null === $innerBlocks ? array_values((array) ($block['innerBlocks'] ?? array())) : $innerBlocks);
    }

    /** @param array<string,mixed> $attrs @param array<string,mixed> $state @return array<string,mixed> */
    private function applySemanticState(array $attrs, array $state): array
    {
        if (array_key_exists('columns', $state)) {
            $columns = (int) $state['columns'];
            if ($columns < 1 || $columns > 8) {
                throw new \InvalidArgumentException('core/gallery columns must be between 1 and 8.');
            }
            $attrs['columns'] = $columns;
        }
        if (array_key_exists('image_crop', $state)) {
            $attrs['imageCrop'] = (bool) $state['image_crop'];
        }
        foreach (array(
            'align' => 'align', 'anchor' => 'anchor', 'class_name' => 'className',
            'background_color' => 'backgroundColor', 'gradient' => 'gradient',
        ) as $field => $attribute) {
            if (!array_key_exists($field, $state)) {
                continue;
            }
            $value = trim((string) $state[$field]);
            if ('' === $value) { unset($attrs[$attribute]); } else { $attrs[$attribute] = $value; }
        }
        return $attrs;
    }

    /**
     * @param array<string,mixed> $attrs
     * @param array<string,string> $semantic
     * @param array<int,array<string,mixed>> $children
     * @return array<string,mixed>
     */
    private function save(array $attrs, array $semantic, array $children): array
    {
        $this->assertAllowedAttributes($attrs, array(
            'columns','imageCrop','align','anchor','className','backgroundColor','gradient','style','metadata','lock',
        ));
        foreach ($children as $child) {
            $this->assertChildAllowed((string) ($child['blockName'] ?? ''));
        }

        $columns = array_key_exists('columns', $attrs) ? (int) $attrs['columns'] : null;
        if (null !== $columns && ($columns < 1 || $columns > 8)) {
            throw new \InvalidArgumentException('core/gallery columns must be between 1 and 8.');
        }
        $imageCrop = array_key_exists('imageCrop', $attrs) ? (bool) $attrs['imageCrop'] : true;

        // useBlockProps.save() orders alignment before Gallery's own className,
        // then custom and color support classes afterwards.
        $wrapper = $this->supports->wrapper($attrs, array('wp-block-gallery'), false, true, false, array(
            'color' => array('background','gradient'),
            'spacing' => array('margin' => true, 'padding' => true),
        ));
        $generated = preg_split('/\s+/', trim((string) ($wrapper['class'] ?? ''))) ?: array();
        $prefix = array();
        $supportClasses = array();
        foreach ($generated as $class) {
            if ('wp-block-gallery' === $class || str_starts_with($class, 'align')) {
                $prefix[] = $class;
            } else {
                $supportClasses[] = $class;
            }
        }
        $classes = array_merge($prefix, array(
            'has-nested-images',
            null === $columns ? 'columns-default' : 'columns-' . $columns,
        ));
        if ($imageCrop) {
            $classes[] = 'is-cropped';
        }
        if ('' !== trim((string) ($attrs['className'] ?? ''))) {
            $classes[] = trim((string) $attrs['className']);
        }
        $classes = array_merge($classes, $supportClasses);
        $wrapper['class'] = Html::classValue($classes);

        $caption = (string) ($semantic['caption_html'] ?? '');
        $captionHtml = '' === trim(strip_tags($caption))
            ? ''
            : '<figcaption class="blocks-gallery-caption wp-element-caption">' . $caption . '</figcaption>';

        $open = '<figure' . Html::attributes($wrapper) . '>';
        $segments = array($open);
        foreach ($children as $_) {
            $segments[] = null;
            $segments[] = '';
        }
        $segments[count($segments) - 1] .= $captionHtml . '</figure>';

        $savedAttrs = $this->withoutDefault($attrs, 'imageCrop', true);
        return $this->block($savedAttrs, $children, $open . $captionHtml . '</figure>', $segments);
    }

    /** @return array{caption_html:string} */
    private function decode(string $html): array
    {
        $figure = Html::singleElement($html, 'figure');
        $caption = '';
        if (preg_match('/<figcaption\b[^>]*class=(?:"|\')[^"\']*blocks-gallery-caption[^"\']*(?:"|\')[^>]*>(.*?)<\/figcaption>\s*$/is', $figure['content'], $match)) {
            $caption = (string) ($match[1] ?? '');
        }
        return array('caption_html' => $caption);
    }
}
