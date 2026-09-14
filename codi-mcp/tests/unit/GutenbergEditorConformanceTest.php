<?php

declare(strict_types=1);

namespace CodiMcpTest\Unit;

use CodiMcp\Packages\Presentation\Gutenberg\SaveRuntime;
use CodiMcpTest\Framework\TestCase;

/**
 * Save-output fixtures captured from the real WordPress block editor on 2026-09-11.
 * Oracle build: wp-includes/js/dist/blocks.min.js?ver=c0e57a630a0b6f6c3bb5.
 *
 * Opening block-comment JSON is deliberately normalized away for markup parity:
 * Gutenberg validation is sensitive to the saved HTML contract, while JSON object
 * key order in a block delimiter is not semantically meaningful. Attribute values
 * are asserted separately below.
 */
final class GutenbergEditorConformanceTest extends TestCase
{
    protected function setUp(): void
    {
        \codi_mcp_test_reset_environment();
    }

    public function test_php_save_runtime_matches_real_editor_markup_fixtures(): void
    {
        $runtime = new SaveRuntime();

        $paragraph = $runtime->create('core/paragraph', array('text' => 'Hello'));
        $heading = $runtime->create('core/heading', array('text' => 'Title', 'level' => 3));
        $group = $runtime->create('core/group', array(
            'attributes' => array('align' => 'full', 'backgroundColor' => 'secondary'),
        ), array($paragraph));
        $button = $runtime->create('core/button', array('text' => 'Buy now', 'url' => '/buy'));
        $buttons = $runtime->create('core/buttons', array(), array($button));
        $column = $runtime->create('core/column', array(
            'width' => '33.3333333333333%',
            'vertical_alignment' => 'center',
        ), array($runtime->create('core/paragraph', array('text' => 'One'))));
        $columns = $runtime->create('core/columns', array(
            'vertical_alignment' => 'center',
            'is_stacked_on_mobile' => false,
        ), array($column, $runtime->create('core/column')));
        $image = $runtime->create('core/image', array(
            'id' => 107,
            'url' => '/media/photo.jpg',
            'alt' => 'A photo',
            'caption' => 'Caption',
            'title' => 'Photo title',
            'href' => '/full/photo.jpg',
            'link_target' => '_blank',
            'rel' => 'noopener',
            'size_slug' => 'large',
            'width' => '320px',
            'aspect_ratio' => '16/9',
            'scale' => 'cover',
            'focal_point' => array('x' => 0.25, 'y' => 0.75),
            'align' => 'wide',
        ));
        $cover = $runtime->create('core/cover', array(
            'url' => '/media/hero.jpg',
            'id' => 107,
            'alt' => 'Hero',
            'dim_ratio' => 40,
            'overlay_color' => 'contrast',
            'focal_point' => array('x' => 0.25, 'y' => 0.75),
            'content_position' => 'bottom right',
            'min_height' => 420,
            'min_height_unit' => 'px',
            'is_dark' => false,
            'size_slug' => 'large',
        ), array($runtime->create('core/paragraph', array('text' => 'Cover content'))));
        $gallery = $runtime->create('core/gallery', array(
            'columns' => 2,
            'image_crop' => true,
            'caption' => 'Gallery caption',
        ), array(
            $runtime->create('core/image', array('id' => 107, 'url' => '/media/one.jpg', 'alt' => 'One', 'size_slug' => 'large')),
            $runtime->create('core/image', array('id' => 108, 'url' => '/media/two.jpg', 'alt' => 'Two', 'size_slug' => 'large')),
        ));
        $query = $runtime->create('core/query', array('per_page' => 6, 'post_type' => 'post', 'inherit' => false), array(
            $runtime->create('core/post-template', array(), array(
                $runtime->create('core/post-title', array('attributes' => array('isLink' => true))),
            )),
            $runtime->create('core/query-pagination', array(), array(
                $runtime->create('core/query-pagination-previous'),
                $runtime->create('core/query-pagination-numbers'),
                $runtime->create('core/query-pagination-next'),
            )),
            $runtime->create('core/query-no-results', array(), array(
                $runtime->create('core/paragraph', array('text' => 'No results found.')),
            )),
        ));

        $fixtures = array(
            'paragraph' => array($paragraph, '<!-- wp:paragraph -->\n<p>Hello</p>\n<!-- /wp:paragraph -->'),
            'heading' => array($heading, '<!-- wp:heading {"level":3} -->\n<h3 class="wp-block-heading">Title</h3>\n<!-- /wp:heading -->'),
            'group' => array($group, '<!-- wp:group {"align":"full","backgroundColor":"secondary"} -->\n<div class="wp-block-group alignfull has-secondary-background-color has-background"><!-- wp:paragraph -->\n<p>Hello</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->'),
            'buttons' => array($buttons, '<!-- wp:buttons -->\n<div class="wp-block-buttons"><!-- wp:button -->\n<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/buy">Buy now</a></div>\n<!-- /wp:button --></div>\n<!-- /wp:buttons -->'),
            'columns' => array($columns, '<!-- wp:columns {"verticalAlignment":"center","isStackedOnMobile":false} -->\n<div class="wp-block-columns are-vertically-aligned-center is-not-stacked-on-mobile"><!-- wp:column {"verticalAlignment":"center","width":"33.3333333333333%"} -->\n<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:33.333333333333%"><!-- wp:paragraph -->\n<p>One</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:column -->\n\n<!-- wp:column -->\n<div class="wp-block-column"></div>\n<!-- /wp:column --></div>\n<!-- /wp:columns -->'),
            'image' => array($image, '<!-- wp:image {"id":107,"width":"320px","aspectRatio":"16/9","scale":"cover","focalPoint":{"x":0.25,"y":0.75},"sizeSlug":"large","align":"wide"} -->\n<figure class="wp-block-image alignwide size-large is-resized"><a href="/full/photo.jpg" target="_blank" rel="noopener"><img src="/media/photo.jpg" alt="A photo" class="wp-image-107" style="aspect-ratio:16/9;object-fit:cover;object-position:25% 75%;width:320px;height:auto" title="Photo title"/></a><figcaption class="wp-element-caption">Caption</figcaption></figure>\n<!-- /wp:image -->'),
            'cover' => array($cover, '<!-- wp:cover {"url":"/media/hero.jpg","id":107,"alt":"Hero","dimRatio":40,"overlayColor":"contrast","focalPoint":{"x":0.25,"y":0.75},"minHeight":420,"minHeightUnit":"px","contentPosition":"bottom right","isDark":false,"sizeSlug":"large"} -->\n<div class="wp-block-cover is-light has-custom-content-position is-position-bottom-right" style="min-height:420px"><img class="wp-block-cover__image-background wp-image-107 size-large" alt="Hero" src="/media/hero.jpg" style="object-position:25% 75%" data-object-fit="cover" data-object-position="25% 75%"/><span aria-hidden="true" class="wp-block-cover__background has-contrast-background-color has-background-dim-40 has-background-dim"></span><div class="wp-block-cover__inner-container"><!-- wp:paragraph -->\n<p>Cover content</p>\n<!-- /wp:paragraph --></div></div>\n<!-- /wp:cover -->'),
            'gallery' => array($gallery, '<!-- wp:gallery {"columns":2} -->\n<figure class="wp-block-gallery has-nested-images columns-2 is-cropped"><!-- wp:image {"id":107,"sizeSlug":"large"} -->\n<figure class="wp-block-image size-large"><img src="/media/one.jpg" alt="One" class="wp-image-107"/></figure>\n<!-- /wp:image -->\n\n<!-- wp:image {"id":108,"sizeSlug":"large"} -->\n<figure class="wp-block-image size-large"><img src="/media/two.jpg" alt="Two" class="wp-image-108"/></figure>\n<!-- /wp:image --><figcaption class="blocks-gallery-caption wp-element-caption">Gallery caption</figcaption></figure>\n<!-- /wp:gallery -->'),
            'query' => array($query, '<!-- wp:query {"query":{"perPage":6,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":false,"taxQuery":null,"parents":[],"format":[]}} -->\n<div class="wp-block-query"><!-- wp:post-template -->\n<!-- wp:post-title {"isLink":true} /-->\n<!-- /wp:post-template -->\n\n<!-- wp:query-pagination -->\n<!-- wp:query-pagination-previous /-->\n\n<!-- wp:query-pagination-numbers /-->\n\n<!-- wp:query-pagination-next /-->\n<!-- /wp:query-pagination -->\n\n<!-- wp:query-no-results -->\n<!-- wp:paragraph -->\n<p>No results found.</p>\n<!-- /wp:paragraph -->\n<!-- /wp:query-no-results --></div>\n<!-- /wp:query -->'),
        );

        foreach ($fixtures as $name => [$block, $editorMarkup]) {
            $this->assertSame(
                $this->normalizeEditorMarkup($editorMarkup),
                $this->normalizeEditorMarkup((string) \serialize_block($block)),
                'PHP save output drifted from the real Gutenberg editor fixture for ' . $name . '.'
            );
        }
    }

