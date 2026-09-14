<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation\Gutenberg\Codecs;

use CodiMcp\Packages\Presentation\Gutenberg\Html;

final class ButtonCodec extends AbstractCodec
{
    public function name(): string { return 'core/button'; }
    public function capabilities(): array { return array('create','update_text','update_link','update_text_color','update_background_color','update_font_size'); }

    public function create(array $state, array $innerBlocks = array()): array
    {
        if ($innerBlocks !== array()) { throw new \InvalidArgumentException('Button cannot contain InnerBlocks.'); }
        $attrs = is_array($state['attributes'] ?? null) ? $state['attributes'] : array();
        foreach (array('tagName' => 'tag_name', 'type' => 'type') as $attr => $field) {
            if (array_key_exists($field, $state) && '' !== trim((string) $state[$field])) { $attrs[$attr] = (string) $state[$field]; }
        }
        $semantic = array(
            'text_html' => Html::plainText((string) ($state['text'] ?? '')),
            'url' => (string) ($state['url'] ?? ''),
            'title' => (string) ($state['title'] ?? ''),
            'link_target' => (string) ($state['link_target'] ?? ''),
            'rel' => (string) ($state['rel'] ?? ''),
        );
        return $this->save($attrs, $semantic);
    }

    public function rewrite(array $block, array $changes = array(), ?array $innerBlocks = null): array
    {
        if (null !== $innerBlocks && $innerBlocks !== array()) { throw new \InvalidArgumentException('Button cannot contain InnerBlocks.'); }
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        $semantic = $this->decode((string) ($block['innerHTML'] ?? ''));
        if (array_key_exists('text', $changes)) { $semantic['text_html'] = Html::plainText((string) $changes['text']); }
        foreach (array('url','title','rel') as $key) { if (array_key_exists($key, $changes)) { $semantic[$key] = (string) $changes[$key]; } }
        if (array_key_exists('link_target', $changes)) { $semantic['link_target'] = (string) $changes['link_target']; }
        if (array_key_exists('tag_name', $changes)) { $attrs['tagName'] = (string) $changes['tag_name']; }
        if (array_key_exists('type', $changes)) { $attrs['type'] = (string) $changes['type']; }
        return $this->save($attrs, $semantic);
    }

    private function save(array $attrs, array $semantic): array
    {
        $this->assertAllowedAttributes($attrs, array('tagName','type','backgroundColor','textColor','gradient','fontSize','className','anchor','metadata','lock'));
        $tag = strtolower(trim((string) ($attrs['tagName'] ?? 'a')));
        if (!in_array($tag, array('a','button'), true)) { throw new \InvalidArgumentException('core/button tagName must be a or button.'); }
        if ('' === trim(strip_tags((string) ($semantic['text_html'] ?? '')))) { throw new \InvalidArgumentException('Presentation button codec requires non-empty button text.'); }

        $wrapper = $this->supports->wrapper($attrs, array('wp-block-button'), true, false, false);
        $innerClasses = array_merge(array('wp-block-button__link'), $this->supports->colorClasses($attrs));
        $font = trim((string) ($attrs['fontSize'] ?? ''));
        if ('' !== $font) { $innerClasses[] = 'has-' . strtolower((string) preg_replace('/[^a-z0-9_-]+/i', '-', $font)) . '-font-size'; }
        $innerClasses[] = 'wp-element-button';
        $inner = array('class' => Html::classValue($innerClasses));
        if ('' !== trim((string) ($semantic['title'] ?? ''))) { $inner['title'] = (string) $semantic['title']; }
        if ('a' === $tag) {
            if ('' !== trim((string) ($semantic['url'] ?? ''))) { $inner['href'] = (string) $semantic['url']; }
            if ('' !== trim((string) ($semantic['link_target'] ?? ''))) { $inner['target'] = (string) $semantic['link_target']; }
            if ('' !== trim((string) ($semantic['rel'] ?? ''))) { $inner['rel'] = (string) $semantic['rel']; }
        } else {
            $inner['type'] = trim((string) ($attrs['type'] ?? 'button')) ?: 'button';
        }
        $html = '<div' . Html::attributes($wrapper) . '><' . $tag . Html::attributes($inner) . '>' . (string) $semantic['text_html'] . '</' . $tag . '></div>';
        return $this->block($attrs, array(), $html, array($html));
    }

    /** @return array<string,string> */
    private function decode(string $html): array
    {
        if (!preg_match('/^\s*<div\b[^>]*>\s*<(a|button)\b([^>]*)>(.*)<\/\1>\s*<\/div>\s*$/is', $html, $match)) {
            throw new \InvalidArgumentException('Existing core/button markup does not match the supported PHP save codec structure.');
        }
        $attributes = Html::parseAttributes((string) $match[2]);
        return array(
            'text_html' => (string) $match[3],
            'url' => (string) ($attributes['href'] ?? ''),
            'title' => (string) ($attributes['title'] ?? ''),
            'link_target' => (string) ($attributes['target'] ?? ''),
            'rel' => (string) ($attributes['rel'] ?? ''),
        );
    }
}
