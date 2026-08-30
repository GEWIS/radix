<?php

declare(strict_types=1);

namespace App\Controller\User;

use App\Entity\Application\Enums\AlertTypes;
use App\Entity\Database\Address as AddressModel;
use App\Entity\Database\Enums\AddressTypes;
use App\Entity\Database\Enums\MailingListMemberOrigin;
use App\Entity\Database\MailingList as MailingListModel;
use App\Entity\Database\Member as MemberModel;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Form\Database\AddressEditType;
use App\Form\Database\DeleteAddressType;
use App\Form\Database\MemberListsType;
use App\Form\SubmitButtons;
use App\Form\User\MemberEmailChangeType;
use App\Message\Database\EmailChangeConfirmationEmail;
use App\Repository\Database\MailingListRepository;
use App\Repository\Database\MemberRepository;
use App\Service\Database\Member as MemberService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_map;

/**
 * What a member may change about themselves: the address the association writes to, where they live and where their
 * post goes, and which mailing lists they are on.
 *
 * Everything else the register holds about them -- their name, their study, their membership number and how long
 * their membership runs -- is shown here but is the secretary's to enter, because it is what the association decided
 * about them rather than what they told us.
 *
 * This writes the ledger rather than the projection, so it asks the register's own repositories for the member behind
 * the account. Everything here answers under `/user/settings`, and so is behind sudo by path; see
 * {@see \App\EventListener\User\SudoEnforcementListener}.
 */