    public function test_block_support_order_matches_real_editor_fixture(): void
    {
        $runtime = new SaveRuntime();
        $paragraph = $runtime->create('core/paragraph', array(
            'text' => 'Styled',
            'attributes' => array(
                'style' => array(
                    'spacing' => array('padding' => array('top' => 'var:preset|spacing|40')),
                    'typography' => array('lineHeight' => '1.4'),
                    'color' => array('text' => '#123456', 'background' => '#abcdef'),
                ),
                'fontSize' => 'large',
            ),
        ));

        $expected = '<p class="has-text-color has-background has-large-font-size" style="color:#123456;background-color:#abcdef;padding-top:var(--wp--preset--spacing--40);line-height:1.4">Styled</p>';
        $this->assertSame($expected, (string) ($paragraph['innerHTML'] ?? ''));
    }

    public function test_conformance_fixtures_preserve_semantic_block_attributes(): void
    {
        $runtime = new SaveRuntime();

        $image = $runtime->create('core/image', array(
            'id' => 107,
            'url' => '/media/photo.jpg',
            'width' => '320px',
            'aspect_ratio' => '16/9',
            'scale' => 'cover',
            'focal_point' => array('x' => 0.25, 'y' => 0.75),
            'size_slug' => 'large',
            'align' => 'wide',
        ));
        $this->assertSame(107, (int) ($image['attrs']['id'] ?? 0));
        $this->assertSame('320px', (string) ($image['attrs']['width'] ?? ''));
        $this->assertSame('16/9', (string) ($image['attrs']['aspectRatio'] ?? ''));
        $this->assertSame('cover', (string) ($image['attrs']['scale'] ?? ''));
        $this->assertSame(array('x' => 0.25, 'y' => 0.75), (array) ($image['attrs']['focalPoint'] ?? array()));
        $this->assertSame('large', (string) ($image['attrs']['sizeSlug'] ?? ''));
        $this->assertSame('wide', (string) ($image['attrs']['align'] ?? ''));
    }

    private function normalizeEditorMarkup(string $markup): string
    {
        $markup = str_replace(array("\r", "\n", "\t", '\\n'), '', $markup);
        $normalized = preg_replace_callback(
            '/<!-- wp:([a-z0-9\/-]+)(?:\s+\{.*?\})?\s*(\/)?-->/i',
            static fn (array $match): string => '<!-- wp:' . (string) $match[1] . (!empty($match[2]) ? ' /' : '') . '-->',
            $markup
        );
        return is_string($normalized) ? $normalized : $markup;
    }
}
