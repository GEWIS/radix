<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Decision;

use App\Entity\Database\Enums\BoardFunctions;
use App\Entity\Database\MailingList as DatabaseMailingList;
use App\Entity\Database\MailingListMember as DatabaseMailingListMember;
use App\Entity\Database\Meeting;
use App\Entity\Database\Member as DatabaseMember;
use App\Entity\Database\SubDecision as DatabaseSubDecision;
use App\Entity\Database\SubDecision\Board\Discharge as DatabaseBoardDischarge;
use App\Entity\Database\SubDecision\Board\Installation as DatabaseBoardInstallation;
use App\Entity\Decision\BoardMember;
use App\Entity\Decision\Keyholder;
use App\Entity\Decision\MailingList as DecisionMailingList;
use App\Entity\Decision\MailingListMember as DecisionMailingListMember;
use App\Entity\Decision\SubDecision as DecisionSubDecision;
use App\Entity\Decision\SubDecision\Board\Discharge as DecisionBoardDischarge;
use App\Entity\Decision\SubDecision\Board\Installation as DecisionBoardInstallation;
use App\Entity\Decision\SubDecision\Board\Release as DecisionBoardRelease;
use App\Entity\Decision\SubDecision\Key\Granting as DecisionKeyGranting;
use App\Entity\Decision\SubDecision\Key\Withdrawal as DecisionKeyWithdrawal;
use App\Repository\Database\MailingListRepository;
use App\Service\Decision\BoardService;
use App\Service\Decision\KeyholderService;
use App\Service\Decision\MiscService;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\LedgerBuilder;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The three smaller derivations: who sits on the board, who holds a key, and the mailing lists with their
 * subscriptions. Each is a table of its own that the site reads, and each is written only from the ledger.
 */
#[CoversClass(BoardService::class)]
#[CoversClass(KeyholderService::class)]
#[CoversClass(MiscService::class)]
final class BoardKeyholderMiscTest extends DatabaseTestCase
{
    private EntityManagerInterface $ledger;
    private EntityManagerInterface $projection;
    private LedgerBuilder $build;
    private BoardService $boardService;
    private KeyholderService $keyholderService;
    private MiscService $miscService;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $ledger = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(
            EntityManagerInterface::class,
            $ledger,
        );

