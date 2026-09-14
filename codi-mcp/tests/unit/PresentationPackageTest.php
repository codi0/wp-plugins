<?php

declare(strict_types=1);

namespace CodiMcpTest\Unit;

use CodiMcp\Packages\Presentation\BlockEditor;
use CodiMcp\Packages\Presentation\BlockMutationPolicy;
use CodiMcp\Packages\Presentation\OperationCatalog;
use CodiMcp\Packages\Presentation\Package;
use CodiMcp\Packages\Presentation\PresentationManager;
use CodiMcp\Packages\Presentation\PresentationRepository;
use CodiMcp\Packages\Presentation\RefCodec;
use CodiMcpTest\Framework\TestCase;

final class PresentationPackageTest extends TestCase
{
    protected function setUp(): void
    {
        \codi_mcp_test_reset_environment();
        if (!defined('CODI_MCP_ABILITY_PREFIX')) {
            define('CODI_MCP_ABILITY_PREFIX', 'codi');
        }
        $GLOBALS['codi_mcp_test_wp_current_user_id'] = 7;
        $page = \get_post(101);
        if (is_object($page)) {
            $page->post_content = '<!-- wp:paragraph --><p>Welcome.</p><!-- /wp:paragraph --><!-- wp:heading {"level":3} --><h3>Start here</h3><!-- /wp:heading -->';
        }
        \delete_post_meta(101, '_wp_page_template');
        $styles = \get_post(106);
        if (is_object($styles)) {
            $styles->post_content = json_encode(array(
                'version' => 3,
                'isGlobalStylesUserThemeJSON' => true,
                'settings' => array(),
                'styles' => array(),
            ), JSON_UNESCAPED_SLASHES);
        }
    }

    public function test_package_registers_eight_default_safe_presentation_abilities(): void
    {
        $package = new Package();
        $expected = array(
            'codi/presentation-help',
            'codi/presentation-find',
            'codi/presentation-inspect',
            'codi/presentation-preview',
            'codi/presentation-verify',
            'codi/presentation-commit',
            'codi/presentation-history',
            'codi/presentation-rollback',
        );
        $this->assertSame($expected, $package->abilityNames());
        $package->registerCategories();
        $package->registerAbilities();
        $this->assertSame($expected, array_keys((array) $GLOBALS['codi_mcp_test_registered_abilities']));
        $this->assertArrayHasKey('codi-presentation', (array) $GLOBALS['codi_mcp_test_registered_ability_categories']);
        foreach ($expected as $name) {
            $descriptor = (array) ($GLOBALS['codi_mcp_test_registered_abilities'][$name] ?? array());
            $meta = (array) ($descriptor['meta'] ?? array());
            $this->assertSame('presentation', (string) ($meta['codi_mcp']['package'] ?? ''));
            $this->assertSame(false, $meta['public'] ?? null);
            $this->assertSame(false, $meta['mcp']['public'] ?? null);
            $this->assertSame(true, $meta['codi_mcp']['owned'] ?? null);
            $this->assertSame(false, $meta['annotations']['openWorldHint'] ?? null);
        }

        $preview = (array) ($GLOBALS['codi_mcp_test_registered_abilities']['codi/presentation-preview'] ?? array());
        $variants = (array) ($preview['input_schema']['properties']['operations']['items']['oneOf'] ?? array());
        $presentationUpdate = array();
        foreach ($variants as $variant) {
            if (array('blocks.update_presentation') === (array) ($variant['properties']['action']['enum'] ?? array())) {
                $presentationUpdate = (array) $variant;
                break;
            }
        }
        $lineHeight = (array) ($presentationUpdate['properties']['line_height'] ?? array());
        $this->assertSame(0.5, (float) ($lineHeight['oneOf'][0]['minimum'] ?? 0));
        $this->assertSame(4.0, (float) ($lineHeight['oneOf'][0]['maximum'] ?? 0));
        $this->assertSame(array(''), (array) ($lineHeight['oneOf'][1]['enum'] ?? array()));

        $inspect = (array) ($GLOBALS['codi_mcp_test_registered_abilities']['codi/presentation-inspect'] ?? array());
        $blockSchema = (array) ($inspect['output_schema']['properties']['blocks']['items'] ?? array());
        $this->assertSame(array('structural_only', 'php_save_codec'), (array) ($blockSchema['properties']['mutation_support']['enum'] ?? array()));
        $this->assertSame('array', (string) ($blockSchema['properties']['codec_capabilities']['type'] ?? ''));
    }

