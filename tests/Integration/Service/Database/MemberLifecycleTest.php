<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Database;

use App\Entity\Database\Address;
use App\Entity\Database\AuditMailingListMembership;
use App\Entity\Database\AuditNote;
use App\Entity\Database\AuditRenewal;
use App\Entity\Database\CheckoutSession;
use App\Entity\Database\Enums\AddressTypes;
use App\Entity\Database\Enums\CheckoutSessionStates;
use App\Entity\Database\Enums\ConfigNamespaces;
use App\Entity\Database\Enums\MailingListMemberAction;
use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\Enums\PostalRegions;
use App\Entity\Database\Enums\Studies;
use App\Entity\Database\MailingListMember;
use App\Entity\Database\Member as MemberModel;
use App\Entity\Database\Membership;
use App\Entity\Database\PaymentLink;
use App\Entity\Database\ProspectiveMember;
use App\Entity\Database\RenewalLink;
use App\Form\Database\Registration\RegistrationData;
use App\Message\Database\RefundProblemEmail;
use App\Message\Database\RegistrationUpdate;
use App\Message\Database\RegistrationUpdateEmail;
use App\Repository\Database\ActionLinkRepository;
use App\Repository\Database\MailingListRepository;
use App\Repository\Database\MemberRepository;
use App\Repository\Database\ProspectiveMemberRepository;
use App\Service\Application\Config;
use App\Service\Database\MailingListService;
use App\Service\Database\Member;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\LedgerBuilder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

use function array_column;
use function array_map;
use function assert;
use function sort;

/**
 * The steps that create a member from an applicant and keep an existing member's record current.
 *
 * The two database connections are both real: the prospective member and the member live in the ledger, while the
 * audit trail and the checkout sessions that gate approval are read through the same manager.
 */
#[CoversClass(Member::class)]
final class MemberLifecycleTest extends DatabaseTestCase
{
    private EntityManagerInterface $ledger;

    private Member $memberService;

    private LedgerBuilder $build;

    private ActionLinkRepository $actionLinkRepository;

    private ProspectiveMemberRepository $prospectiveMemberRepository;

    private MailingListRepository $mailingListRepository;

    private MemberRepository $memberRepository;

