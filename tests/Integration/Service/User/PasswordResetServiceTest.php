<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\User;

use App\Entity\Decision\Member;
use App\Entity\User\CompanyUser;
use App\Entity\User\PasswordReset;
use App\Entity\User\User;
use App\Repository\User\CompanyUserRepository;
use App\Repository\User\PasswordResetRepository;
use App\Service\User\PasswordResetService;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;

use function bin2hex;
use function random_bytes;

/**
 * The reset link is exchanged for a single-use hash and then spent, so a token never appears in a page that may load
 * third-party resources, and a link requested twice cannot be replayed against an account already recovered.
 */
final class PasswordResetServiceTest extends DatabaseTestCase
{
    public function testClaimingMintsATemporaryHashThatLivesForThreeMinutes(): void
    {
        $reset = $this->reset();

        $hash = $this->service()->claim($reset);

        self::assertSame(
            $hash,
            $reset->tempHash,
        );
        self::assertNotNull($reset->tempHashExpiresAt);
        self::assertGreaterThan(
            new DateTimeImmutable('now +2 minutes'),
            $reset->tempHashExpiresAt,
        );
        self::assertLessThanOrEqual(
            new DateTimeImmutable('now +3 minutes'),
            $reset->tempHashExpiresAt,
        );
    }

    public function testConsumingTheHashSpendsIt(): void
    {
        $reset = $this->reset();
        $this->service()->claim($reset);

        $this->service()->consumeTempHash($reset);

        self::assertNull($reset->tempHash);
        self::assertNull($reset->tempHashExpiresAt);
    }

    /**
     * Finishing a reset retires every outstanding reset for the account in the same commit as the new password, so an
     * older link stops working at the same moment as the one that was used.
     */
    public function testCompletingRetiresEveryOutstandingResetForTheMember(): void
    {
        $member = $this->member();
        $used = $this->reset($member);
        $this->reset($member);

        $this->service()->complete(
            $used,
            $this->user(),
        );

        $repository = self::getContainer()->get(PasswordResetRepository::class);
        self::assertSame(
            [],
            $repository->findBy(['member' => $member]),
        );
        self::assertNotNull($this->user()->getPasswordChangedOn());
    }

    /**
     * A reset names either a member or a company representative, never both, and each target retires its own links.
     */
    public function testCompletingAResetForACompanyAccountRetiresItsResets(): void
    {
        $companyUser = self::getContainer()->get(CompanyUserRepository::class)
            ->loadUserByIdentifier('recruitment@nexunt.example.com');
        self::assertInstanceOf(
            CompanyUser::class,
            $companyUser,
        );

        $used = new PasswordReset(
            new DateTimeImmutable('now +1 day'),
            bin2hex(random_bytes(8)),
            bin2hex(random_bytes(16)),
            companyUser: $companyUser,
        );
        $outstanding = new PasswordReset(
            new DateTimeImmutable('now +1 day'),
            bin2hex(random_bytes(8)),
            bin2hex(random_bytes(16)),
            companyUser: $companyUser,
        );
        $this->entityManager->persist($used);
        $this->entityManager->persist($outstanding);
        $this->entityManager->flush();

        $this->service()->complete(
            $used,
            $companyUser,
        );

        self::assertSame(
            [],
            self::getContainer()->get(PasswordResetRepository::class)->findBy(['companyUser' => $companyUser]),
        );
    }

    private function service(): PasswordResetService
    {
        return self::getContainer()->get(PasswordResetService::class);
    }

    private function reset(?Member $member = null): PasswordReset
    {
        $reset = new PasswordReset(
            new DateTimeImmutable('now +1 day'),
            bin2hex(random_bytes(8)),
            bin2hex(random_bytes(16)),
            $member ?? $this->member(),
        );

        $this->entityManager->persist($reset);
        $this->entityManager->flush();

        return $reset;
    }

    private function member(): Member
    {
        $member = $this->entityManager->getRepository(Member::class)->find(8030);
        self::assertInstanceOf(
            Member::class,
            $member,
            'The seed is expected to contain a member.',
        );

        return $member;
    }

    private function user(): User
    {
        $user = $this->entityManager->getRepository(User::class)->find(8030);
        self::assertInstanceOf(
            User::class,
            $user,
            'The seed is expected to contain a user for the member.',
        );

        return $user;
    }
}
