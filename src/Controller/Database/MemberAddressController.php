<?php

declare(strict_types=1);

namespace App\Controller\Database;

use App\Entity\Database\Enums\AddressTypes;
use App\Form\Database\AddressEditType;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

use function Symfony\Component\Translation\t;

/**
 * The three addresses a member can have on file. Which one a page is about follows from the route, never from what
 * is submitted.
 */
#[Route(
    path: '/members/{lidnr}',
    requirements: [
        'lidnr' => '\d+',
        'type' => 'home|student|mail',
    ],
)]
final class MemberAddressController extends AbstractMemberController
{
    #[Route(
        path: '/add/address/{type}',
        name: 'member_address_add',
        methods: [
            'GET',
            'POST',
        ],
    )]
    public function add(
        Request $request,
        int $lidnr,
        AddressTypes $type,
    ): Response {
        $member = $this->resolveMember($lidnr);

        if ($member instanceof Response) {
            return $member;
        }

        $address = $this->memberService->getAddress(
            $member,
            $type,
            true,
        );
        $form = $this->createForm(
            AddressEditType::class,
            $address,
        );
        $form->handleRequest($request);

        if (
            $form->isSubmitted()
            && $form->isValid()
        ) {
            $this->memberService->addAddress($form);

            $this->addFlash(
                'success',
                t(
                    '%entity% has been added to %target%',
                    [
                        '%entity%' => t('Address'),
                        '%target%' => t('member'),
                    ],
                ),
            );

            return $this->redirectToRoute(
                'member_show',
                ['lidnr' => $lidnr],
            );
        }

        return $this->render(
            'database/member/edit-address.html.twig',
            [
                'add' => true,
                'address' => $address,
                'form' => $form,
            ],
        );
    }

    #[Route(
        path: '/edit/address/{type}',
        name: 'member_address_edit',
        methods: [
            'GET',
            'POST',
        ],
    )]
    public function edit(
        Request $request,
        int $lidnr,
        AddressTypes $type,
    ): Response {
        $member = $this->resolveMember($lidnr);

        if ($member instanceof Response) {
            return $member;
        }

        $address = $this->memberService->getAddress(
            $member,
            $type,
        );

        if (null === $address) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(
            AddressEditType::class,
            $address,
        );
        $form->handleRequest($request);

        if (
            $form->isSubmitted()
            && $form->isValid()
        ) {
            $this->memberService->editAddress($form);

            $this->addFlash(
                'success',
                t(
                    'Change(s) of %entity% have been saved!',
                    ['%entity%' => t('member address')],
                ),
            );

            return $this->redirectToRoute(
                'member_show',
                ['lidnr' => $lidnr],
            );
        }

        return $this->render(
            'database/member/edit-address.html.twig',
            [
                'add' => false,
                'address' => $address,
                'form' => $form,
            ],
        );
    }

    #[Route(
        path: '/remove/address/{type}',
        name: 'member_address_remove',
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(
        new Expression("'member_address_remove-' ~ args['lidnr']"),
        tokenKey: '_csrf_token',
    )]
    public function remove(
        int $lidnr,
        AddressTypes $type,
    ): Response {
        $member = $this->resolveMember($lidnr);

        if ($member instanceof Response) {
            return $member;
        }

        if (
            null === $this->memberService->getAddress(
                $member,
                $type,
            )
        ) {
            throw $this->createNotFoundException();
        }

        $this->memberService->removeAddress(
            $member,
            $type,
        );

        $this->addFlash(
            'success',
            t(
                'Succesfully deleted %entity%!',
                ['%entity%' => t('member address')],
            ),
        );

        return $this->redirectToRoute(
            'member_show',
            ['lidnr' => $lidnr],
        );
    }
}
