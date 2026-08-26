<?php

declare(strict_types=1);

namespace App\Form\Decision\OrganPage;

use App\Form\Application\Flow\AbstractStepperFlowType;
use Override;
use Symfony\Component\Form\Flow\FormFlowBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function Symfony\Component\Translation\t;

/**
 * The edit form for a body's page. Everything is staged with the working revision and only reaches the website once
 * the board agrees to it.
 */
class OrganPageFlowType extends AbstractStepperFlowType
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
                OrganPageData::STEP_PAGE,
                PageStepType::class,
            )
            ->addStep(
                OrganPageData::STEP_CONTACT,
                ContactStepType::class,
            )
            ->addStep(
                OrganPageData::STEP_IMAGES,
                ImagesStepType::class,
            );
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'data_class' => OrganPageData::class,
            'finish_label' => t('Save draft'),
            'step_labels' => [
                OrganPageData::STEP_PAGE => t('Page'),
                OrganPageData::STEP_CONTACT => t('Where to find you'),
                OrganPageData::STEP_IMAGES => t('Logo and banner'),
            ],
        ]);
    }
}
