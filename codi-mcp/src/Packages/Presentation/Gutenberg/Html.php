<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation\Gutenberg;

final class Html
{
    /** @param array<string,string> $attributes */
    public static function attributes(array $attributes): string
    {
        $parts = array();
        foreach ($attributes as $name => $value) {
            $name = strtolower(trim((string) $name));
            if ('' === $name || !preg_match('/^[a-z_:][a-z0-9_:\-.]*$/i', $name)) {
                throw new \InvalidArgumentException('Unsafe HTML attribute name in Gutenberg save codec.');
            }
            $parts[] = $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '"';
        }
        return $parts === array() ? '' : ' ' . implode(' ', $parts);
    }

    /** @return array<string,string> */
    public static function parseAttributes(string $source): array
    {
        $attributes = array();
        if ('' === trim($source)) {
            return $attributes;
        }
        preg_match_all('/([a-zA-Z_:][a-zA-Z0-9_:\-.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/', $source, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $name = strtolower((string) ($match[1] ?? ''));
            $raw = '';
            if (isset($match[2]) && '' !== (string) $match[2]) {
                $raw = (string) $match[2];
            } elseif (isset($match[3]) && '' !== (string) $match[3]) {
                $raw = (string) $match[3];
            } elseif (isset($match[4])) {
                $raw = (string) $match[4];
            }
            $attributes[$name] = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $attributes;
    }

    /** @return array{tag:string,attributes:array<string,string>,content:string} */
    public static function singleElement(string $html, string $tagPattern): array
    {
        $tagPattern = trim($tagPattern);
        $pattern = '/^\s*<(' . $tagPattern . ')\b([^>]*)>(.*)<\/\1>\s*$/is';
        if (!preg_match($pattern, $html, $match)) {
            throw new \InvalidArgumentException('Existing Gutenberg saved markup does not match the supported codec structure.');
        }
        return array(
            'tag' => strtolower((string) $match[1]),
            'attributes' => self::parseAttributes((string) $match[2]),
            'content' => (string) $match[3],
        );
    }

    public static function plainText(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param string[] $classes */
    public static function classValue(array $classes): string
    {
        $result = array();
        foreach ($classes as $class) {
            foreach (preg_split('/\s+/', trim((string) $class)) ?: array() as $item) {
                if ('' !== $item && !in_array($item, $result, true)) {
                    $result[] = $item;
                }
            }
        }
        return implode(' ', $result);
    }
}
