<?php

declare(strict_types=1);

namespace App\Controller\Application;

use App\Entity\Application\Enums\AlertTypes;
use App\Entity\Application\LabelInterface;
use App\Form\Application\LabelType;
use App\Repository\Application\LabelRepositoryInterface;
use App\Service\Application\LabelService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Managing the labels of one domain. The concrete controller declares the routes, the entity they act on and the
 * categories a label may not be similar to. The screens and the rules are the same for every domain.
 *
 * A label that is in use is retired rather than removed, because the revisions it is on cannot be changed: removing
 * it from them would change what was approved without anybody reviewing it. A retired label is kept where it is
 * already applied and is not offered anywhere else.
 *
 * The screens are a template per domain over a shared body, as in {@see AbstractRevisionReviewController}: the page
 * title, the breadcrumbs and the route names of the buttons are written out where they are read.
 */
abstract class AbstractLabelController extends AbstractController
{
    protected LabelService $labelService;

    protected TranslatorInterface $translator;

    /**
     * A setter rather than a constructor, so a concrete controller has a constructor of its own, as in
     * {@see AbstractRevisionController}.
     */
    #[Required]
    public function setLabelDependencies(
        LabelService $labelService,
        TranslatorInterface $translator,
    ): void {
        $this->labelService = $labelService;
        $this->translator = $translator;
    }

    abstract protected function labelRepository(): LabelRepositoryInterface;

    /**
     * @return class-string<LabelInterface>
     */
    abstract protected function labelClass(): string;

    /**
     * The categories of this domain, which a label may not be similar to.
     *
     * @return list<TranslatableInterface>
     */
    abstract protected function categories(): array;

    /**
     * The route the screens return to, which is also the one the domain's own templates link back to.
     */
    abstract protected function indexRoute(): string;

    /**
     * The template this domain lists its labels with.
     */
    abstract protected function indexTemplate(): string;

    /**
     * The template this domain edits one label with.
     */
    abstract protected function editTemplate(): string;

    protected function handleIndex(Request $request): Response
    {
        $class = $this->labelClass();
        $label = new $class();
        $form = $this->labelForm($label)->handleRequest($request);

        if (
            $form->isSubmitted()
            && $form->isValid()
        ) {
            $this->labelService->save($label);

            $this->addFlash(
                AlertTypes::Success->value,
                $this->translator->trans('The label was added.'),
            );

            return $this->redirectToIndex();
        }

        return $this->render(
            $this->indexTemplate(),
            [
                'labels' => $this->labelRepository()->findAllWithUsage(),
                'form' => $form,
            ],
        );
    }

    protected function handleEdit(
        Request $request,
        LabelInterface $label,
    ): Response {
        $form = $this->labelForm($label)->handleRequest($request);

        if (
            !$form->isSubmitted()
            || !$form->isValid()
        ) {
            return $this->render(
                $this->editTemplate(),
                [
                    'form' => $form,
                    'label' => $label,
                ],
            );
        }

        $this->labelService->save($label);

        $this->addFlash(
            AlertTypes::Success->value,
            $this->translator->trans('The label was saved.'),
        );

        return $this->redirectToIndex();
    }

    protected function handleDelete(LabelInterface $label): Response
    {
        if ($label->isInUse()) {
            $this->addFlash(
                AlertTypes::Warning->value,
                $this->translator->trans('This label is still in use, so it can only be retired.'),
            );

            return $this->redirectToIndex();
        }

        $this->labelService->delete($label);

        $this->addFlash(
            AlertTypes::Success->value,
            $this->translator->trans('The label was removed.'),
        );

        return $this->redirectToIndex();
    }

    protected function handleRetire(LabelInterface $label): Response
    {
        if ($label->retired) {
            $this->addFlash(
                AlertTypes::Warning->value,
                $this->translator->trans('This label was already retired.'),
            );

            return $this->redirectToIndex();
        }

        if (!$label->isInUse()) {
            $this->addFlash(
                AlertTypes::Warning->value,
                $this->translator->trans('This label is not in use, so it can be removed instead.'),
            );

            return $this->redirectToIndex();
        }

        $this->labelService->retire($label);

        $this->addFlash(
            AlertTypes::Success->value,
            $this->translator->trans(
                'The label was retired. It is kept where it is already used and is no longer offered.',
            ),
        );

        return $this->redirectToIndex();
    }

    protected function handleRestore(LabelInterface $label): Response
    {
        if (!$label->retired) {
            $this->addFlash(
                AlertTypes::Warning->value,
                $this->translator->trans('This label is already offered.'),
            );

            return $this->redirectToIndex();
        }

        $this->labelService->restore($label);

        $this->addFlash(
            AlertTypes::Success->value,
            $this->translator->trans('The label is offered again.'),
        );

        return $this->redirectToIndex();
    }

    /**
     * @return FormInterface<LabelInterface>
     */
    private function labelForm(LabelInterface $label): FormInterface
    {
        return $this->createForm(
            LabelType::class,
            $label,
            [
                'data_class' => $label::class,
                'categories' => $this->categories(),
            ],
        );
    }

    private function redirectToIndex(): RedirectResponse
    {
        return $this->redirectToRoute($this->indexRoute());
    }
}
