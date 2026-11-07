<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Diagnostics;

final class QuerySanitizer
{
    private const MAX_BYTES = 1600;

    public function shape(string $sql): string
    {
        $length = strlen($sql);
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($char === "'" || $char === '"') {
                $quote = $char;
                $out .= '?';
                for ($i++; $i < $length; $i++) {
                    if ($sql[$i] === '\\') {
                        $i++;
                        continue;
                    }
                    if ($sql[$i] === $quote) {
                        if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                            $i++;
                            continue;
                        }
                        break;
                    }
                }
                continue;
            }

            if ($char === '-' && $i + 1 < $length && $sql[$i + 1] === '-') {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                $out .= ' ';
                continue;
            }
            if ($char === '#') {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                $out .= ' ';
                continue;
            }
            if ($char === '/' && $i + 1 < $length && $sql[$i + 1] === '*') {
                $i += 2;
                while ($i + 1 < $length && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                    $i++;
                }
                $i++;
                $out .= ' ';
                continue;
            }

            if (ctype_digit($char)
                && ($i === 0 || !preg_match('/[A-Za-z0-9_$]/', $sql[$i - 1]))) {
                $out .= '?';
                while ($i + 1 < $length && preg_match('/[0-9A-Fa-fxX.eE+-]/', $sql[$i + 1])) {
                    $i++;
                }
                continue;
            }

            $out .= $char;
            if (strlen($out) >= self::MAX_BYTES) {
                $out .= '…';
                break;
            }
        }

        $out = preg_replace('/\s+/', ' ', trim($out)) ?? trim($out);
        return substr($out, 0, self::MAX_BYTES + 3);
    }
}