<?php

declare(strict_types=1);

namespace App\Controller\Database;

use App\Form\Database\MembershipTypeType;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

use function Symfony\Component\Translation\t;

/**
 * The membership of one member: which type they have, and how long it still runs.
 */
#[Route(
    path: '/members/{lidnr}/edit',
    requirements: ['lidnr' => '\d+'],
)]
final class MembershipController extends AbstractMemberController
{
    #[Route(
        path: '/membership',
        name: 'member_membership_edit',
        methods: [
            'GET',
            'POST',
        ],
    )]
    public function membership(
        Request $request,
        int $lidnr,
    ): Response {
        $member = $this->resolveMember($lidnr);

        if ($member instanceof Response) {
            return $member;
        }

        // The last membership rather than the current one: a change is entered against the period that runs latest,
        // and it is that period the change date has to fall inside.
        $membership = $member->getLastMembership();
        $form = $this->createForm(
            MembershipTypeType::class,
            null,
            ['membership' => $membership],
        );
        $form->handleRequest($request);

        if (
            $form->isSubmitted()
            && $form->isValid()
        ) {
            $this->memberService->membership(
                $member,
                $form,
            );

            $this->addFlash(
                'success',
                t(
                    'Change(s) of %entity% have been saved!',
                    ['%entity%' => t('membership type')],
                ),
            );

            return $this->redirectToRoute(
                'member_show',
                ['lidnr' => $lidnr],
            );
        }

        return $this->render(
            'database/member/membership.html.twig',
            [
                'form' => $form,
                'member' => $member,
                'membership' => $membership,
            ],
        );
    }

    #[Route(
        path: '/expiration',
        name: 'member_expiration_edit',
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(
        new Expression("'member_expiration_edit-' ~ args['lidnr']"),
        tokenKey: '_csrf_token',
    )]
    public function expiration(int $lidnr): Response
    {
        $member = $this->resolveMember($lidnr);

        if ($member instanceof Response) {
            return $member;
        }

        $this->memberService->expiration($member);

        $this->addFlash(
            'success',
            t(
                'Change(s) of %entity% have been saved!',
                ['%entity%' => t('membership expiration date')],
            ),
        );

        return $this->redirectToRoute(
            'member_show',
            ['lidnr' => $lidnr],
        );
    }
}
