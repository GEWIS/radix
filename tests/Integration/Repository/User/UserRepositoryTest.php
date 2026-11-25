<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\User;

use App\Entity\Decision\Member;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use App\Tests\Integration\DatabaseTestCase;
use Override;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

use function array_column;
use function strtoupper;

final class UserRepositoryTest extends DatabaseTestCase
{
    private UserRepository $repository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = self::getContainer()->get(UserRepository::class);
    }

    public function testLoadUserByIdentifierAcceptsNumberEmailAndMNumber(): void
    {
        $member = $this->member(8030);
        self::assertNotNull($member->email);

        self::assertSame(
            8030,
            $this->repository->loadUserByIdentifier('8030')?->lidnr,
        );
        self::assertSame(
            8030,
            $this->repository->loadUserByIdentifier('m8030')?->lidnr,
        );
        self::assertSame(
            8030,
            $this->repository->loadUserByIdentifier(strtoupper($member->email))?->lidnr,
        );
        self::assertNull($this->repository->loadUserByIdentifier('nobody@example.invalid'));
        self::assertNull($this->repository->loadUserByIdentifier('999999'));
    }

    public function testUpgradePasswordRehashesAndStoresTheNewPassword(): void
    {
        $user = $this->user(8030);
        $hash = '$argon2id$v=19$m=10,t=3,p=1$replacement$replacement';

        $this->repository->upgradePassword(
            $user,
            $hash,
        );

        $this->entityManager->clear();
        $reloaded = $this->user(8030);
        self::assertSame(
            $hash,
            $reloaded->getPassword(),
        );
    }

    public function testUpgradePasswordRefusesAnotherUserImplementation(): void
    {
        $this->expectException(UnsupportedUserException::class);

        $this->repository->upgradePassword(
            self::createStub(PasswordAuthenticatedUserInterface::class),
            'hash',
        );
    }

    public function testFindByLidnrsWithRolesIsABulkLookup(): void
    {
        $users = $this->repository->findByLidnrsWithRoles([
            8000,
            8001,
        ]);

        self::assertEqualsCanonicalizing(
            [
                8000,
                8001,
            ],
            array_column(
                $users,
                'lidnr',
            ),
        );

        self::assertSame(
            [],
            $this->repository->findByLidnrsWithRoles([]),
        );
    }

    public function testFindForResetRequiresACurrentVisibleMember(): void
    {
        $member = $this->member(8030);
        self::assertNotNull($member->email);

        self::assertSame(
            8030,
            $this->repository->findForReset(
                $member->email,
                8030,
            )?->lidnr,
        );
        self::assertNull($this->repository->findForReset(
            'wrong@example.org',
            8030,
        ));
        self::assertNull($this->repository->findForReset(
            $member->email,
            8031,
        ));

        $hidden = $this->member(8200);
        self::assertNotNull($hidden->email);
        self::assertNull($this->repository->findForReset(
            $hidden->email,
            8200,
        ));
    }

    private function user(int $lidnr): User
    {
        $user = $this->entityManager->find(
            User::class,
            $lidnr,
        );
        self::assertInstanceOf(
            User::class,
            $user,
        );

        return $user;
    }

    private function member(int $lidnr): Member
    {
        $member = $this->entityManager->find(
            Member::class,
            $lidnr,
        );
        self::assertInstanceOf(
            Member::class,
            $member,
        );

        return $member;
    }
}
