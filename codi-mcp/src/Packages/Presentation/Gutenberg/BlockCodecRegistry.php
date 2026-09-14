<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation\Gutenberg;

use CodiMcp\Packages\Presentation\Gutenberg\Codecs\ButtonCodec;
use CodiMcp\Packages\Presentation\Gutenberg\Codecs\ButtonsCodec;
use CodiMcp\Packages\Presentation\Gutenberg\Codecs\ColumnCodec;
use CodiMcp\Packages\Presentation\Gutenberg\Codecs\ColumnsCodec;
use CodiMcp\Packages\Presentation\Gutenberg\Codecs\CoverCodec;
use CodiMcp\Packages\Presentation\Gutenberg\Codecs\GalleryCodec;
use CodiMcp\Packages\Presentation\Gutenberg\Codecs\GroupCodec;
use CodiMcp\Packages\Presentation\Gutenberg\Codecs\HeadingCodec;
use CodiMcp\Packages\Presentation\Gutenberg\Codecs\InnerBlocksOnlyCodec;
use CodiMcp\Packages\Presentation\Gutenberg\Codecs\ImageCodec;
use CodiMcp\Packages\Presentation\Gutenberg\Codecs\ListCodec;
use CodiMcp\Packages\Presentation\Gutenberg\Codecs\ListItemCodec;
use CodiMcp\Packages\Presentation\Gutenberg\Codecs\NullSaveCodec;
use CodiMcp\Packages\Presentation\Gutenberg\Codecs\ParagraphCodec;
use CodiMcp\Packages\Presentation\Gutenberg\Codecs\QueryCodec;

final class BlockCodecRegistry
{
    /** @var array<string,BlockCodec> */
    private array $codecs = array();

    public function __construct(?SupportSerializer $supports = null)
    {
        $supports ??= new SupportSerializer();
        foreach (array(
            new ParagraphCodec($supports),
            new HeadingCodec($supports),
            new GroupCodec($supports),
            new ButtonsCodec($supports),
            new ButtonCodec($supports),
            new ColumnsCodec($supports),
            new ColumnCodec($supports),
            new ImageCodec($supports),
            new CoverCodec($supports),
            new GalleryCodec($supports),
            new ListCodec($supports),
            new ListItemCodec($supports),
            new InnerBlocksOnlyCodec('core/navigation-link', array(
                'label','type','description','rel','id','opensInNewTab','url','title','kind','isTopLevelLink','anchor','className','fontFamily','fontSize','style','metadata','lock',
            ), array(), $supports),
            new InnerBlocksOnlyCodec('core/navigation-submenu', array(
                'label','type','description','rel','id','opensInNewTab','url','title','kind','isTopLevelItem','isParentSubmenu','anchor','className','fontFamily','fontSize','style','metadata','lock',
            ), array('core/navigation-link','core/navigation-submenu'), $supports),
            new QueryCodec($supports),
            new InnerBlocksOnlyCodec('core/post-template', array(
                '__woocommerceNamespace','align','anchor','backgroundColor','borderColor','className','fontFamily','fontSize','gradient','layout','lock','metadata','restrictionId','style','textColor',
            ), null, $supports),
            new InnerBlocksOnlyCodec('core/query-pagination', array(
                'align','anchor','backgroundColor','className','fontFamily','fontSize','gradient','layout','lock','metadata','paginationArrow','restrictionId','showLabel','style','textColor',
            ), array('core/query-pagination-previous','core/query-pagination-numbers','core/query-pagination-next'), $supports),
            new InnerBlocksOnlyCodec('core/query-no-results', array(
                'align','anchor','backgroundColor','className','fontFamily','fontSize','gradient','lock','metadata','restrictionId','style','textColor',
            ), null, $supports),
            new NullSaveCodec('core/post-title', array(
                '__woocommerceNamespace','align','anchor','backgroundColor','borderColor','className','fontFamily','fontSize','gradient','isLink','level','levelOptions','linkTarget','lock','metadata','placeholder','rel','restrictionId','style','textColor',
            ), $supports),
            new NullSaveCodec('core/query-pagination-previous', array(
                'anchor','backgroundColor','className','fontFamily','fontSize','gradient','label','lock','metadata','restrictionId','style','textColor',
            ), $supports),
            new NullSaveCodec('core/query-pagination-numbers', array(
                'anchor','backgroundColor','className','fontFamily','fontSize','gradient','lock','metadata','midSize','restrictionId','style','textColor',
            ), $supports),
            new NullSaveCodec('core/query-pagination-next', array(
                'anchor','backgroundColor','className','fontFamily','fontSize','gradient','label','lock','metadata','restrictionId','style','textColor',
            ), $supports),
        ) as $codec) {
            $this->codecs[$codec->name()] = $codec;
        }
    }

    public function has(string $blockName): bool
    {
        return isset($this->codecs[strtolower(trim($blockName))]);
    }

    public function get(string $blockName): BlockCodec
    {
        $blockName = strtolower(trim($blockName));
        if (!isset($this->codecs[$blockName])) {
            throw new \InvalidArgumentException(sprintf('Presentation has no PHP Gutenberg save codec for block [%s].', $blockName));
        }
        return $this->codecs[$blockName];
    }

    /** @return string[] */
    public function capabilities(string $blockName): array
    {
        return $this->has($blockName) ? $this->get($blockName)->capabilities() : array();
    }

    /** @return string[] */
    public function supportedBlocks(): array
    {
        return array_keys($this->codecs);
    }
}
