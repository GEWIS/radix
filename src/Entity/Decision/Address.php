<?php

declare(strict_types=1);

namespace App\Entity\Decision;

use App\Doctrine\Query\Queryable;
use App\Entity\Database\Enums\AddressTypes;
use App\Entity\Database\Enums\PostalRegions;
use App\Repository\Decision\AddressRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;

/**
 * Address model.
 *
 * @phpstan-type AddressGdprArrayType = array{
 *     type: string,
 *     street: string,
 *     number: string,
 *     postalCode: string,
 *     city: string,
 *     postalRegion: string,
 *     phone: string,
 *  }
 */
#[Entity(repositoryClass: AddressRepository::class)]
#[Queryable]
class Address
{
    /**
     * Member. An address is part of the member's record and identifies this row, so it is removed with them; the
     * cascade is the backstop for the projection drifting out of step with the ledger, which removes each address
     * through the ORM before it removes the member.
     */
    #[Id]
    #[ManyToOne(
        targetEntity: Member::class,
        inversedBy: 'addresses',
    )]
    #[JoinColumn(
        name: 'lidnr',
        referencedColumnName: 'lidnr',
        onDelete: 'CASCADE',
    )]
    public Member $member;

    /**
     * Type.
     *
     * Can be one of:
     *
     * - home (Parent's home)
     * - student (Student's home)
     * - mail (Where GEWIS mail should go to)
     */
    #[Id]
    #[Column(
        type: Types::STRING,
        enumType: AddressTypes::class,
    )]
    public AddressTypes $type;

    /**
     * Country.
     */
    #[Column(
        type: Types::STRING,
        enumType: PostalRegions::class,
    )]
    public PostalRegions $country;

    /**
     * Street.
     */
    #[Column(type: Types::STRING)]
    public string $street;

    /**
     * House number (+ suffix).
     */
    #[Column(type: Types::STRING)]
    public string $number;

    /**
     * Postal code.
     */
    #[Column(type: Types::STRING)]
    public string $postalCode;

    /**
     * City.
     */
    #[Column(type: Types::STRING)]
    public string $city;

    /**
     * Phone number.
     */
    #[Column(type: Types::STRING)]
    public string $phone;

    /**
     * @return AddressGdprArrayType
     */
    public function toGdprArray(): array
    {
        return [
            'type' => $this->type->value,
            'street' => $this->street,
            'number' => $this->number,
            'postalCode' => $this->postalCode,
            'city' => $this->city,
            'postalRegion' => $this->country->value,
            'phone' => $this->phone,
        ];
    }
}
