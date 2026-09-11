<?php

declare(strict_types=1);

namespace App\Entity\Frontpage;

use App\Entity\Application\Traits\IdentifiableTrait;
use App\Entity\Frontpage\Enums\NewsCategory;
use App\Repository\Frontpage\NewsItemRepository;
use DateTime;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;

/**
 * A piece of news the board or a committee put out. The title and the body are written in both languages and the body
 * is markdown, which is what the website renders it as.
 */
#[Entity(repositoryClass: NewsItemRepository::class)]
class NewsItem
{
    use IdentifiableTrait;

    /**
     * The date the news item was written.
     */
    #[Column(type: Types::DATE_MUTABLE)]
    public DateTime $date;

    /**
     * Title of the news item.
     */
    #[OneToOne(
        targetEntity: FrontpageLocalisedText::class,
        cascade: [
            'persist',
            'remove',
        ],
        fetch: 'EAGER',
        orphanRemoval: true,
    )]
    #[JoinColumn(
        name: 'title_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    public FrontpageLocalisedText $title;

    /**
     * The body of the news item, as markdown.
     */
    #[OneToOne(
        targetEntity: FrontpageLocalisedText::class,
        cascade: [
            'persist',
            'remove',
        ],
        fetch: 'EAGER',
        orphanRemoval: true,
    )]
    #[JoinColumn(
        name: 'content_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    public FrontpageLocalisedText $content;

    /**
     * What the item is about, which is what the feed's filter narrows by.
     */
    #[Column(
        type: Types::STRING,
        enumType: NewsCategory::class,
    )]
    public NewsCategory $category = NewsCategory::Association;

    /**
     * Whether this news item is pinned to the top of the news section or not.
     */
    #[Column(type: Types::BOOLEAN)]
    public bool $pinned;
}
