<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Media;

use CodiMcp\Core\Abilities\AbilityMetadata;
use CodiMcp\Core\AbilityPackage;

final class Package implements AbilityPackage
{
    private ?MediaStagingService $staging = null;
    private ?MediaManager $manager = null;

    public function key(): string { return 'media'; }
    public function label(): string { return 'Media'; }

    public function abilityNames(): array
    {
        return array_map(fn (string $slug): string => $this->abilityName($slug), array(
            'media-config', 'media-find', 'media-inspect', 'media-upload', 'media-create', 'media-update', 'media-delete',
        ));
    }

    public function registerCategories(): void
    {
        if (!function_exists('wp_register_ability_category')) { return; }
        wp_register_ability_category($this->category(), array(
            'label' => 'Codi MCP — Media',
            'description' => 'WordPress media-library inspection, bounded upload staging, attachment creation/replacement, metadata, relationships, and deletion.',
        ));
    }

    public function registerAbilities(): void
    {
        if (!function_exists('wp_register_ability')) { return; }
        $this->registerReadOnly('media-config', 'Inspect media configuration', 'Return WordPress upload limits, registered image sizes, allowed MIME types, available image editors, and modern image-format support.', $this->emptySchema(), $this->configSchema(), fn () => $this->manager()->config(), fn () => $this->manager()->canInspect());
        $this->registerReadOnly('media-find', 'Find media-library attachments', 'Find bounded WordPress attachment records by search text, MIME type, or parent post. This is the authoritative media-library discovery ability.', $this->findInputSchema(), $this->findOutputSchema(), fn ($input = array()) => $this->manager()->find(is_array($input) ? $input : array()), fn () => $this->manager()->canInspect());
        $this->registerReadOnly('media-inspect', 'Inspect media attachment', 'Inspect one exact WordPress attachment and its native media metadata.', $this->strictObject(array('attachment_id' => $this->idSchema())), $this->attachmentSchema(true), fn ($input = array()) => $this->manager()->inspect((int) ($input['attachment_id'] ?? 0)), fn ($input = array()) => $this->manager()->canRead(is_array($input) ? $input : array()));
        $this->registerMutation('media-upload', 'Create media upload', 'Create a temporary HTTPS PUT destination for one media file. This tool never accepts media bytes. Use a file-source tool on the machine holding the file to stream the exact file directly to the returned upload.url, then pass upload_id as source_upload_id to media-create or media-update.', $this->uploadInputSchema(), $this->uploadOutputSchema(), fn ($input = array()) => $this->staging()->upload(is_array($input) ? $input : array()), fn () => $this->manager()->canUpload(), false, false);
        $this->registerMutation('media-create', 'Create media attachment', 'Create one WordPress media-library attachment from either a completed staged upload or HTTP(S) source URL, with bounded native attachment fields. Attaching to a parent requires edit permission on that parent.', $this->createInputSchema(), $this->attachmentSchema(true), fn ($input = array()) => $this->manager()->create(is_array($input) ? $input : array()), fn ($input = array()) => $this->manager()->canCreate(is_array($input) ? $input : array()), false, false);
        $this->registerMutation('media-update', 'Update media attachment', 'Update native attachment fields and optionally replace the binary from one completed staged upload or HTTP(S) URL. No server filesystem path is accepted.', $this->updateInputSchema(), $this->attachmentSchema(true), fn ($input = array()) => $this->manager()->update(is_array($input) ? $input : array()), fn ($input = array()) => $this->manager()->canEdit(is_array($input) ? $input : array()), true, true);
        $this->registerMutation('media-delete', 'Delete media attachment', 'Permanently delete one exact WordPress media-library attachment using WordPress native deletion.', $this->strictObject(array('attachment_id' => $this->idSchema())), $this->strictObject(array('attachment_id' => array('type' => 'integer'), 'deleted' => array('type' => 'boolean'))), fn ($input = array()) => $this->manager()->delete((int) ($input['attachment_id'] ?? 0)), fn ($input = array()) => $this->manager()->canDelete(is_array($input) ? $input : array()), true, false);
    }

    private function staging(): MediaStagingService { return $this->staging ??= new MediaStagingService(); }
    private function manager(): MediaManager { return $this->manager ??= new MediaManager($this->staging()); }
    private function abilityName(string $slug): string { return rtrim(CODI_MCP_ABILITY_PREFIX, '/') . '/' . ltrim($slug, '/'); }
    private function category(): string { return rtrim(CODI_MCP_ABILITY_PREFIX, '/') . '-media'; }

    private function registerReadOnly(string $slug, string $label, string $description, array $input, array $output, callable $execute, callable $permission): void
    {
        wp_register_ability($this->abilityName($slug), array('label' => $label, 'description' => $description, 'category' => $this->category(), 'input_schema' => $input, 'output_schema' => $output, 'execute_callback' => $execute, 'permission_callback' => $permission, 'meta' => AbilityMetadata::owned($this->key(), true, false, true)));
    }

    private function registerMutation(string $slug, string $label, string $description, array $input, array $output, callable $execute, callable $permission, bool $destructive, bool $idempotent): void
    {
        wp_register_ability($this->abilityName($slug), array('label' => $label, 'description' => $description, 'category' => $this->category(), 'input_schema' => $input, 'output_schema' => $output, 'execute_callback' => $execute, 'permission_callback' => $permission, 'meta' => AbilityMetadata::owned($this->key(), false, $destructive, $idempotent, in_array($slug, array('media-create', 'media-update'), true))));
    }

