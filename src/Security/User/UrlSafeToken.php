<?php

declare(strict_types=1);

namespace App\Security\User;

use function base64_encode;
use function random_bytes;
use function rtrim;
use function strtr;

/**
 * Shared by the remember-me handler and the known-device registry so the two token alphabets cannot drift apart.
 */
final class UrlSafeToken
{
    private function __construct()
    {
    }

    /**
     * @param positive-int $bytes
     */
    public static function generate(int $bytes = 32): string
    {
        return rtrim(
            strtr(
                base64_encode(random_bytes($bytes)),
                '+/',
                '-_',
            ),
            '=',
        );
    }
}
