<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation\Gutenberg\Codecs;

use CodiMcp\Packages\Presentation\Gutenberg\Html;

final class ImageCodec extends AbstractCodec
{
    public function name(): string { return 'core/image'; }
    public function capabilities(): array { return array('create','update_media','update_alt','update_caption','update_link','update_dimensions'); }

    public function create(array $state, array $innerBlocks = array()): array
    {
        if ($innerBlocks !== array()) { throw new \InvalidArgumentException('Image cannot contain InnerBlocks.'); }
        $attrs = is_array($state['attributes'] ?? null) ? $state['attributes'] : array();
        foreach (array(
            'id' => 'id', 'width' => 'width', 'height' => 'height', 'aspect_ratio' => 'aspectRatio', 'scale' => 'scale',
            'size_slug' => 'sizeSlug', 'link_destination' => 'linkDestination', 'is_decorative' => 'isDecorative', 'align' => 'align',
        ) as $field => $attribute) {
            if (array_key_exists($field, $state) && null !== $state[$field] && '' !== (string) $state[$field]) { $attrs[$attribute] = $state[$field]; }
        }
        if (isset($state['focal_point']) && is_array($state['focal_point'])) { $attrs['focalPoint'] = $state['focal_point']; }
        $semantic = array(
            'url' => (string) ($state['url'] ?? ''),
            'alt' => (string) ($state['alt'] ?? ''),
            'caption_html' => Html::plainText((string) ($state['caption'] ?? '')),
            'title' => (string) ($state['title'] ?? ''),
            'href' => (string) ($state['href'] ?? ''),
            'rel' => (string) ($state['rel'] ?? ''),
            'link_class' => (string) ($state['link_class'] ?? ''),
            'link_target' => (string) ($state['link_target'] ?? ''),
        );
        return $this->save($attrs, $semantic);
    }

    public function rewrite(array $block, array $changes = array(), ?array $innerBlocks = null): array
    {
        if (null !== $innerBlocks && $innerBlocks !== array()) { throw new \InvalidArgumentException('Image cannot contain InnerBlocks.'); }
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        $semantic = $this->decode((string) ($block['innerHTML'] ?? ''));
        foreach (array('url','alt','title','href','rel','link_class','link_target') as $field) {
            if (array_key_exists($field, $changes)) { $semantic[$field] = (string) $changes[$field]; }
        }
        if (array_key_exists('caption', $changes)) { $semantic['caption_html'] = Html::plainText((string) $changes['caption']); }
        foreach (array(
            'id' => 'id', 'width' => 'width', 'height' => 'height', 'aspect_ratio' => 'aspectRatio', 'scale' => 'scale',
            'size_slug' => 'sizeSlug', 'link_destination' => 'linkDestination', 'is_decorative' => 'isDecorative', 'align' => 'align',
        ) as $field => $attribute) {
            if (!array_key_exists($field, $changes)) { continue; }
            $value = $changes[$field];
            if (null === $value || '' === (string) $value) { unset($attrs[$attribute]); } else { $attrs[$attribute] = $value; }
        }
        if (array_key_exists('focal_point', $changes)) {
            if (null === $changes['focal_point']) { unset($attrs['focalPoint']); }
            elseif (is_array($changes['focal_point'])) { $attrs['focalPoint'] = $changes['focal_point']; }
            else { throw new \InvalidArgumentException('Image focal_point must be an object or null.'); }
        }
        return $this->save($attrs, $semantic);
    }

