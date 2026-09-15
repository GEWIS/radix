<?php

declare(strict_types=1);

namespace App\Entity\Career;

use App\Entity\Application\Traits\IdentifiableTrait;
use App\Entity\Career\Enums\CompanyPackageTypes;
use App\Repository\Career\CompanyPackageRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\ManyToOne;
use Exception;

use function boolval;

/**
 * CompanyPackage model.
 */
#[Entity(repositoryClass: CompanyPackageRepository::class)]
#[InheritanceType(value: 'SINGLE_TABLE')]
#[DiscriminatorColumn(
    name: 'packageType',
    type: Types::STRING,
    enumType: CompanyPackageTypes::class,
)]
#[DiscriminatorMap(
    value: [
        'job' => CompanyJobPackage::class,
        'banner' => CompanyBannerPackage::class,
        'featured' => CompanyFeaturedPackage::class,
        'highlight' => CompanyHighlightPackage::class,
    ],
)]
abstract class CompanyPackage
{
    use IdentifiableTrait;

    /**
     * An alphanumeric strings which identifies to which contract this package belongs.
     */
    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $contractNumber = null;

    /**
     * The package's starting date.
     */
    #[Column(type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $starts;

    /**
     * The package's expiration date.
     */
    #[Column(type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $expires;

    /**
     * The package's published state.
     */
    #[Column(type: Types::BOOLEAN)]
    public bool $published;

    /**
     * The package's company.
     */
    #[ManyToOne(
        targetEntity: Company::class,
        inversedBy: 'packages',
    )]
    public Company $company;

    public function __construct()
    {
    }

    /**
     * Get the package's starting date.
     */
    public function getStartingDate(): DateTimeImmutable
    {
        return $this->starts;
    }

    /**
     * Set the package's starting date.
     */
    public function setStartingDate(DateTimeImmutable $starts): void
    {
        $this->starts = $starts;
    }

    /**
     * Get the package's expiration date.
     */
    public function getExpirationDate(): DateTimeImmutable
    {
        return $this->expires;
    }

    /**
     * Set the package's expiration date.
     */
    public function setExpirationDate(DateTimeImmutable $expires): void
    {
        $this->expires = $expires;
    }

    /**
     * Gets the type of the package.
     */
    abstract public function getType(): CompanyPackageTypes;

    /**
     * Check whether this package is expired.
     */
    public function isExpired(): bool
    {
        return (new DateTimeImmutable()) >= $this->getExpirationDate();
    }

    public function isActive(): bool
    {
        if ($this->isExpired()) {
            return false;
        }

        return new DateTimeImmutable() >= $this->getStartingDate()
            && $this->published;
    }

    /**
     * @return array{
     *     contractNumber: ?string,
     *     startDate: string,
     *     expirationDate: string,
     *     published: bool,
     *     article?: ?string,
     *     articleEn?: ?string,
     * }
     */
    public function toArray(): array
    {
        return [
            'contractNumber' => $this->contractNumber,
            'startDate' => $this->getStartingDate()->format('Y-m-d'),
            'expirationDate' => $this->getExpirationDate()->format('Y-m-d'),
            'published' => $this->published,
        ];
    }

    /**
     * @phpstan-param array{
     *     contractNumber: ?string,
     *     startDate?: string,
     *     expirationDate?: string,
     *     published?: bool|string,
     *     article?: ?string,
     *     articleEn?: ?string,
     * } $data
     *
     * @throws Exception
     */
    public function exchangeArray(array $data): void
    {
        $this->contractNumber = $data['contractNumber'];
        $this->setStartingDate(
            isset($data['startDate']) ? new DateTimeImmutable($data['startDate']) : $this->getStartingDate(),
        );
        $this->setExpirationDate(
            isset($data['expirationDate'])
                ? new DateTimeImmutable($data['expirationDate'])
                : $this->getExpirationDate(),
        );
        $this->published = isset($data['published'])
            ? boolval($data['published'])
            : $this->published;
    }
}
