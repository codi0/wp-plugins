<?php

declare(strict_types=1);

namespace CodiMcp\Core\Security;

final class SensitiveDataRedactor
{
    private const REDACTED = '[redacted]';
    private const MAX_DEPTH = 4;
    private const MAX_ITEMS = 50;
    private const MAX_STRING_BYTES = 500;

    /** @return mixed */
    public function sanitize($value, int $depth = 0, bool $positional = false)
    {
        if ($depth >= self::MAX_DEPTH) {
            return '[depth-limit]';
        }
        if (is_null($value) || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value)) {
            if ($positional) {
                return self::REDACTED;
            }
            return strlen($value) > self::MAX_STRING_BYTES
                ? substr($value, 0, self::MAX_STRING_BYTES) . '…'
                : $value;
        }
        if (is_object($value)) {
            return '[object:' . get_class($value) . ']';
        }
        if (!is_array($value)) {
            return '[' . gettype($value) . ']';
        }

        $isList = array_is_list($value);
        $result = array();
        $count = 0;
        foreach ($value as $key => $item) {
            if (++$count > self::MAX_ITEMS) {
                $result['_truncated'] = true;
                break;
            }
            $keyString = (string) $key;
            if (!$isList && $this->isSensitiveKey($keyString)) {
                $result[$key] = self::REDACTED;
                continue;
            }
            $result[$key] = $this->sanitize($item, $depth + 1, $isList && is_string($item));
        }
        return $result;
    }

    public function redactLine(string $line): string
    {
        $trimmed = trim($line);
        if ($trimmed !== '') {
            $decoded = json_decode($trimmed, true, self::MAX_DEPTH + 4);
            if (JSON_ERROR_NONE === json_last_error() && is_array($decoded)) {
                $encoded = function_exists('wp_json_encode')
                    ? wp_json_encode($this->sanitize($decoded), JSON_UNESCAPED_SLASHES)
                    : json_encode($this->sanitize($decoded), JSON_UNESCAPED_SLASHES);
                if (is_string($encoded)) {
                    return $encoded;
                }
            }
        }

        $line = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9+\/_=.-]+/i', '$1 ' . self::REDACTED, $line) ?? $line;
        $key = '(?:pass(?:word)?|passwd|pwd|secret|token|api[_-]?key|authorization|auth|cookie|session|nonce|client[_-]?secret|access[_-]?token|refresh[_-]?token)';
        $line = preg_replace(
            '/((?:"|\')?' . $key . '(?:"|\')?\s*[:=]\s*)(?:"[^"]*"|\'[^\']*\'|[^\s,;&}]+)/i',
            '$1' . self::REDACTED,
            $line
        ) ?? $line;
        $line = preg_replace('/([?&]' . $key . '=)[^&\s]+/i', '$1' . self::REDACTED, $line) ?? $line;
        return $line;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(trim($key));
        return (bool) preg_match(
            '/(?:^|[_-])(?:pass(?:word)?|passwd|pwd|secret|token|api[_-]?key|authorization|auth|cookie|session|nonce|client[_-]?secret|access[_-]?token|refresh[_-]?token)(?:$|[_-])/i',
            $normalized
        );
    }
}
