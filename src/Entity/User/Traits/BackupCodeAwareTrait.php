<?php

declare(strict_types=1);

namespace App\Entity\User\Traits;

use Ambta\DoctrineEncryptBundle\Configuration\Encrypted;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use JsonException;

use function is_array;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Stores MFA backup codes for user entities.
 *
 * The column is plain encrypted text (same primitive as {@see \App\Entity\User\User::$totpSecret}: ambta with the
 * default `type: 'string'`). ambta's subscriber reads and writes {@see self::$backupCodes} through Symfony's
 * PropertyAccessor on every encrypt and decrypt step, so that property is the raw `?string` and nothing may come
 * between the subscriber and it: no accessor pair, no property hook, no `set` visibility of its own. Anything that
 * decoded on the way out or encoded on the way in would corrupt the ciphertext on the way in and double-encode the
 * JSON on the way back out (see ambta's docs about getters/setters owning their side effects).
 *
 * Application code goes through {@see getBackupCodeSlots()} / {@see setBackupCodeSlots()} instead. Those handle the
 * JSON (de)serialization of the `{code, used}` slot array. We tried `#[Encrypted(type: 'json'|'array')]` and
 * `encrypted_json` first; both conflict with the typed-property model in different ways, hence the manual
 * round-trip is needed.
 */
trait BackupCodeAwareTrait
{
    /**
     * Persisted form: JSON-encoded array of `{code: string, used: bool}` slots, encrypted at rest by ambta.
     *
     * Spent slots are kept (not removed) so verification time stays independent of how many codes a user has used.
     */
    #[Column(
        type: Types::TEXT,
        nullable: true,
    )]
    #[Encrypted]
    public ?string $backupCodes = null;

    /**
     * Decoded backup-code slots, or `null` when MFA is disabled / no codes have been issued.
     *
     * @return array<array{code: string, used: bool}>|null
     */
    public function getBackupCodeSlots(): ?array
    {
        if (null === $this->backupCodes) {
            return null;
        }

        try {
            $decoded = json_decode(
                $this->backupCodes,
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded)
            ? $decoded
            : null;
    }

    /**
     * @param array<array{code: string, used: bool}>|null $slots
     */
    public function setBackupCodeSlots(?array $slots): void
    {
        $this->backupCodes = null === $slots
            ? null
            : json_encode(
                $slots,
                JSON_THROW_ON_ERROR,
            );
    }
}
