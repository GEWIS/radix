<?php

declare(strict_types=1);

namespace App\Form\Career\VacancyProfile;

use App\Entity\Career\Company;
use App\Form\Application\Flow\AbstractStepperFlowType;
use Override;
use Symfony\Component\Form\Flow\FormFlowBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function Symfony\Component\Translation\t;

/**
 * The vacancy form. The same flow serves the board and the company itself; what separates them is which fields the
 * first step offers.
 */
class VacancyFlowType extends AbstractStepperFlowType
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
                VacancyData::STEP_GENERAL,
                GeneralStepType::class,
                [
                    'admin' => $options['admin'],
                    'identity_editable' => $options['identity_editable'],
                    'company' => $options['company'],
                    'current_package_id' => $options['current_package_id'],
                ],
            )
            ->addStep(
                VacancyData::STEP_DETAILS,
                DetailsStepType::class,
            )
            ->addStep(
                VacancyData::STEP_CONTACT,
                ContactStepType::class,
            );
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'data_class' => VacancyData::class,
            'admin' => false,
            'identity_editable' => true,
            'company' => null,
            'current_package_id' => null,
            'step_labels' => [
                VacancyData::STEP_GENERAL => t('General information'),
                VacancyData::STEP_DETAILS => t('Details'),
                VacancyData::STEP_CONTACT => t('Contact details'),
            ],
        ]);

        $resolver->setAllowedTypes(
            'admin',
            'bool',
        );
        $resolver->setAllowedTypes(
            'identity_editable',
            'bool',
        );
        $resolver->setAllowedTypes(
            'company',
            [
                Company::class,
                'null',
            ],
        );
        $resolver->setAllowedTypes(
            'current_package_id',
            [
                'int',
                'null',
            ],
        );
    }
}
