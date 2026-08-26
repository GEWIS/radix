<?php

declare(strict_types=1);

namespace App\Form\Career\CompanyProfile;

use App\Form\Application\Flow\AbstractStepperFlowType;
use Override;
use Symfony\Component\Form\Flow\FormFlowBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function Symfony\Component\Translation\t;

/**
 * The company profile form. A company editing its own profile has no say over the identity fields, so its flow is
 * not built with that step at all.
 */
class CompanyProfileFlowType extends AbstractStepperFlowType
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

        if (true === $options['admin']) {
            $builder->addStep(
                CompanyProfileData::STEP_IDENTITY,
                IdentityStepType::class,
            );
        }

        $builder
            ->addStep(
                CompanyProfileData::STEP_PROFILE,
                ProfileStepType::class,
            )
            ->addStep(
                CompanyProfileData::STEP_CONTACT,
                ContactStepType::class,
            )
            ->addStep(
                CompanyProfileData::STEP_LOGO,
                LogoStepType::class,
                [
                    'has_square_logo' => $options['has_square_logo'],
                    'has_banner_logo' => $options['has_banner_logo'],
                ],
            );
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'data_class' => CompanyProfileData::class,
            'admin' => false,
            'has_square_logo' => false,
            'has_banner_logo' => false,
            'step_labels' => [
                CompanyProfileData::STEP_IDENTITY => t('Identity'),
                CompanyProfileData::STEP_PROFILE => t('Profile'),
                CompanyProfileData::STEP_CONTACT => t('Contact details'),
                CompanyProfileData::STEP_LOGO => t('Logo'),
            ],
        ]);

        foreach (
            [
                'admin',
                'has_square_logo',
                'has_banner_logo',
            ] as $option
        ) {
            $resolver->setAllowedTypes(
                $option,
                'bool',
            );
        }
    }
}
