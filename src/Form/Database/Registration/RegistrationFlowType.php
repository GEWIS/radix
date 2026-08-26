<?php

declare(strict_types=1);

namespace App\Form\Database\Registration;

use App\Form\Application\Flow\AbstractStepperFlowType;
use Override;
use Symfony\Component\Form\Flow\FormFlowBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function Symfony\Component\Translation\t;

/**
 * Public sign-up, one step to a request.
 */
class RegistrationFlowType extends AbstractStepperFlowType
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
                RegistrationData::STEP_PERSONAL,
                PersonalStepType::class,
            )
            ->addStep(
                RegistrationData::STEP_STUDY,
                StudyStepType::class,
            )
            ->addStep(
                RegistrationData::STEP_ADDRESS,
                AddressStepType::class,
            )
            ->addStep(
                RegistrationData::STEP_LISTS,
                ListsStepType::class,
                ['mailing_lists' => $options['mailing_lists']],
            )
            ->addStep(
                RegistrationData::STEP_REVIEW,
                ReviewStepType::class,
            );
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'data_class' => RegistrationData::class,
            'mailing_lists' => [],
            'finish_label' => t('Go to checkout'),
            'step_labels' => [
                RegistrationData::STEP_PERSONAL => t('Personal details'),
                RegistrationData::STEP_STUDY => t('Study'),
                RegistrationData::STEP_ADDRESS => t('Address'),
                RegistrationData::STEP_LISTS => t('Mailing lists'),
                RegistrationData::STEP_REVIEW => t('Review and pay'),
            ],
        ]);

        $resolver->setAllowedTypes(
            'mailing_lists',
            'array',
        );
    }
}
