<?php

declare(strict_types=1);

namespace CodiMcp\Core\Deployment;

final class ReplacementSelection
{
    /** @return array{replace:string,folder:string,file:string}|\WP_Error */
    public static function fromInput(array $input): array|\WP_Error
    {
        if (array_key_exists('folders', $input) || array_key_exists('files', $input)) {
            return new \WP_Error('codi_mcp_bad_replacement', 'Select only one replacement target using folder or file.');
        }
        $mode = array_key_exists('replace', $input) ? $input['replace'] : 'entire';
        if ($mode === 'entire' && !array_key_exists('folder', $input) && !array_key_exists('file', $input)) {
            return array('replace' => 'entire', 'folder' => '', 'file' => '');
        }
        if ($mode === 'folder' && self::validFolder($input['folder'] ?? null) && !array_key_exists('file', $input)) {
            return array('replace' => 'folder', 'folder' => $input['folder'], 'file' => '');
        }
        if ($mode === 'file' && self::validFile($input['file'] ?? null) && !array_key_exists('folder', $input)) {
            return array('replace' => 'file', 'folder' => '', 'file' => $input['file']);
        }
        return new \WP_Error('codi_mcp_bad_replacement', 'Choose replace=entire, replace=folder with one relative folder, or replace=file with one relative existing file.');
    }

    public static function validFolder(mixed $folder): bool
    {
        return self::validPath($folder);
    }

    public static function validFile(mixed $file): bool
    {
        return self::validPath($file);
    }

    private static function validPath(mixed $path): bool
    {
        if (!is_string($path) || strlen($path) > 240
            || !preg_match('~^[A-Za-z0-9_-][A-Za-z0-9_.-]*(?:/[A-Za-z0-9_-][A-Za-z0-9_.-]*)*$~D', $path)) {
            return false;
        }
        foreach (explode('/', $path) as $part) {
            if (str_ends_with($part, '.') || preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $part)) {
                return false;
            }
        }
        return true;
    }

    public static function schemaProperties(): array
    {
        return array(
            'replace' => array(
                'type' => 'string',
                'enum' => array('entire', 'folder', 'file'),
                'default' => 'entire',
                'description' => 'entire=package; folder=one folder; file=one existing file.',
            ),
            'folder' => array(
                'type' => 'string',
                'minLength' => 1,
                'maxLength' => 240,
                'description' => 'replace=folder: relative folder. ZIP must wrap it under the package slug; omitted files inside it are deleted.',
            ),
            'file' => array(
                'type' => 'string',
                'minLength' => 1,
                'maxLength' => 240,
                'description' => 'replace=file: exact existing relative file. ZIP must contain only that wrapped file.',
            ),
        );
    }
}
