<?php

declare(strict_types=1);

namespace CodiMcp\Core\Deployment;

final class ReplacementSelection
{
    /** @return array{replace:string,folder:string}|\WP_Error */
    public static function fromInput(array $input): array|\WP_Error
    {
        if (array_key_exists('folders', $input)) {
            return new \WP_Error('codi_mcp_bad_replacement', 'Only one folder may be selected, using the folder field.');
        }
        $mode = array_key_exists('replace', $input) ? $input['replace'] : 'entire';
        if ($mode === 'entire' && !array_key_exists('folder', $input)) {
            return array('replace' => 'entire', 'folder' => '');
        }
        if ($mode !== 'folder' || !self::validFolder($input['folder'] ?? null)) {
            return new \WP_Error('codi_mcp_bad_replacement', 'Choose replace=entire without folder, or replace=folder with one relative folder path.');
        }
        return array('replace' => 'folder', 'folder' => $input['folder']);
    }

    public static function validFolder(mixed $folder): bool
    {
        if (!is_string($folder) || strlen($folder) > 240
            || !preg_match('~^[A-Za-z0-9_-][A-Za-z0-9_.-]*(?:/[A-Za-z0-9_-][A-Za-z0-9_.-]*)*$~D', $folder)) {
            return false;
        }
        foreach (explode('/', $folder) as $part) {
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
                'enum' => array('entire', 'folder'),
                'default' => 'entire',
                'description' => 'entire replaces the package; folder adds/replaces one folder in an installed package.',
            ),
            'folder' => array(
                'type' => 'string',
                'minLength' => 1,
                'maxLength' => 240,
                'description' => 'With replace=folder: relative path, e.g. src/Contacts. ZIP must wrap this path under the package slug. Parent must exist. Omitted files inside this folder are deleted; other files are preserved.',
            ),
        );
    }
}