        $this->ledger = $ledger;
        $this->projection = $this->entityManager;
        $this->build = new LedgerBuilder($ledger);
        $this->boardService = self::getContainer()->get(BoardService::class);
        $this->keyholderService = self::getContainer()->get(KeyholderService::class);
        $this->miscService = self::getContainer()->get(MiscService::class);
    }

    public function testGenerateInstallationCreatesTheBoardMember(): void
    {
        $member = $this->build->member();
        $installation = $this->build->installBoard(
            $this->build->meeting(date: '2026-02-01'),
            $member,
            BoardFunctions::Secretary,
            '2026-09-01',
        );

        $projected = $this->projected(
            $installation,
            DecisionBoardInstallation::class,
        );

        $boardMember = $this->boardService->generateInstallation($projected);
        $this->projection->flush();

        self::assertSame(
            $member->lidnr,
            $boardMember->member->lidnr,
        );
        self::assertSame(
            BoardFunctions::Secretary,
            $boardMember->function,
        );
        self::assertSame(
            '2026-09-01',
            $boardMember->installDate->format('Y-m-d'),
        );
        self::assertNull($boardMember->releaseDate);
        self::assertNull($boardMember->dischargeDate);

        $this->boardService->generateInstallation($projected);
        $this->projection->flush();

        self::assertSame(
            1,
            $this->projection->getRepository(BoardMember::class)->count([
                'installationDec' => $projected,
            ]),
        );
    }

    public function testGenerateReleaseOnlyReleasesTheInstallationItNames(): void
    {
        $meeting = $this->build->meeting(date: '2026-02-01');
        $first = $this->build->installBoard(
            $meeting,
            $this->build->member(),
            BoardFunctions::Chair,
            '2026-09-01',
        );
        $second = $this->build->installBoard(
            $meeting,
            $this->build->member(),
            BoardFunctions::Treasurer,
            '2026-09-01',
        );
        $release = $this->build->releaseBoard(
            $this->build->meeting(date: '2027-09-01'),
            $second,
            '2027-08-15',
        );

        $this->boardService->generateRelease(
            $this->projected(
                $release,
                DecisionBoardRelease::class,
            ),
        );
        $this->projection->flush();

        $secondMember = $this->boardMemberOf($this->projected($second));
        $firstMember = $this->boardMemberOf($this->projected($first));

        self::assertNotNull($secondMember);
        self::assertNotNull($firstMember);
        self::assertSame(
            '2027-08-15',
            $secondMember->releaseDate?->format('Y-m-d'),
        );
        // Still on the board: it was not named by the release.
        self::assertNull($firstMember->releaseDate);
    }

    public function testGenerateDischargeOnlyDischargesTheInstallationItNames(): void
    {
        $meeting = $this->build->meeting(date: '2026-02-01');
        $first = $this->build->installBoard(
            $meeting,
            $this->build->member(),
            BoardFunctions::Chair,
            '2026-09-01',
        );
        $second = $this->build->installBoard(
            $meeting,
            $this->build->member(),
            BoardFunctions::Secretary,
            '2026-09-01',
        );
        $discharge = $this->boardDischarge(
            $this->build->meeting(date: '2027-10-01'),
            $first,
        );

        $this->boardService->generateDischarge(
            $this->projected(
                $discharge,
                DecisionBoardDischarge::class,
            ),
        );
        $this->projection->flush();

        $firstMember = $this->boardMemberOf($this->projected($first));
        $secondMember = $this->boardMemberOf($this->projected($second));

        self::assertNotNull($firstMember);
        self::assertNotNull($secondMember);
        self::assertSame(
            '2027-10-01',
            $firstMember->dischargeDate?->format('Y-m-d'),
        );
        self::assertNull($secondMember->dischargeDate);
    }

    /**
     * An installation that never took effect has no board member to release or discharge, and that is not an error.
     */
    public function testReleaseAndDischargeWithoutABoardMemberDoNothing(): void
    {
        $installation = $this->build->installBoard(
            $this->build->meeting(date: '2026-02-01'),
            $this->build->member(),
        );
        $release = $this->build->releaseBoard(
            $this->build->meeting(date: '2027-09-01'),
            $installation,
        );
        $discharge = $this->boardDischarge(
            $this->build->meeting(date: '2027-10-01'),
            $installation,
        );

        $projectedInstallation = $this->projected(
            $installation,
            DecisionBoardInstallation::class,
        );
        $boardMember = $this->boardMemberOf($projectedInstallation);
        self::assertNotNull($boardMember);
        $this->projection->remove($boardMember);
        $this->projection->flush();
        $this->projection->clear();

        $this->boardService->generateRelease($this->projected($release, DecisionBoardRelease::class));
        $this->boardService->generateDischarge($this->projected($discharge, DecisionBoardDischarge::class));
        $this->projection->flush();

        self::assertNull(
            $this->boardMemberOf($this->projected($installation)),
        );
    }

    public function testGenerateGrantingCreatesTheKeyholder(): void
    {
        $member = $this->build->member();
        $granting = $this->build->grantKey(
            $this->build->meeting(),
            $member,
            '+2 months',
        );

        $projected = $this->projected(
            $granting,
            DecisionKeyGranting::class,
        );

        $keyholder = $this->keyholderService->generateGranting($projected);
        $this->projection->flush();

        self::assertSame(
            $member->lidnr,
            $keyholder->member->lidnr,
        );
        self::assertNull($keyholder->withdrawnDate);

        $this->keyholderService->generateGranting($projected);
        $this->projection->flush();

        self::assertSame(
            1,
            $this->projection->getRepository(Keyholder::class)->count([
                'grantingDec' => $projected,
            ]),
        );
    }

    public function testGenerateWithdrawalOnlyWithdrawsTheKeyItNames(): void
    {
        $member = $this->build->member();
        $first = $this->build->grantKey(
            $this->build->meeting(date: '2026-02-01'),
            $member,
        );
        $second = $this->build->grantKey(
            $this->build->meeting(date: '2026-02-01'),
            $this->build->member(),
        );
        $withdrawal = $this->build->withdrawKey(
            $this->build->meeting(date: '2027-02-01'),
            $first,
            '2027-01-15',
        );

        $this->keyholderService->generateWithdrawal(
            $this->projected(
                $withdrawal,
                DecisionKeyWithdrawal::class,
            ),
        );
        $this->projection->flush();

        $firstKeyholder = $this->keyholderOf($this->projected($first));
        $secondKeyholder = $this->keyholderOf($this->projected($second));

        self::assertNotNull($firstKeyholder);
        self::assertNotNull($secondKeyholder);
        self::assertSame(
            '2027-01-15',
            $firstKeyholder->withdrawnDate?->format('Y-m-d'),
        );
        self::assertNull($secondKeyholder->withdrawnDate);
    }

    /**
     * A key that was never granted cannot be withdrawn; the withdrawal is skipped rather than invented.
     */
    public function testGenerateWithdrawalWithoutAKeyholderDoesNothing(): void
    {
        $granting = $this->build->grantKey(
            $this->build->meeting(date: '2026-02-01'),
            $this->build->member(),
        );
        $this->build->annul(
            $this->build->meeting(date: '2026-03-01'),
            $granting->decision,
        );
        $withdrawal = $this->build->withdrawKey(
            $this->build->meeting(date: '2026-04-01'),
            $granting,
        );

        self::assertNull($this->keyholderOf($this->projected($granting)));

        $this->projection->clear();

        $this->keyholderService->generateWithdrawal(
            $this->projected(
                $withdrawal,
                DecisionKeyWithdrawal::class,
            ),
        );
        $this->projection->flush();

        self::assertNull($this->keyholderOf($this->projected($granting)));
    }

    public function testGenerateListsWritesEveryList(): void
    {
        $ledgerLists = self::getContainer()->get(MailingListRepository::class)->count([]);
        self::assertGreaterThan(
            0,
            $ledgerLists,
        );

        $this->miscService->generateLists();

        self::assertSame(
            $ledgerLists,
            $this->projection->getRepository(DecisionMailingList::class)->count([]),
        );
    }

    public function testGenerateListIsIdempotentAndKeepsTheDescriptionLevel(): void
    {
        $list = $this->mailingList('board-keyholder-list');

        $this->miscService->generateList($list);
        $this->projection->flush();

        self::assertNotNull(
            $this->projection->getRepository(DecisionMailingList::class)->find($list->name),
        );

        $list->setNlDescription('Bijgewerkte omschrijving');
        $list->setEnDescription('Updated description');
        $this->miscService->generateList($list);
        $this->projection->flush();
        $this->projection->clear();

        $projected = $this->projection->getRepository(DecisionMailingList::class)->find($list->name);

        self::assertNotNull($projected);
        self::assertSame(
            'Bijgewerkte omschrijving',
            $projected->getNlDescription(),
        );
        self::assertSame(
            'Updated description',
            $projected->getEnDescription(),
        );
        self::assertSame(
            1,
            $this->projection->getRepository(DecisionMailingList::class)->count(['name' => $list->name]),
        );
    }

    public function testGenerateListMembershipWritesTheSubscription(): void
    {
        $list = $this->mailingList('board-keyholder-subscription');
        $member = $this->build->member();
        $membership = $this->membership(
            $list,
            $member,
        );

        $this->miscService->generateListMembership($membership);
        $this->projection->flush();

        $projected = $this->projectedMembership(
            $list->name,
            $member->email,
        );
        self::assertNotNull($projected);
        self::assertSame(
            $member->lidnr,
            $projected->member->lidnr,
        );

        $this->miscService->generateListMembership($membership);
        $this->projection->flush();

        self::assertSame(
            1,
            $this->projection->getRepository(DecisionMailingListMember::class)->count([
                'mailingList' => $list->name,
                'email' => $member->email,
            ]),
        );
    }

    public function testGenerateListMembershipThrowsWhenTheListIsNotProjected(): void
    {
        $list = new DatabaseMailingList();
        $list->name = 'board-keyholder-never-projected';
        $list->setNlDescription('Bestaat niet');
        $list->setEnDescription('Does not exist');
        $list->onForm = false;
        $list->defaultSub = false;

        $membership = $this->membership(
            $list,
            $this->build->member(),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('List membership without list');

        $this->miscService->generateListMembership($membership);
    }

    public function testGenerateListMembershipThrowsWhenTheMemberIsNotProjected(): void
    {
        $list = $this->mailingList('board-keyholder-orphan');
        $member = new DatabaseMember();
        $member->lidnr = 990001;
        $member->setEmail('unprojected-member@example.org');

        $membership = $this->membership(
            $list,
            $member,
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('List membership without member');

        $this->miscService->generateListMembership($membership);
    }

    public function testDeleteListRemovesIt(): void
    {
        $list = $this->mailingList('board-keyholder-delete');
        self::assertNotNull(
            $this->projection->getRepository(DecisionMailingList::class)->find($list->name),
        );

        $this->miscService->deleteList($list);
        $this->projection->flush();

        self::assertNull(
            $this->projection->getRepository(DecisionMailingList::class)->find($list->name),
        );

        // Nothing left to delete.
        $this->miscService->deleteList($list);
        $this->projection->flush();
    }

    public function testDeleteListMembershipRemovesIt(): void
    {
        $list = $this->mailingList('board-keyholder-unsubscribe');
        $member = $this->build->member();
        $membership = $this->membership(
            $list,
            $member,
        );

        $this->miscService->generateListMembership($membership);
        $this->projection->flush();
        self::assertNotNull($this->projectedMembership($list->name, $member->email));

        $this->miscService->deleteListMembership($membership);
        $this->projection->flush();

        self::assertNull($this->projectedMembership($list->name, $member->email));

        // Nothing left to delete.
        $this->miscService->deleteListMembership($membership);
        $this->projection->flush();
    }

    private function boardDischarge(
        Meeting $meeting,
        DatabaseBoardInstallation $installation,
    ): DatabaseBoardDischarge {
        $discharge = new DatabaseBoardDischarge();
        $discharge->installation = $installation;
        $discharge->sequence = 1;
        $discharge->setDecision($this->build->decision($meeting));

        $this->ledger->persist($discharge);
        $this->ledger->flush();

        return $discharge;
    }

    private function mailingList(string $name): DatabaseMailingList
    {
        $list = new DatabaseMailingList();
        $list->name = $name;
        $list->setNlDescription('Nederlandse omschrijving');
        $list->setEnDescription('English description');
        $list->onForm = true;
        $list->defaultSub = false;

        $this->ledger->persist($list);
        $this->ledger->flush();

        return $list;
    }

    private function membership(
        DatabaseMailingList $list,
        DatabaseMember $member,
    ): DatabaseMailingListMember {
        $membership = new DatabaseMailingListMember();
        $membership->mailingList = $list;
        $member->addList($membership);

        return $membership;
    }

    /**
     * @template T of DecisionSubDecision
     *
     * @param class-string<T>|null $type
     *
     * @return ($type is class-string<T> ? T : DecisionSubDecision)
     */
    private function projected(
        DatabaseSubDecision $subDecision,
        ?string $type = null,
    ): DecisionSubDecision {
        $projected = $this->projection->getRepository(DecisionSubDecision::class)->find([
            'meeting_type' => $subDecision->getMeetingType(),
            'meeting_number' => $subDecision->getMeetingNumber(),
            'decision_point' => $subDecision->getDecisionPoint(),
            'decision_number' => $subDecision->getDecisionNumber(),
            'sequence' => $subDecision->sequence,
        ]);

        self::assertNotNull($projected);

        if (null !== $type) {
            self::assertInstanceOf(
                $type,
                $projected,
            );
        }

        return $projected;
    }

    private function boardMemberOf(DecisionSubDecision $installation): ?BoardMember
    {
        return $this->projection->getRepository(BoardMember::class)
            ->findOneBy(['installationDec' => $installation]);
    }

    private function keyholderOf(DecisionSubDecision $granting): ?Keyholder
    {
        return $this->projection->getRepository(Keyholder::class)
            ->findOneBy(['grantingDec' => $granting]);
    }

    private function projectedMembership(
        string $list,
        ?string $email,
    ): ?DecisionMailingListMember {
        self::assertNotNull($email);

        return $this->projection->getRepository(DecisionMailingListMember::class)->find([
            'mailingList' => $list,
            'email' => $email,
        ]);
    }
}
