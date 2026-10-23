<?php

declare(strict_types=1);

namespace App\Controller\Database;

use App\Attribute\Application\RendersOnSuccess;
use App\Controller\Application\HandlesFormFlowTrait;
use App\Controller\Application\NoLeakHeadersTrait;
use App\Controller\Application\RendersRejectedSubmissionTrait;
use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\GraduateConversionLink;
use App\Entity\Database\RenewalLink;
use App\Form\Database\MemberApproveType;
use App\Form\Database\MemberGraduateConversionType;
use App\Form\Database\MemberRenewalType;
use App\Form\Database\Registration\RegistrationData;
use App\Form\Database\Registration\RegistrationFlowType;
use App\Form\SubmitButtons;
use App\Repository\Database\GraduateConversionLinkRepository;
use App\Repository\Database\RenewalLinkRepository;
use App\Security\User\SudoVoter;
use App\Service\Application\LocalePreference;
use App\Service\Database\ActionLinkService;
use App\Service\Database\Member as MemberService;
use App\Service\Database\ProspectiveMemberRemoval;
use App\Service\Database\RegistrationFailure;
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

use function assert;
use function is_int;
use function Symfony\Component\Translation\t;

/**
 * Everyone who has registered but whose membership the secretary has not confirmed yet, from the public sign-up form
 * that creates them to the moment they become a member or are removed again.
 *
 * The paths are on the actions rather than on the class, and the two public flows have no path here at all: they are
 * declared in config/routes.yaml, because the addresses they are served at are outside the administrative prefix this
 * controller is imported with.
 *
 * That is also why the four administrative actions still declare `#[IsGranted(SudoVoter::ATTRIBUTE)]` where the rest
 * of the administration leaves it to the path: here the prefix is a property of the action, so a second address given
 * to one of them would place it outside {@see \App\EventListener\User\SudoEnforcementListener}.
 */
final class ProspectiveMemberController extends AbstractController
{
    use HandlesFormFlowTrait;
    use NoLeakHeadersTrait;
    use RendersRejectedSubmissionTrait;

    private const string RENEWAL_SESSION_KEY = '_renewal_link_id';

    private const string CONVERSION_SESSION_KEY = '_graduate_conversion_link_id';

    public function __construct(
        private readonly MemberService $memberService,
        private readonly RegistrationService $registrationService,
        private readonly TranslatorInterface $translator,
        private readonly LocalePreference $localePreference,
        private readonly ActionLinkService $actionLinkService,
        private readonly RenewalLinkRepository $renewalLinkRepository,
        private readonly GraduateConversionLinkRepository $graduateConversionLinkRepository,
    ) {
    }

    /**
     * The address the form is reached at when the visitor has not stated which language they want it in.
     *
     * `/join` is on posters and behind gew.is/join, so it remains available, but it has no room for a language and
     * the form is a page like any other. The language in the session is used, and otherwise the one the browser asks
     * for.
     */
    public function subscribeUnlocalised(Request $request): Response
    {
        return $this->redirectToRoute(
            'join_index',
            ['_locale' => $this->localePreference->resolve($request)],
        );
    }

    /**
     * The public sign-up form, filled in by a visitor who is not (yet) known to us.
     *
     * `join_index` is declared in config/routes.yaml; the sign-up host allowlists the address it is served at.
     */
    #[RendersOnSuccess]
    public function subscribe(Request $request): Response
    {
        if (!$this->registrationService->isOpen($request->getClientIp())) {
            return $this->render('database/join/subscribe-disabled.html.twig');
        }

        $mailingLists = $this->registrationService->getMailingListsOnForm();
        $flow = $this->createFlow(
            RegistrationFlowType::class,
            RegistrationData::subscribedByDefault($mailingLists),
            ['mailing_lists' => $mailingLists],
        );
        $flow->handleRequest($request);

        if ($flow->isFinished()) {
            $data = $flow->getData();
            assert($data instanceof RegistrationData);

            $result = $this->registrationService->register($data);

            if (RegistrationFailure::EmailTaken === $result) {
                // Taken while the rest of the form was being filled in. Nothing was stored, so the flow keeps what
                // was entered and reopens at the step that has to be corrected.
                $flow->movePrevious(RegistrationData::STEP_PERSONAL);
                $this->addFlash(
                    'error',
                    $this->translator->trans('There already is a member with this email address.'),
                );

                return $this->redirectToRoute(
                    'join_index',
                    ['_locale' => $request->getLocale()],
                );
            }

            $flow->reset();

            // Stored, but without a checkout page; the e-mail that just went out can restart it.
            if (RegistrationFailure::CheckoutUnavailable === $result) {
                return $this->redirectToRoute(
                    'join_checkout_error',
                    ['_locale' => $request->getLocale()],
                );
            }

            // Rendered rather than redirected with a 303, because the Chromium CSP enforcer does not allow a
            // redirect after a POST.
            return $this->render(
                'database/application/redirect.html.twig',
                [
                    'destination' => $this->translator->trans('our payment provider'),
                    'url' => $result,
                ],
            );
        }

        // Reading the step form is what acts on the clicked button and so moves the flow on, which has to happen
        // before it is asked whether the step was handed in. Not on the finished path: the handler of the finish
        // button clears the flow, and what was entered is still read there.
        $form = $flow->getStepForm();

        if ($this->stepWasHandedIn($flow)) {
            return $this->redirectToRoute(
                'join_index',
                ['_locale' => $request->getLocale()],
            );
        }

        $this->flashRejectedStep(
            $flow,
            $this->translator,
        );

        // This action declares RendersOnSuccess for the page above, so nothing else sets the status on a rejected
        // step.
        return $this->render(
            'database/join/subscribe.html.twig',
            ['form' => $form],
            $this->rejectedSubmission($flow),
        );
    }

