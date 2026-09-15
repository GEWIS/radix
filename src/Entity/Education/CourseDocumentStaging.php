<?php

declare(strict_types=1);

namespace App\Entity\Education;

use App\Entity\Application\Enums\Languages;
use App\Entity\Application\Traits\IdentifiableTrait;
use App\Entity\Education\Enums\CourseDocumentTypes;
use App\Entity\Education\Enums\ExamTypes;
use App\Entity\User\User;
use App\Repository\Education\CourseDocumentStagingRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;

/**
 * A PDF that has been uploaded but not yet filed. Its fields mirror what an {@see Exam} or {@see Summary} needs, so
 * publishing is a copy rather than a translation.
 */
#[Entity(repositoryClass: CourseDocumentStagingRepository::class)]
class CourseDocumentStaging
{
    use IdentifiableTrait;

    /** Kept so a row can be recognised when a guess comes out wrong. */
    #[Column(type: Types::STRING)]
    public string $originalFilename;

    /** Carried over to the document on publication rather than copied again. */
    #[Column(type: Types::STRING)]
    public string $path;

    #[ManyToOne(targetEntity: User::class)]
    #[JoinColumn(
        name: 'uploaded_by',
        referencedColumnName: 'lidnr',
        nullable: true,
        onDelete: 'SET NULL',
    )]
    public ?User $uploadedBy = null;

    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $uploadedAt;

    /** Guessed from the filename, so it may be wrong or missing; checked to exist before anything is published. */
    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $courseCode = null;

    #[Column(
        type: Types::DATE_IMMUTABLE,
        nullable: true,
    )]
    public ?DateTimeImmutable $date = null;

    #[Column(
        type: Types::STRING,
        enumType: Languages::class,
    )]
    public Languages $language = Languages::English;

    #[Column(
        type: Types::STRING,
        enumType: CourseDocumentTypes::class,
    )]
    public CourseDocumentTypes $type = CourseDocumentTypes::Exam;

    #[Column(
        type: Types::STRING,
        nullable: true,
        enumType: ExamTypes::class,
    )]
    public ?ExamTypes $examType = ExamTypes::Final;

    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $author = null;

    #[Column(type: Types::BOOLEAN)]
    public bool $scanned = false;
}