    public function test_operation_catalog_has_no_generic_gutenberg_attribute_escape_hatch(): void
    {
        $actions = array_keys(OperationCatalog::all());
        $this->assertSame(array(
            'blocks.insert_copy',
            'blocks.remove',
            'blocks.move',
            'blocks.update_presentation',
            'blocks.insert_group',
            'blocks.insert_columns',
            'blocks.update_column',
            'blocks.insert_cover',
            'blocks.update_cover',
            'blocks.insert_gallery',
            'blocks.update_gallery',
            'query.insert',
            'query.update',
            'navigation.insert_entry',
            'navigation.update_entry',
            'content.insert_list',
            'content.insert_text',
            'content.update_text',
            'content.insert_button',
            'content.update_button',
            'content.insert_image',
            'content.update_image',
            'presentation.set_template',
            'presentation.clear_template',
            'styles.update',
            'object.create',
            'object.duplicate',
            'object.delete',
        ), $actions);
        foreach (array('blocks.update_attributes', 'blocks.insert_dynamic', 'navigation.update_item', 'content.set_attribute') as $forbidden) {
            $this->assertNotContains($forbidden, $actions);
        }

        $policy = new BlockMutationPolicy();
        $paragraph = $policy->describe('core/paragraph');
        $this->assertSame('php_save_codec', (string) ($paragraph['mutation_support'] ?? ''));
        $this->assertContains('update_text', (array) ($paragraph['codec_capabilities'] ?? array()));
        $this->assertContains('update_text_color', (array) ($paragraph['codec_capabilities'] ?? array()));
        $this->assertContains('update_line_height', (array) ($paragraph['codec_capabilities'] ?? array()));
        $this->assertContains('update_margin_block', (array) ($paragraph['codec_capabilities'] ?? array()));
        $this->assertContains('update_padding', (array) ($paragraph['codec_capabilities'] ?? array()));
        $navigationLink = $policy->describe('core/navigation-link');
        $this->assertTrue((bool) ($navigationLink['dynamic'] ?? false), 'The fixture models Navigation Link as a hybrid/server-rendered block.');
        $this->assertSame('php_save_codec', (string) ($navigationLink['mutation_support'] ?? ''), 'Navigation Link is editable only because Presentation has an explicit save codec.');
        $this->assertNotContains('update_attributes', (array) ($navigationLink['codec_capabilities'] ?? array()), 'Dynamic/hybrid status must never authorize generic attribute mutation.');
    }

    public function test_preview_does_not_write_and_commit_then_rollback_restores_exact_content(): void
    {
        $manager = new PresentationManager();
        $ref = (new RefCodec())->encode('page', 101);
        $original = (string) (\get_post(101)->post_content ?? '');

        $preview = $manager->preview(array(
            'ref' => $ref,
            'operations' => array(array('action' => 'content.update_text', 'path' => '0', 'text' => 'Changed safely')),
        ));
        $this->assertSame($original, (string) (\get_post(101)->post_content ?? ''), 'Preview must not mutate WordPress.');
        $this->assertTrue(str_contains((string) ($preview['proposed_state']['content'] ?? ''), 'Changed safely'));

        $verified = $manager->verify(array('preview_id' => (string) $preview['preview_id']));
        $this->assertTrue((bool) ($verified['valid'] ?? false));

        $commit = $manager->commit(array('preview_id' => (string) $preview['preview_id']));
        $this->assertTrue(str_contains((string) (\get_post(101)->post_content ?? ''), 'Changed safely'));
        $this->assertTrue('' !== (string) ($commit['transaction_id'] ?? ''));

        $rollbackPreview = $manager->rollback(array('transaction_id' => (string) $commit['transaction_id'], 'mode' => 'preview'));
        $this->assertTrue((bool) ($rollbackPreview['can_apply'] ?? false));
        $manager->rollback(array('transaction_id' => (string) $commit['transaction_id'], 'mode' => 'apply'));
        $this->assertSame($original, (string) (\get_post(101)->post_content ?? ''));
    }

