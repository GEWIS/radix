<?php

declare(strict_types=1);

namespace App\Tests\Service\Database;

use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\ListmonkMailingList;
use App\Entity\Database\MailingList;
use App\Entity\Database\MailingListMember;
use App\Entity\Database\MailmanMailingList;
use App\Entity\Database\Member;
use App\Entity\Database\Membership;
use App\Entity\Database\RenewalLink;
use App\Repository\Database\MailingListMemberRepository;
use App\Repository\Database\MailingListRepository;
use App\Service\Database\ListmonkService;
use App\Service\Database\MailingListService;
use App\Service\Database\MailmanService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Console\Output\NullOutput;

/**
 * The register's own view of mailing lists: which lists may be bound, which memberships are due for external removal,
 * and which local-only memberships can be closed without talking to anything.
 */
#[CoversClass(MailingListService::class)]
class MailingListServiceTest extends TestCase
{
    public function testAllListsComeFromTheRepository(): void
    {
        $lists = [
            $this->mailingList('a'),
            $this->mailingList('b'),
        ];

        $repository = self::createStub(MailingListRepository::class);
        $repository->method('findAll')->willReturn($lists);

        self::assertSame(
            $lists,
            $this->service($repository)->getAllLists(),
        );
    }

    public function testAListIsLookedUpByName(): void
    {
        $list = $this->mailingList('board');

        $repository = $this->createMock(MailingListRepository::class);
        $repository->expects(self::once())
            ->method('find')
            ->with('board')
            ->willReturn($list);

        self::assertSame(
            $list,
            $this->service($repository)->getList('board'),
        );
    }

    public function testAddingAListPersistsIt(): void
    {
        $list = $this->mailingList('board');

        $repository = $this->createMock(MailingListRepository::class);
        $repository->expects(self::once())->method('persist')->with($list);

        $this->service($repository)->addList($list);
    }

    public function testDeletingAListRemovesIt(): void
    {
        $list = $this->mailingList('board');

        $repository = $this->createMock(MailingListRepository::class);
        $repository->expects(self::once())->method('remove')->with($list);

        $this->service($repository)->delete($list);
    }

    public function testTheListmonkFetchTimeComesFromTheListmonkService(): void
    {
        $lastFetch = new DateTimeImmutable('-2 minutes');

        $listmonkService = self::createStub(ListmonkService::class);
        $listmonkService->method('getLastFetchTime')->willReturn($lastFetch);

        self::assertSame(
            $lastFetch,
            $this->service(listmonkService: $listmonkService)->getListmonkLastFetch(),
        );
    }

    /**
     * A Mailman list belongs to at most one mailing list, so the ones that are taken are not offered; the list being
     * edited may keep its own binding.
     */
    public function testAMailmanListThatBelongsToAnotherListIsNotSelectable(): void
    {
        $edited = $this->mailingList('edited');

        $free = $this->mailmanList('free');
        $takenElsewhere = $this->mailmanList(
            'taken',
            $this->mailingList('other'),
        );
        $own = $this->mailmanList(
            'own',
            $edited,
        );

        $mailmanService = self::createStub(MailmanService::class);
        $mailmanService->method('getMailingLists')->willReturn([$free, $takenElsewhere, $own]);

        self::assertSame(
            [
                $free,
                $own,
            ],
            $this->service(mailmanService: $mailmanService)->getSelectableMailmanLists($edited),
        );
    }

    public function testWithoutAListToEditEveryManagedMailmanListIsExcluded(): void
    {
        $free = $this->mailmanList('free');
        $managed = $this->mailmanList(
            'managed',
            $this->mailingList('other'),
        );

        $mailmanService = self::createStub(MailmanService::class);
        $mailmanService->method('getMailingLists')->willReturn([$free, $managed]);

        self::assertSame(
            [$free],
            $this->service(mailmanService: $mailmanService)->getSelectableMailmanLists(),
        );
    }

    public function testAListmonkListThatBelongsToAnotherListIsNotSelectable(): void
    {
        $edited = $this->mailingList('edited');

        $free = $this->listmonkList(
            1,
            'free',
        );
        $takenElsewhere = $this->listmonkList(
            2,
            'taken',
            $this->mailingList('other'),
        );
        $own = $this->listmonkList(
            3,
            'own',
            $edited,
        );

        $listmonkService = self::createStub(ListmonkService::class);
        $listmonkService->method('getMailingLists')->willReturn([$free, $takenElsewhere, $own]);

        self::assertSame(
            [
                $free,
                $own,
            ],
            $this->service(listmonkService: $listmonkService)->getSelectableListmonkLists($edited),
        );
    }

