<?php

declare(strict_types=1);

namespace App\Security\User;

/**
 * A write that was refused for want of a sudo grant, as it was received.
 *
 * The uploads are described rather than included: the bytes are in storage under
 * {@see \App\Entity\Application\Enums\StorageNamespace::SudoStash} and the descriptor states where, so that a stash
 * of any size costs the same in Valkey.
 */
final readonly class StashedWrite
{
    /**
     * @param array<array-key, mixed> $parameters the request body, with the credential fields removed
     * @param array<array-key, mixed> $files      the shape of the upload fields, each leaf a descriptor
     */
    public function __construct(
        public string $method,
        public string $uri,
        public array $parameters,
        public array $files,
    ) {
    }
}
