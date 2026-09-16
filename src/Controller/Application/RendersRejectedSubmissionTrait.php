<?php

declare(strict_types=1);

namespace App\Controller\Application;

use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * The status an action sets on a rejected submission when it renders its own success page.
 *
 * {@see \App\EventListener\Application\InvalidSubmissionStatusListener} sets it for every other action, by treating a
 * rendered response to a submission as a rejection. An action that declares
 * {@see \App\Attribute\Application\RendersOnSuccess} is exempt from that and states the rejection itself, and what
 * counts as one belongs here rather than at each of those actions.
 */
trait RendersRejectedSubmissionTrait
{
    /**
     * 422 where the submission was rejected, and no status where the form is only being opened.
     *
     * Turbo requires the status: it refuses to render a 200 response to a form submission, which leaves the errors
     * invisible.
     *
     * @param FormInterface<array<string, mixed>|null> $form
     */
    private function rejectedSubmission(FormInterface $form): ?Response
    {
        return $form->isSubmitted() && !$form->isValid()
            ? new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY)
            : null;
    }
}
