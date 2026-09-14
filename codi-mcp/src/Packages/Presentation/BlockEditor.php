<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation;

use CodiMcp\Packages\Presentation\Gutenberg\BlockCodecRegistry;
use CodiMcp\Packages\Presentation\Gutenberg\SaveRuntime;

final class BlockEditor
{
    private BlockCodecRegistry $codecRegistry;
    private SaveRuntime $saveRuntime;

    public function __construct(
        private ?BlockSchemaGuard $schemaGuard = null,
        private ?BlockMutationPolicy $mutationPolicy = null,
        ?BlockCodecRegistry $codecRegistry = null,
        ?SaveRuntime $saveRuntime = null
    ) {
        $this->schemaGuard ??= new BlockSchemaGuard();
        $this->codecRegistry = $codecRegistry ?? new BlockCodecRegistry();
        $this->saveRuntime = $saveRuntime ?? new SaveRuntime($this->codecRegistry);
        $this->mutationPolicy ??= new BlockMutationPolicy($this->codecRegistry);
    }

    /** @return array<int,array<string,mixed>> */
    public function parse(string $content): array
    {
        $this->assertSafeMarkup($content);
        if (!function_exists('parse_blocks')) {
            throw new \RuntimeException('WordPress block parsing is unavailable.');
        }

        return array_values(array_filter((array) parse_blocks($content), 'is_array'));
    }

    /** @param array<int,array<string,mixed>> $blocks */
    public function serialize(array $blocks): string
    {
        if (!function_exists('serialize_blocks')) {
            throw new \RuntimeException('WordPress block serialization is unavailable.');
        }
        $serialized = (string) serialize_blocks($blocks);
        $this->assertSafeMarkup($serialized);
        $reparsed = $this->parse($serialized);
        $this->schemaGuard->assertEquivalentRoundTrip($blocks, $reparsed);
        return $serialized;
    }

    /** @param array<string,mixed> $block */
    public function insertExisting(string $content, string $parentPath, int $index, array $block): string
    {
        $blocks = $this->parse($content);
        $segments = $this->pathSegments($parentPath, true);
        $blocks = $this->insertAtParent($blocks, $segments, $index, array($block));
        return $this->serialize($blocks);
    }


    public function insertTextBlock(string $content, int $index, string $type, string $text, int $level = 2, string $parentPath = ''): string
    {
        $type = strtolower(trim($type));
        if (!in_array($type, array('paragraph', 'heading'), true)) {
            throw new \InvalidArgumentException('content.insert_text supports paragraph or heading.');
        }
        $blockName = 'paragraph' === $type ? 'core/paragraph' : 'core/heading';
        $block = $this->saveRuntime->create($blockName, array('text' => $text, 'level' => $level));
        return $this->insertExisting($content, $parentPath, $index, $block);
    }

    /** @param array<string,mixed> $attributes */
    public function insertGroup(string $content, int $index, string $parentPath = '', array $attributes = array()): string
    {
        $block = $this->saveRuntime->create('core/group', array('attributes' => $attributes));
        return $this->insertExisting($content, $parentPath, $index, $block);
    }

    /** @param array<string,mixed> $state */
    public function insertButton(string $content, int $index, string $parentPath, array $state): string
    {
        $button = $this->saveRuntime->create('core/button', $state);
        if ('' === trim($parentPath)) {
            $buttons = $this->saveRuntime->create('core/buttons', array(), array($button));
            return $this->insertExisting($content, '', $index, $buttons);
        }
        $parent = $this->blockAtPath($content, $parentPath);
        if ('core/buttons' === strtolower((string) ($parent['blockName'] ?? ''))) {
            return $this->insertExisting($content, $parentPath, $index, $button);
        }
        $buttons = $this->saveRuntime->create('core/buttons', array(), array($button));
        return $this->insertExisting($content, $parentPath, $index, $buttons);
    }

    /** @param array<string,mixed> $changes */
    public function updateButton(string $content, string $path, array $changes): string
    {
        $blocks = $this->parse($content);
        $blocks = $this->updateAtSegments($blocks, $this->pathSegments($path), function (array $block) use ($changes): array {
            if ('core/button' !== strtolower((string) ($block['blockName'] ?? ''))) {
                throw new \InvalidArgumentException('content.update_button requires a core/button target.');
            }
            return $this->saveRuntime->rewrite($block, $changes);
        });
        return $this->serialize($blocks);
    }

