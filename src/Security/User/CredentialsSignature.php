<?php

declare(strict_types=1);

namespace App\Security\User;

use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\Signature\SignatureHasher;
use Symfony\Component\Security\Core\User\UserInterface;

use function hash_equals;

final class CredentialsSignature
{
    public const array PROPERTIES = [
        'password',
        'passwordChangedOn',
        'forceReloginAt',
        'totpSecret',
    ];

    private readonly SignatureHasher $hasher;

    public function __construct(
        #[Autowire(param: 'kernel.secret')]
        #[SensitiveParameter]
        string $secret,
    ) {
        $this->hasher = new SignatureHasher(
            PropertyAccess::createPropertyAccessor(),
            self::PROPERTIES,
            $secret,
        );
    }

    /** Expiry is tracked on the session row, so zero here keeps the hash stable from one request to the next. */
    public function hash(UserInterface $user): string
    {
        return $this->hasher->computeSignatureHash(
            $user,
            0,
        );
    }

    public function matches(
        string $stored,
        UserInterface $user,
    ): bool {
        return hash_equals(
            $stored,
            $this->hash($user),
        );
    }
}