    private function emptySchema(): array { return array('type' => 'object', 'additionalProperties' => false, 'properties' => array()); }
    private function idSchema(): array { return array('type' => 'integer', 'minimum' => 1); }
    private function uploadIdSchema(): array { return array('type' => 'string', 'minLength' => 32, 'maxLength' => 32, 'pattern' => '^[a-f0-9]{32}$'); }
    private function strictObject(array $properties): array { return array('type' => 'object', 'additionalProperties' => false, 'properties' => $properties, 'required' => array_keys($properties)); }

    private function uploadInputSchema(): array
    {
        return array('type' => 'object', 'additionalProperties' => false, 'properties' => array(
            'filename' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'pattern' => '^[^\\\\/]+$'), 'mime_type' => array('type' => 'string', 'maxLength' => 255),
            'size' => array('type' => 'integer', 'minimum' => 1, 'maximum' => MediaStagingService::maximumUploadBytes()), 'sha256' => array('type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$'),
        ), 'required' => array('filename', 'size', 'sha256'));
    }

    private function uploadOutputSchema(): array
    {
        return $this->strictObject(array(
            'upload_id' => array('type' => 'string'),
            'complete' => array('type' => 'boolean'),
            'upload' => $this->strictObject(array(
                'method' => array('type' => 'string', 'enum' => array('PUT')),
                'url' => array('type' => 'string'),
                'content_type' => array('type' => 'string'),
                'size' => array('type' => 'integer'),
                'sha256' => array('type' => 'string'),
                'expires_at' => array('type' => 'string'),
            )),
            'instruction' => array('type' => 'string'),
        ));
    }

    private function sourceProperties(): array { return array('source_upload_id' => $this->uploadIdSchema(), 'source_url' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 2048), 'filename' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 255)); }

    private function createInputSchema(): array
    {
        $properties = $this->sourceProperties() + array('title' => array('type' => 'string', 'maxLength' => 500), 'caption' => array('type' => 'string', 'maxLength' => 50000), 'alt_text' => array('type' => 'string', 'maxLength' => 5000), 'parent_post_id' => array('type' => 'integer', 'minimum' => 0));
        return array('type' => 'object', 'additionalProperties' => false, 'properties' => $properties, 'oneOf' => array(array('type' => 'object', 'required' => array('source_upload_id')), array('type' => 'object', 'required' => array('source_url'))));
    }

    private function updateInputSchema(): array
    {
        $properties = array('attachment_id' => $this->idSchema()) + $this->sourceProperties() + array('title' => array('type' => 'string', 'maxLength' => 500), 'caption' => array('type' => 'string', 'maxLength' => 50000), 'alt_text' => array('type' => 'string', 'maxLength' => 5000), 'parent_post_id' => array('type' => 'integer', 'minimum' => 0));
        return array('type' => 'object', 'additionalProperties' => false, 'properties' => $properties, 'required' => array('attachment_id'), 'anyOf' => array_map(static fn (string $field): array => array('type' => 'object', 'required' => array($field)), array('source_upload_id', 'source_url', 'title', 'caption', 'alt_text', 'parent_post_id')));
    }

    private function findInputSchema(): array { return array('type' => 'object', 'additionalProperties' => false, 'properties' => array('search' => array('type' => 'string', 'maxLength' => 500), 'mime_type' => array('type' => 'string', 'maxLength' => 255), 'parent_post_id' => array('type' => 'integer', 'minimum' => 1), 'page' => array('type' => 'integer', 'minimum' => 1), 'per_page' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 50))); }
    private function findOutputSchema(): array { return $this->strictObject(array('items' => array('type' => 'array', 'items' => $this->attachmentSchema(false)), 'page' => array('type' => 'integer'), 'per_page' => array('type' => 'integer'), 'returned' => array('type' => 'integer'))); }

    private function attachmentSchema(bool $metadata): array
    {
        $properties = array('attachment_id' => array('type' => 'integer'), 'title' => array('type' => 'string'), 'caption' => array('type' => 'string'), 'alt_text' => array('type' => 'string'), 'mime_type' => array('type' => 'string'), 'parent_post_id' => array('type' => 'integer'), 'status' => array('type' => 'string'), 'url' => array('type' => 'string'));
        if ($metadata) { $properties['metadata_json'] = array('type' => 'string'); }
        return $this->strictObject($properties);
    }

    private function configSchema(): array
    {
        $size = $this->strictObject(array('name' => array('type' => 'string'), 'width' => array('type' => 'integer'), 'height' => array('type' => 'integer'), 'crop_json' => array('type' => 'string')));
        $mime = $this->strictObject(array('extensions' => array('type' => 'string'), 'mime' => array('type' => 'string')));
        $editor = $this->strictObject(array('class' => array('type' => 'string'), 'available' => array('type' => 'boolean')));
        return $this->strictObject(array('wp_max_upload_size' => array('type' => 'integer'), 'upload_max_filesize' => array('type' => 'string'), 'post_max_size' => array('type' => 'string'), 'image_sizes' => array('type' => 'array', 'items' => $size), 'allowed_mime_types' => array('type' => 'array', 'items' => $mime), 'image_editors' => array('type' => 'array', 'items' => $editor), 'supports_webp' => array('type' => 'boolean'), 'supports_avif' => array('type' => 'boolean')));
    }
}