    /** @param array<string,mixed> $state */
    public function insertColumns(string $content, int $index, string $parentPath, int $count, array $state = array()): string
    {
        $count = max(1, min(6, $count));
        $columns = array();
        for ($i = 0; $i < $count; $i++) {
            $columns[] = $this->saveRuntime->create('core/column');
        }
        $block = $this->saveRuntime->create('core/columns', $state, $columns);
        return $this->insertExisting($content, $parentPath, $index, $block);
    }

    /** @param array<string,mixed> $changes */
    public function updateColumn(string $content, string $path, array $changes): string
    {
        $blocks = $this->parse($content);
        $blocks = $this->updateAtSegments($blocks, $this->pathSegments($path), function (array $block) use ($changes): array {
            if ('core/column' !== strtolower((string) ($block['blockName'] ?? ''))) {
                throw new \InvalidArgumentException('blocks.update_column requires a core/column target.');
            }
            return $this->saveRuntime->rewrite($block, $changes);
        });
        return $this->serialize($blocks);
    }

    /** @param array<string,mixed> $state */
    public function insertImage(string $content, int $index, string $parentPath, array $state): string
    {
        $block = $this->saveRuntime->create('core/image', $state);
        return $this->insertExisting($content, $parentPath, $index, $block);
    }

    /** @param array<string,mixed> $changes */
    public function updateImage(string $content, string $path, array $changes): string
    {
        $blocks = $this->parse($content);
        $blocks = $this->updateAtSegments($blocks, $this->pathSegments($path), function (array $block) use ($changes): array {
            if ('core/image' !== strtolower((string) ($block['blockName'] ?? ''))) {
                throw new \InvalidArgumentException('content.update_image requires a core/image target.');
            }
            return $this->saveRuntime->rewrite($block, $changes);
        });
        return $this->serialize($blocks);
    }

    /** @param array<string,mixed> $state */
    public function insertCover(string $content, int $index, string $parentPath, array $state): string
    {
        $block = $this->saveRuntime->create('core/cover', $state);
        return $this->insertExisting($content, $parentPath, $index, $block);
    }

    /** @param array<string,mixed> $changes */
    public function updateCover(string $content, string $path, array $changes): string
    {
        $blocks = $this->parse($content);
        $blocks = $this->updateAtSegments($blocks, $this->pathSegments($path), function (array $block) use ($changes): array {
            if ('core/cover' !== strtolower((string) ($block['blockName'] ?? ''))) {
                throw new \InvalidArgumentException('blocks.update_cover requires a core/cover target.');
            }
            return $this->saveRuntime->rewrite($block, $changes);
        });
        return $this->serialize($blocks);
    }

    /** @param array<string,mixed> $state */
    public function insertGallery(string $content, int $index, string $parentPath, array $state = array()): string
    {
        $block = $this->saveRuntime->create('core/gallery', $state);
        return $this->insertExisting($content, $parentPath, $index, $block);
    }

    /** @param array<string,mixed> $changes */
    public function updateGallery(string $content, string $path, array $changes): string
    {
        $blocks = $this->parse($content);
        $blocks = $this->updateAtSegments($blocks, $this->pathSegments($path), function (array $block) use ($changes): array {
            if ('core/gallery' !== strtolower((string) ($block['blockName'] ?? ''))) {
                throw new \InvalidArgumentException('blocks.update_gallery requires a core/gallery target.');
            }
            return $this->saveRuntime->rewrite($block, $changes);
        });
        return $this->serialize($blocks);
    }

    /** @param string[] $items */
    public function insertList(string $content, int $index, string $parentPath, array $items, bool $ordered = false): string
    {
        if ($items === array() || count($items) > 100) {
            throw new \InvalidArgumentException('content.insert_list requires between 1 and 100 items.');
        }
        $children = array();
        foreach ($items as $item) {
            if (!is_string($item)) {
                throw new \InvalidArgumentException('Every content.insert_list item must be text.');
            }
            $children[] = $this->saveRuntime->create('core/list-item', array('text' => $item));
        }
        $list = $this->saveRuntime->create('core/list', array('ordered' => $ordered), $children);
        return $this->insertExisting($content, $parentPath, $index, $list);
    }

