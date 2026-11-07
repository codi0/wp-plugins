<?php

declare(strict_types=1);

namespace CodiMcp\Core\Deployment;

use CodiMcp\Core\Archives\ZipArchiveGuard;

/** Extracts only a selected subtree; no archive entry is ever extracted by name unchecked. */
final class FolderArchive
{
    /** @return true|\WP_Error */
    public function extract(string $zipPath, string $wrapper, string $folder, string $destination): true|\WP_Error
    {
        $guard = (new ZipArchiveGuard())->validate($zipPath);
        if ($guard instanceof \WP_Error) {
            return $guard;
        }
        if (!class_exists('ZipArchive')) {
            return new \WP_Error('codi_mcp_zip_required', 'Folder replacement requires PHP ZipArchive support.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::RDONLY) !== true) {
            return new \WP_Error('codi_mcp_archive_invalid', 'ZIP archive could not be opened.');
        }
        try {
            $entries = array();
            $kinds = array();
            $spellings = array();
            $prefix = $wrapper . '/' . $folder;
            $found = false;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat) || !$this->validName((string) ($stat['name'] ?? ''))) {
                    return $this->invalid('ZIP contains an ambiguous or unsafe entry path.');
                }
                $name = (string) $stat['name'];
                $directory = str_ends_with($name, '/');
                $path = rtrim($name, '/');
                $ancestor = '';
                foreach (explode('/', $path) as $part) {
                    $ancestor = $ancestor === '' ? $part : $ancestor . '/' . $part;
                    $folded = function_exists('mb_strtolower') ? mb_strtolower($ancestor, 'UTF-8') : strtolower($ancestor);
                    if (isset($spellings[$folded]) && $spellings[$folded] !== $ancestor) {
                        return $this->invalid('ZIP contains inconsistent directory letter case.');
                    }
                    $spellings[$folded] = $ancestor;
                }
                $key = strtolower($path);
                if (isset($kinds[$key])) {
                    return $this->invalid('ZIP contains duplicate or case-colliding paths.');
                }
                $system = 0;
                $attributes = 0;
                if (!$zip->getExternalAttributesIndex($index, $system, $attributes)) {
                    return $this->invalid('ZIP entry type could not be inspected.');
                }
                $type = ($attributes >> 16) & 0170000;
                if (!in_array($type, array(0, 0100000, 0040000), true)
                    || ($type === 0040000 && !$directory) || ($type === 0100000 && $directory)) {
                    return $this->invalid('ZIP links and special files are not supported.');
                }
                $kinds[$key] = $directory;
                if (explode('/', $path)[0] === '__MACOSX') {
                    continue;
                }
                if ($path !== $wrapper && !str_starts_with($path, $wrapper . '/')) {
                    return $this->invalid('Folder ZIP must have exactly one top-level directory matching the installed plugin or theme.');
                }
                if ($path === $prefix) {
                    if (!$directory) {
                        return $this->invalid('The selected ZIP path must be a directory.');
                    }
                    $found = true;
                } elseif (str_starts_with($path, $prefix . '/')) {
                    $found = true;
                    $entries[] = array(
                        'index' => $index,
                        'name' => $name,
                        'relative' => substr($path, strlen($prefix) + 1),
                        'directory' => $directory,
                        'size' => (int) ($stat['size'] ?? 0),
                    );
                }
            }
            foreach ($kinds as $path => $directory) {
                $parent = dirname($path);
                while ($parent !== '.' && $parent !== '') {
                    if (isset($kinds[$parent]) && !$kinds[$parent]) {
                        return $this->invalid('ZIP contains a file/directory path collision.');
                    }
                    $parent = dirname($parent);
                }
            }
            if (!$found) {
                return $this->invalid('ZIP does not contain the selected folder. Empty replacements must include an explicit directory entry.');
            }
            if (!@mkdir($destination, 0755) || is_link($destination)) {
                return $this->invalid('Could not create the replacement staging directory.');
            }
            foreach ($entries as $entry) {
                $path = $destination . '/' . $entry['relative'];
                $parent = $entry['directory'] ? $path : dirname($path);
                if (!$this->makeDirectory($destination, $parent)) {
                    return $this->invalid('Could not create a staged directory.');
                }
                if ($entry['directory']) {
                    continue;
                }
                $input = $zip->getStream($entry['name']);
                $output = @fopen($path, 'xb');
                if (!is_resource($input) || !is_resource($output)) {
                    if (is_resource($input)) {
                        fclose($input);
                    }
                    if (is_resource($output)) {
                        fclose($output);
                    }
                    return $this->invalid('Could not extract a unique staged file.');
                }
                try {
                    $copied = stream_copy_to_stream($input, $output, $entry['size'] + 1);
                } finally {
                    fclose($input);
                    fclose($output);
                }
                if ($copied !== $entry['size']) {
                    return $this->invalid('Extracted file size does not match its ZIP entry.');
                }
                @chmod($path, 0644);
            }
            return true;
        } finally {
            $zip->close();
        }
    }

    private function makeDirectory(string $root, string $directory): bool
    {
        $relative = ltrim(substr($directory, strlen($root)), '/');
        $path = $root;
        foreach ($relative === '' ? array() : explode('/', $relative) as $part) {
            $candidate = $path . '/' . $part;
            if (file_exists($candidate)) {
                if (!is_dir($candidate) || !in_array($part, scandir($path) ?: array(), true)) {
                    return false;
                }
            } elseif (!@mkdir($candidate, 0755)) {
                return false;
            }
            $path = $candidate;
        }
        return true;
    }

    private function validName(string $name): bool
    {
        if ($name === '' || strlen($name) > 4096 || str_contains($name, '//') || preg_match('/[\x00-\x1f\x7f:\\\\<>"|?*]/', $name) || str_starts_with($name, '/')) {
            return false;
        }
        foreach (explode('/', rtrim($name, '/')) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || trim($part) !== $part
                || str_ends_with($part, '.') || preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $part)) {
                return false;
            }
        }
        return true;
    }

    private function invalid(string $message): \WP_Error
    {
        return new \WP_Error('codi_mcp_bad_folder_package', $message);
    }
}