    public function test_stale_preview_is_refused_before_native_write(): void
    {
        $manager = new PresentationManager();
        $ref = (new RefCodec())->encode('page', 101);
        $preview = $manager->preview(array(
            'ref' => $ref,
            'operations' => array(array('action' => 'content.update_text', 'path' => '0', 'text' => 'Previewed')),
        ));
        \wp_update_post(array('ID' => 101, 'post_content' => '<!-- wp:paragraph --><p>External change.</p><!-- /wp:paragraph -->'), true);

        $this->expectException(
            fn () => $manager->commit(array('preview_id' => (string) $preview['preview_id'])),
            'target changed after preview'
        );
        $this->assertTrue(str_contains((string) (\get_post(101)->post_content ?? ''), 'External change.'));
    }

    public function test_gutenberg_structure_uses_known_container_codecs_and_fails_closed_elsewhere(): void
    {
        $editor = new BlockEditor();
        $group = $editor->insertGroup('', 0);
        $nested = $editor->insertTextBlock($group, 0, 'paragraph', 'Child', 2, '0');
        $this->assertTrue(str_contains($nested, '<div class="wp-block-group"><!-- wp:paragraph --><p>Child</p><!-- /wp:paragraph --></div>'));
        $emptied = $editor->remove($nested, '0.0');
        $this->assertTrue(str_contains($emptied, '<div class="wp-block-group"></div>'));

        $unknownContainer = '<!-- wp:navigation --><nav class="wp-block-navigation"><!-- wp:paragraph --><p>Child</p><!-- /wp:paragraph --></nav><!-- /wp:navigation -->';
        $this->expectException(fn () => $editor->remove($unknownContainer, '0.0'), 'container save codec');

        $inserted = $editor->insertTextBlock('', 0, 'heading', 'Safe heading', 3);
        $this->assertTrue(str_contains($inserted, '<h3 class="wp-block-heading">Safe heading</h3>'));

        $image = '<!-- wp:image {"id":107} --><figure class="wp-block-image"><img src="x" /></figure><!-- /wp:image -->';
        $this->expectException(fn () => $editor->updateText($image, '0', 'Nope'), 'editor-safe text codec');
    }

    public function test_theme_template_edit_creates_native_customization_and_rollback_reveals_source(): void
    {
        $manager = new PresentationManager();
        $codec = new RefCodec();
        $templateId = \get_stylesheet() . '//front-page';
        $ref = $codec->encodeTemplate('wp_template', $templateId);
        $before = $manager->inspect(array('ref' => $ref));
        $this->assertSame('theme', (string) ($before['surface']['source_kind'] ?? ''));
        $this->assertFalse((bool) ($before['surface']['customized'] ?? true));

        $preview = $manager->preview(array(
            'ref' => $ref,
            'operations' => array(array('action' => 'content.update_text', 'path' => '0', 'text' => 'Customized template')),
        ));
        $this->assertSame('custom', (string) ($preview['proposed_state']['source_kind'] ?? ''));
        $commit = $manager->commit(array('preview_id' => (string) $preview['preview_id']));
        $custom = \get_block_template($templateId, 'wp_template');
        $this->assertSame('custom', (string) ($custom->source ?? ''));
        $this->assertTrue(str_contains((string) ($custom->content ?? ''), 'Customized template'));

        $manager->rollback(array('transaction_id' => (string) $commit['transaction_id'], 'mode' => 'apply'));
        $restored = \get_block_template($templateId, 'wp_template');
        $this->assertSame('theme', (string) ($restored->source ?? ''));
        $this->assertTrue(str_contains((string) ($restored->content ?? ''), 'Theme front page.'));
    }

