<?php

declare(strict_types=1);

namespace CodiMcpTest\Unit;

use CodiMcp\Packages\Presentation\BlockEditor;
use CodiMcp\Packages\Presentation\Gutenberg\BlockCodecRegistry;
use CodiMcp\Packages\Presentation\Gutenberg\SaveRuntime;
use CodiMcp\Packages\Presentation\PresentationManager;
use CodiMcp\Packages\Presentation\RefCodec;
use CodiMcpTest\Framework\TestCase;

final class GutenbergSaveRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        \codi_mcp_test_reset_environment();
        $GLOBALS['codi_mcp_test_wp_current_user_id'] = 7;
        $page = \get_post(101);
        if (is_object($page)) {
            $page->post_content = '<!-- wp:paragraph --><p>Welcome.</p><!-- /wp:paragraph -->';
        }
    }

    public function test_registry_exposes_only_explicit_php_save_codecs(): void
    {
        $registry = new BlockCodecRegistry();
        $this->assertSame(array('core/paragraph','core/heading','core/group','core/buttons','core/button','core/columns','core/column','core/image','core/cover','core/gallery','core/list','core/list-item','core/navigation-link','core/navigation-submenu','core/query','core/post-template','core/query-pagination','core/query-no-results','core/post-title','core/query-pagination-previous','core/query-pagination-numbers','core/query-pagination-next'), $registry->supportedBlocks());
        $this->assertContains('update_link', $registry->capabilities('core/button'));
        $this->assertContains('update_width', $registry->capabilities('core/column'));
        $this->assertContains('update_media', $registry->capabilities('core/image'));
        $this->assertContains('update_query', $registry->capabilities('core/query'));
    }

    public function test_paragraph_heading_group_and_button_generate_expected_core_markup(): void
    {
        $runtime = new SaveRuntime();
        $paragraph = $runtime->create('core/paragraph', array('text' => 'Hello'));
        $this->assertSame('<p>Hello</p>', (string) ($paragraph['innerHTML'] ?? ''));

        $heading = $runtime->create('core/heading', array('text' => 'Title', 'level' => 3));
        $this->assertSame(3, (int) ($heading['attrs']['level'] ?? 0));
        $this->assertSame('<h3 class="wp-block-heading">Title</h3>', (string) ($heading['innerHTML'] ?? ''));

        $group = $runtime->create('core/group', array('attributes' => array('align' => 'full', 'backgroundColor' => 'secondary')), array($paragraph));
        $serializedGroup = \serialize_block($group);
        $this->assertTrue(str_contains($serializedGroup, '<div class="wp-block-group alignfull has-secondary-background-color has-background">'));
        $this->assertTrue(str_contains($serializedGroup, '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->'));

        $button = $runtime->create('core/button', array('text' => 'Buy now', 'url' => '/buy'));
        $this->assertSame('<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/buy">Buy now</a></div>', (string) ($button['innerHTML'] ?? ''));
    }

    public function test_group_codec_unlocks_nested_edits_and_bounded_block_support_styles(): void
    {
        $editor = new BlockEditor();
        $content = $editor->insertGroup('', 0);
        $content = $editor->insertTextBlock($content, 0, 'heading', 'Nested', 2, '0');
        $this->assertTrue(str_contains($content, '<h2 class="wp-block-heading">Nested</h2>'));
        $content = $editor->remove($content, '0.0');
        $this->assertTrue(str_contains($content, '<div class="wp-block-group"></div>'));

        $supported = '<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|20","right":"1rem","bottom":"2rem","left":"1rem"}},"dimensions":{"minHeight":"20rem"},"color":{"background":"#112233"}}} --><div class="wp-block-group has-background" style="background-color:#112233;min-height:20rem;padding-top:var(--wp--preset--spacing--20);padding-right:1rem;padding-bottom:2rem;padding-left:1rem"></div><!-- /wp:group -->';
        $updated = $editor->insertTextBlock($supported, 0, 'paragraph', 'Supported styles', 2, '0');
        $this->assertTrue(str_contains($updated, 'background-color:#112233;min-height:20rem;padding-top:var(--wp--preset--spacing--20)'));

        $unsupported = '<!-- wp:group {"style":{"border":{"radius":"8px"}}} --><div class="wp-block-group"></div><!-- /wp:group -->';
        $this->expectException(fn () => $editor->insertTextBlock($unsupported, 0, 'paragraph', 'No approximation', 2, '0'), 'style.border');
    }

    public function test_button_semantic_update_regenerates_complete_supported_markup(): void
    {
        $editor = new BlockEditor();
        $content = $editor->insertButton('', 0, '', array('text' => 'Old', 'url' => '/old'));
        $this->assertTrue(str_contains($content, '<!-- wp:buttons -->'));
        $this->assertTrue(str_contains($content, 'href="/old"'));

        $content = $editor->updateButton($content, '0.0', array('text' => 'New', 'url' => '/new', 'link_target' => '_blank', 'rel' => 'noopener'));
        $this->assertTrue(str_contains($content, '<a class="wp-block-button__link wp-element-button" href="/new" target="_blank" rel="noopener">New</a>'));
    }

    public function test_columns_and_column_follow_current_core_save_contract(): void
    {
        $runtime = new SaveRuntime();
        $paragraph = $runtime->create('core/paragraph', array('text' => 'One'));
        $column = $runtime->create('core/column', array('width' => '33.3333333333333%', 'vertical_alignment' => 'center'), array($paragraph));
        $this->assertSame('<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:33.333333333333%"></div>', (string) ($column['innerHTML'] ?? ''));

        $columns = $runtime->create('core/columns', array('vertical_alignment' => 'center', 'is_stacked_on_mobile' => false), array($column, $runtime->create('core/column')));
        $serialized = \serialize_block($columns);
        $this->assertTrue(str_contains($serialized, '<div class="wp-block-columns are-vertically-aligned-center is-not-stacked-on-mobile">'));
        $this->assertTrue(str_contains($serialized, 'style="flex-basis:33.333333333333%"'));
        $this->assertTrue(str_contains($serialized, '<!-- wp:column -->'));
    }

    public function test_image_codec_matches_current_core_structure_and_rewrites_sourced_fields(): void
    {
        $runtime = new SaveRuntime();
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
        $this->assertSame(
            '<figure class="wp-block-image alignwide size-large is-resized"><a href="/full/photo.jpg" target="_blank" rel="noopener"><img src="/media/photo.jpg" alt="A photo" class="wp-image-107" style="aspect-ratio:16/9;object-fit:cover;object-position:25% 75%;width:320px;height:auto" title="Photo title"/></a><figcaption class="wp-element-caption">Caption</figcaption></figure>',
            (string) ($image['innerHTML'] ?? '')
        );

        $rewritten = $runtime->rewrite($image, array('alt' => 'Updated alt', 'caption' => 'Updated caption', 'href' => ''));
        $html = (string) ($rewritten['innerHTML'] ?? '');
        $this->assertTrue(str_contains($html, 'alt="Updated alt"'));
        $this->assertTrue(str_contains($html, '>Updated caption</figcaption>'));
        $this->assertFalse(str_contains($html, '<a '));
        $this->assertSame(107, (int) ($rewritten['attrs']['id'] ?? 0));
    }

    public function test_cover_codec_matches_current_image_background_save_contract(): void
    {
        $runtime = new SaveRuntime();
        $child = $runtime->create('core/paragraph', array('text' => 'Cover content'));
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
        ), array($child));
        $serialized = \serialize_block($cover);
        $this->assertTrue(str_contains($serialized, '<div class="wp-block-cover is-light has-custom-content-position is-position-bottom-right" style="min-height:420px">'));
        $this->assertTrue(str_contains($serialized, '<img class="wp-block-cover__image-background wp-image-107 size-large" alt="Hero" src="/media/hero.jpg" style="object-position:25% 75%" data-object-fit="cover" data-object-position="25% 75%"/>'));
        $this->assertTrue(str_contains($serialized, '<span aria-hidden="true" class="wp-block-cover__background has-contrast-background-color has-background-dim-40 has-background-dim"></span>'));
        $this->assertTrue(str_contains($serialized, '<div class="wp-block-cover__inner-container"><!-- wp:paragraph --><p>Cover content</p><!-- /wp:paragraph --></div>'));

        $this->expectException(fn () => $runtime->create('core/cover', array('url' => '/movie.mp4', 'background_type' => 'video')), 'image backgrounds only');
    }

    public function test_gallery_codec_matches_current_nested_image_save_contract(): void
    {
        $runtime = new SaveRuntime();
        $first = $runtime->create('core/image', array('id' => 107, 'url' => '/media/one.jpg', 'alt' => 'One', 'size_slug' => 'large'));
        $second = $runtime->create('core/image', array('id' => 108, 'url' => '/media/two.jpg', 'alt' => 'Two', 'size_slug' => 'large'));
        $gallery = $runtime->create('core/gallery', array('columns' => 2, 'image_crop' => true, 'caption' => 'Gallery caption'), array($first, $second));
        $serialized = \serialize_block($gallery);
        $this->assertTrue(str_contains($serialized, '<figure class="wp-block-gallery has-nested-images columns-2 is-cropped">'));
        $this->assertTrue(str_contains($serialized, '<!-- wp:image {"id":107,"sizeSlug":"large"} -->'));
        $this->assertTrue(str_contains($serialized, '<figcaption class="blocks-gallery-caption wp-element-caption">Gallery caption</figcaption>'));

        $rewritten = $runtime->rewrite($gallery, array('columns' => 3, 'image_crop' => false, 'caption' => 'Updated gallery'));
        $rewrittenMarkup = \serialize_block($rewritten);
        $this->assertTrue(str_contains($rewrittenMarkup, 'has-nested-images columns-3'));
        $this->assertFalse(str_contains($rewrittenMarkup, 'is-cropped'));
        $this->assertTrue(str_contains($rewrittenMarkup, '>Updated gallery</figcaption>'));
    }

    public function test_query_loop_codecs_generate_bounded_current_core_structure(): void
    {
        $runtime = new SaveRuntime();
        $postTitle = $runtime->create('core/post-title', array('attributes' => array('isLink' => true)));
        $postTemplate = $runtime->create('core/post-template', array(), array($postTitle));
        $pagination = $runtime->create('core/query-pagination', array(), array(
            $runtime->create('core/query-pagination-previous'),
            $runtime->create('core/query-pagination-numbers'),
            $runtime->create('core/query-pagination-next'),
        ));
        $noResults = $runtime->create('core/query-no-results', array(), array($runtime->create('core/paragraph', array('text' => 'No results found.'))));
        $query = $runtime->create('core/query', array('per_page' => 6, 'post_type' => 'post', 'inherit' => false), array($postTemplate, $pagination, $noResults));
        $serialized = \serialize_block($query);

        $this->assertTrue(str_contains($serialized, '<div class="wp-block-query">'));
        $this->assertTrue(str_contains($serialized, '<!-- wp:post-title {"isLink":true} /-->'));
        $this->assertTrue(str_contains($serialized, '<!-- wp:query-pagination-previous /-->'));
        $this->assertTrue(str_contains($serialized, '<!-- wp:query-pagination-numbers /-->'));
        $this->assertTrue(str_contains($serialized, '<!-- wp:query-pagination-next /-->'));
        $this->assertTrue(str_contains($serialized, '>No results found.</p>'));

        $rewritten = $runtime->rewrite($query, array('per_page' => 12, 'order' => 'asc', 'order_by' => 'title'));
        $queryAttrs = (array) ($rewritten['attrs']['query'] ?? array());
        $this->assertSame(12, (int) ($queryAttrs['perPage'] ?? 0));
        $this->assertSame('asc', (string) ($queryAttrs['order'] ?? ''));
        $this->assertSame('title', (string) ($queryAttrs['orderBy'] ?? ''));
    }

    public function test_preview_can_build_nested_group_and_button_without_writing_until_commit(): void
    {
        $manager = new PresentationManager();
        $ref = (new RefCodec())->encode('page', 101);
        $original = (string) (\get_post(101)->post_content ?? '');
        $preview = $manager->preview(array(
            'ref' => $ref,
            'operations' => array(
                array('action' => 'blocks.insert_group', 'index' => 1),
                array('action' => 'content.insert_text', 'parent_path' => '1', 'index' => 0, 'type' => 'paragraph', 'text' => 'Inside group'),
                array('action' => 'content.insert_button', 'parent_path' => '1', 'index' => 1, 'text' => 'Read more', 'url' => '/more'),
            ),
        ));
        $this->assertSame($original, (string) (\get_post(101)->post_content ?? ''));
        $proposed = (string) ($preview['proposed_state']['content'] ?? '');
        $this->assertTrue(str_contains($proposed, 'Inside group'));
        $this->assertTrue(str_contains($proposed, 'href="/more"'));
        $manager->commit(array('preview_id' => (string) $preview['preview_id']));
        $this->assertSame($proposed, (string) (\get_post(101)->post_content ?? ''));
    }

    public function test_preview_can_build_columns_and_image_without_writing_until_commit(): void
    {
        $manager = new PresentationManager();
        $ref = (new RefCodec())->encode('page', 101);
        $original = (string) (\get_post(101)->post_content ?? '');
        $preview = $manager->preview(array(
            'ref' => $ref,
            'operations' => array(
                array('action' => 'blocks.insert_columns', 'index' => 1, 'count' => 2, 'is_stacked_on_mobile' => false),
                array('action' => 'content.insert_text', 'parent_path' => '1.0', 'index' => 0, 'type' => 'paragraph', 'text' => 'First column'),
                array('action' => 'blocks.update_column', 'path' => '1.0', 'width' => '40%'),
                array('action' => 'content.insert_image', 'parent_path' => '1.1', 'index' => 0, 'id' => 107, 'url' => '/media/photo.jpg', 'alt' => 'Preview image', 'caption' => 'Second column image', 'width' => '240px'),
                array('action' => 'blocks.insert_cover', 'index' => 2, 'url' => '/media/hero.jpg', 'id' => 107, 'alt' => 'Hero', 'dim_ratio' => 40),
                array('action' => 'content.insert_text', 'parent_path' => '2', 'index' => 0, 'type' => 'heading', 'level' => 2, 'text' => 'Cover heading'),
                array('action' => 'blocks.update_cover', 'path' => '2', 'content_position' => 'bottom right', 'min_height' => 360, 'min_height_unit' => 'px'),
            ),
        ));
        $this->assertSame($original, (string) (\get_post(101)->post_content ?? ''), 'Preview must not write Columns/Image composition.');
        $proposed = (string) ($preview['proposed_state']['content'] ?? '');
        $this->assertTrue(str_contains($proposed, '<!-- wp:columns {"isStackedOnMobile":false} -->'));
        $this->assertTrue(str_contains($proposed, 'style="flex-basis:40%"'));
        $this->assertTrue(str_contains($proposed, '>First column</p>'));
        $this->assertTrue(str_contains($proposed, 'class="wp-image-107"'));
        $this->assertTrue(str_contains($proposed, '>Second column image</figcaption>'));
        $this->assertTrue(str_contains($proposed, 'class="wp-block-cover has-custom-content-position is-position-bottom-right" style="min-height:360px"'));
        $this->assertTrue(str_contains($proposed, '>Cover heading</h2>'));

        $commit = $manager->commit(array('preview_id' => (string) $preview['preview_id']));
        $this->assertTrue('' !== (string) ($commit['transaction_id'] ?? ''));
        $this->assertSame($proposed, (string) (\get_post(101)->post_content ?? ''));
    }

    public function test_preview_can_build_gallery_with_nested_images_without_writing_until_commit(): void
    {
        $manager = new PresentationManager();
        $ref = (new RefCodec())->encode('page', 101);
        $original = (string) (\get_post(101)->post_content ?? '');
        $preview = $manager->preview(array(
            'ref' => $ref,
            'operations' => array(
                array('action' => 'blocks.insert_gallery', 'index' => 1, 'columns' => 2, 'caption' => 'Gallery caption'),
                array('action' => 'content.insert_image', 'parent_path' => '1', 'index' => 0, 'id' => 107, 'url' => '/media/one.jpg', 'alt' => 'One', 'size_slug' => 'large'),
                array('action' => 'content.insert_image', 'parent_path' => '1', 'index' => 1, 'id' => 108, 'url' => '/media/two.jpg', 'alt' => 'Two', 'size_slug' => 'large'),
                array('action' => 'blocks.update_gallery', 'path' => '1', 'columns' => 3, 'image_crop' => false, 'caption' => 'Updated gallery'),
            ),
        ));
        $this->assertSame($original, (string) (\get_post(101)->post_content ?? ''), 'Gallery preview must not write WordPress.');
        $proposed = (string) ($preview['proposed_state']['content'] ?? '');
        $this->assertTrue(str_contains($proposed, '<!-- wp:gallery {"columns":3,"imageCrop":false} -->'));
        $this->assertTrue(str_contains($proposed, 'class="wp-block-gallery has-nested-images columns-3"'));
        $this->assertTrue(str_contains($proposed, 'class="wp-image-107"'));
        $this->assertTrue(str_contains($proposed, 'class="wp-image-108"'));
        $this->assertTrue(str_contains($proposed, '>Updated gallery</figcaption>'));

        $manager->commit(array('preview_id' => (string) $preview['preview_id']));
        $this->assertSame($proposed, (string) (\get_post(101)->post_content ?? ''));
    }

    public function test_preview_can_insert_and_update_query_loop_without_writing_until_commit(): void
    {
        $manager = new PresentationManager();
        $ref = (new RefCodec())->encode('page', 101);
        $original = (string) (\get_post(101)->post_content ?? '');
        $preview = $manager->preview(array(
            'ref' => $ref,
            'operations' => array(
                array('action' => 'query.insert', 'index' => 1, 'per_page' => 6, 'post_type' => 'post'),
                array('action' => 'query.update', 'path' => '1', 'per_page' => 9, 'order' => 'asc', 'order_by' => 'title'),
            ),
        ));
        $this->assertSame($original, (string) (\get_post(101)->post_content ?? ''), 'Query preview must not write WordPress.');
        $proposed = (string) ($preview['proposed_state']['content'] ?? '');
        $this->assertTrue(str_contains($proposed, '<!-- wp:query '));
        $this->assertTrue(str_contains($proposed, '"perPage":9'));
        $this->assertTrue(str_contains($proposed, '"order":"asc"'));
        $this->assertTrue(str_contains($proposed, '<!-- wp:post-title {"isLink":true} /-->'));
        $this->assertTrue(str_contains($proposed, '<!-- wp:query-pagination-numbers /-->'));
        $this->assertTrue(str_contains($proposed, '>No results found.</p>'));

        $manager->commit(array('preview_id' => (string) $preview['preview_id']));
        $this->assertSame($proposed, (string) (\get_post(101)->post_content ?? ''));
    }

    public function test_navigation_entries_use_current_innerblocks_contract_without_generic_attributes(): void
    {
        $manager = new PresentationManager();
        $ref = (new RefCodec())->encode('wp_navigation', 104);
        $original = (string) (\get_post(104)->post_content ?? '');
        $preview = $manager->preview(array(
            'ref' => $ref,
            'operations' => array(
                array('action' => 'navigation.insert_entry', 'index' => 3, 'item_type' => 'submenu', 'label' => 'Resources', 'url' => '/resources'),
                array('action' => 'navigation.insert_entry', 'parent_path' => '3', 'index' => 0, 'item_type' => 'link', 'label' => 'Blog', 'url' => '/blog'),
                array('action' => 'navigation.update_entry', 'path' => '1', 'label' => 'Documentation', 'url' => '/documentation', 'opens_in_new_tab' => true),
            ),
        ));
        $this->assertSame($original, (string) (\get_post(104)->post_content ?? ''), 'Navigation preview must not write WordPress.');
        $proposed = (string) ($preview['proposed_state']['content'] ?? '');
        $this->assertTrue(str_contains($proposed, '<!-- wp:navigation-submenu {"label":"Resources","url":"/resources"} -->'));
        $this->assertTrue(str_contains($proposed, '<!-- wp:navigation-link {"label":"Blog","url":"/blog"} /-->'));
        $this->assertTrue(str_contains($proposed, '"label":"Documentation"'));
        $this->assertTrue(str_contains($proposed, '"opensInNewTab":true'));
        $manager->commit(array('preview_id' => (string) $preview['preview_id']));
        $this->assertSame($proposed, (string) (\get_post(104)->post_content ?? ''));
    }

    public function test_list_codecs_create_nested_lists_and_update_items_through_preview_commit(): void
    {
        $runtime = new SaveRuntime();
        $list = $runtime->create('core/list', array(), array(
            $runtime->create('core/list-item', array('text' => 'One')),
            $runtime->create('core/list-item', array('text' => 'Two')),
        ));
        $this->assertSame('<ul class="wp-block-list"></ul>', (string) ($list['innerHTML'] ?? ''));
        $serialized = \serialize_block($list);
        $this->assertTrue(str_contains($serialized, '<!-- wp:list-item --><li>One</li><!-- /wp:list-item -->'));

        $manager = new PresentationManager();
        $ref = (new RefCodec())->encode('page', 101);
        $original = (string) (\get_post(101)->post_content ?? '');
        $preview = $manager->preview(array(
            'ref' => $ref,
            'operations' => array(
                array('action' => 'content.insert_list', 'index' => 1, 'items' => array('One', 'Two')),
                array('action' => 'content.insert_list', 'parent_path' => '1.0', 'index' => 0, 'ordered' => true, 'items' => array('Nested one', 'Nested two')),
                array('action' => 'content.update_text', 'path' => '1.1', 'text' => 'Two updated'),
            ),
        ));
        $this->assertSame($original, (string) (\get_post(101)->post_content ?? ''), 'List preview must not write WordPress.');
        $proposed = (string) ($preview['proposed_state']['content'] ?? '');
        $this->assertTrue(str_contains($proposed, '<ul class="wp-block-list">'));
        $this->assertTrue(str_contains($proposed, '<ol class="wp-block-list">'));
        $this->assertTrue(str_contains($proposed, '<li>Nested one</li>'));
        $this->assertTrue(str_contains($proposed, '<li>Two updated</li>'));
        $manager->commit(array('preview_id' => (string) $preview['preview_id']));
        $this->assertSame($proposed, (string) (\get_post(101)->post_content ?? ''));
    }

    public function test_preset_presentation_update_is_typed_and_codec_gated(): void
    {
        $manager = new PresentationManager();
        $ref = (new RefCodec())->encode('page', 101);
        $original = (string) (\get_post(101)->post_content ?? '');
        $preview = $manager->preview(array(
            'ref' => $ref,
            'operations' => array(
                array(
                    'action' => 'blocks.update_presentation',
                    'path' => '0',
                    'text_color' => 'contrast',
                    'background_color' => 'base',
                    'font_size' => 'large',
                    'line_height' => '1.6',
                    'margin_top' => '40',
                    'margin_bottom' => '50',
                    'padding_top' => '20',
                    'padding_right' => '30',
                    'padding_bottom' => '20',
                    'padding_left' => '30',
                ),
            ),
        ));
        $this->assertSame($original, (string) (\get_post(101)->post_content ?? ''), 'Presentation control preview must not write WordPress.');
        $proposed = (string) ($preview['proposed_state']['content'] ?? '');
        $this->assertTrue(str_contains($proposed, 'has-base-background-color has-background has-contrast-color has-text-color has-large-font-size'));
        $this->assertTrue(str_contains($proposed, 'margin-top:var(--wp--preset--spacing--40);margin-bottom:var(--wp--preset--spacing--50);padding-top:var(--wp--preset--spacing--20);padding-right:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--20);padding-left:var(--wp--preset--spacing--30);line-height:1.6'));
        $manager->commit(array('preview_id' => (string) $preview['preview_id']));
        $this->assertSame($proposed, (string) (\get_post(101)->post_content ?? ''));

        $editor = new BlockEditor();
        $image = (new SaveRuntime())->create('core/image', array('url' => '/media/photo.jpg'));
        $this->expectException(
            fn () => $editor->updatePresentation((string) \serialize_block($image), '0', array('text_color' => 'contrast')),
            'does not support Presentation field [text_color]'
        );
    }

    public function test_preset_spacing_is_side_specific_clearable_and_fail_closed(): void
    {
        $runtime = new SaveRuntime();
        $editor = new BlockEditor();

        $column = $runtime->create('core/column');
        $columnContent = (string) \serialize_block($column);
        $padded = $editor->updatePresentation($columnContent, '0', array('padding_top' => '30'));
        $this->assertTrue(str_contains($padded, 'padding-top:var(--wp--preset--spacing--30)'));
        $this->expectException(
            fn () => $editor->updatePresentation($columnContent, '0', array('margin_top' => '30')),
            'does not support Presentation field [margin_top]'
        );

        $cleared = $editor->updatePresentation($padded, '0', array('padding_top' => ''));
        $this->assertFalse(str_contains($cleared, '--wp--preset--spacing--30'));

        $paragraph = $runtime->create('core/paragraph', array(
            'text' => 'Existing shorthand',
            'attributes' => array('style' => array('spacing' => array('padding' => 'var:preset|spacing|30'))),
        ));
        $this->expectException(
            fn () => $editor->updatePresentation((string) \serialize_block($paragraph), '0', array('padding_top' => '40')),
            'non-side-specific padding spacing'
        );
    }

    public function test_line_height_is_bounded_clearable_and_codec_gated(): void
    {
        $runtime = new SaveRuntime();
        $editor = new BlockEditor();
        $paragraph = $runtime->create('core/paragraph', array('text' => 'Line height'));
        $content = (string) \serialize_block($paragraph);
        $updated = $editor->updatePresentation($content, '0', array('line_height' => '1.625'));
        $this->assertTrue(str_contains($updated, 'line-height:1.625'));
        $cleared = $editor->updatePresentation($updated, '0', array('line_height' => ''));
        $this->assertFalse(str_contains($cleared, 'line-height:'));
        $this->expectException(
            fn () => $editor->updatePresentation($content, '0', array('line_height' => '120%')),
            'unitless decimal number'
        );
        $this->expectException(
            fn () => $editor->updatePresentation($content, '0', array('line_height' => '4.1')),
            'between 0.5 and 4'
        );
        $image = $runtime->create('core/image', array('url' => '/media/photo.jpg'));
        $this->expectException(
            fn () => $editor->updatePresentation((string) \serialize_block($image), '0', array('line_height' => '1.5')),
            'does not support Presentation field [line_height]'
        );
    }

    private function expectException(callable $callback, string $contains): void
    {
        try { $callback(); } catch (\Throwable $throwable) {
            $this->assertTrue(str_contains(strtolower($throwable->getMessage()), strtolower($contains)), $throwable->getMessage());
            return;
        }
        throw new \RuntimeException('Expected exception containing: ' . $contains);
    }
}
