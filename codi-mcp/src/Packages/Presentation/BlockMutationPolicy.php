<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation;

use CodiMcp\Packages\Presentation\Gutenberg\BlockCodecRegistry;

/** Fail-closed Gutenberg mutation policy. */
final class BlockMutationPolicy
{
    public function __construct(private ?BlockCodecRegistry $codecs = null)
    {
        $this->codecs ??= new BlockCodecRegistry();
    }

    public function assertTextMutationAllowed(string $blockName): void
    {
        $blockName = strtolower(trim($blockName));
        $allowed = in_array('update_text', $this->codecs->capabilities($blockName), true);
        if (!$allowed) {
            throw new \InvalidArgumentException(sprintf('Presentation has no editor-safe text codec for static block [%s].', $blockName));
        }
        $this->assertRegistered($blockName);
    }

    public function assertRootInsertionAllowed(string $blockName): void
    {
        $blockType = $this->registeredBlockType($blockName);
        if (!is_object($blockType)) {
            throw new \InvalidArgumentException(sprintf('Presentation will not insert unregistered block type [%s].', $blockName));
        }
        $parents = is_array($blockType->parent ?? null) ? array_values($blockType->parent) : array();
        $ancestors = is_array($blockType->ancestor ?? null) ? array_values($blockType->ancestor) : array();
        if ($parents !== array() || $ancestors !== array()) {
            throw new \InvalidArgumentException(sprintf('Block [%s] declares parent/ancestor placement constraints and cannot be inserted at the document root.', $blockName));
        }
    }

    public function assertNestedInsertionAllowed(string $blockName, string $parentBlockName): void
    {
        $blockType = $this->registeredBlockType($blockName);
        if (!is_object($blockType)) {
            throw new \InvalidArgumentException(sprintf('Presentation will not insert unregistered block type [%s].', $blockName));
        }
        $parentBlockName = strtolower(trim($parentBlockName));
        $parents = is_array($blockType->parent ?? null) ? array_map('strtolower', array_values($blockType->parent)) : array();
        if ($parents !== array() && !in_array($parentBlockName, $parents, true)) {
            throw new \InvalidArgumentException(sprintf('Block [%s] cannot be inserted directly inside [%s].', $blockName, $parentBlockName));
        }
        $ancestors = is_array($blockType->ancestor ?? null) ? array_map('strtolower', array_values($blockType->ancestor)) : array();
        if ($ancestors !== array() && !in_array($parentBlockName, $ancestors, true)) {
            throw new \InvalidArgumentException(sprintf('Block [%s] declares ancestor constraints that cannot be proven by this insertion path.', $blockName));
        }
    }

    /** @return array{registered:bool,dynamic:bool,mutation_support:string,codec_capabilities:array<int,string>} */
    public function describe(string $blockName): array
    {
        $blockName = strtolower(trim($blockName));
        $blockType = $this->registeredBlockType($blockName);
        $registered = is_object($blockType);
        $dynamic = $registered && method_exists($blockType, 'is_dynamic') && (bool) $blockType->is_dynamic();
        $capabilities = $this->codecs->capabilities($blockName);
        $support = $capabilities !== array() ? 'php_save_codec' : 'structural_only';
        return array('registered' => $registered, 'dynamic' => $dynamic, 'mutation_support' => $support, 'codec_capabilities' => $capabilities);
    }

    private function assertRegistered(string $blockName): void
    {
        if (!is_object($this->registeredBlockType($blockName))) {
            throw new \InvalidArgumentException(sprintf('Block type [%s] is not registered in this WordPress runtime.', $blockName));
        }
    }

    private function registeredBlockType(string $blockName): ?object
    {
        $blockName = strtolower(trim($blockName));
        if ('' === $blockName || !class_exists('WP_Block_Type_Registry') || !method_exists('WP_Block_Type_Registry', 'get_instance')) {
            return null;
        }
        $registry = \WP_Block_Type_Registry::get_instance();
        if (!is_object($registry) || !method_exists($registry, 'get_registered')) {
            return null;
        }
        $blockType = $registry->get_registered($blockName);
        return is_object($blockType) ? $blockType : null;
    }
}
