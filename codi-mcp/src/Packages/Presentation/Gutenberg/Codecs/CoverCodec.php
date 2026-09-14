<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation\Gutenberg\Codecs;

use CodiMcp\Packages\Presentation\Gutenberg\Html;

/** Current core/cover image-background save contract. Video/embed backgrounds fail closed. */
final class CoverCodec extends AbstractCodec
{
    private const POSITIONS = array(
        'top left' => 'is-position-top-left',
        'top center' => 'is-position-top-center',
        'top right' => 'is-position-top-right',
        'center left' => 'is-position-center-left',
        'center right' => 'is-position-center-right',
        'bottom left' => 'is-position-bottom-left',
        'bottom center' => 'is-position-bottom-center',
        'bottom right' => 'is-position-bottom-right',
    );

    public function name(): string { return 'core/cover'; }
    public function capabilities(): array { return array('create','insert_child','remove_child','reorder_child','update_media','update_overlay','update_position','update_dimensions','update_text_color','update_font_size','update_line_height','update_margin_block','update_padding'); }
    public function supportsChildren(): bool { return true; }
    public function assertChildAllowed(string $blockName): void { if ('' === trim($blockName)) { throw new \InvalidArgumentException('Cover child block name is required.'); } }

    public function create(array $state, array $innerBlocks = array()): array
    {
        $attrs = is_array($state['attributes'] ?? null) ? $state['attributes'] : array();
        $attrs = $this->applySemanticState($attrs, $state);
        return $this->save($attrs, $innerBlocks);
    }

    public function rewrite(array $block, array $changes = array(), ?array $innerBlocks = null): array
    {
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        $attrs = $this->applySemanticState($attrs, $changes);
        return $this->save($attrs, null === $innerBlocks ? array_values((array) ($block['innerBlocks'] ?? array())) : $innerBlocks);
    }

    /** @param array<string,mixed> $attrs @param array<string,mixed> $state @return array<string,mixed> */
    private function applySemanticState(array $attrs, array $state): array
    {
        $map = array(
            'url' => 'url', 'id' => 'id', 'alt' => 'alt', 'use_featured_image' => 'useFeaturedImage',
            'has_parallax' => 'hasParallax', 'is_repeated' => 'isRepeated', 'dim_ratio' => 'dimRatio',
            'overlay_color' => 'overlayColor', 'custom_overlay_color' => 'customOverlayColor',
            'gradient' => 'gradient', 'custom_gradient' => 'customGradient', 'content_position' => 'contentPosition',
            'is_dark' => 'isDark', 'focal_point' => 'focalPoint', 'min_height' => 'minHeight',
            'min_height_unit' => 'minHeightUnit', 'tag_name' => 'tagName', 'size_slug' => 'sizeSlug',
            'background_type' => 'backgroundType', 'text_color' => 'textColor', 'font_size' => 'fontSize',
            'align' => 'align', 'anchor' => 'anchor', 'class_name' => 'className',
        );
        foreach ($map as $field => $attribute) {
            if (!array_key_exists($field, $state)) { continue; }
            $value = $state[$field];
            if (null === $value || (is_string($value) && '' === trim($value) && !in_array($attribute, array('url','alt','customOverlayColor','customGradient'), true))) {
                unset($attrs[$attribute]);
                continue;
            }
            $attrs[$attribute] = $value;
        }
        return $attrs;
    }

