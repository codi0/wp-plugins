<?php

declare(strict_types=1);

namespace CodiMcp\Core\Execution;

final class OutputCapture
{
    /**
     * Execute a callback while suppressing direct output.
     *
     * @param array{supported:bool,bytes:int,sha256:string}|null $metadata
     * @return mixed
     */
    public static function run(callable $callback, ?array &$metadata = null)
    {
        $baseLevel = ob_get_level();
        $bytes = 0;
        $hash = hash_init('sha256');
        $started = @ob_start(static function (string $buffer) use (&$bytes, $hash): string {
            $bytes += strlen($buffer);
            hash_update($hash, $buffer);
            return '';
        });

        if (!$started) {
            $metadata = array('supported' => false, 'bytes' => 0, 'sha256' => '');
            return $callback();
        }

        try {
            return $callback();
        } finally {
            while (ob_get_level() > $baseLevel) {
                @ob_end_flush();
            }
            $metadata = array(
                'supported' => true,
                'bytes' => $bytes,
                'sha256' => $bytes > 0 ? hash_final($hash) : '',
            );
        }
    }
}