<?php

declare(strict_types=1);

namespace App\Entity\Career;

use App\Entity\Career\Enums\CompanyBannerFormats;
use App\Entity\Career\Enums\CompanyPackageTypes;
use App\Entity\User\CompanyUser as CompanyUserModel;
use App\Repository\Career\CompanyBannerPackageRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Override;

/**
 * CompanyBannerPackage model.
 *
 * A banner is the strip of advertising that runs between the news items on the home page and on the career page, in
 * one of the two sizes {@see CompanyBannerFormats} describes.
 *
 * Because it is shown to everyone who visits the site, a company cannot simply swap it out: it proposes one and the
 * committee accepts or rejects the proposal. The proposal is stored beside the live banner rather than replacing it, so
 * whatever is already up stays up until the committee accepts the new one. The committee sets a banner directly, since
 * there is no one else whose approval is needed.
 */
#[Entity(repositoryClass: CompanyBannerPackageRepository::class)]
class CompanyBannerPackage extends CompanyPackage
{
    /**
     * The size the banner was bought in, which is the box every image on this package has to fit exactly.
     */
    #[Column(
        type: Types::STRING,
        enumType: CompanyBannerFormats::class,
    )]
    public CompanyBannerFormats $format = CompanyBannerFormats::Leaderboard;

    /**
     * The banner's image URL.
     */
    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    private ?string $image = null;

    /**
     * A banner the company has proposed, waiting for the committee. Null when there is nothing outstanding.
     */
    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    private ?string $pendingImage = null;

    #[Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true,
    )]
    public private(set) ?DateTimeImmutable $pendingImageSubmittedAt = null;

    /**
     * Who proposed it, or null once their account is gone.
     */
    #[ManyToOne(targetEntity: CompanyUserModel::class)]
    #[JoinColumn(
        nullable: true,
        onDelete: 'SET NULL',
    )]
    public private(set) ?CompanyUserModel $pendingImageSubmittedBy = null;

    /**
     * Get the banner's image URL.
     */
    public function getImage(): ?string
    {
        return $this->image;
    }

    /**
     * Set the banner's image URL.
     */
    public function setImage(string $image): void
    {
        $this->image = $image;
    }

    public function getPendingImage(): ?string
    {
        return $this->pendingImage;
    }

    /**
     * Move the proposal's file without touching who proposed it or when. A proposal is made with
     * {@see self::proposeImage()}; this is for the storage migration, which changes where a file is stored and
     * nothing about the proposal itself.
     */
    public function setPendingImage(string $pendingImage): void
    {
        $this->pendingImage = $pendingImage;
    }

    public function hasPendingImage(): bool
    {
        return null !== $this->pendingImage;
    }

    /**
     * Puts a proposal up for review, and returns the earlier one it replaced so the caller can reclaim the file
     * behind it.
     */
    public function proposeImage(
        string $path,
        ?CompanyUserModel $submittedBy,
    ): ?string {
        $replaced = $this->pendingImage;

        $this->pendingImage = $path;
        $this->pendingImageSubmittedAt = new DateTimeImmutable();
        $this->pendingImageSubmittedBy = $submittedBy;

        return $replaced;
    }

    /**
     * Accepts the proposal, and returns the banner it replaced so the caller can reclaim the file behind it.
     */
    public function acceptPendingImage(): ?string
    {
        if (null === $this->pendingImage) {
            return null;
        }

        $replaced = $this->image;
        $this->image = $this->pendingImage;
        $this->clearPendingImage();

        return $replaced;
    }

    /**
     * Rejects the proposal, and returns the file behind it so the caller can reclaim that instead.
     */
    public function rejectPendingImage(): ?string
    {
        $rejected = $this->pendingImage;
        $this->clearPendingImage();

        return $rejected;
    }

    #[Override]
    public function getType(): CompanyPackageTypes
    {
        return CompanyPackageTypes::Banner;
    }

    private function clearPendingImage(): void
    {
        $this->pendingImage = null;
        $this->pendingImageSubmittedAt = null;
        $this->pendingImageSubmittedBy = null;
    }
}
