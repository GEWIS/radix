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

        // Read from the run rather than passed in beside it, so a request cannot open the form without the labels the
        // revision already carries and drop them on save.
        $data = $builder->getData();

        $builder
            ->addStep(
                OrganPageData::STEP_PAGE,
                PageStepType::class,
                [
                    'current_label_ids' => $data instanceof OrganPageData ? $data->labelIds : [],
                    'labels_enabled' => $options['labels_enabled'],
                ],
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
            // The offer of the label picker, decided by the controller from the body's kind. Off by default: a flow
            // that forgets it drops the picker rather than showing it to a body it cannot use.
            'labels_enabled' => false,
            'step_labels' => [
                OrganPageData::STEP_PAGE => t('Page'),
                OrganPageData::STEP_CONTACT => t('Where to find you'),
                OrganPageData::STEP_IMAGES => t('Logo and banner'),
            ],
        ]);
    }
}
