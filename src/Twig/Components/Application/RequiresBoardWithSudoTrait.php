<?php

declare(strict_types=1);

namespace App\Twig\Components\Application;

use App\Entity\User\Enums\UserRoles;
use App\Security\User\SudoVoter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The access check for a live component that is only reachable from the administration.
 *
 * A component is served under `/_components`, outside the paths {@see \App\Security\User\SudoArea} lists, so the
 * enforcement listener does not cover it and the component checks for itself.
 */
trait RequiresBoardWithSudoTrait
{
    private readonly Security $security;

    /**
     * Whether this render has already been through the checks below. Every guarded getter calls them, and one render
     * of the meeting administration calls them twelve times; each `SUDO` decision is a read of the grant in Valkey,
     * and neither of the two answers can change within a render.
     */
    private bool $accessAsserted = false;

    /**
     * Two refusals that are identical here and different to the caller. A user without a board seat cannot resolve
     * the refusal by confirming a password and is refused outright; a user whose sudo grant expired can, and the
     * attribute {@see \App\EventListener\User\SudoAccessDeniedListener} matches on marks that case.
     */
    private function assertAccess(): void
    {
        if ($this->accessAsserted) {
            return;
        }

        if (!$this->security->isGranted(UserRoles::Board->value)) {
            throw new AccessDeniedException();
        }

        if ($this->security->isGranted(SudoVoter::ATTRIBUTE)) {
            $this->accessAsserted = true;

            return;
        }

        $refused = new AccessDeniedException('This part of the site is behind sudo.');
        $refused->setAttributes(SudoVoter::ATTRIBUTE);

        throw $refused;
    }
}
