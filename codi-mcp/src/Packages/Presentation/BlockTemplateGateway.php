<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation;

final class BlockTemplateGateway
{
    /** @return array<int,object> */
    public function list(string $templateType, array $query = array()): array
    {
        $templateType = $this->normalizeType($templateType);
        if (!function_exists('get_block_templates')) {
            throw new \RuntimeException('WordPress unified block-template discovery is unavailable.');
        }
        return array_values(array_filter((array) get_block_templates($query, $templateType), 'is_object'));
    }

    public function get(string $templateType, string $templateId): object
    {
        $templateType = $this->normalizeType($templateType);
        $templateId = trim($templateId);
        if ('' === $templateId || !function_exists('get_block_template')) {
            throw new \InvalidArgumentException('A valid unified WordPress template ID is required.');
        }
        $template = get_block_template($templateId, $templateType);
        if (!is_object($template)) {
            throw new \InvalidArgumentException('The referenced WordPress template no longer exists.');
        }
        return $template;
    }

    /** @param array<string,mixed> $fields */
    public function update(string $templateType, string $templateId, array $fields): object
    {
        $templateType = $this->normalizeType($templateType);
        $template = $this->get($templateType, $templateId);
        $controller = $this->controller($templateType);
        $request = $this->request('PUT');
        $request->set_param('id', $templateId);
        $request->set_param('context', 'edit');
        $this->applyFields($request, $fields, $templateType);
        $result = $controller->update_item($request);
        $this->assertRestSuccess($result, 'update the WordPress template');
        return $this->get($templateType, $templateId);
    }

    /** @param array<string,mixed> $fields */
    public function create(string $templateType, array $fields): object
    {
        $templateType = $this->normalizeType($templateType);
        $slug = trim((string) ($fields['slug'] ?? ''));
        if ('' === $slug) {
            throw new \InvalidArgumentException('Creating a WordPress template requires slug.');
        }
        $controller = $this->controller($templateType);
        $request = $this->request('POST');
        $request->set_param('slug', $slug);
        $request->set_param('context', 'edit');
        $request->set_param('theme', trim((string) ($fields['theme'] ?? '')) ?: $this->currentTheme());
        $this->applyFields($request, $fields, $templateType);
        $result = $controller->create_item($request);
        $this->assertRestSuccess($result, 'create the WordPress template');
        $data = is_object($result) && method_exists($result, 'get_data') ? $result->get_data() : null;
        $id = is_array($data) ? trim((string) ($data['id'] ?? '')) : '';
        if ('' === $id) {
            $matches = $this->list($templateType, array('slug__in' => array($slug)));
            $matches = array_values(array_filter($matches, fn (object $item): bool => (string) ($item->theme ?? '') === $this->currentTheme()));
            $id = isset($matches[0]) ? trim((string) ($matches[0]->id ?? '')) : '';
        }
        if ('' === $id) {
            throw new \RuntimeException('WordPress created the template but did not expose its unified template ID.');
        }
        return $this->get($templateType, $id);
    }

    /**
     * Delete a custom database template record. If the template overrides a theme
     * or plugin source, the unified source becomes visible again after deletion.
     */
    public function deleteCustomization(string $templateType, string $templateId): ?object
    {
        $templateType = $this->normalizeType($templateType);
        $template = $this->get($templateType, $templateId);
        if ('custom' !== (string) ($template->source ?? '')) {
            throw new \InvalidArgumentException('Only a custom WordPress template record can be deleted through Presentation; source templates belong to Themes/Plugins.');
        }
        $controller = $this->controller($templateType);
        $request = $this->request('DELETE');
        $request->set_param('id', $templateId);
        $request->set_param('force', true);
        $result = $controller->delete_item($request);
        $this->assertRestSuccess($result, 'delete the WordPress template customization');
        if (!function_exists('get_block_template')) {
            return null;
        }
        $fallback = get_block_template($templateId, $templateType);
        return is_object($fallback) ? $fallback : null;
    }

    public function sourceIsCustom(object $template): bool
    {
        return 'custom' === strtolower(trim((string) ($template->source ?? '')));
    }

    public function normalizeType(string $templateType): string
    {
        return match (strtolower(trim($templateType))) {
            'template', 'wp_template' => 'wp_template',
            'template_part', 'wp_template_part' => 'wp_template_part',
            default => throw new \InvalidArgumentException('Template type must be wp_template or wp_template_part.'),
        };
    }

    private function controller(string $templateType): object
    {
        if (!class_exists('WP_REST_Templates_Controller')) {
            throw new \RuntimeException('WordPress native template persistence controller is unavailable.');
        }
        return new \WP_REST_Templates_Controller($templateType);
    }

    private function request(string $method): object
    {
        if (!class_exists('WP_REST_Request')) {
            throw new \RuntimeException('WordPress REST request support is unavailable for native template persistence.');
        }
        return new \WP_REST_Request($method);
    }

    /** @param array<string,mixed> $fields */
    private function applyFields(object $request, array $fields, string $templateType): void
    {
        foreach (array('content', 'title', 'description') as $field) {
            if (array_key_exists($field, $fields)) {
                $request->set_param($field, (string) $fields[$field]);
            }
        }
        if ('wp_template_part' === $templateType && array_key_exists('area', $fields)) {
            $area = trim((string) $fields['area']);
            if ('' !== $area) {
                $request->set_param('area', $area);
            }
        }
    }

    private function assertRestSuccess(mixed $result, string $action): void
    {
        if (function_exists('is_wp_error') && is_wp_error($result)) {
            $message = method_exists($result, 'get_error_message') ? (string) $result->get_error_message() : 'Unknown WordPress error.';
            throw new \RuntimeException(sprintf('WordPress could not %s: %s', $action, $message));
        }
        if (!is_object($result)) {
            throw new \RuntimeException(sprintf('WordPress could not %s.', $action));
        }
    }

    private function currentTheme(): string
    {
        return function_exists('get_stylesheet') ? (string) get_stylesheet() : '';
    }
}