    /** @param array<string,mixed> $state */
    public function insertQueryLoop(string $content, int $index, string $parentPath, array $state = array()): string
    {
        $postTitle = $this->saveRuntime->create('core/post-title', array('attributes' => array('isLink' => true)));
        $postTemplate = $this->saveRuntime->create('core/post-template', array(), array($postTitle));
        $pagination = $this->saveRuntime->create('core/query-pagination', array(), array(
            $this->saveRuntime->create('core/query-pagination-previous'),
            $this->saveRuntime->create('core/query-pagination-numbers'),
            $this->saveRuntime->create('core/query-pagination-next'),
        ));
        $noResults = $this->saveRuntime->create('core/query-no-results', array(), array(
            $this->saveRuntime->create('core/paragraph', array('text' => 'No results found.')),
        ));
        $query = $this->saveRuntime->create('core/query', $state, array($postTemplate, $pagination, $noResults));
        return $this->insertExisting($content, $parentPath, $index, $query);
    }

    /** @param array<string,mixed> $changes */
    public function updateQueryLoop(string $content, string $path, array $changes): string
    {
        $blocks = $this->parse($content);
        $blocks = $this->updateAtSegments($blocks, $this->pathSegments($path), function (array $block) use ($changes): array {
            if ('core/query' !== strtolower((string) ($block['blockName'] ?? ''))) {
                throw new \InvalidArgumentException('query.update requires a core/query target.');
            }
            return $this->saveRuntime->rewrite($block, $changes);
        });
        return $this->serialize($blocks);
    }

    /** @param array<string,mixed> $state */
    public function insertNavigationItem(string $content, int $index, string $parentPath, array $state): string
    {
        $itemType = strtolower(trim((string) ($state['item_type'] ?? 'link')));
        $blockName = match ($itemType) {
            'link' => 'core/navigation-link',
            'submenu' => 'core/navigation-submenu',
            default => throw new \InvalidArgumentException('navigation.insert_entry item_type must be link or submenu.'),
        };
        $attributes = $this->navigationAttributes($state, true);
        $block = $this->saveRuntime->create($blockName, array('attributes' => $attributes));
        $blocks = $this->parse($content);
        $blocks = $this->insertNavigationAtParent($blocks, $this->pathSegments($parentPath, true), $index, $block);
        return $this->serialize($blocks);
    }

    /** @param array<string,mixed> $changes */
    public function updateNavigationItem(string $content, string $path, array $changes): string
    {
        $blocks = $this->parse($content);
        $blocks = $this->updateAtSegments($blocks, $this->pathSegments($path), function (array $block) use ($changes): array {
            $name = strtolower((string) ($block['blockName'] ?? ''));
            if (!in_array($name, array('core/navigation-link', 'core/navigation-submenu'), true)) {
                throw new \InvalidArgumentException('navigation.update_entry requires a core/navigation-link or core/navigation-submenu target.');
            }
            $before = $block;
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
            foreach ($this->navigationAttributes($changes, false) as $key => $value) {
                if (null === $value) { unset($attrs[$key]); } else { $attrs[$key] = $value; }
            }
            $block['attrs'] = $attrs;
            $this->schemaGuard->assertSafeMutation($before, $block);
            return $this->saveRuntime->rewrite($block);
        });
        return $this->serialize($blocks);
    }

