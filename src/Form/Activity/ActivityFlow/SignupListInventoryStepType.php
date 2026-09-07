<?php

declare(strict_types=1);

namespace App\Form\Activity\ActivityFlow;

use App\Entity\Activity\ActivityRevision;
use App\ViewModel\Activity\Admin\SignupListOverviewRow;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractType<ActivityRevision>
 */
class SignupListInventoryStepType extends AbstractType
{
    public function __construct(private readonly TranslatorInterface $translator)
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
        // A marker, so that what this step submits says which step it is: a step that collects nothing would carry
        // no key, and the flow takes a submission with no key for whichever step it is on now.
        $builder->add(
            'open',
            HiddenType::class,
            ['mapped' => false],
        );
    }

    /**
     * @param FormInterface<mixed> $form
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildView(
        FormView $view,
        FormInterface $form,
        array $options,
    ): void {
        $revision = $form->getData();
        $rows = [];

        if ($revision instanceof ActivityRevision) {
            // A list is named in the languages the activity is being written in, which the form still holds.
            $activity = $form->getRoot()->getData();
            $languages = $activity instanceof ActivityData
                ? $activity->languages()
                : $revision->languages();

            $position = 0;
            foreach ($revision->getSignupLists() as $list) {
                ++$position;
                $rows[] = SignupListOverviewRow::fromSignupList(
                    $list,
                    $position,
                    $list->hasLineageSignUps(),
                    $languages,
                    $this->translator,
                );
            }
        }

        $view->vars['lists'] = $rows;
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ActivityRevision::class,
            'label' => false,
        ]);
    }
}
