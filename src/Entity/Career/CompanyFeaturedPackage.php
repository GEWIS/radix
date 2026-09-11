<?php

declare(strict_types=1);

namespace App\Entity\Career;

use App\Entity\Career\Enums\CompanyPackageTypes;
use App\Repository\Career\CompanyFeaturedPackageRepository;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;
use Exception;
use Override;

/**
 * CompanyFeaturedPackage model.
 *
 * @phpstan-type CompanyFeaturedPackageArrayType = array{
 *     contractNumber: ?string,
 *     startDate: string,
 *     expirationDate: string,
 *     published: bool,
 *     article: ?string,
 *     articleEn: ?string,
 * }
 */
#[Entity(repositoryClass: CompanyFeaturedPackageRepository::class)]
class CompanyFeaturedPackage extends CompanyPackage
{
    /**
     * The featured package content article. This column should be nullable (the default), as this entity is part of the
     * {@link CompanyPackage} discriminator map.
     */
    #[OneToOne(
        targetEntity: CareerLocalisedText::class,
        cascade: [
            'persist',
            'remove',
        ],
        orphanRemoval: true,
    )]
    #[JoinColumn(
        name: 'article_id',
        referencedColumnName: 'id',
    )]
    public CareerLocalisedText $article;

    public function __construct()
    {
        parent::__construct();

        $this->article = new CareerLocalisedText(
            null,
            null,
        );
    }

    #[Override]
    public function getType(): CompanyPackageTypes
    {
        return CompanyPackageTypes::Featured;
    }

    /**
     * @return CompanyFeaturedPackageArrayType
     */
    #[Override]
    public function toArray(): array
    {
        $array = parent::toArray();
        $array['article'] = $this->article->getValueNL();
        $array['articleEn'] = $this->article->getValueEN();

        return $array;
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
    #[Override]
    public function exchangeArray(array $data): void
    {
        parent::exchangeArray($data);

        // Like the fields the parent exchanges, an absent key leaves the current value in place.
        $this->article->updateValues(
            $data['articleEn'] ?? $this->article->getValueEN(),
            $data['article'] ?? $this->article->getValueNL(),
        );
    }
}
