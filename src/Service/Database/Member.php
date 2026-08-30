<?php

declare(strict_types=1);

namespace App\Service\Database;

use App\Entity\Database\Address as AddressModel;
use App\Entity\Database\AuditEntry as AuditEntryModel;
use App\Entity\Database\AuditMailingListMembership;
use App\Entity\Database\AuditNote as AuditNoteModel;
use App\Entity\Database\AuditRenewal as AuditRenewalModel;
use App\Entity\Database\Enums\AddressTypes;
use App\Entity\Database\Enums\AttentionReasons;
use App\Entity\Database\Enums\MailingListMemberAction;
use App\Entity\Database\Enums\MailingListMemberOrigin;
use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\Enums\PostalRegions;
use App\Entity\Database\Enums\ProspectiveMemberFilter;
use App\Entity\Database\Enums\Studies;
use App\Entity\Database\MailingListMember as MailingListMemberModel;
use App\Entity\Database\Member as MemberModel;
use App\Entity\Database\Membership as MembershipModel;
use App\Entity\Database\MemberUpdate as MemberUpdateModel;
use App\Entity\Database\PaymentLink;
use App\Entity\Database\ProspectiveMember as ProspectiveMemberModel;
use App\Entity\Database\RenewalLink as RenewalLinkModel;
use App\Entity\User\User;
use App\Form\Database\Registration\RegistrationData;
use App\Message\Database\RefundProblemEmail;
use App\Message\Database\RegistrationUpdate;
use App\Message\Database\RegistrationUpdateEmail;
use App\Repository\Database\ActionLinkRepository;
use App\Repository\Database\AuditEntryRepository;
use App\Repository\Database\MailingListMemberRepository;
use App\Repository\Database\MailingListRepository;
use App\Repository\Database\MemberRepository;
use App\Repository\Database\MemberUpdateRepository;
use App\Repository\Database\ProspectiveMemberRepository;
use App\Service\Checker\Renewal as RenewalService;
use App\Validator\Database\BulkMemberIds;
use DateTime;
use ReflectionClass;
use RuntimeException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_diff;
use function array_intersect;
use function array_merge;
use function array_unique;
use function array_values;
use function assert;
use function date;
use function in_array;
use function uasort;

