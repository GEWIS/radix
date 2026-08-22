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
    private Member $member;

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
    private AddressTypes $type;

    /**
     * Country.
     */
    #[Column(
        type: Types::STRING,
        enumType: PostalRegions::class,
    )]
    private PostalRegions $country;

    /**
     * Street.
     */
    #[Column(type: Types::STRING)]
    private string $street;

    /**
     * House number (+ suffix).
     */
    #[Column(type: Types::STRING)]
    private string $number;

    /**
     * Postal code.
     */
    #[Column(type: Types::STRING)]
    private string $postalCode;

    /**
     * City.
     */
    #[Column(type: Types::STRING)]
    private string $city;

    /**
     * Phone number.
     */
    #[Column(type: Types::STRING)]
    private string $phone;

    /**
     * Get the member.
     */
    public function getMember(): Member
    {
        return $this->member;
    }

    /**
     * Set the member.
     */
    public function setMember(Member $member): void
    {
        $this->member = $member;
    }

    /**
     * Get the type.
     */
    public function getType(): AddressTypes
    {
        return $this->type;
    }

    /**
     * Set the type.
     */
    public function setType(AddressTypes $type): void
    {
        $this->type = $type;
    }

    /**
     * Get the country.
     */
    public function getCountry(): PostalRegions
    {
        return $this->country;
    }

    /**
     * Set the country.
     */
    public function setCountry(PostalRegions $country): void
    {
        $this->country = $country;
    }

    /**
     * Get the street.
     */
    public function getStreet(): string
    {
        return $this->street;
    }

    /**
     * Set the street.
     */
    public function setStreet(string $street): void
    {
        $this->street = $street;
    }

    /**
     * Get the house number (+ suffix).
     */
    public function getNumber(): string
    {
        return $this->number;
    }

    /**
     * Set the house number (+ suffix).
     */
    public function setNumber(string $number): void
    {
        $this->number = $number;
    }

    /**
     * Set the postal code.
     */
    public function setPostalCode(string $postalCode): void
    {
        $this->postalCode = $postalCode;
    }

    /**
     * Get the postal code.
     */
    public function getPostalCode(): string
    {
        return $this->postalCode;
    }

    /**
     * Get the city.
     */
    public function getCity(): string
    {
        return $this->city;
    }

    /**
     * Set the city.
     */
    public function setCity(string $city): void
    {
        $this->city = $city;
    }

    /**
     * Get the phone number.
     */
    public function getPhone(): string
    {
        return $this->phone;
    }

    /**
     * Set the phone number.
     */
    public function setPhone(string $phone): void
    {
        $this->phone = $phone;
    }

    /**
     * @return AddressGdprArrayType
     */
    public function toGdprArray(): array
    {
        return [
            'type' => $this->getType()->value,
            'street' => $this->getStreet(),
            'number' => $this->getNumber(),
            'postalCode' => $this->getPostalCode(),
            'city' => $this->getCity(),
            'postalRegion' => $this->getCountry()->value,
            'phone' => $this->getPhone(),
        ];
    }
}
