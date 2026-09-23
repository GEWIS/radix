<?php

declare(strict_types=1);

namespace App\Entity\Database;

use App\Entity\Database\Enums\AddressTypes;
use App\Entity\Database\Enums\PostalRegions;
use App\Repository\Database\AddressRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;

/**
 * Address model.
 */
#[Entity(repositoryClass: AddressRepository::class)]
class Address
{
    /**
     * Member.
     */
    #[Id]
    #[ManyToOne(
        targetEntity: Member::class,
        inversedBy: 'addresses',
    )]
    #[JoinColumn(
        name: 'lidnr',
        referencedColumnName: 'lidnr',
    )]
    private ?Member $member = null;

    /**
     * Type
     *
     * Can be one of:
     *
     * - home (Parent's home)
     * - student (Student's home)
     * - mail (Where GEWIS mail should go to)
     */
    #[Id]
    #[Column(
        enumType: AddressTypes::class,
    )]
    public AddressTypes $type;

    /**
     * Country.
     */
    #[Column(
        enumType: PostalRegions::class,
    )]
    public PostalRegions $country;

    /**
     * Street.
     */
    #[Column(type: Types::STRING)]
    public string $street;

    /**
     * House number (+ suffix)
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
     * Get the member.
     */
    public function getMember(): ?Member
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
     * Convert to array.
     *
     * @return array{
     *     type: AddressTypes,
     *     country: PostalRegions,
     *     street: string,
     *     number: string,
     *     city: string,
     *     postalCode: string,
     *     phone: string,
     * }
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'country' => $this->country,
            'street' => $this->street,
            'number' => $this->number,
            'city' => $this->city,
            'postalCode' => $this->postalCode,
            'phone' => $this->phone,
        ];
    }
}
