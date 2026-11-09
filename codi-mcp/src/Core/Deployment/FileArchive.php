<?php

declare(strict_types=1);

namespace CodiMcp\Core\Deployment;

use CodiMcp\Core\Archives\ZipArchiveGuard;

/** Extracts exactly one selected regular file from a wrapped package ZIP. */
final class FileArchive
{
    /** @return true|\WP_Error */
    public function extract(string $zipPath, string $wrapper, string $file, string $destination): true|\WP_Error
    {
        $guard = (new ZipArchiveGuard())->validate($zipPath);
        if ($guard instanceof \WP_Error) {
            return $guard;
        }
        if (!class_exists('ZipArchive')) {
            return new \WP_Error('codi_mcp_zip_required', 'File replacement requires PHP ZipArchive support.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::RDONLY) !== true) {
            return new \WP_Error('codi_mcp_archive_invalid', 'ZIP archive could not be opened.');
        }

        $selected = $wrapper . '/' . $file;
        $selectedIndex = null;
        $selectedName = '';
        $selectedSize = 0;
        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat)) {
                    return $this->invalid('ZIP contains unreadable entry metadata.');
                }

                $name = (string) ($stat['name'] ?? '');
                $path = rtrim($name, '/');
                $directory = str_ends_with($name, '/');
                if ($path === '' || $path === '__MACOSX' || str_starts_with($path, '__MACOSX/')) {
                    continue;
                }

                if ($path === $selected) {
                    if ($directory || $selectedIndex !== null) {
                        return $this->invalid('ZIP must contain the selected file exactly once.');
                    }
                    $system = 0;
                    $attributes = 0;
                    if (!$zip->getExternalAttributesIndex($index, $system, $attributes)) {
                        return $this->invalid('ZIP entry type could not be inspected.');
                    }
                    $type = ($attributes >> 16) & 0170000;
                    if (!in_array($type, array(0, 0100000), true)) {
                        return $this->invalid('The selected ZIP entry must be a regular file.');
                    }
                    $selectedIndex = $index;
                    $selectedName = $name;
                    $selectedSize = max(0, (int) ($stat['size'] ?? 0));
                    continue;
                }

                if ($directory && ($path === $wrapper || str_starts_with($selected . '/', $path . '/'))) {
                    continue;
                }

                return $this->invalid('File replacement ZIP may contain only the selected wrapped file and its directory entries.');
            }

            if ($selectedIndex === null) {
                return $this->invalid('ZIP does not contain the selected file.');
            }
            if (file_exists($destination) || is_link($destination) || !is_dir(dirname($destination))) {
                return $this->invalid('Could not create the replacement staging file.');
            }

            $input = $zip->getStream($selectedName);
            $output = @fopen($destination, 'xb');
            if (!is_resource($input) || !is_resource($output)) {
                if (is_resource($input)) {
                    fclose($input);
                }
                if (is_resource($output)) {
                    fclose($output);
                }
                return $this->invalid('Could not extract the selected staged file.');
            }
            try {
                $copied = stream_copy_to_stream($input, $output, $selectedSize + 1);
            } finally {
                fclose($input);
                fclose($output);
            }
            if ($copied !== $selectedSize) {
                @unlink($destination);
                return $this->invalid('Extracted file size does not match its ZIP entry.');
            }
            @chmod($destination, 0644);
            return true;
        } finally {
            $zip->close();
        }
    }

    private function invalid(string $message): \WP_Error
    {
        return new \WP_Error('codi_mcp_bad_file_package', $message);
    }
}
