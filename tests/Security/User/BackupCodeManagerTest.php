<?php

declare(strict_types=1);

namespace App\Tests\Security\User;

use App\Entity\User\BackupCodeAwareInterface;
use App\Security\User\BackupCodeManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_filter;
use function array_unique;
use function count;
use function strlen;

/**
 * A backup code is a one-time way past the second factor. What matters is that a spent code is refused like any other
 * wrong one, and that generating a set replaces whatever was there.
 */
final class BackupCodeManagerTest extends TestCase
{
    public function testAnUnusedCodeThatMatchesIsAccepted(): void
    {
        $manager = $this->manager();

        self::assertTrue($manager->isBackupCode(
            $this->user([
                [
                    'code' => 'aaaa',
                    'used' => false,
                ],
                [
                    'code' => 'bbbb',
                    'used' => false,
                ],
            ]),
            'bbbb',
        ));
    }

    /**
     * A spent code is a miss, and the caller cannot tell it from a code that never existed.
     */
    public function testASpentCodeIsRefused(): void
    {
        $manager = $this->manager();

        self::assertFalse($manager->isBackupCode(
            $this->user([
                [
                    'code' => 'aaaa',
                    'used' => true,
                ],
            ]),
            'aaaa',
        ));
    }

    public function testACodeThatWasNeverIssuedIsRefused(): void
    {
        $manager = $this->manager();

        self::assertFalse($manager->isBackupCode(
            $this->user([
                [
                    'code' => 'aaaa',
                    'used' => false,
                ],
            ]),
            'cccc',
        ));
    }

    public function testAnAccountWithoutCodesAcceptsNone(): void
    {
        $manager = $this->manager();

        self::assertFalse($manager->isBackupCode($this->user(null), 'aaaa'));
    }

    public function testUsingACodeSpendsOnlyThatSlot(): void
    {
        $user = $this->user([
            [
                'code' => 'aaaa',
                'used' => false,
            ],
            [
                'code' => 'bbbb',
                'used' => false,
            ],
        ]);

        $this->manager()->invalidateBackupCode(
            $user,
            'bbbb',
        );

        self::assertSame(
            [
                [
                    'code' => 'aaaa',
                    'used' => false,
                ],
                [
                    'code' => 'bbbb',
                    'used' => true,
                ],
            ],
            $user->getBackupCodeSlots(),
        );
    }

    public function testACodeThatDoesNotMatchSpendsNothing(): void
    {
        $user = $this->user([
            [
                'code' => 'aaaa',
                'used' => false,
            ],
        ]);

        $this->manager()->invalidateBackupCode(
            $user,
            'cccc',
        );

        self::assertSame(
            [
                [
                    'code' => 'aaaa',
                    'used' => false,
                ],
            ],
            $user->getBackupCodeSlots(),
        );
    }

    /**
     * A slot that is already spent is skipped, so presenting a spent code cannot spend a later one by accident.
     */
    public function testSpendingSkipsSlotsThatAreAlreadySpent(): void
    {
        $user = $this->user([
            [
                'code' => 'aaaa',
                'used' => true,
            ],
            [
                'code' => 'bbbb',
                'used' => false,
            ],
        ]);

        $this->manager()->invalidateBackupCode(
            $user,
            'aaaa',
        );

        self::assertSame(
            [
                [
                    'code' => 'aaaa',
                    'used' => true,
                ],
                [
                    'code' => 'bbbb',
                    'used' => false,
                ],
            ],
            $user->getBackupCodeSlots(),
        );
    }

    public function testGeneratingReplacesTheSetWithFreshUnusedCodes(): void
    {
        $user = $this->user([
            [
                'code' => 'old',
                'used' => true,
            ],
        ]);

        $plaintext = $this->manager()->generateAndStore($user);
        $stored = $user->getBackupCodeSlots();
        self::assertNotNull($stored);

        self::assertCount(
            BackupCodeManager::DEFAULT_COUNT,
            $plaintext,
        );
        self::assertCount(
            BackupCodeManager::DEFAULT_COUNT,
            $stored,
        );
        self::assertSame(
            $plaintext,
            array_column(
                $stored,
                'code',
            ),
        );
        self::assertSame(
            [],
            array_filter(
                $stored,
                static fn (array $slot): bool => $slot['used'],
            ),
        );

        foreach ($plaintext as $code) {
            self::assertSame(
                16,
                strlen($code),
            );
        }
    }

    public function testTheNumberOfCodesCanBeChosen(): void
    {
        $user = $this->user(null);

        self::assertCount(
            2,
            $this->manager()->generateAndStore(
                $user,
                2,
            ),
        );
        $stored = $user->getBackupCodeSlots();
        self::assertNotNull($stored);
        self::assertCount(
            2,
            $stored,
        );
    }

    public function testGeneratedCodesAreNotRepeated(): void
    {
        $codes = $this->manager()->generateAndStore($this->user(null));

        self::assertCount(
            count($codes),
            array_unique($codes),
        );
    }

    private function manager(): BackupCodeManager
    {
        return new BackupCodeManager(self::createStub(EntityManagerInterface::class));
    }

    /**
     * @param array<array{code: string, used: bool}>|null $slots
     */
    private function user(?array $slots): BackupCodeAwareInterface
    {
        return new class ($slots) implements BackupCodeAwareInterface {
            /**
             * @param array<array{code: string, used: bool}>|null $slots
             */
            public function __construct(private ?array $slots)
            {
            }

            /**
             * @return array<array{code: string, used: bool}>|null
             */
            public function getBackupCodeSlots(): ?array
            {
                return $this->slots;
            }

            public function setBackupCodeSlots(?array $slots): void
            {
                $this->slots = $slots;
            }
        };
    }
}
