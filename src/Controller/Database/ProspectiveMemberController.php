<?php

declare(strict_types=1);

namespace App\Controller\Database;

use App\Entity\Database\Enums\MembershipTypes;
use App\Form\Database\MemberApproveType;
use App\Form\Database\MemberRenewalType;
use App\Form\Database\RegistrationType;
use App\Security\User\SudoVoter;
use App\Service\Database\Member as MemberService;
use App\Service\Database\ProspectiveMemberRemoval;
use App\Service\Database\RegistrationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Everyone who has registered but whose membership the secretary has not confirmed yet, from the public sign-up form
 * that creates them to the moment they become a member or are removed again.
 *
 * The paths are on the actions rather than on the class, and the two public flows have no path here at all: they are
 * declared in config/routes.yaml, because the addresses they answer at sit outside the administrative prefix this
 * controller is imported with.
 */
final class ProspectiveMemberController extends AbstractController
{
    public function __construct(
        private readonly MemberService $memberService,
        private readonly RegistrationService $registrationService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The address the form is reached at when nobody has said which language they want it in.
     *
     * `/join` is on posters and behind gew.is/join, so it keeps answering; what it has no room for is a language, and
     * the form is a page like any other and should carry one. The locale the request already resolved to is the
     * visitor's own -- their session if they have chosen, and the default otherwise -- so it is the one to send them
     * to rather than a language picked here.
     */
    public function subscribeUnlocalised(Request $request): Response
    {
        return $this->redirectToRoute(
            'join_index',
            ['_locale' => $request->getLocale()],
        );
    }

    /**
     * The public sign-up form, filled in by someone who is not (yet) anyone to us.
     *
     * `join_index` is declared in config/routes.yaml; the sign-up host allowlists the address it answers at.
     */
    public function subscribe(Request $request): Response
    {
        if (!$this->registrationService->isOpen($request->getClientIp())) {
            return $this->render('database/join/subscribe-disabled.html.twig');
        }

        $form = $this->createForm(
            RegistrationType::class,
            null,
            [
                'mailing_lists' => $this->registrationService->getMailingListsOnForm(),
            ],
        );
        $form->handleRequest($request);

        if (
            $form->isSubmitted()
            && $form->isValid()
        ) {
            $checkoutUrl = $this->registrationService->register($form);

            if (null !== $checkoutUrl) {
                // Rendered rather than answered with a 303, because the Chromium CSP enforcer does not allow a
                // redirect after a POST.
                return $this->render(
                    'database/application/redirect.html.twig',
                    [
                        'destination' => $this->translator->trans('our payment provider'),
                        'url' => $checkoutUrl,
                    ],
                );
            }

            // A registration that is rejected leaves its reason on the form; one that is still valid was stored and
            // only lacks a checkout page, which the e-mail that just went out can restart.
            if ($form->isValid()) {
                return $this->redirectToRoute('join_checkout_error');
            }
        }

        return $this->render(
            'database/join/subscribe.html.twig',
            ['form' => $form],
        );
    }

    /**
     * Graduate renewal, reached from the link in the renewal e-mail.
     *
     * Served from the join host and open to anyone holding the token: whoever follows the link is not signed in, and
     * the token is what says who they are. A token that has been used or has expired is not an error — the page says
     * the link no longer works rather than pretending it does.
     *
     * `join_renew_short` and `join_renew` are declared in config/routes.yaml. Both spellings answer here: the short one
     * is what a renewal e-mail sent months ago links to, and the long one is what the register itself links to.
     */
    public function renew(
        Request $request,
        string $token,
    ): Response {
        $renewalLink = $this->memberService->getRenewalLink($token);

        if (null === $renewalLink) {
            return $this->render('database/join/renew-unavailable.html.twig');
        }

        $member = $renewalLink->getMember();
        $form = $this->createForm(
            MemberRenewalType::class,
            $member,
            ['renewal_link' => $renewalLink],
        );
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $email = (string) $form->get('email')->getData();

            if (
                $this->memberService->emailBelongsToSomeoneElse(
                    $email,
                    $member,
                )
            ) {
                $form->get('email')->addError(new FormError(
                    $this->translator->trans('There already is a member with this e-mail address.'),
                ));
            } elseif ($form->isValid()) {
                $this->memberService->renewMember(
                    $member,
                    $renewalLink,
                    $renewalLink->getNewExpiration(),
                );

                return $this->render(
                    'database/join/renew-done.html.twig',
                    ['member' => $member],
                );
            }
        }

        return $this->render(
            'database/join/renew.html.twig',
            ['form' => $form],
        );
    }

