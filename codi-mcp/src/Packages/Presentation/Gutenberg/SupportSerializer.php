<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation\Gutenberg;

/**
 * PHP counterpart for the explicitly supported subset of useBlockProps.save().
 *
 * The runtime is intentionally fail-closed. A codec must opt into every style
 * support branch it can reproduce; unknown/non-empty branches are rejected.
 */
final class SupportSerializer
{
    /**
     * @param array<string,mixed> $attrs
     * @param string[] $baseClasses
     * @param array<string,mixed> $styleSupport
     * @return array<string,string>
     */
    public function wrapper(
        array $attrs,
        array $baseClasses = array(),
        bool $allowCustomClass = true,
        bool $includeColors = true,
        bool $includeFontSize = true,
        array $styleSupport = array()
    ): array {
        $classes = $baseClasses;
        $align = $this->slug((string) ($attrs['align'] ?? ''));
        if ('' !== $align) {
            $classes[] = 'align' . $align;
        }
        if ($allowCustomClass && '' !== trim((string) ($attrs['className'] ?? ''))) {
            $classes[] = trim((string) $attrs['className']);
        }
        if ($includeColors) {
            $classes = array_merge($classes, $this->colorClasses($attrs));
        }

        // Gutenberg's block-support pipeline emits color support classes before
        // typography/font-size classes. Compute style support first so the PHP
        // serializer preserves the editor's canonical save() ordering.
        $support = $this->styleSupportAttributes($attrs, $styleSupport, $includeColors, $includeFontSize);
        $classes = array_merge($classes, $support['classes']);
        if ($includeFontSize) {
            $fontSize = $this->slug((string) ($attrs['fontSize'] ?? ''));
            if ('' !== $fontSize) {
                $classes[] = 'has-' . $fontSize . '-font-size';
            }
        }

        $attributes = array();
        $classValue = Html::classValue($classes);
        if ('' !== $classValue) {
            $attributes['class'] = $classValue;
        }
        $anchor = trim((string) ($attrs['anchor'] ?? ''));
        if ('' !== $anchor) {
            $attributes['id'] = $anchor;
        }
        $ariaLabel = trim((string) ($attrs['ariaLabel'] ?? ''));
        if ('' !== $ariaLabel) {
            $attributes['aria-label'] = $ariaLabel;
        }
        if ($support['styles'] !== array()) {
            $attributes['style'] = $this->cssDeclarations($support['styles']);
        }
        return $attributes;
    }

    /** @param array<string,mixed> $attrs @return string[] */
    public function colorClasses(array $attrs): array
    {
        $classes = array();
        $background = $this->slug((string) ($attrs['backgroundColor'] ?? ''));
        if ('' !== $background) {
            $classes[] = 'has-' . $background . '-background-color';
            $classes[] = 'has-background';
        }
        $text = $this->slug((string) ($attrs['textColor'] ?? ''));
        if ('' !== $text) {
            $classes[] = 'has-' . $text . '-color';
            $classes[] = 'has-text-color';
        }
        $gradient = $this->slug((string) ($attrs['gradient'] ?? ''));
        if ('' !== $gradient) {
            $classes[] = 'has-' . $gradient . '-gradient-background';
            $classes[] = 'has-background';
        }
        return $classes;
    }

