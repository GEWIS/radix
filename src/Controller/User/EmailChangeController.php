<?php

declare(strict_types=1);

namespace App\Controller\User;

use App\Controller\Application\NoLeakHeadersTrait;
use App\Entity\Application\Enums\AlertTypes;
use App\Entity\Database\EmailChangeLink;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Message\Database\EmailChangedNoticeEmail;
use App\Repository\Database\EmailChangeLinkRepository;
use App\Service\Database\ActionLinkService;
use App\Service\Database\Member as MemberService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_int;

/**
 * Outside `/user/settings` because a `SameSite=Strict` session cookie is not sent on the click from a
 * mailbox: asking for a session there would hand the member a new one. Being signed in is asked for behind
 * the hash instead.
 */
class EmailChangeController extends AbstractController
{
    use NoLeakHeadersTrait;

    private const string SESSION_KEY = '_email_change_link_id';

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly MemberService $memberService,
        private readonly ActionLinkService $actionLinkService,
        private readonly EmailChangeLinkRepository $emailChangeLinkRepository,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route(
        path: '/user/email-change/{token}',
        name: 'user_email_change_claim',
        requirements: ['token' => '[0-9a-f]{32}\.[0-9a-f]{96}'],
        methods: ['GET'],
    )]
    public function claim(string $token): Response
    {
        $link = $this->actionLinkService->resolveEmailChange($token);

        if (null === $link) {
            return $this->withNoLeakHeaders($this->render(
                'user/email-change.html.twig',
                ['link' => null],
            ));
        }

        return $this->withNoLeakHeaders($this->redirectToRoute(
            'user_email_change_confirm',
            ['th' => $this->actionLinkService->claim($link)],
        ));
    }

    #[Route(
        path: '/user/email-change',
        name: 'user_email_change_confirm',
        methods: ['GET'],
    )]
    public function confirm(Request $request): Response
    {
        $tempHash = (string) $request->query->get(
            'th',
            '',
        );

        if ('' !== $tempHash) {
            $claimed = $this->actionLinkService->findByTempHash($tempHash);

            if ($claimed instanceof EmailChangeLink) {
                // Single-use: spent as the page is handed over rather than once it is acted on.
                $this->actionLinkService->consumeTempHash($claimed);

                $request->getSession()->set(
                    self::SESSION_KEY,
                    $claimed->getId(),
                );
            }

            return $this->withNoLeakHeaders($this->redirectToRoute('user_email_change_confirm'));
        }

        $this->denyAccessUnlessGranted(
            UserRoles::User->value,
            message: 'You are not allowed to change these details.',
        );

        $user = $this->getUser();

        return $this->withNoLeakHeaders($this->render(
            'user/email-change.html.twig',
            [
                'link' => $user instanceof User
                    ? $this->pendingLink(
                        $request,
                        $user,
                    )
                    : null,
            ],
        ));
    }

    #[IsGranted(
        attribute: UserRoles::User->value,
        message: 'You are not allowed to change these details.',
    )]
    #[IsCsrfTokenValid(
        id: 'email_change',
        tokenKey: '_csrf_token',
    )]
    #[Route(
        path: '/user/email-change',
        name: 'user_email_change_apply',
        methods: ['POST'],
    )]
    public function apply(
        Request $request,
        #[CurrentUser]
        User $user,
    ): Response {
        $link = $this->pendingLink(
            $request,
            $user,
        );

        if (null === $link) {
            return $this->withNoLeakHeaders($this->render(
                'user/email-change.html.twig',
                ['link' => null],
            ));
        }

        $previousEmail = $link->getPreviousEmail();
        $member = $this->memberService->confirmEmailChange($link);

        $request->getSession()->remove(self::SESSION_KEY);

        // The replaced address is the one that still reaches a member whose account was taken.
        if (null !== $previousEmail) {
            $this->bus->dispatch(new EmailChangedNoticeEmail(
                $member->getLidnr(),
                $previousEmail,
                $link->getNewEmail(),
            ));
        }

        $this->addFlash(
            AlertTypes::Success->value,
            $this->translator->trans('Your e-mail address has been changed. You now sign in with it as well.'),
        );

        return $this->redirectToRoute('user_settings_details_index');
    }

    private function pendingLink(
        Request $request,
        User $user,
    ): ?EmailChangeLink {
        $linkId = $request->getSession()->get(self::SESSION_KEY);

        if (!is_int($linkId)) {
            return null;
        }

        $link = $this->emailChangeLinkRepository->find($linkId);

        if (
            null === $link
            || $link->isUsed()
            || $link->linkExpired()
            || $link->getMember()->getLidnr() !== $user->getMember()->getLidnr()
        ) {
            $request->getSession()->remove(self::SESSION_KEY);

            return null;
        }

        return $link;
    }
}
