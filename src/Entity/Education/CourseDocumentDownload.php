<?php

declare(strict_types=1);

namespace App\Entity\Education;

use App\Entity\Application\Traits\IdentifiableTrait;
use App\Entity\Education\Enums\DownloadStatus;
use App\Entity\User\User;
use App\Repository\Education\CourseDocumentDownloadRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The watermark names who requested a download and when, so the request is recorded before the file exists. The row
 * doubles as the handle the browser waits on while the worker builds the file, and as what a leaked copy is traced back
 * to: the same reference goes into the delivered PDF as machine-readable text.
 *
 * Who requested it is snapshotted as {@see $requestedByName} rather than read back from the association, because the
 * watermark has to keep the name it recorded at the time even if the account is later renamed or removed.
 */
#[Entity(repositoryClass: CourseDocumentDownloadRepository::class)]
class CourseDocumentDownload
{
    use IdentifiableTrait;

    /** The unguessable handle the download routes are keyed on. */
    #[Column(
        type: UuidType::NAME,
        unique: true,
    )]
    public Uuid $token;

    #[ManyToOne(targetEntity: CourseDocument::class)]
    #[JoinColumn(
        name: 'document_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE',
    )]
    public CourseDocument $document;

    /** Null for an anonymous request from the campus network. */
    #[ManyToOne(targetEntity: User::class)]
    #[JoinColumn(
        name: 'requested_by',
        referencedColumnName: 'lidnr',
        nullable: true,
        onDelete: 'SET NULL',
    )]
    public ?User $requestedBy = null;

    /**
     * What the watermark names as the person who downloaded it: the member's full name, or the client address for an
     * anonymous request from the campus network.
     */
    #[Column(type: Types::STRING)]
    public string $requestedByName;

    /**
     * The address the request came from. For an anonymous request from campus this is the only thing tying the built
     * file to the requester, so it is what the collect routes check.
     */
    #[Column(type: Types::STRING)]
    public string $requestedFrom;

    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $requestedAt;

    #[Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true,
    )]
    public ?DateTimeImmutable $collectedAt = null;

    #[Column(
        type: Types::STRING,
        enumType: DownloadStatus::class,
    )]
    public DownloadStatus $status = DownloadStatus::Pending;

    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $path = null;

    /**
     * Written into the delivered PDF as machine-readable text, so a copy found elsewhere leads back to this row.
     */
    public function getReference(): string
    {
        return $this->token->toRfc4122();
    }

    /**
     * The token is unguessable, but possession of it is not enough on its own: the file it leads to names the
     * requester, so passing the link on would let another user redistribute a document under that member's name. An
     * anonymous request from campus is only identified by the address it came from, so that is what has to match.
     */
    public function isCollectableBy(
        ?User $user,
        ?string $clientIp,
    ): bool {
        if (null !== $this->requestedBy) {
            return $this->requestedBy->getId() === $user?->getId();
        }

        return null === $user
            && $this->requestedFrom === $clientIp;
    }
}
