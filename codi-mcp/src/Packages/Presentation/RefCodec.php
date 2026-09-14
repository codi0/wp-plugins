<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation;

final class RefCodec
{
    private const PREFIX = 'codi:presentation:v1:';

    public function encode(string $postType, int $postId): string
    {
        $postType = strtolower(trim($postType));
        if ($postId < 1 || !preg_match('/^[a-z0-9_-]+$/', $postType)) {
            throw new \InvalidArgumentException('Cannot encode an invalid Presentation post ref.');
        }

        return self::PREFIX . 'post:' . $postType . ':' . $postId;
    }

    public function encodeTemplate(string $templateType, string $templateId): string
    {
        $kind = match (strtolower(trim($templateType))) {
            'wp_template', 'template' => 'template',
            'wp_template_part', 'template_part' => 'template_part',
            default => throw new \InvalidArgumentException('Invalid Presentation template type.'),
        };
        $templateId = trim($templateId);
        if ('' === $templateId || strlen($templateId) > 500) {
            throw new \InvalidArgumentException('Invalid Presentation template ID.');
        }
        $encoded = rtrim(strtr(base64_encode($templateId), '+/', '-_'), '=');
        if ('' === $encoded) {
            throw new \InvalidArgumentException('Could not encode Presentation template ID.');
        }
        return self::PREFIX . $kind . ':' . $encoded;
    }

    /** @return array{type:string,post_type:string,post_id:int,template_type:string,template_id:string} */
    public function decode(string $ref): array
    {
        $ref = trim($ref);
        if (preg_match('/^codi:presentation:v1:post:([a-z0-9_-]+):([1-9][0-9]*)$/', $ref, $matches)) {
            return array(
                'type' => 'post',
                'post_type' => (string) $matches[1],
                'post_id' => (int) $matches[2],
                'template_type' => '',
                'template_id' => '',
            );
        }
        if (preg_match('/^codi:presentation:v1:(template|template_part):([A-Za-z0-9_-]+)$/', $ref, $matches)) {
            $encoded = (string) $matches[2];
            $padding = (4 - (strlen($encoded) % 4)) % 4;
            $decoded = base64_decode(strtr($encoded . str_repeat('=', $padding), '-_', '+/'), true);
            if (!is_string($decoded) || '' === trim($decoded) || strlen($decoded) > 500) {
                throw new \InvalidArgumentException('Invalid Presentation template ref.');
            }
            return array(
                'type' => 'template',
                'post_type' => '',
                'post_id' => 0,
                'template_type' => 'template_part' === (string) $matches[1] ? 'wp_template_part' : 'wp_template',
                'template_id' => $decoded,
            );
        }
        throw new \InvalidArgumentException('Invalid Presentation ref.');
    }
}
