<?php

declare(strict_types=1);

namespace App\Entity\Frontpage;

use App\Entity\Application\Traits\IdentifiableTrait;
use App\Entity\Application\Traits\TimestampableTrait;
use App\Entity\User\Enums\UserRoles;
use App\Repository\Frontpage\PageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\HasLifecycleCallbacks;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;

/**
 * A page the association writes itself, addressed by its own words rather than by an id: everything on the website
 * that is neither news, an activity, nor a body's own page.
 *
 * The content is stored as HTML and rendered as-is, so it is sanitized on the way in; see
 * {@see \App\Service\Frontpage\PageAdminService}.
 */
#[Entity(repositoryClass: PageRepository::class)]
#[HasLifecycleCallbacks]
class Page
{
    use IdentifiableTrait;
    use TimestampableTrait;

    /**
     * Category of the page.
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
        name: 'category_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    public FrontpageLocalisedText $category;

    /**
     * Sub-category of the page.
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
        name: 'subCategory_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    public FrontpageLocalisedText $subCategory;

    /**
     * Name of the page.
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
        name: 'name_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    public FrontpageLocalisedText $name;

    /**
     * Title of the page.
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
     * The HTML content of the page.
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
     * The minimal role required to view a page.
     */
    #[Column(
        type: Types::STRING,
        enumType: UserRoles::class,
    )]
    public UserRoles $requiredRole;

    /**
     * @return array{
     *     categoryEn: ?string,
     *     category: ?string,
     *     subCategoryEn: ?string,
     *     subCategory: ?string,
     *     nameEn: ?string,
     *     name: ?string,
     *     titleEn: ?string,
     *     title: ?string,
     *     contentEn: ?string,
     *     content: ?string,
     *     requiredRole: string,
     * }
     */
    public function toArray(): array
    {
        return [
            'categoryEn' => $this->category->getValueEN(),
            'category' => $this->category->getValueNL(),
            'subCategoryEn' => $this->subCategory->getValueEN(),
            'subCategory' => $this->subCategory->getValueNL(),
            'nameEn' => $this->name->getValueEN(),
            'name' => $this->name->getValueNL(),
            'titleEn' => $this->title->getValueEN(),
            'title' => $this->title->getValueNL(),
            'contentEn' => $this->content->getValueEN(),
            'content' => $this->content->getValueNL(),
            'requiredRole' => $this->requiredRole->value,
        ];
    }
}
