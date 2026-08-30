<?php

declare(strict_types=1);

namespace App\Form\Database\Member;

use App\Entity\Database\Decision;
use App\Form\Database\BaseDecisionType;
use App\Form\Database\DataMapper\Member\SuspensionMapper;
use App\Form\Database\MemberLookupType;
use DateTimeInterface;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use function Symfony\Component\Translation\t;

/**
 * @extends AbstractType<Decision>
 */
class SuspensionType extends AbstractType
{
    public function __construct(private readonly SuspensionMapper $dataMapper)
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
        $builder
            ->add(
                'member',
                MemberLookupType::class,
            )
            ->add(
                'since',
                DateType::class,
                [
                    'label' => t('First Day'),
                    'widget' => 'single_text',
                    'constraints' => [new NotNull()],
                ],
            )
            ->add(
                'until',
                DateType::class,
                [
                    'label' => t('Last Day'),
                    'widget' => 'single_text',
                    'constraints' => [
                        new NotNull(),
                        new Callback([self::class, 'validateNotBeforeStart']),
                    ],
                ],
            )
            ->add(
                'submit',
                SubmitType::class,
                ['label' => t('Suspend Member')],
            )
            ->setDataMapper($this->dataMapper);
    }

    #[Override]
    public function getParent(): string
    {
        return BaseDecisionType::class;
    }

    /**
     * Both ends of the period are part of the suspension, so the shortest one there is lasts a single day and has the
     * same date at both ends.
     */
    public static function validateNotBeforeStart(
        mixed $value,
        ExecutionContextInterface $context,
    ): void {
        $root = $context->getRoot();

        if (!$root instanceof FormInterface) {
            return;
        }

        $since = $root->get('since')->getData();

        if (
            !$value instanceof DateTimeInterface
            || !$since instanceof DateTimeInterface
            || $value >= $since
        ) {
            return;
        }

        $context->buildViolation(t(
            'A suspension cannot end before it starts.',
            [],
            'validators',
        )->getMessage())->addViolation();
    }
}