    /**
     * Graduate renewal, stage one: the address the link in the renewal e-mail points at.
     *
     * Served from the join host and open to anyone with the token: a user who follows the link is not signed in, and
     * the token is what identifies them. That is also why the token is not part of the address of the renewal form
     * itself: the click comes from a mailbox on another origin, so the token would be in the referrer of every
     * request that page makes, and the session cookie is not sent on it. The token is exchanged for a hash that is
     * valid for one use and three minutes, and the form is served behind that hash.
     *
     * A token that has been used or has expired is not an error: the page states that the link no longer works rather
     * than showing the renewal form.
     *
     * `join_renew_claim` is declared in config/routes.yaml, along with the two addresses this used to be served at,
     * which redirect here because a renewal e-mail sent months ago links to one of them.
     */
    public function renewClaim(string $token): Response
    {
        $renewalLink = $this->actionLinkService->resolveRenewal($token);

        if (null === $renewalLink) {
            return $this->render('database/join/renew-unavailable.html.twig');
        }

        return $this->withNoLeakHeaders($this->redirectToRoute(
            'join_renew',
            ['th' => $this->actionLinkService->claim($renewalLink)],
        ));
    }

    /**
     * Graduate renewal, stage two: the form itself, served behind the hash that stage one returns.
     */
    #[RendersOnSuccess]
    public function renew(Request $request): Response
    {
        $session = $request->getSession();

        if (null !== ($tempHash = $request->query->get('th'))) {
            $renewalLink = $this->actionLinkService->findByTempHash((string) $tempHash);

            if (!$renewalLink instanceof RenewalLink) {
                return $this->render('database/join/renew-unavailable.html.twig');
            }

            // Cleared when the form is served rather than when it is submitted, so the hash is valid once.
            $this->actionLinkService->consumeTempHash($renewalLink);

            $session->set(
                self::RENEWAL_SESSION_KEY,
                $renewalLink->id,
            );

            return $this->withNoLeakHeaders($this->redirectToRoute('join_renew'));
        }

        $renewalLinkId = $session->get(self::RENEWAL_SESSION_KEY);

        if (!is_int($renewalLinkId)) {
            return $this->render('database/join/renew-unavailable.html.twig');
        }

        $renewalLink = $this->renewalLinkRepository->find($renewalLinkId);

        // Checked again, because the link may have been used in another tab or expired while the page was open.
        if (
            null === $renewalLink
            || $renewalLink->used
            || $renewalLink->linkExpired()
        ) {
            $session->remove(self::RENEWAL_SESSION_KEY);

            return $this->render('database/join/renew-unavailable.html.twig');
        }

        $member = $renewalLink->member;
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
                    $this->translator->trans('There already is a member with this email address.'),
                ));
            } elseif ($form->isValid()) {
                $this->memberService->renewMember(
                    $member,
                    $renewalLink,
                    $renewalLink->newExpiration,
                );

                $session->remove(self::RENEWAL_SESSION_KEY);

                return $this->render(
                    'database/join/renew-done.html.twig',
                    ['member' => $member],
                );
            }
        }

        // As with the registration above: this action renders its own success page, so it sets the status itself
        // when the response is a rejection rather than the form being opened.
        return $this->withNoLeakHeaders($this->render(
            'database/join/renew.html.twig',
            ['form' => $form],
            $this->rejectedSubmission($form),
        ));
    }

    public function graduateClaim(string $token): Response
    {
        $link = $this->actionLinkService->resolveGraduateConversion($token);

        if (null === $link) {
            return $this->render('database/join/graduate-unavailable.html.twig');
        }

        return $this->withNoLeakHeaders($this->redirectToRoute(
            'join_graduate',
            ['th' => $this->actionLinkService->claim($link)],
        ));
    }

    public function graduate(Request $request): Response
    {
        $session = $request->getSession();

        if (null !== ($tempHash = $request->query->get('th'))) {
            $link = $this->actionLinkService->findByTempHash((string) $tempHash);

            if (!$link instanceof GraduateConversionLink) {
                return $this->render('database/join/graduate-unavailable.html.twig');
            }

            // Single-use: spent as the form is handed over rather than once it is submitted.
            $this->actionLinkService->consumeTempHash($link);

            $session->set(
                self::CONVERSION_SESSION_KEY,
                $link->id,
            );

            return $this->withNoLeakHeaders($this->redirectToRoute('join_graduate'));
        }

        $linkId = $session->get(self::CONVERSION_SESSION_KEY);

        if (!is_int($linkId)) {
            return $this->render('database/join/graduate-unavailable.html.twig');
        }

        $link = $this->graduateConversionLinkRepository->find($linkId);

        // Decided afresh: it may have been answered in another tab, or gone stale while the page was open.
        if (
            null === $link
            || $link->used
            || $link->linkExpired()
        ) {
            $session->remove(self::CONVERSION_SESSION_KEY);

            return $this->render('database/join/graduate-unavailable.html.twig');
        }

        $member = $link->member;
        $form = $this->createForm(
            MemberGraduateConversionType::class,
            $member,
            ['conversion_link' => $link],
        );
        $form->handleRequest($request);

        if (
            $form->isSubmitted()
            && $form->isValid()
        ) {
            $declined = SubmitButtons::clicked(
                $form,
                'decline',
            );

            if ($declined) {
                $removal = true === $form->get('removal')->getData();
                $this->memberService->declineGraduateConversion(
                    $member,
                    $link,
                    $removal,
                );

                $session->remove(self::CONVERSION_SESSION_KEY);

                return $this->render(
                    'database/join/graduate-declined.html.twig',
                    [
                        'member' => $member,
                        'removal_requested' => $removal,
                        'expiration' => $link->currentExpiration,
                    ],
                );
            }

            $email = (string) $form->get('email')->getData();

            if (
                !$this->memberService->emailBelongsToSomeoneElse(
                    $email,
                    $member,
                )
            ) {
                $this->memberService->acceptGraduateConversion(
                    $member,
                    $link,
                );

                $session->remove(self::CONVERSION_SESSION_KEY);

                return $this->render(
                    'database/join/graduate-done.html.twig',
                    ['member' => $member],
                );
            }

            $form->get('email')->addError(new FormError(
                $this->translator->trans('There already is a member with this email address.'),
            ));
        }

        return $this->withNoLeakHeaders($this->render(
            'database/join/graduate.html.twig',
            [
                'form' => $form,
                'expiration' => $link->currentExpiration,
            ],
        ));
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
                'canResendPaymentLink' => $prospectiveMember['canResendPaymentLink'],
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
                    ['lidnr' => $member->lidnr],
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

    /**
     * Only a hash of an action link's token is stored, so a link that was sent cannot be sent again as it was. This
     * generates a new one, which invalidates the link the applicant was given before.
     */
    #[IsGranted(SudoVoter::ATTRIBUTE)]
    #[Route(
        path: '/members/prospective/{id}/resend',
        name: 'join_prospective_member_resend',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(
        new Expression("'prospective_member_resend-' ~ args['id']"),
        tokenKey: '_csrf_token',
    )]
    public function resend(int $id): Response
    {
        $prospectiveMember = $this->memberService->getProspectiveMember($id)['member'];

        if (null === $prospectiveMember) {
            throw $this->createNotFoundException();
        }

        if ($this->registrationService->resendPaymentLink($prospectiveMember)) {
            $this->addFlash(
                'success',
                t('The payment link has been sent again.'),
            );
        } else {
            $this->addFlash(
                'danger',
                t('This prospective member has no payment left to make.'),
            );
        }

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
