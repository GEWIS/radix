<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Database;

use App\Entity\Database\Enums\MembershipTypes;
use App\Service\Database\Member;
use App\Tests\Support\LedgerBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function count;
use function implode;

/**
 * The bulk renewal preview and the renewal it applies. Each row is a member in a state that decides whether
 * renewal is possible, so the preview shows which rows will be skipped.
 */
#[CoversClass(Member::class)]
final class MemberBulkRenewalTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private Member $memberService;

    private LedgerBuilder $build;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->memberService = self::getContainer()->get(Member::class);
        $this->build = new LedgerBuilder($this->entityManager);
    }

    public function testWithoutAMembershipTypeThereIsNothingToPreviewOrApply(): void
    {
        $result = $this->memberService->bulkRenewal(
            '8000',
            null,
            false,
        );

        self::assertNull($result['preview']);
        self::assertNull($result['result']);
    }

    public function testThePreviewSaysWhyAMemberCannotBeRenewed(): void
    {
        $unknown = 999_999;
        $deleted = $this->build->member();
        $deleted->deleted = true;
        $future = $this->build->member(
            MembershipTypes::Ordinary,
            '+1 month',
            '+13 months',
        );
        $honorary = $this->build->member(MembershipTypes::Honorary);
        $renewable = $this->build->member();
        $this->entityManager->flush();

        $result = $this->memberService->bulkRenewal(
            implode(
                ',',
                [
                    $unknown,
                    $deleted->lidnr,
                    $future->lidnr,
                    $honorary->lidnr,
                    $renewable->lidnr,
                ],
            ),
            MembershipTypes::Ordinary,
            false,
        );

        $preview = $result['preview'];
        self::assertNotNull($preview);
        self::assertNull($result['result']);
        self::assertSame(
            1,
            $preview['valid_count'],
        );
        self::assertSame(
            4,
            $preview['invalid_count'],
        );

        $messages = [];
        foreach ($preview['rows'] as $row) {
            $messages[$row['member_id']] = $row['message'];
        }

        self::assertSame(
            'Member not found.',
            $messages[$unknown],
        );
        self::assertSame(
            'Member is deleted.',
            $messages[$deleted->lidnr],
        );
        self::assertSame(
            'Member already has a future membership.',
            $messages[$future->lidnr],
        );
        self::assertSame(
            'Unable to change membership type of honorary member.',
            $messages[$honorary->lidnr],
        );
        self::assertSame(
            'Ready to renew.',
            $messages[$renewable->lidnr],
        );
    }

    public function testConfirmingRenewsExactlyTheRowsThePreviewAccepted(): void
    {
        $renewable = $this->build->member(
            MembershipTypes::Ordinary,
            '-1 year',
            '-1 day',
        );
        $honorary = $this->build->member(MembershipTypes::Honorary);
        $this->entityManager->flush();

        $before = count($renewable->getMemberships());

        $result = $this->memberService->bulkRenewal(
            $renewable->lidnr . ',' . $honorary->lidnr,
            MembershipTypes::Graduate,
            true,
        );

        self::assertNotNull($result['preview']);
        self::assertSame(
            ['executed_count' => 1],
            $result['result'],
        );
        self::assertCount(
            $before + 1,
            $renewable->getMemberships(),
        );
        $latest = $renewable->getLastMembership();
        self::assertNotNull($latest);
        self::assertSame(
            MembershipTypes::Graduate,
            $latest->type,
        );

        $executed = [];
        $skipped = [];
        foreach ($result['preview']['rows'] as $row) {
            if ($row['executed']) {
                $executed[] = $row['member_id'];

                continue;
            }

            $skipped[] = $row['member_id'];
        }

        self::assertSame(
            [$renewable->lidnr],
            $executed,
        );
        self::assertSame(
            [$honorary->lidnr],
            $skipped,
        );
    }
}