class Member
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly MailingListRepository $mailingListRepository,
        private readonly MailingListMemberRepository $mailingListMemberRepository,
        private readonly ActionLinkRepository $actionLinkRepository,
        private readonly AuditEntryRepository $auditEntryRepository,
        private readonly MemberRepository $memberRepository,
        private readonly MemberUpdateRepository $memberUpdateRepository,
        private readonly ProspectiveMemberRepository $prospectiveMemberRepository,
        private readonly MailingListService $mailingListService,
        private readonly RenewalService $renewalService,
        private readonly Security $security,
        private readonly MessageBusInterface $bus,
        private readonly Audit $auditService,
    ) {
    }

    /**
     * Subscribe a member from what the sign-up flow collected.
     *
     * Answers null when the address is already spoken for. The flow rejects that on the step that asks for it, so
     * getting here means it was taken while the rest of the form was being filled in.
     */
    public function subscribe(RegistrationData $data): ?ProspectiveMemberModel
    {
        if (
            null === $data->email
            || $this->memberRepository->hasMemberWith($data->email)
            || $this->prospectiveMemberRepository->hasMemberWith($data->email)
        ) {
            return null;
        }

        $prospectiveMember = new ProspectiveMemberModel();
        $prospectiveMember->setEmail($data->email);
        $prospectiveMember->setInitials((string) $data->initials);
        $prospectiveMember->setFirstName((string) $data->firstName);
        $prospectiveMember->setMiddleName($data->middleName);
        $prospectiveMember->setLastName((string) $data->lastName);
        $prospectiveMember->setStudentNumber($data->studentNumber);
        $prospectiveMember->setBirth(DateTime::createFromInterface($data->birth ?? new DateTime()));
        $prospectiveMember->setStudy($data->study ?? Studies::Other);

        // changed on date
        $date = new DateTime();
        $date->setTime(
            0,
            0,
        );
        $prospectiveMember->setChangedOn($date);

        // store the address
        $address = new AddressModel();
        $address->setType(AddressTypes::Student);
        $address->setCountry($data->country ?? PostalRegions::Netherlands);
        $address->setStreet((string) $data->street);
        $address->setNumber((string) $data->number);
        $address->setPostalCode((string) $data->postalCode);
        $address->setCity((string) $data->city);
        $address->setPhone($data->phone);
        $prospectiveMember->setAddress($address);

        // check mailing lists
        $prospectiveMember->addLists($data->lists);

        // subscribe to default mailing lists not on the form
        foreach ($this->mailingListRepository->findDefault() as $list) {
            $prospectiveMember->addList($list->getName());
        }

        $this->prospectiveMemberRepository->persist($prospectiveMember);

        // Create a payment link for the prospective member in the event that the checkout did not succeed.
        $paymentLink = new PaymentLink();
        $paymentLink->setProspectiveMember($prospectiveMember);
        $prospectiveMember->setPaymentLink($paymentLink);
        $this->actionLinkRepository->persist($paymentLink);

        return $prospectiveMember;
    }

    /**
     * Queue an e-mail to the (prospective) member and the secretary with an update on the (prospective) member's
     * registration.
     *
     * Only the membership number travels; the record is read back and rendered from by
     * {@see \App\MessageHandler\Database\RegistrationUpdateEmailHandler}. Every caller has flushed by the time it
     * gets here, so there is something left to read back.
     */
    public function sendRegistrationUpdateEmail(
        MemberModel|ProspectiveMemberModel $member,
        RegistrationUpdate $type,
    ): void {
        $this->bus->dispatch(
            new RegistrationUpdateEmail(
                $type,
                $member->getLidnr(),
            ),
        );
    }

    public function sendRefundProblemEmail(
        string $refundId,
        string $refundStatus,
    ): void {
        $this->bus->dispatch(
            new RefundProblemEmail(
                $refundId,
                $refundStatus,
            ),
        );
    }

    /**
     * Turn a prospective member into a member.
     *
     * The member is built from the prospective member, whose data was validated when they registered; only the
     * membership type is decided during approval. Returns `null` when the checkout does not (yet) allow approval.
     */
    public function finalizeSubscription(
        MembershipTypes $membershipType,
        ProspectiveMemberModel $prospectiveMember,
    ): ?MemberModel {
        if (!$prospectiveMember->canBeApproved()) {
            return null;
        }

        if ($this->memberRepository->hasMemberWith($prospectiveMember->getEmail())) {
            // phpcs:ignore -- user-visible strings should not be split
            throw new RuntimeException('You cannot approve this member. A member with this email address already exists. Make sure this is not an error in the database. Disapproving will refund the member, so make sure they paid twice before refunding.');
        }

        $member = new MemberModel();
        $member->setInitials($prospectiveMember->getInitials());
        $member->setFirstName($prospectiveMember->getFirstName());
        $member->setMiddleName($prospectiveMember->getMiddleName());
        $member->setLastName($prospectiveMember->getLastName());
        $member->setEmail($prospectiveMember->getEmail());
        $member->setBirth($prospectiveMember->getBirth());
        $member->setStudy($prospectiveMember->getStudy());
        $member->setStudentNumber($prospectiveMember->getStudentNumber());

        // changed on date
        $date = new DateTime();
        $date->setTime(
            0,
            0,
        );
        $member->setChangedOn($date);

        // creating the first membership for the member
        // sensible defaults are set in the creation
        $membership = new MembershipModel(
            member: $member,
            type: $membershipType,
            startDate: null,
            endDate: null,
        );
        $member->addMembership($membership);

        // add address
        $member->addAddresses($prospectiveMember->getAddresses());

        // The subscriptions chosen on the registration form, keyed by name so that a list that is both offered and
        // subscribed to by default is only added once.
        $lists = [];
        foreach ($this->mailingListRepository->findAllOnForm() as $list) {
            if (
                !in_array(
                    $list->getName(),
                    $prospectiveMember->getLists(),
                    true,
                )
            ) {
                continue;
            }

            $lists[$list->getName()] = $list;
        }

        // subscribe to default mailing lists not on the form
        foreach ($this->mailingListRepository->findDefault() as $list) {
            $lists[$list->getName()] = $list;
        }

        foreach ($lists as $list) {
            // Ignore Mailman/listmonk sync lock here as we _always_ need to persist this information.
            // Will be cascade persisted through `$member`.
            $mailingListMember = new MailingListMemberModel();
            $mailingListMember->setMailingList($list);
            $mailingListMember->setMember($member);
            // Force cascade by adding to member.
            $member->addList($mailingListMember);
        }

        // Set paid automatically.
        $membership->setPaid(20);

        // Remove prospectiveMember model
        $this->memberRepository->persist($member);

        $this->removeProspective($prospectiveMember);

        $this->sendRegistrationUpdateEmail(
            $member,
            RegistrationUpdate::Welcome,
        );

        return $member;
    }

    /**
     * Get member info.
     */
    public function getMember(int $id): ?MemberModel
    {
        return $this->memberRepository->findSimple($id);
    }

    /**
     * Get a member including decision information if that exists. This can therefor return `null` even though the
     * member exists.
     */
    public function getMemberWithDecisions(int $id): ?MemberModel
    {
        return $this->memberRepository->findWithInstallations($id);
    }

    /**
     * Get prospective member info
     *
     * @return array{
     *     member: ?ProspectiveMemberModel,
     *     canBeApproved: ?bool,
     *     canDelete: ?bool,
     *     approveMessages: ?array<array-key, string[]>,
     * }
     */
    public function getProspectiveMember(int $id): array
    {
        $member = $this->prospectiveMemberRepository->find($id);

        if (null === $member) {
            return [
                'member' => null,
                'canBeApproved' => null,
                'canDelete' => null,
                'approveMessages' => null,
            ];
        }

        $approveMessages = [];

        // During the remainder of 2026, show a warning.
        if (2026 >= date('Y')) {
            $approveMessages[] = [
                'info',
                // phpcs:ignore -- user-visible strings should not be split
                '<b>Warning:</b> TU/e data is no longer automatically being checked as of 2026. Suggestions are based on member-inputted information.',
            ];
        }

        if ($member->getStudy()->isMcsStudy()) {
            $approveMessages[] = [
                'success',
                // phpcs:ignore -- user-visible strings should not be split
                '<b>Info:</b> Member studies at department. Recommended membership type: <strong>Gewoon lid</strong>.',
            ];
        } elseif ($member->getStudy()->isEngDPhD()) {
            $approveMessages[] = [
                'warning',
                // phpcs:ignore -- user-visible strings should not be split
                '<b>Warning:</b> Member is EngD/PhD candidate. Recommended membership type: <strong>Extern lid</strong>.',
            ];
        } else {
            $approveMessages[] = [
                'danger',
                // phpcs:ignore -- user-visible strings should not be split
                '<b>Warning:</b> Member does not study at department, manual check needed.',
            ];
        }

        return [
            'member' => $member,
            'canBeApproved' => $member->canBeApproved(),
            'canDelete' => $member->canBeDeleted(),
            'approveMessages' => $approveMessages,
        ];
    }

    /**
     * Toggle if a member receives the supremum.
     */
    public function setSupremum(
        MemberModel $member,
        string $value,
    ): void {
        $member->setSupremum($value);

        $this->memberRepository->persist($member);
    }

    /**
     * Search for a member that is not deleted, expired, and hidden.
     *
     * @return MemberModel[]
     */
    public function searchFiltered(string $query): array
    {
        return $this->memberRepository->search($query);
    }

    /**
     * Check if we can easily remove a member.
     */
    public function canRemove(MemberModel $member): bool
    {
        return $this->memberRepository->canRemove($member);
    }

    /**
     * Remove a member.
     */
    public function remove(MemberModel $member): void
    {
        foreach ($member->getMailingListMemberships() as $mailingListMembership) {
            $mailingListMembership->setToBeDeleted(true);
            $mailingListMembership->unsetMember();
            $this->mailingListMemberRepository->persist($mailingListMembership);
        }

        if ($this->canRemove($member)) {
            $this->memberRepository->remove($member);
        } else {
            $this->clear($member);
        }
    }

    /**
     * Remove all members that are expired on or before some date.
     */
    public function removeExpiredMembers(DateTime $expiration): void
    {
        $members = $this->memberRepository->findExpired($expiration);

        foreach ($members as $member) {
            $this->remove($member);
        }
    }

    /**
     * Remove a prospective member.
     */
    public function removeProspective(ProspectiveMemberModel $member): void
    {
        $this->prospectiveMemberRepository->remove($member);
    }

    /**
     * Remove all prospective members whose last Checkout Session has fully expired (1 + 30 + 1 day ago) or failed 31
     * days ago or who don't have a checkout session.
     */
    public function removeExpiredProspectiveMembers(): void
    {
        $prospectiveMembers = array_merge(
            $this->prospectiveMemberRepository->findWithFullyExpiredOrFailedCheckout(),
            $this->prospectiveMemberRepository->findWithoutCheckout(),
        );

        foreach ($prospectiveMembers as $prospectiveMember) {
            $this->removeProspective($prospectiveMember);
        }
    }

    /**
     * Clear a member.
     */
    public function clear(MemberModel $member): void
    {
        foreach ($member->getAddresses() as $address) {
            $this->memberRepository->removeAddress($address);
        }

        foreach ($member->getAuditEntries() as $auditEntry) {
            $this->auditEntryRepository->remove($auditEntry);
        }

        $date = new DateTime('0001-01-01 00:00:00');

        $member->setEmail(null);
        $member->setStudentNumber(null);
        $member->setStudy(Studies::Unknown);
        $member->setLastCheckedOn(null);
        $member->setChangedOn(new DateTime());
        $member->setBirth($date);
        $member->setSupremum('optout');
        $member->setHidden(true);
        $member->setDeleted(true);
        $member->unsetMemberships();
        $this->unsubscribeLists(
            $member,
            false,
        );

        $this->memberRepository->persist($member);
    }

    /**
     * Edit a member.
     *
     * The form is bound to the member and submitted by the caller.
     */
    public function edit(
        MemberModel $member,
        FormInterface $form,
    ): ?MemberModel {
        if (!$form->isValid()) {
            return null;
        }

        // update changed on date
        $date = new DateTime();
        $date->setTime(
            0,
            0,
        );
        $member->setChangedOn($date);

        $this->memberRepository->persist($member);

        return $member;
    }

    /**
     * Edit membership by secretary.
     */
    public function membership(
        MemberModel $member,
        FormInterface $form,
    ): ?MemberModel {
        // It is not possible to have another membership type after being an honorary member and there does not exist a
        // good transition to a different membership type (because of the dates/expiration etc.).
        if (MembershipTypes::Honorary === $member->getCurrentOrLastMembership()->getType()) {
            throw new RuntimeException('Unable to change membership type of honorary member.');
        }

        if (!$form->isValid()) {
            return null;
        }

        $data = $form->getData();
        $member = $this->applyMembershipChange(
            $member,
            $data['type'],
            $data['changeDate'],
        );

        // The secretary has now done what a renewal link would have invited the member to do themselves, so the
        // link must not stay usable.
        foreach ($this->actionLinkRepository->findRenewalByMember($member->getLidnr()) ?? [] as $renewalLink) {
            $renewalLink->setUsed(true);
            $this->actionLinkRepository->persist($renewalLink);
        }

        return $member;
    }

    /**
     * The bulk renewal of a batch of memberships: what the membership numbers would do, and what confirming them did.
     *
     * Confirming re-runs the check that produced the preview rather than trusting one, so a membership that was
     * renewed in between is refused instead of renewed twice.
     *
     * @return array{
     *     preview: ?array{
     *         membership_type: MembershipTypes,
     *         rows: array<int, array{
     *             member_id: int,
     *             member: ?MemberModel,
     *             current_expiration: ?string,
     *             new_expiration: ?string,
     *             valid: bool,
     *             message: string,
     *             executed: bool,
     *         }>,
     *         valid_count: int,
     *         invalid_count: int,
     *     },
     *     result: ?array{executed_count: int},
     * }
     */
    public function bulkRenewal(
        string $memberIds,
        ?MembershipTypes $membershipType,
        bool $confirm,
    ): array {
        if (null === $membershipType) {
            return [
                'preview' => null,
                'result' => null,
            ];
        }

        $preview = $this->buildBulkRenewalPreview(
            $this->parseMemberIds($memberIds),
            $membershipType,
        );

        if (!$confirm) {
            return [
                'preview' => $preview,
                'result' => null,
            ];
        }

        $executedCount = 0;

        foreach ($preview['rows'] as $index => $row) {
            if (
                !$row['valid']
                || null === $row['member']
            ) {
                continue;
            }

            try {
                $lastMembership = $row['member']->getLastMembership();
                assert($lastMembership instanceof MembershipModel);

                $preview['rows'][$index]['member'] = $this->applyMembershipChange(
                    $row['member'],
                    $membershipType,
                    clone $lastMembership->getEndDate(),
                );
                $preview['rows'][$index]['executed'] = true;
                $preview['rows'][$index]['message'] = $this->translator->trans('Renewed.');
                $executedCount++;
            } catch (RuntimeException $exception) {
                $preview['rows'][$index]['valid'] = false;
                $preview['rows'][$index]['message'] = $exception->getMessage();
            }
        }

        return [
            'preview' => $preview,
            'result' => ['executed_count' => $executedCount],
        ];
    }

    /**
     * @param int[] $memberIds
     *
     * @return array{
     *     membership_type: MembershipTypes,
     *     rows: array<int, array{
     *         member_id: int,
     *         member: ?MemberModel,
     *         current_expiration: ?string,
     *         new_expiration: ?string,
     *         valid: bool,
     *         message: string,
     *         executed: bool,
     *     }>,
     *     valid_count: int,
     *     invalid_count: int,
     * }
     */
    private function buildBulkRenewalPreview(
        array $memberIds,
        MembershipTypes $membershipType,
    ): array {
        $rows = [];
        $validCount = 0;
        $invalidCount = 0;

        $now = new DateTime();

        foreach ($memberIds as $memberId) {
            $member = $this->getMember($memberId);

            if (null === $member) {
                $rows[] = [
                    'member_id' => $memberId,
                    'member' => null,
                    'current_expiration' => null,
                    'new_expiration' => null,
                    'valid' => false,
                    'message' => $this->translator->trans('Member not found.'),
                    'executed' => false,
                ];
                $invalidCount++;

                continue;
            }

            if ($member->getDeleted()) {
                $rows[] = [
                    'member_id' => $memberId,
                    'member' => $member,
                    'current_expiration' => $member->getExpiration()->format('Y-m-d'),
                    'new_expiration' => null,
                    'valid' => false,
                    'message' => $this->translator->trans('Member is deleted.'),
                    'executed' => false,
                ];
                $invalidCount++;

                continue;
            }

            // We allow renewing memberships that have not started yet in resolveMembershipChange
            // but we don't allow renewal in bulk
            if ($member->getLastMembership()->getStartDate() > $now) {
                $rows[] = [
                    'member_id' => $memberId,
                    'member' => $member,
                    'current_expiration' => $member->getExpiration()->format('Y-m-d'),
                    'new_expiration' => null,
                    'valid' => false,
                    'message' => $this->translator->trans('Member already has a future membership.'),
                    'executed' => false,
                ];
                $invalidCount++;

                continue;
            }

            try {
                $lastMembership = $member->getLastMembership();
                assert($lastMembership instanceof MembershipModel);
                $resolvedChange = $this->resolveMembershipChange(
                    $member,
                    $membershipType,
                    clone $lastMembership->getEndDate(),
                );

                $rows[] = [
                    'member_id' => $memberId,
                    'member' => $member,
                    'current_expiration' => $resolvedChange['oldExpiration']->format('Y-m-d'),
                    'new_expiration' => $resolvedChange['newExpiration']->format('Y-m-d'),
                    'valid' => true,
                    'message' => $this->translator->trans('Ready to renew.'),
                    'executed' => false,
                ];
                $validCount++;
            } catch (RuntimeException $exception) {
                $rows[] = [
                    'member_id' => $memberId,
                    'member' => $member,
                    'current_expiration' => $member->getExpiration()->format('Y-m-d'),
                    'new_expiration' => null,
                    'valid' => false,
                    'message' => $exception->getMessage(),
                    'executed' => false,
                ];
                $invalidCount++;
            }
        }

        return [
            'membership_type' => $membershipType,
            'rows' => $rows,
            'valid_count' => $validCount,
            'invalid_count' => $invalidCount,
        ];
    }

    /**
     * The date the membership would run until after being extended once more.
     *
     * The period is built the same way {@see self::expiration()} builds the one it stores, so what is asked for on
     * the confirmation page cannot drift away from what is carried out.
     */
    public function getExtendedExpiration(MemberModel $member): DateTime
    {
        $membership = $member->getCurrentOrLastMembership();

        return new MembershipModel(
            member: $member,
            type: $membership->getType(),
            startDate: clone $membership->getEndDate(),
        )->getEndDate();
    }

    /**
     * Extend the duration of the membership.
     */
    public function expiration(
        MemberModel $member,
        FormInterface $form,
    ): ?MemberModel {
        if (!$form->isValid()) {
            return null;
        }

        $newMembership = new MembershipModel(
            member: $member,
            type: $member->getCurrentOrLastMembership()->getType(),
            startDate: $member->getCurrentOrLastMembership()->getEndDate(),
            endDate: null,
        );
        $member->addMembership($newMembership);

        $this->memberRepository->persist($member);

        return $member;
    }

    /**
     * Edit address.
     *
     * The form is bound to the address returned by {@see self::getAddress()} and submitted by the caller.
     */
    public function editAddress(FormInterface $form): ?AddressModel
    {
        return $this->persistAddressFromForm($form);
    }

    /**
     * Add address.
     *
     * The form is bound to the address returned by {@see self::getAddress()} and submitted by the caller.
     */
    public function addAddress(FormInterface $form): ?AddressModel
    {
        return $this->persistAddressFromForm($form);
    }

    /**
     * Remove address.
     */
    public function removeAddress(
        MemberModel $member,
        AddressTypes $type,
        FormInterface $form,
    ): ?MemberModel {
        if (!$form->isValid()) {
            return null;
        }

        $address = $this->memberRepository->findMemberAddress(
            $member,
            $type,
        );
        $this->memberRepository->removeAddress($address);

        return $member;
    }

    /**
     * Whether a Mailman/listmonk sync is running.
     *
     * While it is, the pending states on the subscriptions are being turned into real ones, and an edit made in
     * between would be lost, so the subscriptions of a member cannot be offered for editing.
     */
    public function isMailingListSyncLocked(): bool
    {
        return $this->mailingListService->isSyncLocked();
    }

    /**
     * Update mailing list subscriptions of a member
     */
    public function subscribeLists(
        MemberModel $member,
        FormInterface $form,
    ): ?MemberModel {
        // Check if we are performing a sync or not.
        if ($this->mailingListService->isSyncLocked()) {
            return null;
        }

        $data = $form->getData();

        /** @var string[] $selectedLists */
        $selectedLists = $data['lists'] ?: [];
        $currentLists = $member->getMailingListMemberships()->map(
            static function (MailingListMemberModel $subscription) {
                return $subscription->getMailingList()->getName();
            },
        )->toArray();

        // Determine which mailing lists the member should be (un)subscribed from/to.
        $intersection = array_intersect(
            $selectedLists,
            $currentLists,
        );
        $toRemove = array_diff(
            $currentLists,
            $selectedLists,
        );
        $toAdd = array_diff(
            $selectedLists,
            $intersection,
        );

        // If a member unsubscribes, we set the to be deleted status of that entry
        // This will later be processed and then this entry will be deleted
        foreach ($toRemove as $list) {
            $list = $this->mailingListRepository->find($list);

            if (null === $list) {
                continue;
            }

            $membership = $this->mailingListMemberRepository->findByListAndMember(
                $list,
                $member,
            );
            $membership->setToBeDeleted(true);

            $this->auditService->persist(
                AuditMailingListMembership::create(
                    MailingListMemberAction::Remove,
                    MailingListMemberOrigin::Manual,
                    $member,
                    $list,
                    $membership->getEmail(),
                    $this->auditUser(),
                ),
            );
        }

        // Mailing lists to add
        foreach ($toAdd as $list) {
            $list = $this->mailingListRepository->find($list);

            if (null === $list) {
                continue;
            }

            $mailingListMember = new MailingListMemberModel();
            $mailingListMember->setMailingList($list);
            $mailingListMember->setMember($member);
            // Force cascade by adding to member.
            $member->addList($mailingListMember);

            $this->auditService->persist(
                AuditMailingListMembership::create(
                    MailingListMemberAction::Add,
                    MailingListMemberOrigin::Manual,
                    $member,
                    $list,
                    $mailingListMember->getEmail(),
                    $this->auditUser(),
                ),
            );
        }

        // Simply cascade persist through member.
        $this->memberRepository->persist($member);

        return $member;
    }

    /**
     * Unsubscribe a member from all mailing lists. This is used when removing/clearing a member.
     * We never use recordAudit = true yet, but it is implemented to avoid forgetting it.
     */
    public function unsubscribeLists(
        MemberModel $member,
        bool $recordAudit = true,
    ): void {
        foreach ($member->getMailingListMemberships() as $mailingListMembership) {
            $mailingListMembership->setToBeDeleted(true);

            if ($recordAudit) {
                $this->auditService->persist(
                    AuditMailingListMembership::create(
                        MailingListMemberAction::Remove,
                        MailingListMemberOrigin::Manual,
                        $member,
                        $mailingListMembership->getMailingList(),
                        $mailingListMembership->getEmail(),
                        $this->auditUser(),
                    ),
                );
            }

            $this->mailingListMemberRepository->persist($mailingListMembership);
        }
    }

    /**
     * Add audit note to a member.
     */
    public function addAuditNote(
        MemberModel $member,
        FormInterface $form,
    ): ?AuditNoteModel {
        if (!$form->isValid()) {
            return null;
        }

        $auditNote = $form->getData();
        assert($auditNote instanceof AuditNoteModel);
        $auditNote->setUser($this->auditUser());

        $this->addAuditEntry(
            $member,
            $auditNote,
        );

        return $auditNote;
    }

    private function addAuditEntry(
        MemberModel $member,
        AuditEntryModel $auditEntry,
    ): AuditEntryModel {
        $auditEntry->setMember($member);
        $this->auditEntryRepository->persist($auditEntry);

        return $auditEntry;
    }

    /**
     * @return array{
     *     members: int,
     *     graduates: int,
     *     expired: int,
     *     prospectives: array{
     *       total: int,
     *       paid: int,
     *     },
     *     updates: int,
     * }
     */
    public function getStatusFigures(): array
    {
        $totalInclExpired = $this->memberRepository->countMembers(
            true,
            false,
            true,
        );
        $totalExclExpired = $this->memberRepository->countMembers(
            true,
            false,
            false,
        );
        $nongraduatesExclExpired = $this->memberRepository->countMembers(
            false,
            false,
            false,
        );

        return [
            'members' => $nongraduatesExclExpired,
            'graduates' => $totalExclExpired - $nongraduatesExclExpired,
            'expired' => $totalInclExpired - $totalExclExpired,
            'prospectives' => [
                'total' => $this->prospectiveMemberRepository->count([]),
                'paid' => $this->getPaidProspectivesCount(),
            ],
            'updates' => $this->getPendingUpdateCount(),
        ];
    }

    /**
     * How many members hold a current membership of each type.
     *
     * Kept out of the status figures because only the dashboard asks for it.
     *
     * @return array<string, int>
     */
    public function getMembershipBreakdown(): array
    {
        return $this->memberRepository->countByMembershipType();
    }

    /**
     * The number of pending member updates, a separate function to make sure we don't have to do a lot
     * of database queries for each page.
     */
    public function getPendingUpdateCount(): int
    {
        return $this->memberUpdateRepository->count([]);
    }

    /**
     * Prospective members who have paid. Counted on its own so the sidebar badge does not have to ask for the whole
     * state of the register.
     */
    public function getPaidProspectivesCount(): int
    {
        return $this->prospectiveMemberRepository->countForFilter(ProspectiveMemberFilter::Paid);
    }

    /**
     * Get a list of all pending member updates.
     *
     * @return MemberUpdateModel[]
     */
    public function getPendingMemberUpdates(): array
    {
        return $this->memberUpdateRepository->getPendingUpdates();
    }

    /**
     * Get a specific member update.
     */
    public function getPendingMemberUpdate(int $lidnr): ?MemberUpdateModel
    {
        return $this->memberUpdateRepository->find($lidnr);
    }

    public function approveMemberUpdate(
        MemberModel $member,
        MemberUpdateModel $memberUpdate,
    ): ?MemberModel {
        // We use reflection here, because using the hydrator on Member(Edit)Form sucks (requires more info). This does
        // not account for any type changes that may be required (everything is currently a string).
        $reflectionClass = new ReflectionClass($member);
        foreach ($memberUpdate->toArray() as $property => $value) {
            if (!$reflectionClass->hasProperty($property)) {
                continue;
            }

            $reflectionProperty = $reflectionClass->getProperty($property);
            $reflectionProperty->setValue(
                $member,
                $value,
            );
        }

        $this->memberRepository->persist($member);
        $this->memberUpdateRepository->remove($memberUpdate);

        return $member;
    }

    public function rejectMemberUpdate(MemberUpdateModel $memberUpdate): ?bool
    {
        $this->memberUpdateRepository->remove($memberUpdate);

        return true;
    }

    /**
     * Split the raw bulk renewal input into membership numbers.
     *
     * The input is expected to have passed the bulk membership number constraint already; anything that survives here
     * is cast rather than rejected.
     *
     * @return int[]
     */
    private function parseMemberIds(string $rawMemberIds): array
    {
        $memberIds = [];

        foreach (BulkMemberIds::tokenize($rawMemberIds) as $token) {
            $memberIds[] = (int) $token;
        }

        return $memberIds;
    }

    /**
     * @return array{
     *     currentType: MembershipTypes,
     *     oldExpiration: DateTime,
     *     newExpiration: DateTime,
     *     changeDate: DateTime,
     *     lastMembership: MembershipModel,
     * }
     */
    private function resolveMembershipChange(
        MemberModel $member,
        MembershipTypes $newType,
        ?DateTime $changeDate = null,
    ): array {
        $currentMembership = $member->getCurrentOrLastMembership();

        if (null === $currentMembership) {
            throw new RuntimeException('Unable to change membership type without a membership.');
        }

        if (MembershipTypes::Honorary === $currentMembership->getType()) {
            throw new RuntimeException('Unable to change membership type of honorary member.');
        }

        $lastMembership = $member->getLastMembership();
        assert($lastMembership instanceof MembershipModel);

        $effectiveChangeDate = null === $changeDate
            ? new DateTime()
            : clone $changeDate;
        $effectiveChangeDate->setTime(
            0,
            0,
        );

        if ($effectiveChangeDate < $lastMembership->getStartDate()) {
            $effectiveChangeDate = clone $lastMembership->getStartDate();
        }

        if ($effectiveChangeDate > $lastMembership->getEndDate()) {
            $effectiveChangeDate = clone $lastMembership->getEndDate();
        }

        $newExpiration = $effectiveChangeDate->getTimestamp() === $lastMembership->getStartDate()->getTimestamp()
            ? clone $lastMembership->getEndDate()
            : new MembershipModel(
                $member,
                $newType,
                clone $effectiveChangeDate,
            )->getEndDate();

        return [
            'currentType' => $lastMembership->getType(),
            'oldExpiration' => clone $member->getExpiration(),
            'newExpiration' => $newExpiration,
            'changeDate' => $effectiveChangeDate,
            'lastMembership' => $lastMembership,
        ];
    }

    private function applyMembershipChange(
        MemberModel $member,
        MembershipTypes $newType,
        ?DateTime $changeDate = null,
    ): MemberModel {
        $resolvedChange = $this->resolveMembershipChange(
            $member,
            $newType,
            $changeDate,
        );

        $date = new DateTime();
        $date->setTime(
            0,
            0,
        );
        $member->setChangedOn($date);

        $renewalAudit = new AuditRenewalModel();
        $renewalAudit->setOldExpiration($resolvedChange['oldExpiration']);

        $lastMembership = $resolvedChange['lastMembership'];
        $effectiveChangeDate = $resolvedChange['changeDate'];

        if ($effectiveChangeDate->getTimestamp() === $lastMembership->getStartDate()->getTimestamp()) {
            $lastMembership->setType($newType);
        } else {
            $lastMembership->setEndDate(clone $effectiveChangeDate);
            $member->addMembership(new MembershipModel(
                member: $member,
                type: $newType,
                startDate: clone $effectiveChangeDate,
                endDate: null,
            ));
        }

        $renewalAudit->setNewExpiration($resolvedChange['newExpiration']);
        $renewalAudit->setUser($this->auditUser());
        $this->addAuditEntry(
            $member,
            $renewalAudit,
        );
        $this->memberRepository->persist($member);

        return $member;
    }

    private function persistAddressFromForm(FormInterface $form): ?AddressModel
    {
        if (!$form->isValid()) {
            return null;
        }

        $address = $form->getData();
        assert($address instanceof AddressModel);

        $this->memberRepository->persistAddress($address);

        return $address;
    }

    /**
     * Get the address to bind an address form to.
     *
     * When creating, a new address of the requested type is attached to the member; otherwise the member's existing
     * address of that type is returned.
     */
    public function getAddress(
        MemberModel $member,
        AddressTypes $type,
        bool $create = false,
    ): ?AddressModel {
        if ($create) {
            $address = new AddressModel();
            $address->setMember($member);
            $address->setType($type);

            return $address;
        }

        return $this->memberRepository->findMemberAddress(
            $member,
            $type,
        );
    }

    /**
     * Get the members requiring attention
     *
     * @return array{
     *     days: int,
     *     rows: array<int, array{
     *         member: MemberModel,
     *         reasons: AttentionReasons[],
     *     }>,
     *     bulk_renewal_shortcuts: array{
     *         expiring_active: int[],
     *         expiring_non_active: int[],
     *     },
     * }
     */
    public function getMembersRequiringAttention(int $days = 30): array
    {
        $members = [];
        $reasons = [];
        $bulkRenewalShortcuts = [
            'expiring_active' => [],
            'expiring_non_active' => [],
        ];

        /** @var array<value-of<AttentionReasons>, MemberModel[]> $combined */
        $combined = [];

        $combined[AttentionReasons::MissingEmail->value] = $this->memberRepository->findAttentionWithoutEmail();
        $combined[AttentionReasons::MissingStudentNumberOrdinary->value] =
        $this->memberRepository->findAttentionWithoutStudentNumber();
        $combined[AttentionReasons::ExpiringExternalNonActive->value] =
        $this->memberRepository->findAttentionExpiring(
            includeActive: false,
            includeNonActive: true,
            specificType: MembershipTypes::External,
            expiresWithinDays: $days,
        );
        $combined[AttentionReasons::ExpiringExternalActive->value] = $this->memberRepository->findAttentionExpiring(
            includeActive: true,
            includeNonActive: false,
            specificType: MembershipTypes::External,
            expiresWithinDays: $days,
        );
        $combined[AttentionReasons::ExpiringOrdinaryActive->value] = $this->memberRepository->findAttentionExpiring(
            includeActive: true,
            includeNonActive: false,
            specificType: MembershipTypes::Ordinary,
            expiresWithinDays: $days,
        );
        $combined[AttentionReasons::ExpiringOrdinaryNonActive->value] = $this->memberRepository->findAttentionExpiring(
            includeActive: false,
            includeNonActive: true,
            specificType: MembershipTypes::Ordinary,
            expiresWithinDays: $days,
        );
        $combined[AttentionReasons::ExpiringGraduateActiveInactive->value] =
        $this->memberRepository->findAttentionExpiring(
            includeActive: true,
            includeNonActive: false,
            inActiveIsActive: true,
            specificType: MembershipTypes::Graduate,
            expiresWithinDays: $days,
        );

        foreach (AttentionReasons::cases() as $reason) {
            if ($reason->includeBulkActiveMemberRenewal()) {
                foreach ($combined[$reason->value] ?? [] as $member) {
                    $bulkRenewalShortcuts['expiring_active'][] = $member->getLidnr();
                }
            }

            if ($reason->includeBulkGraduateConversion()) {
                foreach ($combined[$reason->value] ?? [] as $member) {
                    $bulkRenewalShortcuts['expiring_non_active'][] = $member->getLidnr();
                }
            }

            // A member can turn up under several reasons, which are gathered onto the one row they get.
            foreach ($combined[$reason->value] ?? [] as $member) {
                $members[$member->getLidnr()] = $member;
                $reasons[$member->getLidnr()][] = $reason;
            }
        }

        uasort(
            $members,
            static function (MemberModel $a, MemberModel $b) {
                return ($a->getExpiration() <=> $b->getExpiration()) * 10
                    + ($a->getLastName() <=> $b->getLastName()) * 2
                    + ($a->getFirstName() <=> $b->getFirstName());
            },
        );

        $rows = [];
        foreach ($members as $lidnr => $member) {
            $rows[] = [
                'member' => $member,
                'reasons' => $reasons[$lidnr],
            ];
        }

        $bulkRenewalShortcuts['expiring_active'] = array_values(
            array_unique($bulkRenewalShortcuts['expiring_active']),
        );
        $bulkRenewalShortcuts['expiring_non_active'] = array_values(
            array_unique($bulkRenewalShortcuts['expiring_non_active']),
        );

        return [
            'days' => $days,
            'rows' => $rows,
            'bulk_renewal_shortcuts' => $bulkRenewalShortcuts,
        ];
    }

    /**
     * Whether this address already belongs to someone else — a member or an applicant.
     *
     * A renewal may change the address it is sent to, and two records answering to the same address cannot both be
     * reached, so the form refuses one that is taken.
     */
    public function emailBelongsToSomeoneElse(
        string $email,
        MemberModel $member,
    ): bool {
        if ($email === $member->getEmail()) {
            return false;
        }

        return $this->memberRepository->hasMemberWith($email)
            || $this->prospectiveMemberRepository->hasMemberWith($email);
    }

    /**
     * Renew a member (with existing membership type).
     * Currently only used for renewal links.
     */
    public function renewMember(
        MemberModel $member,
        RenewalLinkModel $renewalLink,
        DateTime $newExpiration,
    ): MemberModel {
        $member->setChangedOn(new DateTime());

        $renewalLink->setUsed(true);
        $this->actionLinkRepository->persist($renewalLink);
        $this->renewalService->sendRenewalSuccessEmail($renewalLink);

        // Record a renewal audit entry
        $renewalAudit = AuditRenewalModel::fromRenewalLink($renewalLink);
        $renewalAudit->setNewExpiration($newExpiration);
        $this->addAuditEntry(
            $member,
            $renewalAudit,
        );

        $newMembership = new MembershipModel(
            member: $member,
            type: $member->getCurrentOrLastMembership()->getType(),
            startDate: $member->getCurrentOrLastMembership()->getEndDate(),
            endDate: $newExpiration,
        );
        $member->addMembership($newMembership);

        $this->memberRepository->persist($member);

        return $member;
    }

    /**
     * The member an audit entry is attributed to.
     *
     * Security::getUser() answers with whatever the firewall authenticated, which on this side is a member's own
     * account; the ledger records the member behind it, because that is who did the thing. A command run from the
     * console is nobody.
     */
    private function auditUser(): ?MemberModel
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return null;
        }

        return $this->memberRepository->find($user->getMember()->getLidnr());
    }
}
