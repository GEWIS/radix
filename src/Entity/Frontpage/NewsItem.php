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
    private DateTime $date;

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
    private FrontpageLocalisedText $title;

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
    private FrontpageLocalisedText $content;

    /**
     * What the item is about, which is what the feed's filter narrows by.
     */
    #[Column(
        type: Types::STRING,
        enumType: NewsCategory::class,
    )]
    private NewsCategory $category = NewsCategory::Association;

    /**
     * Whether this news item is pinned to the top of the news section or not.
     */
    #[Column(type: Types::BOOLEAN)]
    private bool $pinned;

    public function getCategory(): NewsCategory
    {
        return $this->category;
    }

    public function setCategory(NewsCategory $category): void
    {
        $this->category = $category;
    }

    public function getPinned(): bool
    {
        return $this->pinned;
    }

    public function setPinned(bool $pinned): void
    {
        $this->pinned = $pinned;
    }

    public function getDate(): DateTime
    {
        return $this->date;
    }

    public function getTitle(): FrontpageLocalisedText
    {
        return $this->title;
    }

    public function setTitle(FrontpageLocalisedText $title): void
    {
        $this->title = $title;
    }

    public function getContent(): FrontpageLocalisedText
    {
        return $this->content;
    }

    public function setContent(FrontpageLocalisedText $content): void
    {
        $this->content = $content;
    }

    public function setDate(DateTime $date): void
    {
        $this->date = $date;
    }
}
