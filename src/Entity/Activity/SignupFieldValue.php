<?php

declare(strict_types=1);

namespace App\Entity\Activity;

use App\Entity\Activity\Enums\SignupFieldTypes;
use App\Entity\Application\Enums\Languages;
use App\Entity\Application\Traits\IdentifiableTrait;
use App\Repository\Activity\SignupFieldValueRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * SignupFieldValue model.
 *
 * @phpstan-import-type SignupOptionGdprArrayType from SignupOption as ImportedSignupOptionGdprArrayType
 * @phpstan-type SignupFieldValueGdprArrayType = array{
 *     id: ?int,
 *     value: ?string,
 *     option: ?ImportedSignupOptionGdprArrayType,
 * }
 */
#[Entity(repositoryClass: SignupFieldValueRepository::class)]
class SignupFieldValue
{
    use IdentifiableTrait;

    /**
     * Field which the value belongs to.
     */
    #[ManyToOne(targetEntity: SignupField::class)]
    #[JoinColumn(
        name: 'field_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    public SignupField $field;

    /**
     * Signup which the value belongs to. An answer is part of the sign-up and never outlives it, which the ORM cascade
     * on the owning collection already says; the database says it too, so that a sign-up removed by the database (as
     * happens when the member behind it is taken out of the register) does not run into its own answers.
     */
    #[ManyToOne(
        targetEntity: Signup::class,
        inversedBy: 'fieldValues',
    )]
    #[JoinColumn(
        name: 'signup_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE',
    )]
    public Signup $signup;

    /**
     * The value of the associated field, is not an option.
     */
    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $value = null;

    /**
     * The option chosen.
     */
    #[ManyToOne(targetEntity: SignupOption::class)]
    #[JoinColumn(
        name: 'option_id',
        referencedColumnName: 'id',
    )]
    public ?SignupOption $option = null;

    /**
     * The human-readable, localised value for display: yes/no answers are translated, choice answers resolve to the
     * option's localised text, everything else is the raw string.
     */
    public function displayValue(
        TranslatorInterface $translator,
        Languages $language,
    ): string {
        return match ($this->field->type) {
            SignupFieldTypes::YesNo => $translator->trans($this->value ?? ''),
            SignupFieldTypes::Choice => $this->option?->value->getText($language) ?? '',
            default => $this->value ?? '',
        };
    }

    /**
     * @return SignupFieldValueGdprArrayType
     */
    public function toGdprArray(): array
    {
        return [
            'id' => $this->id,
            'value' => $this->value,
            'option' => $this->option?->toGdprArray(),
        ];
    }
}