    #[IsGranted(SudoVoter::ATTRIBUTE)]
    #[Route(
        path: '/members/prospective',
        name: 'join_prospective_member_index',
        methods: ['GET'],
    )]
    public function index(): Response
    {
        return $this->render('database/join/prospective-member/index.html.twig');
    }

    #[IsGranted(SudoVoter::ATTRIBUTE)]
    #[Route(
        path: '/members/prospective/{id}',
        name: 'join_prospective_member_show',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function show(int $id): Response
    {
        $prospectiveMember = $this->memberService->getProspectiveMember($id);

        if (null === $prospectiveMember['member']) {
            throw $this->createNotFoundException();
        }

        return $this->render(
            'database/join/prospective-member/show.html.twig',
            [
                'member' => $prospectiveMember['member'],
                'canDelete' => $prospectiveMember['canDelete'],
                'approveMessages' => $prospectiveMember['approveMessages'],
                // Only a prospective member whose payment is settled can be approved, so the rest do not get a form.
                'form' => true === $prospectiveMember['canBeApproved']
                    ? $this->createForm(MemberApproveType::class)
                    : null,
            ],
        );
    }

    #[IsGranted(SudoVoter::ATTRIBUTE)]
    #[Route(
        path: '/members/prospective/{id}/finalize',
        name: 'join_prospective_member_finalize',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function finalize(
        Request $request,
        int $id,
    ): Response {
        $prospectiveMember = $this->memberService->getProspectiveMember($id)['member'];

        if (null === $prospectiveMember) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(MemberApproveType::class);
        $form->handleRequest($request);
        $membershipType = $form->get('type')->getData();

        if (
            $form->isSubmitted()
            && $form->isValid()
            && $membershipType instanceof MembershipTypes
        ) {
            $member = $this->memberService->finalizeSubscription(
                $membershipType,
                $prospectiveMember,
            );

            if (null !== $member) {
                $this->addFlash(
                    'success',
                    'The membership has been confirmed.',
                );

                return $this->redirectToRoute(
                    'member_show',
                    ['lidnr' => $member->getLidnr()],
                );
            }
        }

        $this->addFlash(
            'danger',
            'This prospective member cannot be approved.',
        );

        return $this->redirectToRoute(
            'join_prospective_member_show',
            ['id' => $id],
        );
    }

    #[IsGranted(SudoVoter::ATTRIBUTE)]
    #[Route(
        path: '/members/prospective/{id}/delete',
        name: 'join_prospective_member_delete',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(
        new Expression("'prospective_member_delete-' ~ args['id']"),
        tokenKey: '_csrf_token',
    )]
    public function delete(int $id): Response
    {
        $prospectiveMember = $this->memberService->getProspectiveMember($id)['member'];

        if (null === $prospectiveMember) {
            throw $this->createNotFoundException();
        }

        $removal = $this->registrationService->removeProspectiveMember($prospectiveMember);

        if (ProspectiveMemberRemoval::Removed === $removal) {
            $this->addFlash(
                'success',
                'The prospective member has been removed.',
            );

            return $this->redirectToRoute('join_prospective_member_index');
        }

        if (ProspectiveMemberRemoval::NotRemovable === $removal) {
            return $this->redirectToRoute(
                'join_prospective_member_show',
                ['id' => $id],
            );
        }

        // The membership fee is still with us, so the prospective member stays on file until the refund is settled.
        return $this->render(
            'database/join/prospective-member/delete.html.twig',
            ['reason' => $removal],
        );
    }
}