    public function test_global_styles_use_native_state_and_rollback(): void
    {
        $manager = new PresentationManager();
        $ref = (new RefCodec())->encode('wp_global_styles', 106);
        $preview = $manager->preview(array(
            'ref' => $ref,
            'operations' => array(array(
                'action' => 'styles.update',
                'styles' => array('color' => array('text' => '#112233')),
            )),
        ));
        $commit = $manager->commit(array('preview_id' => (string) $preview['preview_id']));
        $stored = json_decode((string) (\get_post(106)->post_content ?? ''), true);
        $this->assertSame('#112233', (string) ($stored['styles']['color']['text'] ?? ''));

        $manager->rollback(array('transaction_id' => (string) $commit['transaction_id'], 'mode' => 'apply'));
        $restored = json_decode((string) (\get_post(106)->post_content ?? ''), true);
        $this->assertFalse(isset($restored['styles']['color']['text']));
    }

    public function test_pattern_sync_status_uses_semantic_values_and_native_meta_absence(): void
    {
        $repository = new PresentationRepository();
        $ref = (new RefCodec())->encode('wp_block', 105);
        $snapshot = $repository->snapshotByRef($ref);
        $this->assertSame('synced', (string) ($snapshot['sync_status'] ?? ''));

        \update_post_meta(105, 'wp_pattern_sync_status', 'unsynced');
        $this->assertSame('unsynced', (string) \get_post_meta(105, 'wp_pattern_sync_status', true));
        $restored = $repository->restoreSnapshot($snapshot);
        $this->assertSame('', (string) \get_post_meta(105, 'wp_pattern_sync_status', true));
        $this->assertSame('synced', (string) ($restored['sync_status'] ?? ''));

        $manager = new PresentationManager();
        $preview = $manager->preview(array(
            'operations' => array(array(
                'action' => 'object.create',
                'kind' => 'pattern',
                'title' => 'Unsynced Pattern Fixture',
                'sync_status' => 'unsynced',
            )),
        ));
        $this->assertSame('unsynced', (string) ($preview['proposed_state']['sync_status'] ?? ''));
        $commit = $manager->commit(array('preview_id' => (string) $preview['preview_id']));
        $createdRef = (string) ($commit['ref'] ?? '');
        $created = (new RefCodec())->decode($createdRef);
        $this->assertSame('unsynced', (string) \get_post_meta((int) $created['post_id'], 'wp_pattern_sync_status', true));
        $inspected = $manager->inspect(array('ref' => $createdRef));
        $this->assertSame('unsynced', (string) ($inspected['sync_status'] ?? ''));
    }

    public function test_pattern_delete_rollback_preserves_native_identity_and_refuses_id_collision(): void
    {
        $manager = new PresentationManager();
        $ref = (new RefCodec())->encode('wp_block', 105);
        $original = \get_post(105);
        $this->assertTrue(is_object($original));
        $originalContent = (string) ($original->post_content ?? '');

        $deletePreview = $manager->preview(array(
            'ref' => $ref,
            'operations' => array(array('action' => 'object.delete')),
        ));
        $deleteCommit = $manager->commit(array('preview_id' => (string) $deletePreview['preview_id']));
        $this->assertFalse(is_object(\get_post(105)));
        $rollback = $manager->rollback(array('transaction_id' => (string) $deleteCommit['transaction_id'], 'mode' => 'apply'));
        $this->assertSame($ref, (string) ($rollback['result_ref'] ?? ''));
        $restored = \get_post(105);
        $this->assertTrue(is_object($restored));
        $this->assertSame($originalContent, (string) ($restored->post_content ?? ''));
        $this->assertSame('synced', (string) ($manager->inspect(array('ref' => $ref))['sync_status'] ?? ''));

        $deleteAgain = $manager->preview(array(
            'ref' => $ref,
            'operations' => array(array('action' => 'object.delete')),
        ));
        $secondCommit = $manager->commit(array('preview_id' => (string) $deleteAgain['preview_id']));
        $collisionId = \wp_insert_post(array(
            'import_id' => 105,
            'post_type' => 'wp_block',
            'post_status' => 'publish',
            'post_name' => 'collision-pattern',
            'post_title' => 'Collision Pattern',
            'post_content' => '<!-- wp:paragraph --><p>Do not overwrite.</p><!-- /wp:paragraph -->',
        ), true);
        $this->assertSame(105, (int) $collisionId);
        $this->expectException(
            fn () => $manager->rollback(array('transaction_id' => (string) $secondCommit['transaction_id'], 'mode' => 'preview')),
            'original native post id is no longer vacant'
        );
        $collision = \get_post(105);
        $this->assertSame('Collision Pattern', (string) ($collision->post_title ?? ''));
        $this->assertTrue(str_contains((string) ($collision->post_content ?? ''), 'Do not overwrite.'));
    }

