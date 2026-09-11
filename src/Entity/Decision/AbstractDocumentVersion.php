<?php

declare(strict_types=1);

namespace App\Entity\Decision;

use App\Entity\Application\Traits\IdentifiableTrait;
use App\Entity\Application\Traits\TimestampableTrait;
use App\Entity\User\User;
use DateTime;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\MappedSuperclass;

/**
 * One uploaded file of a versioned document. Versions are ordered by their insertion order (the id), never by parsing
 * the free-form label.
 */
#[MappedSuperclass]
abstract class AbstractDocumentVersion
{
    use IdentifiableTrait;
    use TimestampableTrait;

    /**
     * Free-form version label, e.g. "v1.2".
     */
    #[Column(
        type: Types::STRING,
        length: 32,
    )]
    public string $versionLabel;

    /**
     * Path of the file, relative to the storage directory.
     */
    #[Column(type: Types::STRING)]
    public string $path;

    /**
     * The account that uploaded this version. `null` for versions carried over from the legacy flat documents.
     */
    #[ManyToOne(targetEntity: User::class)]
    #[JoinColumn(
        name: 'uploadedBy',
        referencedColumnName: 'lidnr',
        nullable: true,
        onDelete: 'SET NULL',
    )]
    public ?User $uploadedBy = null;

    /**
     * When this version was uploaded. `null` when unknown, which is the case for most versions carried over from the
     * legacy flat documents.
     */
    #[Column(
        type: Types::DATETIME_MUTABLE,
        nullable: true,
    )]
    public ?DateTime $uploadedAt = null;
}