    /** @return array<string,mixed> */
    public function blockAtPath(string $content, string $path): array
    {
        $blocks = $this->parse($content);
        $segments = $this->pathSegments($path);
        $current = $blocks;
        $block = null;
        foreach ($segments as $segment) {
            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                throw new \InvalidArgumentException('Block path is out of range.');
            }
            $block = $current[$segment];
            $current = array_values(array_filter((array) ($block['innerBlocks'] ?? array()), 'is_array'));
        }
        if (!is_array($block)) {
            throw new \InvalidArgumentException('Block path is out of range.');
        }
        return $block;
    }

    public function remove(string $content, string $path): string
    {
        $segments = $this->pathSegments($path);
        $blocks = $this->parse($content);
        [, $blocks] = $this->removeAtSegments($blocks, $segments);
        return $this->serialize($blocks);
    }

    public function move(string $content, string $path, string $toParentPath, int $toIndex): string
    {
        $source = $this->pathSegments($path);
        $destination = $this->pathSegments($toParentPath, true);
        $sourceParent = $source;
        $sourceIndex = (int) array_pop($sourceParent);
        if ($destination !== array() && array_slice($destination, 0, count($source)) === $source) {
            throw new \InvalidArgumentException('blocks.move cannot move a block into its own descendant.');
        }

        $blocks = $this->parse($content);
        if ($sourceParent === $destination) {
            $blocks = $this->reorderWithinParent($blocks, $sourceParent, $sourceIndex, $toIndex);
            return $this->serialize($blocks);
        }
        throw new \InvalidArgumentException('Moving a block between different parents requires a block-specific container codec. Presentation currently supports reordering only within the same parent.');
    }

    /** @param array<string,mixed> $changes */
    public function updatePresentation(string $content, string $path, array $changes): string
    {
        $fields = array(
            'text_color' => array('attribute' => 'textColor', 'capability' => 'update_text_color'),
            'background_color' => array('attribute' => 'backgroundColor', 'capability' => 'update_background_color'),
            'font_size' => array('attribute' => 'fontSize', 'capability' => 'update_font_size'),
            'line_height' => array('typography' => 'lineHeight', 'capability' => 'update_line_height'),
            'margin_top' => array('spacing' => 'margin', 'side' => 'top', 'capability' => 'update_margin_block'),
            'margin_bottom' => array('spacing' => 'margin', 'side' => 'bottom', 'capability' => 'update_margin_block'),
            'padding_top' => array('spacing' => 'padding', 'side' => 'top', 'capability' => 'update_padding'),
            'padding_right' => array('spacing' => 'padding', 'side' => 'right', 'capability' => 'update_padding'),
            'padding_bottom' => array('spacing' => 'padding', 'side' => 'bottom', 'capability' => 'update_padding'),
            'padding_left' => array('spacing' => 'padding', 'side' => 'left', 'capability' => 'update_padding'),
        );
        $blocks = $this->parse($content);
        $blocks = $this->updateAtSegments($blocks, $this->pathSegments($path), function (array $block) use ($changes, $fields): array {
            $name = strtolower(trim((string) ($block['blockName'] ?? '')));
            $capabilities = $this->codecRegistry->capabilities($name);
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
            $changed = false;
            foreach ($fields as $field => $spec) {
                if (!array_key_exists($field, $changes)) { continue; }
                if (!in_array((string) $spec['capability'], $capabilities, true)) {
                    throw new \InvalidArgumentException(sprintf('Block [%s] does not support Presentation field [%s].', $name, $field));
                }
                if (isset($spec['typography'])) {
                    $raw = $changes[$field];
                    if (!is_scalar($raw) || is_bool($raw)) {
                        throw new \InvalidArgumentException(sprintf('Presentation field [%s] must be a unitless number or empty to clear it.', $field));
                    }
                    $value = trim((string) $raw);
                    if ('' !== $value) {
                        if (strlen($value) > 16 || !preg_match('/^[0-9]+(?:\.[0-9]{1,3})?$/', $value)) {
                            throw new \InvalidArgumentException(sprintf('Presentation field [%s] must be a unitless decimal number with at most three decimal places.', $field));
                        }
                        $number = (float) $value;
                        if ($number < 0.5 || $number > 4.0) {
                            throw new \InvalidArgumentException(sprintf('Presentation field [%s] must be between 0.5 and 4.', $field));
                        }
                        $value = rtrim(rtrim(number_format($number, 3, '.', ''), '0'), '.');
                    }
                    $style = is_array($attrs['style'] ?? null) ? $attrs['style'] : array();
                    $typography = is_array($style['typography'] ?? null) ? $style['typography'] : array();
                    if ('' === $value) { unset($typography[(string) $spec['typography']]); } else { $typography[(string) $spec['typography']] = $value; }
                    if ($typography === array()) { unset($style['typography']); } else { $style['typography'] = $typography; }
                    if ($style === array()) { unset($attrs['style']); } else { $attrs['style'] = $style; }
                    $changed = true;
                    continue;
                }
                $value = strtolower(trim((string) $changes[$field]));
                if ('' !== $value && !preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/', $value)) {
                    throw new \InvalidArgumentException(sprintf('Presentation field [%s] must be a WordPress preset slug or empty to clear it.', $field));
                }
                if (isset($spec['attribute'])) {
                    if ('' === $value) { unset($attrs[(string) $spec['attribute']]); } else { $attrs[(string) $spec['attribute']] = $value; }
                    $changed = true;
                    continue;
                }
                $property = (string) ($spec['spacing'] ?? '');
                $side = (string) ($spec['side'] ?? '');
                $style = is_array($attrs['style'] ?? null) ? $attrs['style'] : array();
                $spacing = is_array($style['spacing'] ?? null) ? $style['spacing'] : array();
                if (array_key_exists($property, $spacing) && !is_array($spacing[$property]) && null !== $spacing[$property] && '' !== $spacing[$property]) {
                    throw new \InvalidArgumentException(sprintf('Block [%s] uses non-side-specific %s spacing and cannot be safely changed by [%s].', $name, $property, $field));
                }
                $sides = is_array($spacing[$property] ?? null) ? $spacing[$property] : array();
                if ('' === $value) { unset($sides[$side]); } else { $sides[$side] = 'var:preset|spacing|' . $value; }
                if ($sides === array()) { unset($spacing[$property]); } else { $spacing[$property] = $sides; }
                if ($spacing === array()) { unset($style['spacing']); } else { $style['spacing'] = $spacing; }
                if ($style === array()) { unset($attrs['style']); } else { $attrs['style'] = $style; }
                $changed = true;
            }
            if (!$changed) {
                throw new \InvalidArgumentException('blocks.update_presentation requires at least one supported presentation field.');
            }
            $before = $block;
            $block['attrs'] = $attrs;
            $this->schemaGuard->assertSafeMutation($before, $block);
            return $this->saveRuntime->rewrite($block);
        });
        return $this->serialize($blocks);
    }

    public function updateText(string $content, string $path, string $text): string
    {
        $blocks = $this->parse($content);
        $blocks = $this->updateAtSegments($blocks, $this->pathSegments($path), function (array $block) use ($text): array {
            $name = strtolower((string) ($block['blockName'] ?? ''));
            $this->mutationPolicy->assertTextMutationAllowed($name);
            if (!in_array($name, array('core/paragraph', 'core/heading', 'core/list-item'), true)) {
                throw new \InvalidArgumentException('content.update_text only supports paragraph, heading, and list-item blocks.');
            }
            return $this->saveRuntime->rewrite($block, array('text' => $text));
        });
        return $this->serialize($blocks);
    }




    /** @return array<int,array<string,mixed>> */
    public function summarize(string $content): array
    {
        $summary = array();
        $this->summarizeBlocks($this->parse($content), '', $summary);
        return $summary;
    }

    /** @param array<int,array<string,mixed>> $blocks @param array<int,array<string,mixed>> $summary */
    private function summarizeBlocks(array $blocks, string $parentPath, array &$summary): void
    {
        foreach ($blocks as $index => $block) {
            $path = '' === $parentPath ? (string) $index : $parentPath . '.' . $index;
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
            $name = (string) ($block['blockName'] ?? '');
            $safety = $this->mutationPolicy->describe($name);
            $summary[] = array(
                'path' => $path,
                'name' => $name,
                'attrs' => $attrs,
                'text' => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) ($block['innerHTML'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? ''),
                'registered' => (bool) $safety['registered'],
                'dynamic' => (bool) $safety['dynamic'],
                'mutation_support' => (string) $safety['mutation_support'],
                'codec_capabilities' => array_values((array) ($safety['codec_capabilities'] ?? array())),
            );
            $children = array_values(array_filter((array) ($block['innerBlocks'] ?? array()), 'is_array'));
            if ($children !== array()) {
                $this->summarizeBlocks($children, $path, $summary);
            }
        }
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function navigationAttributes(array $state, bool $creating): array
    {
        $attrs = array();
        if (array_key_exists('label', $state)) {
            $label = trim((string) $state['label']);
            if ('' === $label) { throw new \InvalidArgumentException('Navigation item label cannot be empty.'); }
            $attrs['label'] = $label;
        } elseif ($creating) {
            throw new \InvalidArgumentException('navigation.insert_entry requires label.');
        }
        foreach (array('url' => 'url', 'rel' => 'rel', 'title' => 'title', 'description' => 'description') as $field => $attribute) {
            if (!array_key_exists($field, $state)) { continue; }
            $value = trim((string) $state[$field]);
            $attrs[$attribute] = '' === $value ? null : $value;
        }
        if (array_key_exists('opens_in_new_tab', $state)) {
            $attrs['opensInNewTab'] = (bool) $state['opens_in_new_tab'] ? true : null;
        }
        return $attrs;
    }

    /** @param array<int,array<string,mixed>> $blocks @param array<int,int> $segments @param array<string,mixed> $block @return array<int,array<string,mixed>> */
    private function insertNavigationAtParent(array $blocks, array $segments, int $index, array $block): array
    {
        $childName = strtolower((string) ($block['blockName'] ?? ''));
        if (!in_array($childName, array('core/navigation-link', 'core/navigation-submenu'), true)) {
            throw new \InvalidArgumentException('Navigation composition only accepts Navigation Link or Submenu blocks.');
        }
        if ($segments === array()) {
            $index = max(0, min(count($blocks), $index));
            array_splice($blocks, $index, 0, array($block));
            return array_values($blocks);
        }
        $head = (int) array_shift($segments);
        if (!isset($blocks[$head]) || !is_array($blocks[$head])) {
            throw new \InvalidArgumentException('Navigation parent path is out of range.');
        }
        $parent = $blocks[$head];
        $parentName = strtolower((string) ($parent['blockName'] ?? ''));
        if ('core/navigation-submenu' !== $parentName) {
            throw new \InvalidArgumentException('Nested navigation items may only be inserted inside core/navigation-submenu.');
        }
        $children = array_values(array_filter((array) ($parent['innerBlocks'] ?? array()), 'is_array'));
        if ($segments === array()) {
            $this->saveRuntime->assertChildAllowed($parentName, $childName);
            $index = max(0, min(count($children), $index));
            array_splice($children, $index, 0, array($block));
            $blocks[$head] = $this->saveRuntime->rewrite($parent, array(), $children);
            return array_values($blocks);
        }
        $parent['innerBlocks'] = $this->insertNavigationAtParent($children, $segments, $index, $block);
        $blocks[$head] = $parent;
        return array_values($blocks);
    }

    /** @return array<int,int> */
    private function pathSegments(string $path, bool $allowEmpty = false): array
    {
        $path = trim($path);
        if ('' === $path) {
            if ($allowEmpty) {
                return array();
            }
            throw new \InvalidArgumentException('A block path is required.');
        }
        if (!preg_match('/^[0-9]+(?:\.[0-9]+)*$/', $path)) {
            throw new \InvalidArgumentException('Block paths must use zero-based dot-separated integer indexes.');
        }
        return array_map('intval', explode('.', $path));
    }

    /** @param array<int,array<string,mixed>> $blocks @param array<int,int> $segments @param array<int,array<string,mixed>> $newBlocks @return array<int,array<string,mixed>> */
    private function insertAtParent(array $blocks, array $segments, int $index, array $newBlocks): array
    {
        if ($segments === array()) {
            foreach ($newBlocks as $block) {
                $blockName = strtolower(trim((string) ($block['blockName'] ?? '')));
                if ('' !== $blockName) {
                    $this->mutationPolicy->assertRootInsertionAllowed($blockName);
                }
            }
            $index = max(0, min(count($blocks), $index));
            array_splice($blocks, $index, 0, $newBlocks);
            return array_values($blocks);
        }

        $head = (int) array_shift($segments);
        if (!isset($blocks[$head]) || !is_array($blocks[$head])) {
            throw new \InvalidArgumentException('Parent block path is out of range.');
        }
        $parent = $blocks[$head];
        $children = array_values(array_filter((array) ($parent['innerBlocks'] ?? array()), 'is_array'));
        if ($segments === array()) {
            $parentName = strtolower(trim((string) ($parent['blockName'] ?? '')));
            if (!$this->saveRuntime->supportsChildren($parentName)) {
                throw new \InvalidArgumentException(sprintf('Nested insertion into [%s] requires a supported PHP container save codec.', $parentName));
            }
            foreach ($newBlocks as $block) {
                $childName = strtolower(trim((string) ($block['blockName'] ?? '')));
                $this->mutationPolicy->assertNestedInsertionAllowed($childName, $parentName);
                $this->saveRuntime->assertChildAllowed($parentName, $childName);
            }
            $index = max(0, min(count($children), $index));
            array_splice($children, $index, 0, $newBlocks);
            $blocks[$head] = $this->saveRuntime->rewrite($parent, array(), $children);
            return array_values($blocks);
        }
        $parent['innerBlocks'] = $this->insertAtParent($children, $segments, $index, $newBlocks);
        $blocks[$head] = $parent;
        return array_values($blocks);
    }

    /** @param array<int,array<string,mixed>> $blocks @param array<int,int> $segments @return array{0:array<string,mixed>,1:array<int,array<string,mixed>>} */
    private function removeAtSegments(array $blocks, array $segments): array
    {
        $head = (int) array_shift($segments);
        if (!isset($blocks[$head])) {
            throw new \InvalidArgumentException('Block path is out of range.');
        }
        if ($segments === array()) {
            $removed = $blocks[$head];
            array_splice($blocks, $head, 1);
            return array($removed, array_values($blocks));
        }

        $parent = $blocks[$head];
        $children = array_values(array_filter((array) ($parent['innerBlocks'] ?? array()), 'is_array'));
        if (count($segments) === 1) {
            $parentName = strtolower(trim((string) ($parent['blockName'] ?? '')));
            if (!$this->saveRuntime->supportsChildren($parentName)) {
                throw new \InvalidArgumentException(sprintf('Nested block removal from [%s] requires a supported PHP container save codec.', $parentName));
            }
            [$removed, $children] = $this->removeAtSegments($children, $segments);
            $blocks[$head] = $this->saveRuntime->rewrite($parent, array(), $children);
            return array($removed, array_values($blocks));
        }
        [$removed, $children] = $this->removeAtSegments($children, $segments);
        $parent['innerBlocks'] = $children;
        $blocks[$head] = $parent;
        return array($removed, array_values($blocks));
    }

    /** @param array<int,array<string,mixed>> $blocks @param array<int,int> $segments @return array<int,array<string,mixed>> */
    private function updateAtSegments(array $blocks, array $segments, callable $mutator): array
    {
        $head = (int) array_shift($segments);
        if (!isset($blocks[$head])) {
            throw new \InvalidArgumentException('Block path is out of range.');
        }
        if ($segments === array()) {
            $blocks[$head] = $mutator($blocks[$head]);
            return array_values($blocks);
        }
        $children = array_values(array_filter((array) ($blocks[$head]['innerBlocks'] ?? array()), 'is_array'));
        $blocks[$head]['innerBlocks'] = $this->updateAtSegments($children, $segments, $mutator);
        return array_values($blocks);
    }

    /** @param array<int,array<string,mixed>> $blocks @param array<int,int> $parentSegments @return array<int,array<string,mixed>> */
    private function reorderWithinParent(array $blocks, array $parentSegments, int $fromIndex, int $toIndex): array
    {
        if ($parentSegments === array()) {
            if (!isset($blocks[$fromIndex])) {
                throw new \InvalidArgumentException('Block path is out of range.');
            }
            $moving = $blocks[$fromIndex];
            array_splice($blocks, $fromIndex, 1);
            $toIndex = max(0, min(count($blocks), $toIndex));
            array_splice($blocks, $toIndex, 0, array($moving));
            return array_values($blocks);
        }
        $head = (int) array_shift($parentSegments);
        if (!isset($blocks[$head])) {
            throw new \InvalidArgumentException('Parent block path is out of range.');
        }
        $children = array_values(array_filter((array) ($blocks[$head]['innerBlocks'] ?? array()), 'is_array'));
        $blocks[$head]['innerBlocks'] = $this->reorderWithinParent($children, $parentSegments, $fromIndex, $toIndex);
        // Child count is unchanged, so preserve the parent's innerContent byte-for-byte.
        return array_values($blocks);
    }

    private function assertSafeMarkup(string $content): void
    {
        if (strlen($content) > 2 * 1024 * 1024) {
            throw new \InvalidArgumentException('Presentation block markup exceeds the 2 MiB safety limit.');
        }
        if (!str_contains($content, '<!-- wp:') && !str_contains($content, '<!-- /wp:')) {
            return;
        }
        preg_match_all('/<!--\s*(\/)?wp:([\w\/-]+)(?:\s+\{.*?\})?\s*(\/)?\s*-->/s', $content, $matches, PREG_SET_ORDER);
        $stack = array();
        foreach ($matches as $match) {
            $closing = '/' === trim((string) ($match[1] ?? ''));
            $name = (string) ($match[2] ?? '');
            $selfClosing = '/' === trim((string) ($match[3] ?? '')) || str_ends_with(trim((string) ($match[0] ?? '')), '/-->');
            if ($closing) {
                if (array_pop($stack) !== $name) {
                    throw new \InvalidArgumentException('Unbalanced Gutenberg block delimiters.');
                }
            } elseif (!$selfClosing) {
                $stack[] = $name;
            }
        }
        if ($stack !== array()) {
            throw new \InvalidArgumentException('Unbalanced Gutenberg block delimiters.');
        }
    }

}
