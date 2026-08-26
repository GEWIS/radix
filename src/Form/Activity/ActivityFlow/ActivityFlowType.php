<?php

declare(strict_types=1);

namespace App\Form\Activity\ActivityFlow;

use App\Entity\Activity\ActivityRevision;
use App\Form\Application\Flow\AbstractStepperFlowType;
use Override;
use Symfony\Component\Form\Flow\FormFlowBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function Symfony\Component\Translation\t;

/**
 * The activity form. Everything is staged with the working revision and only goes live on approval.
 */
class ActivityFlowType extends AbstractStepperFlowType
{
    /**
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildFormFlow(
        FormFlowBuilderInterface $builder,
        array $options,
    ): void {
        parent::buildFormFlow(
            $builder,
            $options,
        );

        $builder
            ->addStep(
                ActivityData::STEP_GENERAL,
                GeneralStepType::class,
                [
                    'schedule_locked' => $options['schedule_locked'],
                    'bound_organ_id' => $options['bound_organ_id'],
                ],
            )
            ->addStep(
                ActivityData::STEP_DETAILS,
                DetailsStepType::class,
            )
            // Bound to the revision itself: the sign-up lists are a tree of records with their own editor rather
            // than something the data object carries between the steps.
            ->addStep(
                ActivityData::STEP_SIGNUP_LISTS,
                SignupListsStepType::class,
                [
                    'mapped' => false,
                    'data' => $options['revision'],
                ],
            );
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'data_class' => ActivityData::class,
            'finish_label' => t('Save draft'),
            'schedule_locked' => false,
            'bound_organ_id' => null,
            'revision' => null,
            'step_labels' => [
                ActivityData::STEP_GENERAL => t('General information'),
                ActivityData::STEP_DETAILS => t('Details'),
                ActivityData::STEP_SIGNUP_LISTS => t('Sign-up lists'),
            ],
        ]);

        $resolver->setAllowedTypes(
            'schedule_locked',
            'bool',
        );
        $resolver->setAllowedTypes(
            'bound_organ_id',
            [
                'int',
                'null',
            ],
        );
        $resolver->setAllowedTypes(
            'revision',
            [
                ActivityRevision::class,
                'null',
            ],
        );
    }
}