    /** @param array<string,mixed> $attrs @param array<int,array<string,mixed>> $children @return array<string,mixed> */
    private function save(array $attrs, array $children): array
    {
        $this->assertAllowedAttributes($attrs, array(
            'url','useFeaturedImage','id','alt','hasParallax','isRepeated','dimRatio','overlayColor','customOverlayColor','isUserOverlayColor',
            'backgroundType','focalPoint','minHeight','minHeightUnit','gradient','customGradient','contentPosition','isDark','templateLock',
            'tagName','sizeSlug','poster','allowedVideoProviders','align','anchor','className','textColor','fontSize','style','layout','allowedBlocks','metadata','lock',
        ));
        $backgroundType = strtolower(trim((string) ($attrs['backgroundType'] ?? 'image')));
        if ('image' !== $backgroundType) {
            throw new \InvalidArgumentException('The PHP core/cover save codec currently supports image backgrounds only; video and embedded-video Covers remain read-only internally.');
        }
        $tag = strtolower(trim((string) ($attrs['tagName'] ?? 'div')));
        if (!in_array($tag, array('div','section','main','aside','header','footer','article'), true)) {
            throw new \InvalidArgumentException('Unsupported core/cover tagName for PHP save codec.');
        }
        $focal = $this->focalPoint($attrs['focalPoint'] ?? null);
        $hasParallax = (bool) ($attrs['hasParallax'] ?? false);
        $isRepeated = (bool) ($attrs['isRepeated'] ?? false);
        $isImgElement = !($hasParallax || $isRepeated);
        $url = trim((string) ($attrs['url'] ?? ''));
        if ('' !== $url) { $this->assertSafeUrl($url); }

        $classes = array('wp-block-cover');
        if (false === (bool) ($attrs['isDark'] ?? true)) { $classes[] = 'is-light'; }
        if ($hasParallax) { $classes[] = 'has-parallax'; }
        if ($isRepeated) { $classes[] = 'is-repeated'; }
        $position = strtolower(trim((string) ($attrs['contentPosition'] ?? '')));
        if (!in_array($position, array('', 'center', 'center center'), true)) {
            if (!isset(self::POSITIONS[$position])) { throw new \InvalidArgumentException('Unsupported core/cover contentPosition.'); }
            $classes[] = 'has-custom-content-position';
            $classes[] = self::POSITIONS[$position];
        }

        $wrapper = $this->supports->wrapper($attrs, $classes, true, false, true, array(
            'color' => array('text'),
            'typography' => array('fontSize','lineHeight'),
            'dimensions' => array('aspectRatio'),
            'spacing' => array('margin' => array('top','bottom'), 'padding' => true),
        ));
        $textColor = $this->supports->textColor($attrs);
        if ($textColor['classes'] !== array()) {
            $wrapper['class'] = Html::classValue(array((string) ($wrapper['class'] ?? ''), ...$textColor['classes']));
        }
        foreach ($textColor['styles'] as $property => $value) { $this->appendStyle($wrapper, $property, $value); }
        $minHeight = $this->minHeight($attrs);
        if (null !== $minHeight) { $this->appendStyle($wrapper, 'min-height', $minHeight); }

        $media = '';
        if (empty($attrs['useFeaturedImage']) && '' !== $url) {
            $media = $isImgElement ? $this->imageMedia($attrs, $url, $focal) : $this->backgroundMedia($attrs, $url, $focal);
        }
        $overlay = $this->overlay($attrs, $url);
        $beforeChildren = '<' . $tag . Html::attributes($wrapper) . '>' . $media . $overlay . '<div class="wp-block-cover__inner-container">';
        $afterChildren = '</div></' . $tag . '>';
        $segments = array($beforeChildren);
        foreach ($children as $_) { $segments[] = null; $segments[] = ''; }
        $segments[count($segments) - 1] .= $afterChildren;

        $attrs = $this->withoutDefault($attrs, 'useFeaturedImage', false);
        $attrs = $this->withoutDefault($attrs, 'hasParallax', false);
        $attrs = $this->withoutDefault($attrs, 'isRepeated', false);
        $attrs = $this->withoutDefault($attrs, 'dimRatio', 100);
        $attrs = $this->withoutDefault($attrs, 'backgroundType', 'image');
        $attrs = $this->withoutDefault($attrs, 'isDark', true);
        $attrs = $this->withoutDefault($attrs, 'tagName', 'div');
        return $this->block($attrs, $children, $beforeChildren . $afterChildren, $segments);
    }

    /** @param array<string,mixed> $attrs */
    private function imageMedia(array $attrs, string $url, ?string $position): string
    {
        $classes = array('wp-block-cover__image-background');
        $id = (int) ($attrs['id'] ?? 0); if ($id > 0) { $classes[] = 'wp-image-' . $id; }
        $size = $this->slug((string) ($attrs['sizeSlug'] ?? '')); if ('' !== $size) { $classes[] = 'size-' . $size; }
        if (!empty($attrs['hasParallax'])) { $classes[] = 'has-parallax'; }
        if (!empty($attrs['isRepeated'])) { $classes[] = 'is-repeated'; }
        $html = array('class' => Html::classValue($classes), 'alt' => (string) ($attrs['alt'] ?? ''), 'src' => $url);
        if (null !== $position) { $html['style'] = 'object-position:' . $position; }
        $html['data-object-fit'] = 'cover';
        if (null !== $position) { $html['data-object-position'] = $position; }
        return '<img' . Html::attributes($html) . '/>';
    }

    /** @param array<string,mixed> $attrs */
    private function backgroundMedia(array $attrs, string $url, ?string $position): string
    {
        if (preg_match('/[)"\'<>\x00]/', $url)) { throw new \InvalidArgumentException('Cover parallax/repeat background URL cannot be represented safely in CSS.'); }
        $classes = array('wp-block-cover__image-background');
        $id = (int) ($attrs['id'] ?? 0); if ($id > 0) { $classes[] = 'wp-image-' . $id; }
        $size = $this->slug((string) ($attrs['sizeSlug'] ?? '')); if ('' !== $size) { $classes[] = 'size-' . $size; }
        if (!empty($attrs['hasParallax'])) { $classes[] = 'has-parallax'; }
        if (!empty($attrs['isRepeated'])) { $classes[] = 'is-repeated'; }
        $alt = trim((string) ($attrs['alt'] ?? ''));
        $html = array();
        if ('' !== $alt) { $html['role'] = 'img'; $html['aria-label'] = $alt; }
        $html['class'] = Html::classValue($classes);
        $styles = array();
        if (null !== $position) { $styles[] = 'background-position:' . $position; }
        $styles[] = 'background-image:url(' . $url . ')';
        $html['style'] = implode(';', $styles);
        return '<div' . Html::attributes($html) . '></div>';
    }