    public function test_requested_frontend_verification_fails_when_no_frontend_route_exists(): void
    {
        $manager = new PresentationManager();
        $found = $manager->find(array('kinds' => array('global_styles'), 'limit' => 10));
        $ref = (string) ($found['items'][0]['ref'] ?? '');
        $this->assertTrue($ref !== '');

        $verified = $manager->verify(array('ref' => $ref, 'frontend' => true));
        $this->assertFalse((bool) ($verified['valid'] ?? true), 'Requested frontend verification must not pass when no frontend route can be checked.');
        $frontend = array_values(array_filter((array) ($verified['checks'] ?? array()), static fn (array $check): bool => (string) ($check['code'] ?? '') === 'frontend'));
        $this->assertSame(1, count($frontend));
        $this->assertFalse((bool) ($frontend[0]['ok'] ?? true));
    }

    public function test_history_failure_compensates_native_write_and_preserves_preview(): void
    {
        $manager = new PresentationManager();
        $ref = (new RefCodec())->encode('page', 101);
        $original = (string) (\get_post(101)->post_content ?? '');
        $preview = $manager->preview(array(
            'ref' => $ref,
            'operations' => array(array('action' => 'content.update_text', 'path' => '0', 'text' => 'Must be compensated')),
        ));
        $GLOBALS['codi_mcp_test_wp_option_write_failures']['codi_mcp_presentation_history_v1'] = 1;

        $this->expectException(
            fn () => $manager->commit(array('preview_id' => (string) $preview['preview_id'])),
            'state was compensated'
        );
        $this->assertSame($original, (string) (\get_post(101)->post_content ?? ''));

        $verified = $manager->verify(array('preview_id' => (string) $preview['preview_id']));
        $this->assertTrue((bool) ($verified['valid'] ?? false), 'A compensated failure must leave the reviewed preview available and fresh.');
    }

    public function test_rollback_history_failure_compensates_native_state_and_remains_retryable(): void
    {
        $manager = new PresentationManager();
        $ref = (new RefCodec())->encode('page', 101);
        $original = (string) (\get_post(101)->post_content ?? '');
        $preview = $manager->preview(array(
            'ref' => $ref,
            'operations' => array(array('action' => 'content.update_text', 'path' => '0', 'text' => 'Rollback guard state')),
        ));
        $commit = $manager->commit(array('preview_id' => (string) $preview['preview_id']));
        $transactionId = (string) ($commit['transaction_id'] ?? '');
        $changed = (string) (\get_post(101)->post_content ?? '');
        $this->assertTrue(str_contains($changed, 'Rollback guard state'));

        $GLOBALS['codi_mcp_test_wp_option_write_failures']['codi_mcp_presentation_tx_' . $transactionId] = 1;
        $this->expectException(
            fn () => $manager->rollback(array('transaction_id' => $transactionId, 'mode' => 'apply')),
            'state was compensated'
        );
        $this->assertSame($changed, (string) (\get_post(101)->post_content ?? ''), 'Failed rollback bookkeeping must restore the pre-rollback native state.');

        $history = $manager->history(array('ref' => $ref, 'limit' => 10));
        $this->assertSame(1, (int) ($history['returned'] ?? 0), 'A failed rollback must not leave an orphan rollback history entry.');
        $this->assertSame('', (string) ($history['items'][0]['rolled_back_at'] ?? ''), 'The original transaction must remain retryable after compensated rollback failure.');

        $manager->rollback(array('transaction_id' => $transactionId, 'mode' => 'apply'));
        $this->assertSame($original, (string) (\get_post(101)->post_content ?? ''));
    }

    private function expectException(callable $callback, string $messageContains): void
    {
        try {
            $callback();
        } catch (\Throwable $throwable) {
            $this->assertTrue(str_contains(strtolower($throwable->getMessage()), strtolower($messageContains)), $throwable->getMessage());
            return;
        }
        throw new \RuntimeException('Expected exception containing: ' . $messageContains);
    }
}
