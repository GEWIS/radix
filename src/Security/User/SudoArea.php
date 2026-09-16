<?php

declare(strict_types=1);

namespace App\Security\User;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

use function implode;
use function preg_match;
use function sprintf;

/**
 * The addresses that are behind sudo.
 *
 * Defined here rather than in the listener that enforces them, because two listeners need the same list:
 * {@see \App\EventListener\User\SudoEnforcementListener} refuses a request without a grant, and
 * {@see \App\EventListener\User\SudoRefreshListener} extends a grant for a guarded write.
 *
 * Signing in, resetting a password and confirming sudo are served under `/user` and `/company` but outside
 * `/security`, so a user without a grant can still obtain one.
 */
final readonly class SudoArea
{
    private const array AREAS = [
        'admin',
        'user/settings',
        'user/security',
        'company/security',
    ];

    private string $pattern;

    public function __construct(
        #[Autowire('%app.locales%')]
        string $locales,
    ) {
        $this->pattern = sprintf(
            '{^/(?:%s)/(?:%s)(?:/|$)}',
            $locales,
            implode(
                '|',
                self::AREAS,
            ),
        );
    }

    public function covers(Request $request): bool
    {
        return 1 === preg_match(
            $this->pattern,
            $request->getPathInfo(),
        );
    }
}
