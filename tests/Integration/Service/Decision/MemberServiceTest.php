<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Decision;

use App\Entity\Database\Address as DatabaseAddress;
use App\Entity\Database\Enums\AddressTypes;
use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\Enums\PostalRegions;
use App\Entity\Database\Enums\Studies;
use App\Entity\Database\MailingList as DatabaseMailingList;
use App\Entity\Database\MailingListMember as DatabaseMailingListMember;
use App\Entity\Database\Member as DatabaseMember;
use App\Entity\Decision\Address as DecisionAddress;
use App\Entity\Decision\MailingList as DecisionMailingList;
use App\Entity\Decision\MailingListMember as DecisionMailingListMember;
use App\Entity\Decision\Member as DecisionMember;
use App\Repository\Database\MemberRepository;
use App\Service\Decision\MemberService;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\LedgerBuilder;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;

use function count;

/**
 * The projection of a member and everything attached to their record: their addresses and their mailing list
 * subscriptions. The register writes this as the member is written, and `app:decision:generate` rewrites all of it.
 */
#[CoversClass(MemberService::class)]
final class MemberServiceTest extends DatabaseTestCase
{
    private EntityManagerInterface $ledger;
    private EntityManagerInterface $projection;
    private LedgerBuilder $build;
    private MemberService $service;

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
        $this->service = self::getContainer()->get(MemberService::class);
    }

    public function testGenerateExportsEveryLedgerMemberAndReportsProgress(): void
    {
        $total = count(self::getContainer()->get(MemberRepository::class)->findAll());
        self::assertGreaterThan(
            0,
            $total,
        );

        $progress = [];
        $this->service->generate(
            static function (int $current, int $total) use (&$progress): void {
                $progress[] = [
                    $current,
                    $total,
                ];
            },
        );

        self::assertNotEmpty($progress);
        self::assertSame(
            [
                1,
                $total,
            ],
            $progress[0],
        );
        self::assertSame(
            $total,
            $this->projection->getRepository(DecisionMember::class)->count([]),
        );
    }

    public function testGenerateMemberCreatesTheProjectedMember(): void
    {
        $member = $this->build->member();

        $this->service->generateMember($member);
        $this->projection->flush();
        $this->projection->clear();

        $projected = $this->projectedMember($member->lidnr);

        self::assertNotNull($projected);
        self::assertSame(
            $member->firstName,
            $projected->firstName,
        );
        self::assertSame(
            $member->lastName,
            $projected->lastName,
        );
        self::assertSame(
            $member->email,
            $projected->email,
        );
        self::assertSame(
            '2000-01-01',
            $projected->birth->format('Y-m-d'),
        );
        self::assertSame(
            Studies::Unknown,
            $projected->study,
        );
        self::assertSame(
            MembershipTypes::Ordinary,
            $projected->type,
        );
    }

    public function testGenerateMemberUpdatesTheProjectedMemberRatherThanAddingOne(): void
    {
        $member = $this->build->member();

        $this->service->generateMember($member);
        $this->projection->flush();

        $member->lastName = 'Anders';
        $member->study = Studies::MCSE;
        $this->service->generateMember($member);
        $this->projection->flush();
        $this->projection->clear();

        $projected = $this->projectedMember($member->lidnr);

        self::assertNotNull($projected);
        self::assertSame(
            'Anders',
            $projected->lastName,
        );
        self::assertSame(
            Studies::MCSE,
            $projected->study,
        );
        self::assertSame(
            1,
            $this->projection->getRepository(DecisionMember::class)->count(['lidnr' => $member->lidnr]),
        );
    }

    public function testGenerateMemberProjectsEveryAddressType(): void
    {
        $member = $this->build->member();

        foreach (AddressTypes::cases() as $type) {
            $this->address(
                $member,
                $type,
                'Straat ' . $type->value,
            );
        }

        $this->service->generateMember($member);
        $this->projection->flush();
        $this->projection->clear();

        foreach (AddressTypes::cases() as $type) {
            $address = $this->projectedAddress(
                $member->lidnr,
                $type,
            );

            self::assertNotNull($address);
            self::assertSame(
                'Straat ' . $type->value,
                $address->street,
            );
        }

        self::assertCount(
            3,
            $this->projectedMember($member->lidnr)?->getAddresses() ?? [],
        );
    }

    public function testGenerateAddressUpdatesTheAddressOfThatTypeInPlace(): void
    {
        $member = $this->build->member();
        $address = $this->address(
            $member,
            AddressTypes::Student,
            'Oude straat',
        );

        $address->street = 'Nieuwe straat';
        $this->service->generateAddress($address);
        $this->projection->flush();

        $projected = $this->projectedAddress(
            $member->lidnr,
            AddressTypes::Student,
        );

        self::assertNotNull($projected);
        self::assertSame(
            'Nieuwe straat',
            $projected->street,
        );
        self::assertSame(
            1,
            $this->projection->getRepository(DecisionAddress::class)->count([
                'member' => $member->lidnr,
                'type' => AddressTypes::Student,
            ]),
        );
    }

    public function testGenerateAddressThrowsWhenTheMemberIsNotProjected(): void
    {
        $member = $this->build->member();
        $address = $this->address(
            $member,
            AddressTypes::Home,
            'Thuisstraat',
        );

        $this->service->deleteMember($member);
        $this->projection->flush();
        $this->projection->clear();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('Address without member');

        $this->service->generateAddress($address);
    }

    public function testDeleteAddressRemovesTheProjectedAddress(): void
    {
        $member = $this->build->member();
        $address = $this->address(
            $member,
            AddressTypes::Mail,
            'Poststraat',
        );
        self::assertNotNull(
            $this->projectedAddress(
                $member->lidnr,
                AddressTypes::Mail,
            ),
        );

        $this->service->deleteAddress($address);
        $this->projection->flush();

        self::assertNull(
            $this->projectedAddress(
                $member->lidnr,
                AddressTypes::Mail,
            ),
        );

        // Nothing to delete a second time.
        $this->service->deleteAddress($address);
        $this->projection->flush();
    }

    public function testDeleteMemberRemovesTheProjectedMember(): void
    {
        $member = $this->build->member();
        self::assertNotNull($this->projectedMember($member->lidnr));

        $this->service->deleteMember($member);
        $this->projection->flush();

        self::assertNull($this->projectedMember($member->lidnr));

        // A member the projection does not know is not an error.
        $this->service->deleteMember($member);
        $this->projection->flush();
    }

    public function testGenerateListsAddsTheSubscriptionsTheMemberHas(): void
    {
        $list = $this->mailingList('member-service-list');
        $member = $this->build->member();
        $membership = new DatabaseMailingListMember();
        $membership->mailingList = $list;
        $member->addList($membership);

        $projectedMember = $this->projectedMember($member->lidnr);
        self::assertNotNull($projectedMember);

        $this->service->generateLists(
            $member,
            $projectedMember,
        );
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
    }

    /**
     * A subscription entered against another address is not this member's subscription, so it is not projected onto
     * their record.
     */
    public function testGenerateListsIgnoresASubscriptionUnderAnotherAddress(): void
    {
        $list = $this->mailingList('member-service-other-address');
        $member = $this->build->member();

        $own = new DatabaseMailingListMember();
        $own->mailingList = $list;
        $member->addList($own);

        $foreign = new DatabaseMailingListMember();
        $foreign->mailingList = $list;
        $member->addList($foreign);
        $foreign->email = 'other@example.org';

        $projectedMember = $this->projectedMember($member->lidnr);
        self::assertNotNull($projectedMember);

        $this->service->generateLists(
            $member,
            $projectedMember,
        );
        $this->projection->flush();

        self::assertNotNull(
            $this->projectedMembership(
                $list->name,
                $member->email,
            ),
        );
        self::assertNull(
            $this->projectedMembership(
                $list->name,
                'other@example.org',
            ),
        );
    }

    /**
     * A subscription the ledger marked for removal is taken out of the projection, not written to it.
     */
    public function testASubscriptionToBeDeletedIsRemovedFromTheProjection(): void
    {
        $list = $this->mailingList('member-service-remove');
        $member = $this->build->member();
        $membership = new DatabaseMailingListMember();
        $membership->mailingList = $list;
        $member->addList($membership);
        $this->ledger->persist($membership);
        $this->ledger->flush();

        self::assertNotNull(
            $this->projectedMembership(
                $list->name,
                $member->email,
            ),
        );

        $membership->toBeDeleted = true;
        $this->ledger->flush();

        self::assertNull(
            $this->projectedMembership(
                $list->name,
                $member->email,
            ),
        );
    }

    /**
     * The source's known limitation: the removal branch compares a mailing list name to a mailing list entity, so a
     * subscription that disappeared from the ledger is left in the projection.
     */
    public function testGenerateListsLeavesASubscriptionTheLedgerNoLongerHas(): void
    {
        $list = $this->mailingList('member-service-stale');
        $member = $this->build->member();
        $membership = new DatabaseMailingListMember();
        $membership->mailingList = $list;
        $member->addList($membership);
        $this->ledger->persist($membership);
        $this->ledger->flush();

        $member->getMailingListMemberships()->removeElement($membership);

        $projectedMember = $this->projectedMember($member->lidnr);
        self::assertNotNull($projectedMember);

        $this->service->generateLists(
            $member,
            $projectedMember,
        );
        $this->projection->flush();

        self::assertNotNull(
            $this->projectedMembership(
                $list->name,
                $member->email,
            ),
        );
    }

    public function testGenerateListsThrowsWhenTheListIsMissingFromTheProjection(): void
    {
        $list = $this->mailingList('member-service-orphan');
        $member = $this->build->member();
        $membership = new DatabaseMailingListMember();
        $membership->mailingList = $list;
        $member->addList($membership);

        $projectedList = $this->projection->getRepository(DecisionMailingList::class)->find($list->name);
        self::assertNotNull($projectedList);
        $this->projection->remove($projectedList);
        $this->projection->flush();

        $projectedMember = $this->projectedMember($member->lidnr);
        self::assertNotNull($projectedMember);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('mailing list missing from the projection');

        $this->service->generateLists(
            $member,
            $projectedMember,
        );
    }

    private function address(
        DatabaseMember $member,
        AddressTypes $type,
        string $street,
    ): DatabaseAddress {
        $address = new DatabaseAddress();
        $address->type = $type;
        $address->country = PostalRegions::Netherlands;
        $address->street = $street;
        $address->number = '1';
        $address->postalCode = '5612 AB';
        $address->city = 'Eindhoven';
        $address->phone = '+31 40 000 0000';
        $member->addAddress($address);

        $this->ledger->persist($address);
        $this->ledger->flush();

        return $address;
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

    private function projectedMember(int $lidnr): ?DecisionMember
    {
        return $this->projection->getRepository(DecisionMember::class)->find($lidnr);
    }

    private function projectedAddress(
        int $lidnr,
        AddressTypes $type,
    ): ?DecisionAddress {
        return $this->projection->getRepository(DecisionAddress::class)->find([
            'member' => $lidnr,
            'type' => $type,
        ]);
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