    public function testWithoutAListToEditEveryManagedListmonkListIsExcluded(): void
    {
        $free = $this->listmonkList(
            1,
            'free',
        );
        $managed = $this->listmonkList(
            2,
            'managed',
            $this->mailingList('other'),
        );

        $listmonkService = self::createStub(ListmonkService::class);
        $listmonkService->method('getMailingLists')->willReturn([$free, $managed]);

        self::assertSame(
            [$free],
            $this->service(listmonkService: $listmonkService)->getSelectableListmonkLists(),
        );
    }

    public function testTheStatusFiguresAreThePendingCreationsAndDeletions(): void
    {
        $memberRepository = self::createStub(MailingListMemberRepository::class);
        $memberRepository->method('countPendingCreation')->willReturn(3);
        $memberRepository->method('countPendingDeletion')->willReturn(5);

        self::assertSame(
            [
                'mailingListChangesPending' => [
                    'creations' => 3,
                    'deletions' => 5,
                ],
            ],
            $this->service(memberRepository: $memberRepository)->getStatusFigures(),
        );
    }

    /**
     * Either external service being locked means the mailing list sync is running, so asking both keeps the two
     * synchronisations from stepping on each other.
     */
    public function testTheSyncIsLockedWhenEitherExternalServiceIs(): void
    {
        foreach ([[true, false], [false, true], [true, true]] as [$listmonkLocked, $mailmanLocked]) {
            $listmonkService = self::createStub(ListmonkService::class);
            $listmonkService->method('isSyncLocked')->willReturn($listmonkLocked);

            $mailmanService = self::createStub(MailmanService::class);
            $mailmanService->method('isSyncLocked')->willReturn($mailmanLocked);

            self::assertTrue(
                $this->service(
                    listmonkService: $listmonkService,
                    mailmanService: $mailmanService,
                )->isSyncLocked(),
            );
        }

        self::assertFalse($this->service(
            listmonkService: $this->unlockedListmonk(),
            mailmanService: $this->unlockedMailman(),
        )->isSyncLocked());
    }

    public function testMarkingEveryMemberForCreationIsDoneThroughTheRepository(): void
    {
        $list = $this->mailingList('board');

        $memberRepository = $this->createMock(MailingListMemberRepository::class);
        $memberRepository->expects(self::once())
            ->method('markAllMembersForCreation')
            ->with($list);

        $this->service(memberRepository: $memberRepository)->markAllMembersForCreation($list);
    }

    /**
     * Someone who can still renew is not removed yet, unless their membership is hidden; everyone else is scheduled
     * for deletion.
     */
    public function testMaintenanceSkipsMembersThatCanStillRenewAndSchedulesTheRest(): void
    {
        $renewing = $this->membershipOf($this->member('renewing@gewis.test'));
        self::memberOf($renewing)->getRenewalLinks()->add($this->activeRenewal(self::memberOf($renewing)));

        $expired = $this->membershipOf($this->member('expired@gewis.test'));

        $memberRepository = $this->createMock(MailingListMemberRepository::class);
        $memberRepository->method('findAllExpiredOrHidden')->willReturn([$renewing, $expired]);
        $memberRepository->expects(self::once())
            ->method('persist')
            ->with($expired);

        $this->service(memberRepository: $memberRepository)->performMaintenance();

        self::assertFalse($renewing->toBeDeleted);
        self::assertTrue($expired->toBeDeleted);
    }

    public function testAHiddenMemberIsRemovedEvenWithAnActiveRenewalLink(): void
    {
        $membership = $this->membershipOf($this->member('hidden@gewis.test'));
        self::memberOf($membership)->hidden = true;
        self::memberOf($membership)->getRenewalLinks()->add($this->activeRenewal(self::memberOf($membership)));

        $memberRepository = $this->createMock(MailingListMemberRepository::class);
        $memberRepository->method('findAllExpiredOrHidden')->willReturn([$membership]);
        $memberRepository->expects(self::once())->method('persist')->with($membership);

        $this->service(memberRepository: $memberRepository)->performMaintenance();

        self::assertTrue($membership->toBeDeleted);
    }

    public function testMaintenanceOnADryRunWritesNothing(): void
    {
        $membership = $this->membershipOf($this->member('expired@gewis.test'));

        $memberRepository = $this->createMock(MailingListMemberRepository::class);
        $memberRepository->method('findAllExpiredOrHidden')->willReturn([$membership]);
        $memberRepository->expects(self::never())->method('persist');

        $this->service(memberRepository: $memberRepository)->performMaintenance(
            new NullOutput(),
            true,
        );

        self::assertFalse($membership->toBeDeleted);
    }

