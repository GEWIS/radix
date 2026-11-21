<?php

declare(strict_types=1);

namespace App\Tests\Entity\User\Traits;

use App\Entity\User\Traits\BackupCodeAwareTrait;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

#[CoversTrait(BackupCodeAwareTrait::class)]
final class BackupCodeAwareTraitTest extends TestCase
{
    /**
     * No codes is stored as null, not as an empty list: a user without MFA has no slots at all.
     */
    public function testNoCodesIsNullRatherThanAnEmptyList(): void
    {
        $user = $this->user();
        $user->setBackupCodeSlots(null);

        self::assertNull($user->backupCodes);
        self::assertNull($user->getBackupCodeSlots());
    }

    /**
     * The slots are JSON on the column and an array in code; a used slot is kept so verification does not get cheaper
     * as codes are spent.
     */
    public function testTheSlotsRoundTripIncludingTheirUsedFlag(): void
    {
        $user = $this->user();
        $slots = [
            [
                'code' => 'AAAA-BBBB',
                'used' => false,
            ],
            [
                'code' => 'CCCC-DDDD',
                'used' => true,
            ],
        ];

        $user->setBackupCodeSlots($slots);

        self::assertSame(
            '[{"code":"AAAA-BBBB","used":false},{"code":"CCCC-DDDD","used":true}]',
            $user->backupCodes,
        );
        self::assertSame(
            $slots,
            $user->getBackupCodeSlots(),
        );
    }

    /**
     * The column is encrypted text; a value that does not decode must not break every request for the user, so no
     * slots is the safe reading.
     */
    public function testUndecodableTextReadsAsNoSlots(): void
    {
        $user = $this->user();
        $user->backupCodes = 'not json';

        self::assertNull($user->getBackupCodeSlots());

        $user->backupCodes = '"a string"';

        self::assertNull($user->getBackupCodeSlots());
    }

    public function testAnEmptyListIsAnEmptyList(): void
    {
        $user = $this->user();
        $user->setBackupCodeSlots([]);

        self::assertSame(
            '[]',
            $user->backupCodes,
        );
        self::assertSame(
            [],
            $user->getBackupCodeSlots(),
        );
    }

    private function user(): BackupCodeAwareHolder
    {
        return new BackupCodeAwareHolder();
    }
}