    /**
     * @param array<string,mixed> $attrs
     * @param array<string,mixed> $allowed
     * @return array{classes:array<int,string>,styles:array<string,string>}
     */
    private function styleSupportAttributes(array $attrs, array $allowed, bool $includeColors, bool $includeFontSize): array
    {
        $style = is_array($attrs['style'] ?? null) ? $attrs['style'] : array();
        if ($style === array()) {
            return array('classes' => array(), 'styles' => array());
        }

        foreach ($style as $branch => $value) {
            if ($this->isEmptyValue($value)) {
                continue;
            }
            if (!array_key_exists((string) $branch, $allowed)) {
                throw new \InvalidArgumentException(sprintf('PHP Gutenberg save runtime does not yet reproduce style.%s block support.', (string) $branch));
            }
        }

        $classes = array();
        $styles = array();

        if (isset($style['color']) && !$this->isEmptyValue($style['color'])) {
            if (!is_array($style['color'])) {
                throw new \InvalidArgumentException('style.color must be an object for the PHP Gutenberg save runtime.');
            }
            $colorAllowed = $this->allowedKeys($allowed['color'] ?? array());
            foreach ($style['color'] as $key => $value) {
                if ($this->isEmptyValue($value)) {
                    continue;
                }
                if (!in_array((string) $key, $colorAllowed, true)) {
                    throw new \InvalidArgumentException(sprintf('PHP Gutenberg save runtime does not yet reproduce style.color.%s.', (string) $key));
                }
            }
            if ($includeColors) {
                $this->applyColorStyle($attrs, $style['color'], $classes, $styles);
            }
        }

        if (isset($style['typography']) && !$this->isEmptyValue($style['typography'])) {
            if (!is_array($style['typography'])) {
                throw new \InvalidArgumentException('style.typography must be an object for the PHP Gutenberg save runtime.');
            }
            $typographyAllowed = $this->allowedKeys($allowed['typography'] ?? array());
            foreach ($style['typography'] as $key => $value) {
                if ($this->isEmptyValue($value)) {
                    continue;
                }
                if (!in_array((string) $key, $typographyAllowed, true)) {
                    throw new \InvalidArgumentException(sprintf('PHP Gutenberg save runtime does not yet reproduce style.typography.%s.', (string) $key));
                }
            }
            if ($includeFontSize && !array_key_exists('fontSize', $attrs) && isset($style['typography']['fontSize'])) {
                $preset = $this->preset((string) $style['typography']['fontSize']);
                if (is_array($preset) && 'font-size' === $preset['type']) {
                    $classes[] = 'has-' . $preset['slug'] . '-font-size';
                } else {
                    $styles['font-size'] = $this->cssValue($style['typography']['fontSize']);
                }
            }
            if (isset($style['typography']['lineHeight'])) {
                $styles['line-height'] = $this->cssValue($style['typography']['lineHeight']);
            }
        }

        if (isset($style['dimensions']) && !$this->isEmptyValue($style['dimensions'])) {
            if (!is_array($style['dimensions'])) {
                throw new \InvalidArgumentException('style.dimensions must be an object for the PHP Gutenberg save runtime.');
            }
            $dimensionsAllowed = $this->allowedKeys($allowed['dimensions'] ?? array());
            foreach ($style['dimensions'] as $key => $value) {
                if ($this->isEmptyValue($value)) {
                    continue;
                }
                if (!in_array((string) $key, $dimensionsAllowed, true)) {
                    throw new \InvalidArgumentException(sprintf('PHP Gutenberg save runtime does not yet reproduce style.dimensions.%s.', (string) $key));
                }
                $property = match ((string) $key) {
                    'minHeight' => 'min-height',
                    'minWidth' => 'min-width',
                    'aspectRatio' => 'aspect-ratio',
                    default => '',
                };
                if ('' !== $property) {
                    $styles[$property] = $this->cssValue($value);
                }
            }
        }

        if (isset($style['spacing']) && !$this->isEmptyValue($style['spacing'])) {
            if (!is_array($style['spacing'])) {
                throw new \InvalidArgumentException('style.spacing must be an object for the PHP Gutenberg save runtime.');
            }
            $spacingAllowed = is_array($allowed['spacing'] ?? null) ? $allowed['spacing'] : array();
            foreach ($style['spacing'] as $property => $value) {
                if ($this->isEmptyValue($value)) {
                    continue;
                }
                if (!array_key_exists((string) $property, $spacingAllowed)) {
                    throw new \InvalidArgumentException(sprintf('PHP Gutenberg save runtime does not yet reproduce style.spacing.%s.', (string) $property));
                }
                if (!in_array((string) $property, array('margin', 'padding'), true)) {
                    throw new \InvalidArgumentException(sprintf('PHP Gutenberg save runtime does not yet serialize spacing property [%s].', (string) $property));
                }
                $this->applySpacing((string) $property, $value, $spacingAllowed[$property], $styles);
            }
        }

        return array('classes' => $classes, 'styles' => $styles);
    }

    /**
     * @param array<string,mixed> $color
     * @param string[] $classes
     * @param array<string,string> $styles
     */
    private function applyColorStyle(array $attrs, array $color, array &$classes, array &$styles): void
    {
        if (!array_key_exists('textColor', $attrs) && isset($color['text'])) {
            $preset = $this->preset((string) $color['text']);
            if (is_array($preset) && 'color' === $preset['type']) {
                $classes[] = 'has-' . $preset['slug'] . '-color';
            } else {
                $styles['color'] = $this->cssValue($color['text']);
            }
            $classes[] = 'has-text-color';
        }
        if (!array_key_exists('backgroundColor', $attrs) && isset($color['background'])) {
            $preset = $this->preset((string) $color['background']);
            if (is_array($preset) && 'color' === $preset['type']) {
                $classes[] = 'has-' . $preset['slug'] . '-background-color';
            } else {
                $styles['background-color'] = $this->cssValue($color['background']);
            }
            $classes[] = 'has-background';
        }
        if (!array_key_exists('gradient', $attrs) && isset($color['gradient'])) {
            $preset = $this->preset((string) $color['gradient']);
            if (is_array($preset) && 'gradient' === $preset['type']) {
                $classes[] = 'has-' . $preset['slug'] . '-gradient-background';
            } else {
                $styles['background'] = $this->cssValue($color['gradient']);
            }
            $classes[] = 'has-background';
        }
    }

