<?php

declare(strict_types=1);

namespace App\Entity\Career;

use App\Entity\Application\AbstractRevision;
use App\Entity\Application\AbstractRevisionComment;
use App\Entity\Application\Enums\SocialPlatform;
use App\Entity\Application\RevisableInterface;
use App\Entity\Application\Traits\HasSocialLinksTrait;
use App\Repository\Career\CompanyRevisionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\HasLifecycleCallbacks;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Override;

/**
 * An immutable snapshot of a {@see Company}'s revisable content for one point in its revision chain. The stable
 * {@see Company} owns its name, slug, representative details, packages and publication flag; everything that may be
 * revised and reviewed (the localised texts, the logo and the contact details) is on this entity.
 */
#[Entity(repositoryClass: CompanyRevisionRepository::class)]
#[HasLifecycleCallbacks]
class CompanyRevision extends AbstractRevision
{
    use HasSocialLinksTrait;

    /**
     * The company this revision belongs to.
     */
    #[ManyToOne(
        targetEntity: Company::class,
        inversedBy: 'revisions',
    )]
    #[JoinColumn(nullable: false)]
    public Company $company;

    /**
     * The revision this one supersedes (null for the first revision in the chain).
     */
    #[ManyToOne(targetEntity: self::class)]
    #[JoinColumn(nullable: true)]
    private ?CompanyRevision $previousRevision = null;

    #[OneToOne(
        targetEntity: CareerLocalisedText::class,
        cascade: [
            'persist',
            'remove',
        ],
        orphanRemoval: true,
    )]
    #[JoinColumn(
        name: 'slogan_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    public CareerLocalisedText $slogan;

    #[OneToOne(
        targetEntity: CareerLocalisedText::class,
        cascade: [
            'persist',
            'remove',
        ],
        orphanRemoval: true,
    )]
    #[JoinColumn(
        name: 'description_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    public CareerLocalisedText $description;

    #[OneToOne(
        targetEntity: CareerLocalisedText::class,
        cascade: [
            'persist',
            'remove',
        ],
        orphanRemoval: true,
    )]
    #[JoinColumn(
        name: 'website_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    public CareerLocalisedText $website;

    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $squareLogo = null;

    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $bannerLogo = null;

    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $contactName = null;

    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $contactAddress = null;

    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $contactEmail = null;

    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $contactPhone = null;

    /**
     * Where else this company can be followed. Owned by the revision, so adding or dropping one is reviewed like
     * any other part of the profile.
     *
     * @var Collection<array-key, CompanySocialLink>
     */
    #[OneToMany(
        targetEntity: CompanySocialLink::class,
        mappedBy: 'revision',
        cascade: [
            'persist',
            'remove',
        ],
        orphanRemoval: true,
    )]
    private Collection $socialLinks;

    public function __construct()
    {
        $this->socialLinks = new ArrayCollection();

        // The localised texts belong to the revision itself, and a form cannot bind to one that has none.
        // Doctrine does not run this when it hydrates a stored revision, so nothing is thrown away.
        $this->slogan = new CareerLocalisedText(
            null,
            null,
        );
        $this->description = new CareerLocalisedText(
            null,
            null,
        );
        $this->website = new CareerLocalisedText(
            null,
            null,
        );
    }

    #[Override]
    public function getRevisable(): RevisableInterface
    {
        return $this->company;
    }

    /**
     * @return class-string<AbstractRevisionComment>
     */
    #[Override]
    public function getCommentClass(): string
    {
        return CompanyRevisionComment::class;
    }

    #[Override]
    public function getPreviousRevision(): ?CompanyRevision
    {
        return $this->previousRevision;
    }

    public function setPreviousRevision(?CompanyRevision $previousRevision): void
    {
        $this->previousRevision = $previousRevision;
    }

    #[Override]
    public function detachPreviousRevision(): void
    {
        $this->previousRevision = null;
    }

    /**
     * @return Collection<array-key, CompanySocialLink>
     */
    #[Override]
    public function getSocialLinks(): Collection
    {
        return $this->socialLinks;
    }

    #[Override]
    protected function newSocialLink(SocialPlatform $platform): CompanySocialLink
    {
        $link = new CompanySocialLink($platform);
        $link->revision = $this;

        return $link;
    }
}