    /**
     * A local-only list has nothing to synchronise with, so a pending removal is simply dropped and a pending
     * creation is marked done.
     */
    public function testLocalOnlyMembershipsAreClosedWithoutAnExternalService(): void
    {
        $list = $this->mailingList('local');

        $deletion = $this->membership(
            $list,
            $this->member('gone@gewis.test'),
            'gone@gewis.test',
        );
        $deletion->toBeDeleted = true;
        $deletion->toBeCreated = false;

        $creation = $this->membership(
            $list,
            $this->member('new@gewis.test'),
            'new@gewis.test',
        );
        $creation->toBeCreated = true;

        $memberRepository = $this->createMock(MailingListMemberRepository::class);
        $memberRepository->method('findAllPendingLocalOnly')->willReturn([$deletion, $creation]);
        $memberRepository->expects(self::once())->method('remove')->with($deletion);
        $memberRepository->expects(self::once())->method('persist')->with($creation);

        $this->service(memberRepository: $memberRepository)->syncLocalOnlyMembership();

        self::assertFalse($creation->toBeCreated);
        self::assertNotNull($creation->lastSyncOn);
        self::assertTrue($creation->lastSyncSuccess);
        self::assertNotNull($deletion->lastSyncOn);
        self::assertTrue($deletion->lastSyncSuccess);
    }

    public function testLocalOnlyMembershipsAreNotWrittenOnADryRun(): void
    {
        $list = $this->mailingList('local');

        $deletion = $this->membership(
            $list,
            $this->member('gone@gewis.test'),
            'gone@gewis.test',
        );
        $deletion->toBeDeleted = true;
        $deletion->toBeCreated = false;

        $creation = $this->membership(
            $list,
            $this->member('new@gewis.test'),
            'new@gewis.test',
        );
        $creation->toBeCreated = true;

        $memberRepository = $this->createMock(MailingListMemberRepository::class);
        $memberRepository->method('findAllPendingLocalOnly')->willReturn([$deletion, $creation]);
        $memberRepository->expects(self::never())->method('remove');
        $memberRepository->expects(self::never())->method('persist');

        $this->service(memberRepository: $memberRepository)
            ->syncLocalOnlyMembership(
                new NullOutput(),
                true,
            );

        self::assertTrue($creation->toBeCreated);
        self::assertNull($creation->lastSyncOn);
        self::assertFalse($creation->lastSyncSuccess);
    }

    /**
     * Binding a list to another external list means everyone on it still has to be created there.
     */
    public function testChangingTheBindingMarksEveryMemberForCreation(): void
    {
        $oldMailman = $this->mailmanList('old');
        $newMailman = $this->mailmanList('new');
        $list = $this->mailingList(
            'board',
            $newMailman,
        );

        $repository = $this->createMock(MailingListRepository::class);
        $repository->expects(self::once())->method('persist')->with($list);

        $memberRepository = $this->createMock(MailingListMemberRepository::class);
        $memberRepository->expects(self::once())
            ->method('markAllMembersForCreation')
            ->with($list);

        $this->service(
            $repository,
            $memberRepository,
            entityManager: $this->unitOfWorkReturning(['mailmanList' => $oldMailman, 'listmonkList' => null]),
        )->editList($list);
    }

    public function testChangingTheListmonkBindingMarksEveryMemberForCreation(): void
    {
        $oldListmonk = $this->listmonkList(
            1,
            'old',
        );
        $newListmonk = $this->listmonkList(
            2,
            'new',
        );
        $list = $this->mailingList(
            'board',
            listmonkList: $newListmonk,
        );

        $repository = self::createStub(MailingListRepository::class);

        $memberRepository = $this->createMock(MailingListMemberRepository::class);
        $memberRepository->expects(self::once())
            ->method('markAllMembersForCreation')
            ->with($list);

        $this->service(
            $repository,
            $memberRepository,
            entityManager: $this->unitOfWorkReturning(['mailmanList' => null, 'listmonkList' => $oldListmonk]),
        )->editList($list);
    }

    public function testSavingTheSameBindingDoesNotMarkEveryMemberAgain(): void
    {
        $mailman = $this->mailmanList('same');
        $list = $this->mailingList(
            'board',
            $mailman,
        );

        $repository = $this->createMock(MailingListRepository::class);
        $repository->expects(self::once())->method('persist')->with($list);

        $memberRepository = $this->createMock(MailingListMemberRepository::class);
        $memberRepository->expects(self::never())->method('markAllMembersForCreation');

        $this->service(
            $repository,
            $memberRepository,
            entityManager: $this->unitOfWorkReturning(['mailmanList' => $mailman, 'listmonkList' => null]),
        )->editList($list);
    }