    private function save(array $attrs, array $semantic): array
    {
        $this->assertAllowedAttributes($attrs, array('id','width','height','aspectRatio','scale','focalPoint','sizeSlug','linkDestination','isDecorative','lightbox','align','anchor','className','style','metadata','lock'));
        $url = trim((string) ($semantic['url'] ?? ''));
        if ('' === $url) { throw new \InvalidArgumentException('Presentation image codec requires a non-empty image URL.'); }
        $this->assertSafeUrl($url, 'image URL');

        // Core Image places alignment before its block-specific size/resized classes,
        // and custom classes after them. Keep this ordering byte-compatible with
        // the editor's current save() output rather than delegating all classes to
        // the generic wrapper support serializer.
        $wrapper = $this->supports->wrapper($attrs, array('wp-block-image'), false, false, false, array(
            'spacing' => array('margin' => true),
        ));
        $classes = preg_split('/\\s+/', trim((string) ($wrapper['class'] ?? ''))) ?: array();
        $sizeSlug = $this->slug((string) ($attrs['sizeSlug'] ?? ''));
        if ('' !== $sizeSlug) { $classes[] = 'size-' . $sizeSlug; }
        if ($this->hasDimension($attrs, 'width') || $this->hasDimension($attrs, 'height')) { $classes[] = 'is-resized'; }
        if ('' !== trim((string) ($attrs['className'] ?? ''))) { $classes[] = trim((string) $attrs['className']); }
        $wrapper['class'] = Html::classValue($classes);

        $imageClasses = array();
        $id = (int) ($attrs['id'] ?? 0);
        if ($id > 0) { $imageClasses[] = 'wp-image-' . $id; }
        $image = array('src' => $url, 'alt' => (string) ($semantic['alt'] ?? ''));
        if ($imageClasses !== array()) { $image['class'] = Html::classValue($imageClasses); }
        $style = $this->imageStyle($attrs);
        if ('' !== $style) { $image['style'] = $style; }
        if ('' !== trim((string) ($semantic['title'] ?? ''))) { $image['title'] = (string) $semantic['title']; }
        if (!empty($attrs['isDecorative'])) { $image['role'] = 'none'; }
        $imageHtml = '<img' . Html::attributes($image) . '/>';

        $href = trim((string) ($semantic['href'] ?? ''));
        if ('' !== $href) {
            $this->assertSafeUrl($href, 'image link');
            $link = array();
            if ('' !== trim((string) ($semantic['link_class'] ?? ''))) { $link['class'] = (string) $semantic['link_class']; }
            $link['href'] = $href;
            if ('' !== trim((string) ($semantic['link_target'] ?? ''))) { $link['target'] = (string) $semantic['link_target']; }
            if ('' !== trim((string) ($semantic['rel'] ?? ''))) { $link['rel'] = (string) $semantic['rel']; }
            $imageHtml = '<a' . Html::attributes($link) . '>' . $imageHtml . '</a>';
        }

        $caption = (string) ($semantic['caption_html'] ?? '');
        $bindings = is_array($attrs['metadata']['bindings'] ?? null) ? $attrs['metadata']['bindings'] : array();
        $displayCaption = '' !== trim(strip_tags($caption)) || isset($bindings['caption']) || 'core/pattern-overrides' === (string) ($bindings['__default']['source'] ?? '');
        if ($displayCaption) { $imageHtml .= '<figcaption class="wp-element-caption">' . $caption . '</figcaption>'; }
        $html = '<figure' . Html::attributes($wrapper) . '>' . $imageHtml . '</figure>';
        return $this->block($attrs, array(), $html, array($html));
    }

    /** @return array<string,string> */
    private function decode(string $html): array
    {
        $figure = Html::singleElement($html, 'figure');
        $content = trim($figure['content']);
        $caption = '';
        if (preg_match('/<figcaption\b[^>]*>(.*?)<\/figcaption>\s*$/is', $content, $captionMatch, PREG_OFFSET_CAPTURE)) {
            $caption = (string) ($captionMatch[1][0] ?? '');
            $content = trim(substr($content, 0, (int) ($captionMatch[0][1] ?? strlen($content))));
        }

        $link = array();
        if (preg_match('/^<a\b([^>]*)>(.*)<\/a>$/is', $content, $linkMatch)) {
            $link = Html::parseAttributes((string) $linkMatch[1]);
            $content = trim((string) $linkMatch[2]);
        }
        if (!preg_match('/^<img\b([^>]*)\/?\s*>$/is', $content, $imageMatch)) {
            throw new \InvalidArgumentException('Existing core/image markup does not match the supported PHP save codec structure.');
        }
        $image = Html::parseAttributes((string) $imageMatch[1]);
        return array(
            'url' => (string) ($image['src'] ?? ''),
            'alt' => (string) ($image['alt'] ?? ''),
            'caption_html' => $caption,
            'title' => (string) ($image['title'] ?? ''),
            'href' => (string) ($link['href'] ?? ''),
            'rel' => (string) ($link['rel'] ?? ''),
            'link_class' => (string) ($link['class'] ?? ''),
            'link_target' => (string) ($link['target'] ?? ''),
        );
    }

