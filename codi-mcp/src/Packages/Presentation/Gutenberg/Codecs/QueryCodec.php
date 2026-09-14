<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation\Gutenberg\Codecs;

use CodiMcp\Packages\Presentation\Gutenberg\Html;

/** Current core/query wrapper save contract plus bounded semantic query parameters. */
final class QueryCodec extends AbstractCodec
{
    public function name(): string { return 'core/query'; }
    public function capabilities(): array { return array('create','insert_child','remove_child','reorder_child','update_query'); }
    public function supportsChildren(): bool { return true; }

    public function assertChildAllowed(string $blockName): void
    {
        $blockName = strtolower(trim($blockName));
        if (!in_array($blockName, array('core/post-template','core/query-pagination','core/query-no-results','core/query-total'), true)) {
            throw new \InvalidArgumentException(sprintf('core/query does not accept child block [%s] through its Presentation codec.', $blockName));
        }
    }

    public function create(array $state, array $innerBlocks = array()): array
    {
        $attrs = is_array($state['attributes'] ?? null) ? $state['attributes'] : array();
        $attrs = $this->applySemanticState($attrs, $state, true);
        return $this->save($attrs, $innerBlocks);
    }

    public function rewrite(array $block, array $changes = array(), ?array $innerBlocks = null): array
    {
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        $attrs = $this->applySemanticState($attrs, $changes, false);
        return $this->save($attrs, null === $innerBlocks ? array_values((array) ($block['innerBlocks'] ?? array())) : $innerBlocks);
    }

    /** @param array<string,mixed> $attrs @param array<string,mixed> $state @return array<string,mixed> */
    private function applySemanticState(array $attrs, array $state, bool $create): array
    {
        if ($create && !isset($attrs['query'])) {
            $attrs['query'] = $this->defaultQuery();
        }
        $query = is_array($attrs['query'] ?? null) ? $attrs['query'] : $this->defaultQuery();
        $map = array(
            'per_page' => 'perPage',
            'post_type' => 'postType',
            'order' => 'order',
            'order_by' => 'orderBy',
            'offset' => 'offset',
            'search' => 'search',
            'inherit' => 'inherit',
            'sticky' => 'sticky',
        );
        foreach ($map as $field => $key) {
            if (array_key_exists($field, $state)) {
                $query[$key] = $state[$field];
            }
        }
        if ($create || array_intersect(array_keys($map), array_keys($state)) !== array()) {
            $attrs['query'] = $this->normalizeQuery($query);
        }
        return $attrs;
    }

    /** @param array<string,mixed> $attrs @param array<int,array<string,mixed>> $children @return array<string,mixed> */
    private function save(array $attrs, array $children): array
    {
        $this->assertAllowedAttributes($attrs, array('queryId','query','tagName','align','anchor','className','metadata','lock'));
        $tag = strtolower(trim((string) ($attrs['tagName'] ?? 'div')));
        if (!in_array($tag, array('div','section','main','aside'), true)) {
            throw new \InvalidArgumentException('Unsupported core/query tagName for PHP save codec.');
        }
        if (isset($attrs['query']) && !is_array($attrs['query'])) {
            throw new \InvalidArgumentException('core/query query attribute must be an object.');
        }
        $wrapper = $this->supports->wrapper($attrs, array('wp-block-query'), true, false, false);
        $open = '<' . $tag . Html::attributes($wrapper) . '>';
        $segments = array($open);
        foreach ($children as $child) {
            $this->assertChildAllowed((string) ($child['blockName'] ?? ''));
            $segments[] = null;
            $segments[] = '';
        }
        $segments[count($segments) - 1] .= '</' . $tag . '>';
        return $this->block($attrs, $children, $open . '</' . $tag . '>', $segments);
    }

    /** @return array<string,mixed> */
    private function defaultQuery(): array
    {
        return array(
            'author' => '',
            'exclude' => array(),
            'format' => array(),
            'inherit' => false,
            'offset' => 0,
            'order' => 'desc',
            'orderBy' => 'date',
            'pages' => 0,
            'parents' => array(),
            'perPage' => 6,
            'postType' => 'post',
            'search' => '',
            'sticky' => '',
            'taxQuery' => null,
        );
    }

    /** @param array<string,mixed> $query @return array<string,mixed> */
    private function normalizeQuery(array $query): array
    {
        $perPage = (int) ($query['perPage'] ?? 6);
        if ($perPage < 1 || $perPage > 100) { throw new \InvalidArgumentException('Query per_page must be between 1 and 100.'); }
        $query['perPage'] = $perPage;
        $offset = (int) ($query['offset'] ?? 0);
        if ($offset < 0 || $offset > 10000) { throw new \InvalidArgumentException('Query offset must be between 0 and 10000.'); }
        $query['offset'] = $offset;
        $postType = strtolower(trim((string) ($query['postType'] ?? 'post')));
        if ('' === $postType || !preg_match('/^[a-z0-9_-]{1,64}$/', $postType)) { throw new \InvalidArgumentException('Query post_type is invalid.'); }
        $query['postType'] = $postType;
        $order = strtolower(trim((string) ($query['order'] ?? 'desc')));
        if (!in_array($order, array('asc','desc'), true)) { throw new \InvalidArgumentException('Query order must be asc or desc.'); }
        $query['order'] = $order;
        $orderBy = strtolower(trim((string) ($query['orderBy'] ?? 'date')));
        if (!in_array($orderBy, array('date','title','modified','menu_order','author','comment_count','rand'), true)) {
            throw new \InvalidArgumentException('Query order_by is unsupported.');
        }
        $query['orderBy'] = $orderBy;
        $sticky = strtolower(trim((string) ($query['sticky'] ?? '')));
        if (!in_array($sticky, array('','only','exclude'), true)) { throw new \InvalidArgumentException('Query sticky must be empty, only, or exclude.'); }
        $query['sticky'] = $sticky;
        $query['search'] = (string) ($query['search'] ?? '');
        if (strlen($query['search']) > 500) { throw new \InvalidArgumentException('Query search is too long.'); }
        $query['inherit'] = (bool) ($query['inherit'] ?? false);
        return $query;
    }
}
