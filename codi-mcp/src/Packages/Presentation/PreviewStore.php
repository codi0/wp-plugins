<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation;

final class PreviewStore
{
    private const PREFIX = 'codi_mcp_presentation_preview_';
    private const TTL_SECONDS = 1800;

    /** @param array<string,mixed> $preview @return array<string,mixed> */
    public function create(array $preview): array
    {
        if (!function_exists('set_transient')) {
            throw new \RuntimeException('WordPress transient storage is unavailable for Presentation previews.');
        }
        $id = bin2hex(random_bytes(16));
        $created = time();
        $preview['preview_id'] = $id;
        $preview['created_at'] = gmdate('c', $created);
        $preview['expires_at'] = gmdate('c', $created + self::TTL_SECONDS);
        if (!set_transient(self::PREFIX . $id, $preview, self::TTL_SECONDS)) {
            throw new \RuntimeException('Could not persist the Presentation preview.');
        }
        return $preview;
    }

    /** @return array<string,mixed> */
    public function get(string $previewId): array
    {
        $previewId = strtolower(trim($previewId));
        if (!preg_match('/^[a-f0-9]{32}$/', $previewId) || !function_exists('get_transient')) {
            throw new \InvalidArgumentException('Invalid Presentation preview ID.');
        }
        $preview = get_transient(self::PREFIX . $previewId);
        if (!is_array($preview)) {
            throw new \InvalidArgumentException('Presentation preview was not found or has expired.');
        }
        return $preview;
    }

    public function delete(string $previewId): void
    {
        if (function_exists('delete_transient') && preg_match('/^[a-f0-9]{32}$/', strtolower(trim($previewId)))) {
            delete_transient(self::PREFIX . strtolower(trim($previewId)));
        }
    }
}
