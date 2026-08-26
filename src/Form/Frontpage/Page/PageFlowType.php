<?php

declare(strict_types=1);

namespace App\Form\Frontpage\Page;

use App\Form\Application\Flow\AbstractStepperFlowType;
use Override;
use Symfony\Component\Form\Flow\FormFlowBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function Symfony\Component\Translation\t;

/**
 * A custom page: where it lives in the site, who may read it and what it says, one step to a request.
 */
class PageFlowType extends AbstractStepperFlowType
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
                PageData::STEP_ADDRESS,
                AddressStepType::class,
                ['role_editable' => $options['role_editable']],
            )
            ->addStep(
                PageData::STEP_CONTENT,
                ContentStepType::class,
            );
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'data_class' => PageData::class,
            'role_editable' => true,
            'step_labels' => [
                PageData::STEP_ADDRESS => t('Where the page lives'),
                PageData::STEP_CONTENT => t('Title and content'),
            ],
        ]);

        $resolver->setAllowedTypes(
            'role_editable',
            'bool',
        );
    }
}
