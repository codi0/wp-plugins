<?php

declare(strict_types=1);

namespace CodiMcp\Core\Deployment;

/**
 * Copies a package into private staging and proves the staged tree still
 * represents the fingerprint captured immediately before recovery creation.
 */
final class VerifiedPackageSnapshot
{
    private const MAX_ENTRIES = 5000;
    private const MAX_BYTES = 100 * 1024 * 1024;

    private string $workspace;

    /** @var callable|null */
    private $afterCopy;

    public function __construct(?string $workspace = null, ?callable $afterCopy = null)
    {
        $temporary = function_exists('get_temp_dir') ? (string) get_temp_dir() : sys_get_temp_dir();
        $this->workspace = rtrim($workspace ?? (rtrim($temporary, "\\/") . DIRECTORY_SEPARATOR . 'codi-mcp' . DIRECTORY_SEPARATOR . 'recovery-staging'), "\\/");
        $this->afterCopy = $afterCopy;
    }

    /** @return array{path:string}|\WP_Error */
    public function create(string $sourceDirectory, string $expectedFingerprint): array|\WP_Error
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $expectedFingerprint)) {
            return new \WP_Error('codi_mcp_recovery_fingerprint', 'Recovery source fingerprint is invalid.');
        }
        if ((!is_dir($this->workspace) && !@mkdir($this->workspace, 0700, true)) || is_link($this->workspace)) {
            return new \WP_Error('codi_mcp_recovery_storage', 'Could not create private recovery staging.');
        }
        @chmod($this->workspace, 0700);

        do {
            $destination = $this->workspace . DIRECTORY_SEPARATOR . 'snapshot-' . bin2hex(random_bytes(16));
        } while (file_exists($destination));
        if (!@mkdir($destination, 0700)) {
            return new \WP_Error('codi_mcp_recovery_storage', 'Could not create recovery snapshot staging.');
        }

        $entries = 0;
        $bytes = 0;
        try {
            $this->copyTree($sourceDirectory, $destination, $entries, $bytes);
            $actual = (new PackageFingerprint())->directory($destination);
            if (!hash_equals($expectedFingerprint, $actual)) {
                throw new \RuntimeException('Package source changed while the recovery snapshot was being created.');
            }
            // Recheck live source after the durable input tree has been established.
            $live = (new PackageFingerprint())->directory($sourceDirectory);
            if (!hash_equals($expectedFingerprint, $live)) {
                throw new \RuntimeException('Package source changed while the recovery snapshot was being created.');
            }
            return array('path' => $destination);
        } catch (\Throwable $error) {
            $this->removeTree($destination);
            $code = str_contains($error->getMessage(), 'changed while')
                ? 'codi_mcp_source_changed'
                : 'codi_mcp_recovery_source';
            return new \WP_Error($code, $error->getMessage());
        }
    }

    public function remove(string $path): void
    {
        if ($path !== '' && str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', $this->workspace) . '/')) {
            $this->removeTree($path);
        }
    }

    private function copyTree(string $source, string $destination, int &$entries, int &$bytes): void
    {
        if (is_link($source) || !is_dir($source)) {
            throw new \RuntimeException('Package source must be a directory without symbolic links.');
        }
        $names = scandir($source);
        if ($names === false) {
            throw new \RuntimeException('Could not read package source during recovery snapshot creation.');
        }
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (++$entries > self::MAX_ENTRIES) {
                throw new \RuntimeException('Package exceeds the 5000-entry recovery snapshot limit.');
            }
            $from = $source . DIRECTORY_SEPARATOR . $name;
            $to = $destination . DIRECTORY_SEPARATOR . $name;
            if (is_link($from)) {
                throw new \RuntimeException('Package recovery refuses symbolic links.');
            }
            if (is_dir($from)) {
                if (!@mkdir($to, 0700)) {
                    throw new \RuntimeException('Could not create a recovery snapshot directory.');
                }
                $this->copyTree($from, $to, $entries, $bytes);
                continue;
            }
            if (!is_file($from)) {
                throw new \RuntimeException('Package contains an unsupported filesystem entry.');
            }
            $size = filesize($from);
            if ($size === false || ($bytes += $size) > self::MAX_BYTES) {
                throw new \RuntimeException('Package exceeds the 100 MiB recovery snapshot limit.');
            }
            $input = @fopen($from, 'rb');
            $output = @fopen($to, 'xb');
            if (!is_resource($input) || !is_resource($output)) {
                if (is_resource($input)) {
                    fclose($input);
                }
                if (is_resource($output)) {
                    fclose($output);
                }
                throw new \RuntimeException('Could not copy package source into recovery staging.');
            }
            try {
                $copied = stream_copy_to_stream($input, $output, $size + 1);
            } finally {
                fclose($input);
                fclose($output);
            }
            if ($copied !== $size) {
                throw new \RuntimeException('Package source changed while the recovery snapshot was being created.');
            }
            @chmod($to, 0600);
            if (is_callable($this->afterCopy)) {
                ($this->afterCopy)($from, $to, $entries);
            }
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
            return;
        }
        foreach (scandir($path) ?: array() as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->removeTree($path . DIRECTORY_SEPARATOR . $name);
            }
        }
        @rmdir($path);
    }
}