    private function service(
        ?MailingListRepository $repository = null,
        ?MailingListMemberRepository $memberRepository = null,
        ?ListmonkService $listmonkService = null,
        ?MailmanService $mailmanService = null,
        ?EntityManagerInterface $entityManager = null,
    ): MailingListService {
        return new MailingListService(
            $repository ?? self::createStub(MailingListRepository::class),
            $memberRepository ?? self::createStub(MailingListMemberRepository::class),
            $listmonkService ?? self::createStub(ListmonkService::class),
            $mailmanService ?? self::createStub(MailmanService::class),
            $entityManager ?? self::createStub(EntityManagerInterface::class),
        );
    }

    /**
     * @param array<string, mixed> $originalData
     */
    private function unitOfWorkReturning(array $originalData): EntityManagerInterface
    {
        $unitOfWork = self::createStub(UnitOfWork::class);
        $unitOfWork->method('getOriginalEntityData')->willReturn($originalData);

        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('getUnitOfWork')->willReturn($unitOfWork);

        return $entityManager;
    }

    private function unlockedListmonk(): ListmonkService
    {
        $service = self::createStub(ListmonkService::class);
        $service->method('isSyncLocked')->willReturn(false);

        return $service;
    }

    private function unlockedMailman(): MailmanService
    {
        $service = self::createStub(MailmanService::class);
        $service->method('isSyncLocked')->willReturn(false);

        return $service;
    }

    private function mailingList(
        string $name,
        ?MailmanMailingList $mailmanList = null,
        ?ListmonkMailingList $listmonkList = null,
    ): MailingList {
        $list = new MailingList();
        $list->name = $name;
        $list->onForm = true;
        $list->defaultSub = false;
        $list->setEnDescription('English ' . $name);
        $list->setNlDescription('Nederlands ' . $name);
        $list->mailmanList = $mailmanList;
        $list->listmonkList = $listmonkList;

        return $list;
    }

    private function mailmanList(
        string $id,
        ?MailingList $mailingList = null,
    ): MailmanMailingList {
        $mailmanList = new MailmanMailingList();
        $mailmanList->mailmanId = $id;
        $mailmanList->name = 'Mailman ' . $id;
        $mailmanList->setLastSeen();

        if (null !== $mailingList) {
            self::setProperty(
                $mailmanList,
                'mailingList',
                $mailingList,
            );
        }

        return $mailmanList;
    }

    private function listmonkList(
        int $id,
        string $name,
        ?MailingList $mailingList = null,
    ): ListmonkMailingList {
        $listmonkList = new ListmonkMailingList();
        $listmonkList->listmonkId = $id;
        $listmonkList->name = $name;
        $listmonkList->setLastSeen();

        if (null !== $mailingList) {
            self::setProperty(
                $listmonkList,
                'mailingList',
                $mailingList,
            );
        }

        return $listmonkList;
    }

    private function member(string $email): Member
    {
        $member = new Member();
        $member->firstName = 'Test';
        $member->middleName = '';
        $member->lastName = 'Member';
        $member->initials = 'T';
        $member->setEmail($email);

        return $member;
    }

    private function membershipOf(Member $member): MailingListMember
    {
        $list = $this->mailingList('expired-' . $member->email);
        self::assertNotNull($member->email);

        return $this->membership(
            $list,
            $member,
            $member->email,
        );
    }

    private function membership(
        MailingList $list,
        ?Member $member,
        string $email,
    ): MailingListMember {
        $membership = new MailingListMember();
        $membership->mailingList = $list;

        if (null !== $member) {
            $membership->setMember($member);
        }

        $membership->email = $email;

        return $membership;
    }

    private function activeRenewal(?Member $member): RenewalLink
    {
        self::assertNotNull($member);
        $currentExpiration = new DateTimeImmutable()->modify('+1 month');
        $member->addMembership(new Membership(
            $member,
            MembershipTypes::Ordinary,
            $currentExpiration->modify('-1 year'),
            $currentExpiration,
        ));

        return new RenewalLink(
            $member,
            $currentExpiration->modify('+1 year'),
        );
    }

    private static function setProperty(
        object $object,
        string $property,
        mixed $value,
    ): void {
        new ReflectionProperty(
            $object,
            $property,
        )->setValue(
            $object,
            $value,
        );
    }

    private static function memberOf(MailingListMember $membership): Member
    {
        $member = $membership->member;
        self::assertNotNull($member);

        return $member;
    }
}