    /** @param array<string,mixed> $attrs */
    private function overlay(array $attrs, string $url): string
    {
        $classes = array('wp-block-cover__background');
        $overlay = $this->slug((string) ($attrs['overlayColor'] ?? ''));
        if ('' !== $overlay) { $classes[] = 'has-' . $overlay . '-background-color'; }
        $dim = array_key_exists('dimRatio', $attrs) ? (int) $attrs['dimRatio'] : 100;
        if ($dim < 0 || $dim > 100) { throw new \InvalidArgumentException('core/cover dimRatio must be between 0 and 100.'); }
        if (50 !== $dim) { $classes[] = 'has-background-dim-' . (10 * (int) round($dim / 10)); }
        $classes[] = 'has-background-dim';
        $gradient = $this->slug((string) ($attrs['gradient'] ?? ''));
        $customGradient = trim((string) ($attrs['customGradient'] ?? ''));
        $hasGradient = '' !== $gradient || '' !== $customGradient;
        if ('' !== $url && $hasGradient && 0 !== $dim) { $classes[] = 'wp-block-cover__gradient-background'; }
        if ($hasGradient) { $classes[] = 'has-background-gradient'; }
        if ('' !== $gradient) { $classes[] = 'has-' . $gradient . '-gradient-background'; }
        $html = array('aria-hidden' => 'true', 'class' => Html::classValue($classes));
        $styles = array();
        $customColor = trim((string) ($attrs['customOverlayColor'] ?? ''));
        if ('' === $overlay && '' !== $customColor) { $styles[] = 'background-color:' . $this->safeCssValue($customColor); }
        if ('' !== $customGradient) { $styles[] = 'background:' . $this->safeGradient($customGradient); }
        if ($styles !== array()) { $html['style'] = implode(';', $styles); }
        return '<span' . Html::attributes($html) . '></span>';
    }

    /** @param array<string,mixed> $attrs */
    private function minHeight(array $attrs): ?string
    {
        $value = $attrs['minHeight'] ?? null;
        if (!is_numeric($value) || (float) $value <= 0) { return null; }
        $unit = strtolower(trim((string) ($attrs['minHeightUnit'] ?? '')));
        if ('' !== $unit && !preg_match('/^(?:px|%|vh|vw|svh|lvh|dvh|em|rem)$/', $unit)) { throw new \InvalidArgumentException('Unsupported core/cover minHeightUnit.'); }
        $number = rtrim(rtrim(sprintf('%.6F', (float) $value), '0'), '.');
        return $number . ('' === $unit ? 'px' : $unit);
    }

    /** @param mixed $focal */
    private function focalPoint(mixed $focal): ?string
    {
        if (null === $focal || $focal === array()) { return null; }
        if (!is_array($focal) || !is_numeric($focal['x'] ?? null) || !is_numeric($focal['y'] ?? null)) { throw new \InvalidArgumentException('core/cover focalPoint requires numeric x/y.'); }
        $x = (float) $focal['x']; $y = (float) $focal['y'];
        if ($x < 0 || $x > 1 || $y < 0 || $y > 1) { throw new \InvalidArgumentException('core/cover focalPoint x/y must be between 0 and 1.'); }
        return (int) round($x * 100) . '% ' . (int) round($y * 100) . '%';
    }

    /** @param array<string,string> $attrs */
    private function appendStyle(array &$attrs, string $property, string $value): void
    {
        $current = trim((string) ($attrs['style'] ?? ''));
        $attrs['style'] = ('' === $current ? '' : rtrim($current, ';') . ';') . $property . ':' . $value;
    }

    private function safeCssValue(string $value): string
    {
        $value = trim($value);
        if ('' === $value || strlen($value) > 500 || preg_match('/[;<>{}\x00]/', $value) || preg_match('/(?:expression|url)\s*\(/i', $value) || str_contains(strtolower($value), 'javascript:')) { throw new \InvalidArgumentException('Unsafe core/cover CSS value.'); }
        return $value;
    }

    private function safeGradient(string $value): string
    {
        $value = trim($value);
        if ('' === $value || strlen($value) > 2000 || preg_match('/[;<>{}\x00]/', $value) || str_contains(strtolower($value), 'javascript:') || !preg_match('/(?:gradient|^var\()/i', $value)) { throw new \InvalidArgumentException('Unsafe or unsupported core/cover custom gradient.'); }
        return $value;
    }

    private function assertSafeUrl(string $url): void
    {
        $lower = strtolower($url);
        if (str_contains($url, "\0") || str_contains($url, '<') || str_contains($url, '>') || str_starts_with($lower, 'javascript:') || str_starts_with($lower, 'data:text/html')) { throw new \InvalidArgumentException('Unsafe core/cover image URL.'); }
    }

    private function slug(string $value): string { return trim((string) preg_replace('/[^a-z0-9_-]+/i', '-', strtolower(trim($value))), '-'); }
}