    /** @param mixed $value @param mixed $allowedSides @param array<string,string> $styles */
    private function applySpacing(string $property, mixed $value, mixed $allowedSides, array &$styles): void
    {
        if (!is_array($value)) {
            if (true !== $allowedSides) {
                throw new \InvalidArgumentException(sprintf('PHP Gutenberg save runtime requires side-specific %s values for this block.', $property));
            }
            $styles[$property] = $this->cssValue($value);
            return;
        }

        $allowed = true === $allowedSides
            ? array('top', 'right', 'bottom', 'left')
            : array_values(array_filter((array) $allowedSides, 'is_string'));
        foreach ($value as $side => $sideValue) {
            if ($this->isEmptyValue($sideValue)) {
                continue;
            }
            if (!in_array((string) $side, $allowed, true)) {
                throw new \InvalidArgumentException(sprintf('PHP Gutenberg save runtime does not allow %s-%s for this block.', $property, (string) $side));
            }
        }
        foreach (array('top', 'right', 'bottom', 'left') as $side) {
            if (array_key_exists($side, $value) && !$this->isEmptyValue($value[$side])) {
                $styles[$property . '-' . $side] = $this->cssValue($value[$side]);
            }
        }
    }

    /** @param array<string,mixed> $attrs @return array{classes:array<int,string>,styles:array<string,string>} */
    public function textColor(array $attrs): array
    {
        $classes = array();
        $styles = array();
        $text = $this->slug((string) ($attrs['textColor'] ?? ''));
        if ('' !== $text) {
            return array('classes' => array('has-' . $text . '-color', 'has-text-color'), 'styles' => array());
        }
        $style = is_array($attrs['style'] ?? null) ? $attrs['style'] : array();
        $color = is_array($style['color'] ?? null) ? $style['color'] : array();
        if (!array_key_exists('text', $color) || $this->isEmptyValue($color['text'])) {
            return array('classes' => array(), 'styles' => array());
        }
        $preset = $this->preset((string) $color['text']);
        if (is_array($preset) && 'color' === $preset['type']) {
            $classes[] = 'has-' . $preset['slug'] . '-color';
        } else {
            $styles['color'] = $this->cssValue($color['text']);
        }
        $classes[] = 'has-text-color';
        return array('classes' => $classes, 'styles' => $styles);
    }

    /** @param mixed $value */
    private function cssValue(mixed $value): string
    {
        if (!is_scalar($value) || is_bool($value)) {
            throw new \InvalidArgumentException('Block-support CSS values must be scalar strings or numbers.');
        }
        $value = trim((string) $value);
        if ('' === $value || strlen($value) > 1000) {
            throw new \InvalidArgumentException('Block-support CSS value is empty or too long.');
        }
        $preset = $this->preset($value);
        if (is_array($preset)) {
            return 'var(--wp--preset--' . $preset['type'] . '--' . $preset['slug'] . ')';
        }
        if (preg_match('/[;<>{}\x00]/', $value) || preg_match('/(?:expression|url)\s*\(/i', $value) || str_contains(strtolower($value), 'javascript:')) {
            throw new \InvalidArgumentException('Unsafe or unsupported CSS value in Gutenberg save codec.');
        }
        return $value;
    }

    /** @return array{type:string,slug:string}|null */
    private function preset(string $value): ?array
    {
        if (!preg_match('/^var:preset\|([a-z0-9-]+)\|([a-z0-9_-]+)$/i', trim($value), $match)) {
            return null;
        }
        return array('type' => strtolower((string) $match[1]), 'slug' => $this->slug((string) $match[2]));
    }

    /** @param mixed $value */
    private function isEmptyValue(mixed $value): bool
    {
        return null === $value || '' === $value || (is_array($value) && $value === array());
    }

    /** @param mixed $value @return string[] */
    private function allowedKeys(mixed $value): array
    {
        if (true === $value) {
            return array();
        }
        return array_values(array_filter((array) $value, 'is_string'));
    }

    /** @param array<string,string> $styles */
    private function cssDeclarations(array $styles): string
    {
        // Match the current core block-support save ordering. CSS declaration
        // order is semantically equivalent in the browser, but keeping parity
        // with Gutenberg makes conformance drift visible and deterministic.
        $order = array(
            'color', 'background-color', 'background',
            'min-height', 'min-width', 'aspect-ratio',
            'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
            'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
            'font-size', 'line-height',
        );
        $parts = array();
        foreach ($order as $property) {
            if (!array_key_exists($property, $styles)) {
                continue;
            }
            $parts[] = $property . ':' . $styles[$property];
            unset($styles[$property]);
        }
        foreach ($styles as $property => $value) {
            $parts[] = $property . ':' . $value;
        }
        return implode(';', $parts);
    }

    private function slug(string $value): string
    {
        $value = strtolower(trim($value));
        if ('' === $value) {
            return '';
        }
        if (function_exists('sanitize_html_class')) {
            return (string) sanitize_html_class($value);
        }
        return trim((string) preg_replace('/[^a-z0-9_-]+/', '-', $value), '-');
    }
}
