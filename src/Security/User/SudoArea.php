<?php

declare(strict_types=1);

namespace App\Security\User;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

use function array_any;
use function implode;
use function is_string;
use function preg_match;
use function sprintf;
use function str_ends_with;

/**
 * The addresses that are behind sudo.
 *
 * Defined here rather than in the listener that enforces them, because two listeners need the same list:
 * {@see \App\EventListener\User\SudoEnforcementListener} refuses a request without a grant, and
 * {@see \App\EventListener\User\SudoRefreshListener} extends a grant for a guarded write.
 *
 * Signing in, resetting a password and confirming sudo are served under `/user` and `/company` but outside
 * `/security`, so a user without a grant can still obtain one.
 *
 * The two edit-lock endpoints are inside the area and are treated apart from the rest of it, which is why
 * {@see isKeepalive()} is here as well: {@see \App\EventListener\User\SudoRefreshListener} does not extend a grant
 * for one and {@see SudoStash} does not keep one.
 */
final readonly class SudoArea
{
    private const array KEEPALIVE_SUFFIXES = [
        'edit_ping',
        'edit_release',
    ];

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
        return $this->coversPath($request->getPathInfo());
    }

    /**
     * The same test against a path on its own, for a caller that has one before it has a request to run it on.
     */
    public function coversPath(string $path): bool
    {
        return 1 === preg_match(
            $this->pattern,
            $path,
        );
    }

    /**
     * The heartbeat and the release of an edit lock. Both are POSTs on a guarded path, and neither is sent by user
     * interaction: the first is on an interval and the second on the element being removed. Neither has a body worth
     * keeping, and neither means the user is still there.
     */
    public function isKeepalive(Request $request): bool
    {
        $route = $request->attributes->get('_route');
        if (!is_string($route)) {
            return false;
        }

        return array_any(
            self::KEEPALIVE_SUFFIXES,
            static fn (string $suffix): bool => str_ends_with(
                $route,
                $suffix,
            ),
        );
    }
}
