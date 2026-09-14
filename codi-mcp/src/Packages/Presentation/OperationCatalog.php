<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation;

final class OperationCatalog
{
    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        return array(
            'blocks.insert_copy' => array('summary' => 'Insert an intact block copied from an existing Presentation surface; no block attributes or saved markup are regenerated.', 'targets' => array('content', 'template', 'template_part', 'pattern', 'navigation')),
            'blocks.remove' => array('summary' => 'Remove an existing Gutenberg block. Nested removal is allowed only when the direct parent has a PHP save codec that can regenerate its InnerBlocks wrapper.', 'targets' => array('content', 'template', 'template_part', 'pattern', 'navigation')),
            'blocks.move' => array('summary' => 'Reorder one existing Gutenberg block within the same parent while preserving or regenerating the parent serialization. Cross-parent moves remain unavailable.', 'targets' => array('content', 'template', 'template_part', 'pattern', 'navigation')),
            'blocks.update_presentation' => array('summary' => 'Update supported WordPress preset colors, font size, side-specific spacing, and bounded unitless line height on a codec-backed block. No arbitrary attributes or CSS values are accepted.', 'targets' => array('content', 'template', 'template_part', 'pattern')),
            'blocks.insert_group' => array('summary' => 'Insert a core/group through the PHP Gutenberg save runtime. Nested insertion requires a codec-backed parent.', 'targets' => array('content', 'template', 'template_part', 'pattern')),
            'blocks.insert_columns' => array('summary' => 'Insert a core/columns structure with one to six empty core/column children through PHP save codecs.', 'targets' => array('content', 'template', 'template_part', 'pattern')),
            'blocks.update_column' => array('summary' => 'Update a core/column width or vertical alignment and regenerate its complete saved wrapper.', 'targets' => array('content', 'template', 'template_part', 'pattern')),
            'blocks.insert_cover' => array('summary' => 'Insert an image-background core/cover through its current PHP save codec; video/embed variants remain unavailable.', 'targets' => array('content', 'template', 'template_part', 'pattern')),
            'blocks.update_cover' => array('summary' => 'Update supported core/cover image, overlay, position, and dimension fields and regenerate its saved markup.', 'targets' => array('content', 'template', 'template_part', 'pattern')),
            'blocks.insert_gallery' => array('summary' => 'Insert an empty core/gallery through its PHP save codec; add core/image children with content.insert_image.', 'targets' => array('content', 'template', 'template_part', 'pattern')),
            'blocks.update_gallery' => array('summary' => 'Update supported Gallery columns, crop, caption, alignment, and color fields while preserving codec-backed image children.', 'targets' => array('content', 'template', 'template_part', 'pattern')),
            'query.insert' => array('summary' => 'Insert a standard core/query loop with linked post titles, pagination, and a no-results message through explicit PHP save codecs.', 'targets' => array('content', 'template', 'template_part', 'pattern')),
            'query.update' => array('summary' => 'Update bounded core/query parameters such as post type, page size, ordering, offset, search, inheritance, and sticky handling.', 'targets' => array('content', 'template', 'template_part', 'pattern')),
            'navigation.insert_entry' => array('summary' => 'Insert a Navigation Link or Submenu into a wp_navigation object using the current InnerBlocks-only save contract.', 'targets' => array('navigation')),
            'navigation.update_entry' => array('summary' => 'Update bounded semantic fields on a Navigation Link or Submenu without exposing generic block attributes.', 'targets' => array('navigation')),
            'content.insert_list' => array('summary' => 'Insert an ordered or unordered core/list from one to 100 plain-text items through explicit List/List Item save codecs.', 'targets' => array('content', 'template', 'template_part', 'pattern')),
            'content.insert_text' => array('summary' => 'Insert a paragraph or heading through the PHP Gutenberg save runtime, including inside codec-backed containers such as core/group.', 'targets' => array('content', 'template', 'template_part', 'pattern')),
            'content.update_text' => array('summary' => 'Regenerate Paragraph, Heading, or List Item saved markup from semantic text through an explicit PHP save codec.', 'targets' => array('content', 'template', 'template_part', 'pattern')),
            'content.insert_button' => array('summary' => 'Insert a semantic core/button through its PHP save codec, automatically wrapping it in core/buttons when needed.', 'targets' => array('content', 'template', 'template_part', 'pattern')),
            'content.update_button' => array('summary' => 'Update semantic Button text/link fields and regenerate the complete supported core/button saved markup.', 'targets' => array('content', 'template', 'template_part', 'pattern')),
            'content.insert_image' => array('summary' => 'Insert a core/image through the PHP save codec using semantic media, link, caption, and dimension fields.', 'targets' => array('content', 'template', 'template_part', 'pattern')),
            'content.update_image' => array('summary' => 'Update supported core/image semantic fields and regenerate the complete current saved markup.', 'targets' => array('content', 'template', 'template_part', 'pattern')),
            'presentation.set_template' => array('summary' => 'Assign a native WordPress template slug to a content entity.', 'targets' => array('content')),
            'presentation.clear_template' => array('summary' => 'Clear the explicit native WordPress template assignment from a content entity.', 'targets' => array('content')),
            'styles.update' => array('summary' => 'Merge validated settings/styles into the native wp_global_styles user-origin record.', 'targets' => array('global_styles')),
            'object.create' => array('summary' => 'Create an empty WordPress-managed pattern, navigation, template, or template part. Populate it only with safe block insertion operations.', 'targets' => array('pattern', 'navigation', 'template', 'template_part')),
            'object.duplicate' => array('summary' => 'Duplicate a WordPress-managed presentation object while preserving its saved block markup exactly.', 'targets' => array('pattern', 'navigation', 'template', 'template_part')),
            'object.delete' => array('summary' => 'Delete a Presentation-owned pattern/navigation record or remove a custom template/template-part override. Theme/plugin source templates are never deleted here.', 'targets' => array('pattern', 'navigation', 'template', 'template_part')),
        );
    }

    public static function has(string $action): bool
    {
        return isset(self::all()[strtolower(trim($action))]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function publicList(): array
    {
        $items = array();
        foreach (self::all() as $action => $spec) {
            $items[] = array('action' => $action, 'summary' => (string) $spec['summary'], 'target_kinds' => array_values((array) $spec['targets']));
        }
        return $items;
    }
}
