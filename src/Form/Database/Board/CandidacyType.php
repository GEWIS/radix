<?php

declare(strict_types=1);

namespace App\Form\Database\Board;

use App\Entity\Application\AssociationYear;
use App\Entity\Database\Decision;
use App\Entity\Database\Meeting;
use App\Form\Database\BaseDecisionType;
use App\Form\Database\DataMapper\Board\CandidacyMapper;
use App\Form\Database\MemberLookupType;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Range;

use function Symfony\Component\Translation\t;

/**
 * @extends AbstractType<Decision>
 */
class CandidacyType extends AbstractType
{
    public function __construct(private readonly CandidacyMapper $dataMapper)
    {
    }

    /**
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(
        FormBuilderInterface $builder,
        array $options,
    ): void {
        $meeting = $options['meeting'];

        $builder
            ->add(
                'boardYear',
                IntegerType::class,
                [
                    'label' => t('Board Year'),
                    // The board a meeting puts candidates forward for is the one after the association year the
                    // meeting itself falls in, which is what the field starts on.
                    'data' => $meeting instanceof Meeting
                        ? AssociationYear::fromDate($meeting->getDate())->getYear() + 1
                        : null,
                    'help' => t('The first of the two years, so 2026 for the board of 2026 - 2027.'),
                    'constraints' => [
                        new NotNull(),
                        new Range(
                            min: 1990,
                            max: 2999,
                        ),
                    ],
                ],
            )
            // The row order is the constitutional order the candidates are put forward in, and it is what decides
            // the order of the sub-decisions this form records. A board may put candidates forward across several
            // meetings, so nothing here asks for a whole board at once.
            ->add(
                'candidates',
                CollectionType::class,
                [
                    'label' => t('Candidates'),
                    'entry_type' => MemberLookupType::class,
                    'allow_add' => true,
                    'allow_delete' => true,
                    'prototype' => true,
                    'prototype_name' => '__candidate__',
                    'block_prefix' => 'board_candidate_collection',
                    'constraints' => [new Count(min: 1)],
                ],
            )
            ->add(
                'submit',
                SubmitType::class,
                ['label' => t('Put Candidates Forward')],
            )
            ->setDataMapper($this->dataMapper);
    }

    #[Override]
    public function getParent(): string
    {
        return BaseDecisionType::class;
    }
}
