<?php

declare(strict_types=1);

namespace App\Form\User\ExternalApp;

use App\Form\Application\Flow\AbstractStepperFlowType;
use Override;
use Symfony\Component\Form\Flow\FormFlowBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function Symfony\Component\Translation\t;

/**
 * Admin create/edit form for a registered external application, one step to a request.
 */
final class ExternalAppFlowType extends AbstractStepperFlowType
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
                ExternalAppData::STEP_APPLICATION,
                ApplicationStepType::class,
            )
            ->addStep(
                ExternalAppData::STEP_SIGNING,
                SigningStepType::class,
            )
            ->addStep(
                ExternalAppData::STEP_CLAIMS,
                ClaimsStepType::class,
            );
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'data_class' => ExternalAppData::class,
            'step_labels' => [
                ExternalAppData::STEP_APPLICATION => t('Application'),
                ExternalAppData::STEP_SIGNING => t('Signing and delivery'),
                ExternalAppData::STEP_CLAIMS => t('Claims and availability'),
            ],
        ]);
    }
}