    private function imageStyle(array $attrs): string
    {
        $styles = array();
        foreach (array('aspectRatio' => 'aspect-ratio', 'scale' => 'object-fit') as $attribute => $property) {
            if (isset($attrs[$attribute]) && '' !== trim((string) $attrs[$attribute])) { $styles[$property] = $this->cssValue($attrs[$attribute]); }
        }
        if (isset($attrs['focalPoint'], $attrs['scale']) && is_array($attrs['focalPoint']) && '' !== trim((string) $attrs['scale'])) {
            $x = $attrs['focalPoint']['x'] ?? null; $y = $attrs['focalPoint']['y'] ?? null;
            if (!is_numeric($x) || !is_numeric($y) || (float) $x < 0 || (float) $x > 1 || (float) $y < 0 || (float) $y > 1) {
                throw new \InvalidArgumentException('Image focalPoint x/y must be numeric values between 0 and 1.');
            }
            $styles['object-position'] = $this->percent((float) $x * 100) . '% ' . $this->percent((float) $y * 100) . '%';
        }
        if (array_key_exists('width', $attrs) || array_key_exists('height', $attrs)) {
            if (array_key_exists('width', $attrs) && null !== $attrs['width']) { $styles['width'] = $this->dimension($attrs['width']); }
            $height = $attrs['height'] ?? null;
            $styles['height'] = (null === $height || 'auto' === $height || '' === (string) $height) ? 'auto' : $this->dimension($height);
        }
        $parts = array(); foreach ($styles as $property => $value) { if ('' !== $value) { $parts[] = $property . ':' . $value; } }
        return implode(';', $parts);
    }

    private function dimension(mixed $value): string
    {
        if (is_int($value) || is_float($value)) { return $value . 'px'; }
        $value = trim((string) $value);
        if ('auto' === $value) { return 'auto'; }
        return $this->cssValue($value);
    }

    private function cssValue(mixed $value): string
    {
        if (!is_scalar($value) || is_bool($value)) { throw new \InvalidArgumentException('Image CSS values must be scalar.'); }
        $value = trim((string) $value);
        if ('' === $value || strlen($value) > 500 || preg_match('/[;<>{}\x00]/', $value) || preg_match('/(?:expression|url)\s*\(/i', $value)) {
            throw new \InvalidArgumentException('Unsafe or unsupported Image CSS value.');
        }
        return $value;
    }

    private function assertSafeUrl(string $url, string $label): void
    {
        $lower = strtolower(trim($url));
        if (str_contains($url, "\0") || str_contains($url, '<') || str_contains($url, '>') || str_starts_with($lower, 'javascript:') || str_starts_with($lower, 'data:text/html')) {
            throw new \InvalidArgumentException(sprintf('Unsafe %s.', $label));
        }
    }

    private function hasDimension(array $attrs, string $key): bool { return array_key_exists($key, $attrs) && null !== $attrs[$key] && '' !== (string) $attrs[$key]; }
    private function percent(float $value): string { return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.'); }
    private function slug(string $value): string { return trim((string) preg_replace('/[^a-z0-9_-]+/i', '-', strtolower(trim($value))), '-'); }
}
