<?php

declare(strict_types=1);

namespace App\Entity\Activity;

use App\Entity\Application\LocalisedText as LocalisedTextModel;
use App\Entity\Application\Traits\IdentifiableTrait;
use App\Repository\Activity\SignupOptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToOne;

/**
 * SignupOption model.
 * Contains the possible options of a field of type ``option''.
 *
 * @phpstan-import-type LocalisedTextGdprArrayType from LocalisedTextModel as ImportedLocalisedTextGdprArrayType
 * @phpstan-type SignupOptionGdprArrayType = array{
 *     id: ?int,
 *     value: ImportedLocalisedTextGdprArrayType,
 * }
 */
#[Entity(repositoryClass: SignupOptionRepository::class)]
class SignupOption
{
    use IdentifiableTrait;

    /**
     * Field that the option belongs to.
     */
    #[ManyToOne(
        targetEntity: SignupField::class,
        cascade: ['persist'],
        inversedBy: 'options',
    )]
    #[JoinColumn(
        name: 'field_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    public SignupField $field;

    /**
     * The value of the option.
     */
    #[OneToOne(
        targetEntity: ActivityLocalisedText::class,
        cascade: [
            'persist',
            'remove',
        ],
        fetch: 'EAGER',
        orphanRemoval: true,
    )]
    #[JoinColumn(
        name: 'value_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    public ActivityLocalisedText $value;

    /**
     * The position of this option among its field's options; the organiser reorders them in the editor and this fixes
     * the order they are offered in (lower first). The id is the tiebreaker (see the ``SignupField::$options''
     * ordering) so pre-existing options keep their relative order.
     */
    #[Column(
        type: Types::INTEGER,
        options: ['default' => 0],
    )]
    public int $position = 0;

    /**
     * Whether this option is preselected as the default answer on the public sign-up form. At most one option per
     * field may be the default; it only sets the initial value for a new sign-up and never overrides an existing
     * answer.
     */
    #[Column(type: Types::BOOLEAN)]
    public bool $isDefault = false;

    public function __construct()
    {
        // Form-ready default; Doctrine bypasses the constructor when hydrating existing rows.
        $this->value = new ActivityLocalisedText();
    }

    /**
     * Returns an associative array representation of this object.
     *
     * @return array{
     *     id: ?int,
     *     value: ?string,
     *     valueEn: ?string,
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'value' => $this->value->getValueNL(),
            'valueEn' => $this->value->getValueEN(),
        ];
    }

    /**
     * @return SignupOptionGdprArrayType
     */
    public function toGdprArray(): array
    {
        return [
            'id' => $this->getId(),
            'value' => $this->value->toGdprArray(),
        ];
    }
}