#[IsGranted(
    attribute: UserRoles::User->value,
    message: 'You are not allowed to change these details.',
)]
#[Route(
    path: '/user/settings/details',
    name: 'user_settings_details_',
)]
class MemberDetailsController extends AbstractController
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly MemberService $memberService,
        private readonly MemberRepository $memberRepository,
        private readonly MailingListRepository $mailingListRepository,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route(
        path: '',
        name: 'index',
        methods: [
            'GET',
            'POST',
        ],
    )]
    public function index(
        Request $request,
        #[CurrentUser]
        User $user,
    ): Response {
        $member = $this->member($user);
        $syncLocked = $this->memberService->isMailingListSyncLocked();
        $selfServiceLists = $this->mailingListRepository->findAllSelfService();

        $emailForm = $this->createForm(MemberEmailChangeType::class)->handleRequest($request);

        if ($emailForm->isSubmitted()) {
            $response = $this->handleEmailChange(
                $emailForm,
                $member,
            );

            if (null !== $response) {
                return $response;
            }
        }

        $listsForm = $this->createForm(
            MemberListsType::class,
            null,
            [
                'member' => $member,
                'lists' => $selfServiceLists,
                // What a list is for is worth reading before ticking it, and the panel already says these are the
                // mailing lists.
                'describe' => true,
            ],
        )->handleRequest($request);

        if (
            $listsForm->isSubmitted()
            && $listsForm->isValid()
        ) {
            $response = $this->handleSubscriptions(
                $listsForm,
                $member,
                $selfServiceLists,
            );

            if (null !== $response) {
                return $response;
            }
        }

        return $this->render(
            'user/settings/details.html.twig',
            [
                'member' => $member,
                'emailForm' => $emailForm,
                'listsForm' => $listsForm,
                // While a synchronisation is running the pending states on the subscriptions are being turned into
                // real ones, so what the page would submit could not be saved anyway.
                'syncLocked' => $syncLocked,
                'selfServiceLists' => $selfServiceLists,
                'addresses' => $this->addresses($member),
            ],
        );
    }

    /**
     * The three addresses a member can have on file, in the order the page shows them, each with what is on file for
     * it or null. The register holds them as a collection of whichever ones exist, and a page that has to offer
     * adding one needs to know which are missing.
     *
     * @return list<array{type: AddressTypes, address: AddressModel|null}>
     */
    private function addresses(MemberModel $member): array
    {
        $addresses = [];

        foreach (AddressTypes::cases() as $type) {
            $addresses[] = [
                'type' => $type,
                'address' => $this->memberService->getAddress(
                    $member,
                    $type,
                ),
            ];
        }

        return $addresses;
    }

    /**
     * Add or correct one of the three addresses. Which one is being written follows from the address of the page,
     * never from what is submitted.
     */
    #[Route(
        path: '/address/{type}',
        name: 'address',
        requirements: ['type' => 'home|student|mail'],
        methods: [
            'GET',
            'POST',
        ],
    )]
    public function address(
        Request $request,
        AddressTypes $type,
        #[CurrentUser]
        User $user,
    ): Response {
        $member = $this->member($user);
        $existing = $this->memberService->getAddress(
            $member,
            $type,
        );
        $address = $existing ?? $this->memberService->getAddress(
            $member,
            $type,
            true,
        );

        $form = $this->createForm(
            AddressEditType::class,
            $address,
        )->handleRequest($request);

        if (
            $form->isSubmitted()
            && $form->isValid()
        ) {
            if (null === $existing) {
                $this->memberService->addAddress($form);
            } else {
                $this->memberService->editAddress($form);
            }

            $this->addFlash(
                AlertTypes::Success->value,
                $this->translator->trans('Your address has been saved.'),
            );

            return $this->redirectToRoute('user_settings_details_index');
        }

        return $this->render(
            'user/settings/details-address.html.twig',
            [
                'add' => null === $existing,
                'addressType' => $type,
                'form' => $form,
            ],
        );
    }

    #[Route(
        path: '/address/{type}/remove',
        name: 'address_remove',
        requirements: ['type' => 'home|student|mail'],
        methods: [
            'GET',
            'POST',
        ],
    )]
    public function removeAddress(
        Request $request,
        AddressTypes $type,
        #[CurrentUser]
        User $user,
    ): Response {
        $member = $this->member($user);

        if (
            null === $this->memberService->getAddress(
                $member,
                $type,
            )
        ) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(DeleteAddressType::class)->handleRequest($request);

        // `isValid()` before the button: a clicked button says nothing about the token.
        if (
            $form->isSubmitted()
            && $form->isValid()
        ) {
            if (
                SubmitButtons::clicked(
                    $form,
                    'submit_yes',
                )
            ) {
                $this->memberService->removeAddress(
                    $member,
                    $type,
                    $form,
                );

                $this->addFlash(
                    AlertTypes::Success->value,
                    $this->translator->trans('Your address has been removed.'),
                );
            }

            return $this->redirectToRoute('user_settings_details_index');
        }

        return $this->render(
            'user/settings/details-address-remove.html.twig',
            [
                'addressType' => $type,
                'form' => $form,
            ],
        );
    }

    /**
     * The member the account belongs to, as the register holds them.
     *
     * The account carries the projection's member, which is what the website reads; what this page writes is the
     * ledger, so it asks the register for the same member. An account without one cannot exist -- it is removed with
     * the member -- so a member that is not there is a broken installation rather than a page to render.
     */
    private function member(User $user): MemberModel
    {
        $member = $this->memberRepository->find($user->getMember()->getLidnr());

        if (null === $member) {
            throw $this->createNotFoundException();
        }

        return $member;
    }

    /**
     * Answers a response when the page is done with, and null when the form should be rendered again with what is
     * wrong with it.
     *
     * @param FormInterface<array<string, mixed>|null> $form
     */
    private function handleEmailChange(
        FormInterface $form,
        MemberModel $member,
    ): ?Response {
        if (!$form->isValid()) {
            return null;
        }

        $email = (string) $form->get('email')->getData();

        if ($email === $member->getEmail()) {
            $form->get('email')->addError(new FormError(
                $this->translator->trans('This is already your e-mail address.'),
            ));

            return null;
        }

        // Two records answering to one address cannot both be reached, so an address that is taken is refused. This
        // says only that it is taken, not by whom.
        if (
            $this->memberService->emailBelongsToSomeoneElse(
                $email,
                $member,
            )
        ) {
            $form->get('email')->addError(new FormError(
                $this->translator->trans('There already is a member with this e-mail address.'),
            ));

            return null;
        }

        $link = $this->memberService->requestEmailChange(
            $member,
            $email,
        );

        // The token exists for this request only: the register keeps a hash of it, so it goes into the message now or
        // it is gone.
        $this->bus->dispatch(new EmailChangeConfirmationEmail(
            $member->getLidnr(),
            $email,
            (string) $link->getPlainToken(),
        ));

        $this->addFlash(
            AlertTypes::Info->value,
            $this->translator->trans(
				// phpcs:ignore -- user-visible strings should not be split
			'We have sent a message to your new e-mail address. Your address changes once you have followed the link in it.',
            ),
        );

        return $this->redirectToRoute('user_settings_details_index');
    }

    /**
     * @param FormInterface<array<string, mixed>|null> $form
     * @param array<array-key, MailingListModel>       $selfServiceLists
     */
    private function handleSubscriptions(
        FormInterface $form,
        MemberModel $member,
        array $selfServiceLists,
    ): ?Response {
        $data = $form->getData();
        /** @var string[] $selected */
        $selected = $data['lists'] ?? [];

        $saved = $this->memberService->updateSubscriptions(
            $member,
            $selected,
            array_map(
                static fn (MailingListModel $list): string => $list->getName(),
                $selfServiceLists,
            ),
            MailingListMemberOrigin::SelfService,
        );

        // A sync that started while the page was open takes precedence, and the subscriptions are left alone.
        if (null === $saved) {
            $this->addFlash(
                AlertTypes::Danger->value,
                $this->translator->trans(
					// phpcs:ignore -- user-visible strings should not be split
				'Your subscriptions could not be saved because they are being synchronised. Please try again later.',
                ),
            );

            return null;
        }

        $this->addFlash(
            AlertTypes::Success->value,
            $this->translator->trans('Your subscriptions have been saved.'),
        );

        return $this->redirectToRoute('user_settings_details_index');
    }
}