    private Config $config;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = self::getContainer()->get(EntityManagerInterface::class);
        $this->memberService = self::getContainer()->get(Member::class);
        $this->build = new LedgerBuilder($this->ledger);
        $this->actionLinkRepository = self::getContainer()->get(ActionLinkRepository::class);
        $this->prospectiveMemberRepository = self::getContainer()->get(ProspectiveMemberRepository::class);
        $this->mailingListRepository = self::getContainer()->get(MailingListRepository::class);
        $this->memberRepository = self::getContainer()->get(MemberRepository::class);
        $this->config = self::getContainer()->get(Config::class);
    }

    #[Override]
    protected function tearDown(): void
    {
        // The sync lock is memoised in the service for the length of a process, which is longer than one test.
        $this->config->reset();

        parent::tearDown();
    }

    public function testSubscribeRefusesAnAddressThatIsAlreadyTaken(): void
    {
        self::assertNull(
            $this->memberService->subscribe($this->registrationData(null)),
        );

        $existing = $this->build->member();
        assert(null !== $existing->email);
        self::assertNull(
            $this->memberService->subscribe($this->registrationData($existing->email)),
        );

        $prospective = $this->prospectiveMember('applicant@example.org');
        $this->prospectiveMemberRepository->persist($prospective);
        self::assertNull(
            $this->memberService->subscribe($this->registrationData('applicant@example.org')),
        );
    }

    public function testSubscribeBuildsAProspectiveMemberWithItsAddressAndLists(): void
    {
        $data = $this->registrationData('jane.doe@example.org');
        $data->lists = [
            'activities',
            'announcements',
        ];

        $prospective = $this->memberService->subscribe($data);
        self::assertInstanceOf(
            ProspectiveMember::class,
            $prospective,
        );

        self::assertSame(
            'jane.doe@example.org',
            $prospective->email,
        );
        self::assertSame(
            'J.D.',
            $prospective->initials,
        );
        self::assertSame(
            'Jane',
            $prospective->firstName,
        );
        self::assertSame(
            'van',
            $prospective->middleName,
        );
        self::assertSame(
            'Doe',
            $prospective->lastName,
        );
        self::assertSame(
            '1234567',
            $prospective->studentNumber,
        );
        self::assertSame(
            Studies::BCS,
            $prospective->study,
        );
        self::assertSame(
            '2000-05-06',
            $prospective->birth->format('Y-m-d'),
        );
        self::assertSame(
            new DateTimeImmutable('today')->format('Y-m-d'),
            $prospective->changedOn->format('Y-m-d'),
        );

        self::assertSame(
            PostalRegions::Netherlands,
            $prospective->country,
        );
        self::assertSame(
            'Main Street',
            $prospective->street,
        );
        self::assertSame(
            '42',
            $prospective->number,
        );
        self::assertSame(
            '5612 AB',
            $prospective->postalCode,
        );
        self::assertSame(
            'Eindhoven',
            $prospective->city,
        );
        self::assertSame(
            '040-1234567',
            $prospective->phone,
        );

        // The form choice plus the default list, and the default that was also chosen on the form only once.
        self::assertSame(
            [
                'activities',
                'announcements',
            ],
            $prospective->getLists(),
        );

        $paymentLink = $prospective->getPaymentLink();
        self::assertInstanceOf(
            PaymentLink::class,
            $paymentLink,
        );
        self::assertNotNull($paymentLink->id);
    }

    public function testFinalizeRefusesWhatTheCheckoutDoesNotAllow(): void
    {
        $prospective = $this->prospectiveMember('unpaid@example.org');
        $this->prospectiveMemberRepository->persist($prospective);

        self::assertNull(
            $this->memberService->finalizeSubscription(
                MembershipTypes::Ordinary,
                $prospective,
            ),
        );
    }

    public function testFinalizeRefusesAnAddressThatBecameAMemberMeanwhile(): void
    {
        $prospective = $this->prospectiveMember('raced@example.org');
        $this->prospectiveMemberRepository->persist($prospective);
        $prospective = $this->withPaidCheckout($prospective);

        $existing = $this->build->member();
        $existing->setEmail('raced@example.org');
        $this->ledger->flush();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('A member with this email address already exists.');

        $this->memberService->finalizeSubscription(
            MembershipTypes::Ordinary,
            $prospective,
        );
    }

    public function testFinalizeCreatesTheMemberAndClearsTheApplication(): void
    {
        $prospective = $this->memberService->subscribe($this->registrationData('grace@example.org'));
        self::assertInstanceOf(
            ProspectiveMember::class,
            $prospective,
        );
        $prospective = $this->withPaidCheckout($prospective);

        $prospectiveId = $prospective->lidnr;
        $paymentLink = $prospective->getPaymentLink();
        self::assertInstanceOf(
            PaymentLink::class,
            $paymentLink,
        );
        $paymentLinkId = $paymentLink->id;

        $transport = $this->transport();
        $transport->reset();

        $member = $this->memberService->finalizeSubscription(
            MembershipTypes::Ordinary,
            $prospective,
        );

        self::assertInstanceOf(
            MemberModel::class,
            $member,
        );
        self::assertSame(
            'grace@example.org',
            $member->getEmailRecipient()?->getAddress(),
        );

        $membership = self::firstMembership($member);
        self::assertSame(
            MembershipTypes::Ordinary,
            $membership->type,
        );
        self::assertSame(
            20,
            $membership->paid,
        );

        $address = $this->memberService->getAddress(
            $member,
            AddressTypes::Student,
        );
        self::assertInstanceOf(
            Address::class,
            $address,
        );
        self::assertSame(
            'Eindhoven',
            $address->city,
        );

        $lists = [];
        foreach ($member->getMailingListMemberships() as $subscription) {
            $lists[] = $subscription->mailingList->name;
        }

        sort($lists);
        // `announcements` is a default and was also chosen on the form, but the member is on it once.
        self::assertSame(
            [
                'activities',
                'announcements',
            ],
            $lists,
        );

        $sent = $transport->getSent();
        self::assertCount(
            1,
            $sent,
        );
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(
            RegistrationUpdateEmail::class,
            $message,
        );
        self::assertSame(
            RegistrationUpdate::Welcome,
            $message->getType(),
        );
        self::assertSame(
            $member->lidnr,
            $message->getLidnr(),
        );

        $this->ledger->clear();

        self::assertNull($this->prospectiveMemberRepository->find($prospectiveId));
        self::assertNull($this->actionLinkRepository->find($paymentLinkId));
    }

    public function testProspectiveMemberLookupReportsWhyItIsStuck(): void
    {
        self::assertSame(
            [
                'member' => null,
                'canBeApproved' => null,
                'canDelete' => null,
                'canResendPaymentLink' => null,
                'approveMessages' => null,
            ],
            $this->memberService->getProspectiveMember(999_999),
        );

        $paid = $this->prospectiveMember(
            'paid@example.org',
            Studies::MCSE,
        );
        $this->prospectiveMemberRepository->persist($paid);

        $paymentLink = new PaymentLink();
        $paymentLink->prospectiveMember = $paid;
        $paid->setPaymentLink($paymentLink);
        $this->actionLinkRepository->persist($paymentLink);

        $paid = $this->withPaidCheckout($paid);

        $result = $this->memberService->getProspectiveMember($paid->lidnr);

        self::assertSame(
            $paid,
            $result['member'],
        );
        self::assertTrue($result['canBeApproved']);
        self::assertTrue($result['canDelete']);
        self::assertTrue($result['canResendPaymentLink']);
        self::assertSame(
            'success',
            $this->approveMessageOf($paid->lidnr),
        );

        $engd = $this->prospectiveMember(
            'engd@example.org',
            Studies::PhDCS,
        );
        $this->prospectiveMemberRepository->persist($engd);
        self::assertSame(
            'warning',
            $this->approveMessageOf($engd->lidnr),
        );

        $other = $this->prospectiveMember(
            'other@example.org',
            Studies::Other,
        );
        $this->prospectiveMemberRepository->persist($other);
        self::assertSame(
            'danger',
            $this->approveMessageOf($other->lidnr),
        );

        // Every approval message list starts with the 2026 warning, followed by the study recommendation.
        $messages = $result['approveMessages'];
        self::assertNotNull($messages);
        self::assertSame(
            [
                'info',
                'success',
            ],
            array_column(
                $messages,
                0,
            ),
        );
    }

    public function testSupremumIsStored(): void
    {
        $member = $this->build->member();

        $this->memberService->setSupremum(
            $member,
            'optout',
        );
        $this->ledger->clear();

        $reloaded = $this->memberRepository->find($member->lidnr);
        self::assertInstanceOf(
            MemberModel::class,
            $reloaded,
        );
        self::assertSame(
            'optout',
            $reloaded->supremum,
        );
    }

    public function testSearchLeavesOutHiddenMembers(): void
    {
        $hidden = $this->build->member();
        $hidden->lastName = 'Zoekbaar';
        $hidden->hidden = true;
        $visible = $this->build->member();
        $visible->lastName = 'Zoekbaar';
        $this->ledger->flush();

        $lidnrs = array_map(
            static fn (MemberModel $member): int => $member->lidnr,
            $this->memberService->searchFiltered('Zoekbaar'),
        );

        self::assertContains(
            $visible->lidnr,
            $lidnrs,
        );
        self::assertNotContains(
            $hidden->lidnr,
            $lidnrs,
        );
    }

    public function testRemovingAProspectiveMemberTakesItsPaymentLinkWithIt(): void
    {
        $prospective = $this->memberService->subscribe($this->registrationData('remove-me@example.org'));
        self::assertInstanceOf(
            ProspectiveMember::class,
            $prospective,
        );
        $paymentLink = $prospective->getPaymentLink();
        self::assertInstanceOf(
            PaymentLink::class,
            $paymentLink,
        );

        $prospectiveId = $prospective->lidnr;
        $paymentLinkId = $paymentLink->id;

        $this->memberService->removeProspective($prospective);
        $this->ledger->clear();

        self::assertNull($this->prospectiveMemberRepository->find($prospectiveId));
        self::assertNull($this->actionLinkRepository->find($paymentLinkId));
    }

    public function testExpiredProspectiveMembersWithoutCheckoutAreRemoved(): void
    {
        $prospective = $this->prospectiveMember(
            'stale@example.org',
            changedOn: new DateTimeImmutable('-40 days'),
        );
        $this->prospectiveMemberRepository->persist($prospective);
        $prospectiveId = $prospective->lidnr;

        $this->memberService->removeExpiredProspectiveMembers();
        $this->ledger->clear();

        self::assertNull($this->prospectiveMemberRepository->find($prospectiveId));
    }

    public function testEditOnlyTouchesTheDateWhenTheFormIsValid(): void
    {
        $member = $this->build->member();
        $member->changedOn = new DateTimeImmutable('2020-01-01');

        $invalid = $this->form(false);
        self::assertNull($this->memberService->edit($member, $invalid));
        self::assertSame(
            '2020-01-01',
            $member->changedOn->format('Y-m-d'),
        );

        $valid = $this->form(true);
        self::assertSame(
            $member,
            $this->memberService->edit(
                $member,
                $valid,
            ),
        );
        self::assertSame(
            new DateTimeImmutable('today')->format('Y-m-d'),
            $member->changedOn->format('Y-m-d'),
        );
    }

    public function testHonoraryMembershipCannotBeChangedEvenByAnInvalidForm(): void
    {
        $member = $this->build->member(MembershipTypes::Honorary);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Unable to change membership type of honorary member.');

        $this->memberService->membership(
            $member,
            $this->form(
                false,
                [
                    'type' => MembershipTypes::Ordinary,
                    'changeDate' => new DateTimeImmutable('2026-01-01'),
                ],
            ),
        );
    }

    public function testMembershipChangeNeedsAValidForm(): void
    {
        $member = $this->build->member();
        $original = self::lastMembership($member);

        self::assertNull($this->memberService->membership($member, $this->form(false)));
        self::assertSame(
            $original,
            $member->getLastMembership(),
        );
    }

    public function testChangeOnTheLastStartReplacesTheTypeInPlace(): void
    {
        $member = $this->build->member(
            MembershipTypes::Ordinary,
            '2025-08-01',
            '2026-07-01',
        );

        $this->memberService->membership(
            $member,
            $this->form(
                true,
                [
                    'type' => MembershipTypes::External,
                    'changeDate' => new DateTimeImmutable('2025-07-01'),
                ],
            ),
        );

        self::assertCount(
            1,
            $member->getMemberships(),
        );
        $membership = self::lastMembership($member);
        self::assertSame(
            MembershipTypes::External,
            $membership->type,
        );
        self::assertSame(
            '2025-08-01',
            $membership->getStartDate()->format('Y-m-d'),
        );
        self::assertSame(
            '2026-07-01',
            $membership->endDate->format('Y-m-d'),
        );
    }

    public function testAChangeInsideThePeriodSplitsTheMembership(): void
    {
        $member = $this->build->member(
            MembershipTypes::Ordinary,
            '2025-08-01',
            '2026-07-01',
        );

        $this->memberService->membership(
            $member,
            $this->form(
                true,
                [
                    'type' => MembershipTypes::External,
                    'changeDate' => new DateTimeImmutable('2026-01-01'),
                ],
            ),
        );

        self::assertCount(
            2,
            $member->getMemberships(),
        );
        $first = self::firstMembership($member);
        self::assertSame(
            '2026-01-01',
            $first->endDate->format('Y-m-d'),
        );
        self::assertSame(
            MembershipTypes::Ordinary,
            $first->type,
        );

        $last = self::lastMembership($member);
        self::assertSame(
            MembershipTypes::External,
            $last->type,
        );
        self::assertSame(
            '2026-01-01',
            $last->getStartDate()->format('Y-m-d'),
        );
    }

    public function testAChangeAfterTheEndClampsToTheEnd(): void
    {
        $member = $this->build->member(
            MembershipTypes::Ordinary,
            '2025-08-01',
            '2026-07-01',
        );

        $this->memberService->membership(
            $member,
            $this->form(
                true,
                [
                    'type' => MembershipTypes::External,
                    'changeDate' => new DateTimeImmutable('2027-01-01'),
                ],
            ),
        );

        self::assertCount(
            2,
            $member->getMemberships(),
        );
        $first = self::firstMembership($member);
        self::assertSame(
            '2026-07-01',
            $first->endDate->format('Y-m-d'),
        );
        $last = self::lastMembership($member);
        self::assertSame(
            '2026-07-01',
            $last->getStartDate()->format('Y-m-d'),
        );
        self::assertSame(
            MembershipTypes::External,
            $last->type,
        );
    }

    public function testAMembershipChangeMakesOutstandingRenewalLinksUnusable(): void
    {
        $member = $this->build->member(
            MembershipTypes::Ordinary,
            '2025-08-01',
            '2026-07-01',
        );
        $renewalLink = new RenewalLink(
            $member,
            new DateTimeImmutable('2027-07-01'),
        );
        $this->actionLinkRepository->persist($renewalLink);

        $this->memberService->membership(
            $member,
            $this->form(
                true,
                [
                    'type' => MembershipTypes::Ordinary,
                    'changeDate' => new DateTimeImmutable('2026-01-01'),
                ],
            ),
        );

        self::assertTrue($renewalLink->used);
    }

    public function testAddressesAreOnlyWrittenAfterValidation(): void
    {
        $member = $this->build->member();

        $invalid = $this->form(false);
        self::assertNull($this->memberService->editAddress($invalid));
        self::assertNull($this->memberService->addAddress($invalid));
        self::assertNull($this->memberService->getAddress($member, AddressTypes::Student));

        $address = $this->memberService->getAddress(
            $member,
            AddressTypes::Student,
            true,
        );
        self::assertInstanceOf(
            Address::class,
            $address,
        );
        self::assertSame(
            $member,
            $address->getMember(),
        );

        $address->country = PostalRegions::Netherlands;
        $address->street = 'Straat';
        $address->number = '1';
        $address->postalCode = '1234 AB';
        $address->city = 'Eindhoven';
        $address->phone = '040-0000000';

        $persisted = $this->memberService->addAddress($this->form(true, $address));
        self::assertSame(
            $address,
            $persisted,
        );

        $this->ledger->clear();
        $reloaded = $this->memberRepository->find($member->lidnr);
        self::assertInstanceOf(
            MemberModel::class,
            $reloaded,
        );
        $found = $this->memberService->getAddress(
            $reloaded,
            AddressTypes::Student,
        );
        self::assertInstanceOf(
            Address::class,
            $found,
        );
        self::assertSame(
            'Straat',
            $found->street,
        );

        self::assertSame(
            $reloaded,
            $this->memberService->removeAddress(
                $reloaded,
                AddressTypes::Student,
            ),
        );
        self::assertNull($this->memberService->getAddress($reloaded, AddressTypes::Student));
    }

    public function testTheMailingListSyncLockIsTheServicesOwnLock(): void
    {
        $mailingListService = self::getContainer()->get(MailingListService::class);
        self::assertFalse($mailingListService->isSyncLocked());

        $this->config->setConfig(
            ConfigNamespaces::DatabaseMailman,
            'locked',
            new DateTimeImmutable('+1 hour'),
        );

        self::assertTrue($this->memberService->isMailingListSyncLocked());
        self::assertSame(
            $mailingListService->isSyncLocked(),
            $this->memberService->isMailingListSyncLocked(),
        );

        $this->config->unsetConfig(
            ConfigNamespaces::DatabaseMailman,
            'locked',
        );
    }

    public function testSubscriptionsAreNotEditableWhileTheSyncRuns(): void
    {
        $this->config->setConfig(
            ConfigNamespaces::DatabaseMailman,
            'locked',
            new DateTimeImmutable('+1 hour'),
        );

        $member = $this->build->member();
        self::assertNull($this->memberService->subscribeLists(
            $member,
            $this->form(
                true,
                ['lists' => ['activities']],
            ),
        ));

        $this->config->unsetConfig(
            ConfigNamespaces::DatabaseMailman,
            'locked',
        );
    }

    public function testSubscriptionsAreAddedAndRemovedOnRequest(): void
    {
        $member = $this->build->member();
        $announcements = $this->mailingListRepository->find('announcements');
        assert(null !== $announcements);
        $subscription = new MailingListMember();
        $subscription->mailingList = $announcements;
        $member->addList($subscription);
        $this->ledger->persist($member);
        $this->ledger->flush();

        $result = $this->memberService->subscribeLists(
            $member,
            $this->form(
                true,
                ['lists' => ['activities']],
            ),
        );
        self::assertSame(
            $member,
            $result,
        );

        $byName = [];
        foreach ($member->getMailingListMemberships() as $listMembership) {
            $byName[$listMembership->mailingList->name] = $listMembership;
        }

        self::assertTrue($byName['announcements']->toBeDeleted);
        self::assertArrayHasKey(
            'activities',
            $byName,
        );
        self::assertFalse($byName['activities']->toBeDeleted);

        // Both the removal and the addition were written to the audit trail.
        $this->ledger->clear();
        $reloaded = $this->memberRepository->find($member->lidnr);
        self::assertInstanceOf(
            MemberModel::class,
            $reloaded,
        );
        $actions = array_map(
            static fn (mixed $entry): mixed => $entry instanceof AuditMailingListMembership
                ? $entry->action
                : null,
            $reloaded->getAuditEntries()->toArray(),
        );
        self::assertContains(
            MailingListMemberAction::Add,
            $actions,
        );
        self::assertContains(
            MailingListMemberAction::Remove,
            $actions,
        );
    }

    public function testAnUnknownListNameIsIgnored(): void
    {
        $member = $this->build->member();

        $this->memberService->subscribeLists(
            $member,
            $this->form(
                true,
                ['lists' => ['does-not-exist']],
            ),
        );

        self::assertCount(
            0,
            $member->getMailingListMemberships(),
        );
    }

    public function testUnsubscribeMarksEverySubscriptionAndRecordsIt(): void
    {
        $member = $this->build->member();
        $activities = $this->mailingListRepository->find('activities');
        assert(null !== $activities);
        $subscription = new MailingListMember();
        $subscription->mailingList = $activities;
        $member->addList($subscription);
        $this->ledger->persist($member);
        $this->ledger->flush();

        $this->memberService->unsubscribeLists($member);
        $this->ledger->flush();

        self::assertTrue($subscription->toBeDeleted);

        $this->ledger->clear();
        $reloaded = $this->memberRepository->find($member->lidnr);
        self::assertInstanceOf(
            MemberModel::class,
            $reloaded,
        );
        self::assertCount(
            1,
            $reloaded->getAuditEntries(),
        );
    }

    public function testAuditNotesAreOnlyAttachedAfterValidation(): void
    {
        $member = $this->build->member();
        $note = new AuditNote();
        $note->note = 'Called about their renewal.';

        self::assertNull($this->memberService->addAuditNote($member, $this->form(false, $note)));

        self::assertSame(
            $note,
            $this->memberService->addAuditNote(
                $member,
                $this->form(
                    true,
                    $note,
                ),
            ),
        );
        self::assertNull($note->user);

        $this->ledger->clear();
        $reloaded = $this->memberRepository->find($member->lidnr);
        self::assertInstanceOf(
            MemberModel::class,
            $reloaded,
        );
        self::assertCount(
            1,
            $reloaded->getAuditEntries(),
        );
        $entry = $reloaded->getAuditEntries()->first();
        self::assertInstanceOf(
            AuditNote::class,
            $entry,
        );
        self::assertSame(
            'Called about their renewal.',
            $entry->note,
        );
    }

    public function testAnAddressIsTakenWhenEitherAnotherRecordStillUsesIt(): void
    {
        $member = $this->build->member();
        assert(null !== $member->email);

        self::assertFalse($this->memberService->emailBelongsToSomeoneElse($member->email, $member));

        $other = $this->build->member();
        assert(null !== $other->email);
        self::assertTrue($this->memberService->emailBelongsToSomeoneElse($other->email, $member));

        $prospective = $this->prospectiveMember('applicant@example.org');
        $this->prospectiveMemberRepository->persist($prospective);
        self::assertTrue($this->memberService->emailBelongsToSomeoneElse('applicant@example.org', $member));

        self::assertFalse($this->memberService->emailBelongsToSomeoneElse('free@example.org', $member));
    }

    public function testStatusFiguresMoveWithWhatIsAdded(): void
    {
        $before = $this->memberService->getStatusFigures();
        $beforeBreakdown = $this->memberService->getMembershipBreakdown();
        $beforePaid = $this->memberService->getPaidProspectivesCount();

        $this->build->member(
            MembershipTypes::Ordinary,
            '-1 month',
            '+11 months',
        );

        $paid = $this->prospectiveMember('paid-figure@example.org');
        $this->prospectiveMemberRepository->persist($paid);
        $this->withPaidCheckout($paid);

        $after = $this->memberService->getStatusFigures();
        $afterBreakdown = $this->memberService->getMembershipBreakdown();

        self::assertSame(
            $before['members'] + 1,
            $after['members'],
        );
        self::assertSame(
            $before['graduates'],
            $after['graduates'],
        );
        self::assertSame(
            $before['prospectives']['total'] + 1,
            $after['prospectives']['total'],
        );
        self::assertSame(
            $beforeBreakdown[MembershipTypes::Ordinary->value] + 1,
            $afterBreakdown[MembershipTypes::Ordinary->value],
        );
        self::assertSame(
            $beforePaid + 1,
            $this->memberService->getPaidProspectivesCount(),
        );
        self::assertSame(
            $beforePaid + 1,
            $after['prospectives']['paid'],
        );
    }

    public function testRenewingRecordsTheNewPeriodAndItsAuditTrail(): void
    {
        $member = $this->build->member(
            MembershipTypes::Ordinary,
            '2025-08-01',
            '2026-07-01',
        );
        $renewalLink = new RenewalLink(
            $member,
            new DateTimeImmutable('2027-07-01'),
        );
        $this->actionLinkRepository->persist($renewalLink);

        $this->memberService->renewMember(
            $member,
            $renewalLink,
            new DateTimeImmutable('2027-07-01'),
        );

        self::assertTrue($renewalLink->used);
        self::assertCount(
            2,
            $member->getMemberships(),
        );
        $newMembership = self::lastMembership($member);
        self::assertSame(
            '2026-07-01',
            $newMembership->getStartDate()->format('Y-m-d'),
        );
        self::assertSame(
            '2027-07-01',
            $newMembership->endDate->format('Y-m-d'),
        );

        $this->ledger->clear();
        $reloaded = $this->memberRepository->find($member->lidnr);
        self::assertInstanceOf(
            MemberModel::class,
            $reloaded,
        );
        $entry = $reloaded->getAuditEntries()->first();
        self::assertInstanceOf(
            AuditRenewal::class,
            $entry,
        );
        self::assertSame(
            '2027-07-01',
            $entry->newExpiration->format('Y-m-d'),
        );
    }

    public function testAnUnknownMembershipNumberHasNoRecord(): void
    {
        self::assertNull($this->memberService->getMember(999_999));
    }

    public function testRegistrationUpdateEmailsAreQueuedWithTheMembershipNumber(): void
    {
        $member = $this->build->member();
        $transport = $this->transport();
        $transport->reset();

        $this->memberService->sendRegistrationUpdateEmail(
            $member,
            RegistrationUpdate::Welcome,
        );

        $sent = $transport->getSent();
        self::assertCount(
            1,
            $sent,
        );
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(
            RegistrationUpdateEmail::class,
            $message,
        );
        self::assertSame(
            RegistrationUpdate::Welcome,
            $message->getType(),
        );
        self::assertSame(
            $member->lidnr,
            $message->getLidnr(),
        );
    }

    public function testRefundProblemsAreQueuedWithWhatStripeReported(): void
    {
        $transport = $this->transport();
        $transport->reset();

        $this->memberService->sendRefundProblemEmail(
            're_test',
            'failed',
        );

        $sent = $transport->getSent();
        self::assertCount(
            1,
            $sent,
        );
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(
            RefundProblemEmail::class,
            $message,
        );
        self::assertSame(
            're_test',
            $message->getRefundId(),
        );
        self::assertSame(
            'failed',
            $message->getRefundStatus(),
        );
    }

    private function registrationData(?string $email): RegistrationData
    {
        $data = new RegistrationData();
        $data->initials = 'J.D.';
        $data->firstName = 'Jane';
        $data->middleName = 'van';
        $data->lastName = 'Doe';
        $data->email = $email;
        $data->birth = new DateTimeImmutable('2000-05-06');
        $data->studentNumber = '1234567';
        $data->study = Studies::BCS;
        $data->country = PostalRegions::Netherlands;
        $data->street = 'Main Street';
        $data->number = '42';
        $data->postalCode = '5612 AB';
        $data->city = 'Eindhoven';
        $data->phone = '040-1234567';
        $data->lists = ['activities'];

        return $data;
    }

    private function prospectiveMember(
        string $email,
        Studies $study = Studies::Other,
        ?DateTimeImmutable $changedOn = null,
    ): ProspectiveMember {
        $prospective = new ProspectiveMember();
        $prospective->email = $email;
        $prospective->initials = 'P.';
        $prospective->firstName = 'Pro';
        $prospective->middleName = '';
        $prospective->lastName = 'Spective';
        $prospective->studentNumber = '7654321';
        $prospective->study = $study;
        $prospective->birth = new DateTimeImmutable('2000-01-01');
        $prospective->changedOn = $changedOn ?? new DateTimeImmutable('today');

        $address = new Address();
        $address->type = AddressTypes::Student;
        $address->country = PostalRegions::Netherlands;
        $address->street = 'Stratumseind';
        $address->number = '1';
        $address->postalCode = '5611 AB';
        $address->city = 'Eindhoven';
        $address->phone = '040-0000000';
        $prospective->setAddress($address);

        return $prospective;
    }

    private function withPaidCheckout(ProspectiveMember $prospective): ProspectiveMember
    {
        $this->addPaidCheckout($prospective);
        $this->ledger->flush();

        $lidnr = $prospective->lidnr;
        $this->ledger->clear();

        $reloaded = $this->prospectiveMemberRepository->find($lidnr);
        self::assertInstanceOf(
            ProspectiveMember::class,
            $reloaded,
        );

        return $reloaded;
    }

    private function addPaidCheckout(ProspectiveMember $prospective): void
    {
        $checkout = new CheckoutSession();
        $checkout->checkoutId = 'cs_test_' . $prospective->email;
        $checkout->prospectiveMember = $prospective;
        $checkout->created = new DateTimeImmutable('-1 hour');
        $checkout->expiration = new DateTimeImmutable('+1 hour');
        $checkout->state = CheckoutSessionStates::Paid;

        $this->ledger->persist($checkout);
    }

    /**
     * @return FormInterface<mixed>
     */
    private function form(
        bool $valid,
        mixed $data = null,
    ): FormInterface {
        $form = self::createStub(FormInterface::class);
        $form->method('isValid')->willReturn($valid);

        if (null !== $data) {
            $form->method('getData')->willReturn($data);
        }

        return $form;
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.high_priority');
        self::assertInstanceOf(
            InMemoryTransport::class,
            $transport,
        );

        return $transport;
    }

    private function approveMessageOf(int $lidnr): string
    {
        $result = $this->memberService->getProspectiveMember($lidnr);
        $messages = $result['approveMessages'];
        self::assertNotNull($messages);
        self::assertArrayHasKey(
            1,
            $messages,
        );

        return $messages[1][0];
    }

    private static function lastMembership(MemberModel $member): Membership
    {
        $membership = $member->getLastMembership();
        self::assertNotNull($membership);

        return $membership;
    }

    private static function firstMembership(MemberModel $member): Membership
    {
        $membership = $member->getMemberships()->first();
        self::assertInstanceOf(
            Membership::class,
            $membership,
        );

        return $membership;
    }
}
