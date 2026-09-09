<?php

declare(strict_types=1);

namespace App\Twig\Extensions;

use App\Service\Database\Meeting as MeetingService;
use App\Service\Database\Member as MemberService;
use Override;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The badges on the administration's sidebar, which every administration page renders.
 *
 * Cached without an expiry, unlike the career badges beside them: none of these counts has a date predicate, so only
 * a write changes them and the listener drops the entry when one happens.
 */
final class ApplicationExtension extends AbstractExtension
{
    public const string PROSPECTIVES_CACHE_KEY = 'layout.admin.prospectives_awaiting_approval';

    public const string MEMBER_UPDATES_CACHE_KEY = 'layout.admin.member_updates_pending';

    public const string UNTRANSLATED_CACHE_KEY = 'layout.admin.decisions_awaiting_translation';

    public function __construct(
        private readonly MemberService $memberService,
        private readonly MeetingService $meetingService,
        #[Autowire(service: 'cache.app')]
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @return TwigFunction[]
     */
    #[Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'prospective_awaiting_approval',
                $this->prospectiveAwaitingApproval(...),
            ),
            new TwigFunction(
                'member_updates_pending',
                $this->memberUpdatesPending(...),
            ),
            new TwigFunction(
                'decisions_awaiting_translation',
                $this->decisionsAwaitingTranslation(...),
            ),
        ];
    }

    /**
     * Prospective members who have paid and are waiting for the secretary to set a membership type.
     *
     * Counted on its own rather than read off {@see \App\Service\Application\RegisterStatusService}: these two badges
     * are on the administration's sidebar, which every administration page carries, and that service answers with the
     * whole state of the register. Reading one number out of it made every page in the administration run the dozen
     * queries the dashboard needs.
     */
    public function prospectiveAwaitingApproval(): int
    {
        return $this->cache->get(
            self::PROSPECTIVES_CACHE_KEY,
            fn (): int => $this->memberService->getPaidProspectivesCount(),
        );
    }

    /**
     * Member-submitted changes waiting to be approved or rejected. Counted on its own, as the badge above is.
     */
    public function memberUpdatesPending(): int
    {
        return $this->cache->get(
            self::MEMBER_UPDATES_CACHE_KEY,
            fn (): int => $this->memberService->getPendingUpdateCount(),
        );
    }

    public function decisionsAwaitingTranslation(): int
    {
        return $this->cache->get(
            self::UNTRANSLATED_CACHE_KEY,
            fn (): int => $this->meetingService->countUntranslatedDecisions(),
        );
    }
}
