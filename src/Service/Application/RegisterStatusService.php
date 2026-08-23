<?php

declare(strict_types=1);

namespace App\Service\Application;

use App\Service\Database\ListmonkService;
use App\Service\Database\MailingListService;
use App\Service\Database\MailmanService;
use App\Service\Database\Member as MemberService;
use App\Service\Report\ApiService;
use DateTime;
use Override;
use Psr\Cache\CacheItemInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Service\ResetInterface;

use function array_merge;

/**
 * The state of the register and its integrations right now: how many members and prospective members there are, what
 * is waiting for the secretary, and whether the mailing list and API syncs are keeping up.
 *
 * Nothing here belongs to the website's front page: it is what the register's section of the administration dashboard
 * opens with.
 */
class RegisterStatusService implements ResetInterface
{
    /**
     * How long a set of figures is reused across requests.
     *
     * The notification bell carries these on every page the secretary opens, so without this a dozen queries run to
     * decide whether the bell has anything to say. None of them is a number anyone acts on within the minute: a
     * membership approved now shows up on the page after next.
     */
    private const int TTL = 60;

    /**
     * What the figures were this request, so that asking twice costs once even when they come from the cache.
     * Cleared between requests because the application runs in a worker, where the service outlives the request.
     *
     * @var array<string, mixed>|null
     */
    private ?array $data = null;

    public function __construct(
        private readonly ApiService $apiService,
        private readonly ListmonkService $listmonkService,
        private readonly MailingListService $mailingListService,
        private readonly MailmanService $mailmanService,
        private readonly MemberService $memberService,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @return array{
     *   members: int,
     *   graduates: int,
     *   expired: int,
     *   prospectives: array{
     *     total: int,
     *     paid: int,
     *   },
     *   updates: int,
     *   syncPaused: bool,
     *   syncPausedUntil: ?DateTime,
     *   totalCount: int,
     *   mailmanLastFetch: ?DateTime,
     *   mailmanLastFetchOverdue: bool,
     *   mailmanLastSync: ?DateTime,
     *   listmonkLastFetch: ?DateTime,
     *   listmonkLastFetchOverdue: bool,
     *   listmonkLastSync: ?DateTime,
     *   mailingListChangesPending: array{
     *      creations: int,
     *      deletions: int,
     *   }
     * }
     */
    public function getStatus(): array
    {
        if (null !== $this->data) {
            return $this->data;
        }

        $data = $this->cache->get(
            'register_status',
            function (CacheItemInterface $item): array {
                $item->expiresAfter(self::TTL);

                $figures = array_merge(
                    $this->memberService->getStatusFigures(),
                    $this->apiService->getStatusFigures(),
                    $this->mailmanService->getStatusFigures(),
                    $this->listmonkService->getStatusFigures(),
                    $this->mailingListService->getStatusFigures(),
                );

                // Counted from the figures rather than asked for again: the bell and the dashboard then cannot state
                // different numbers, and it saves running every one of those queries a second time.
                $figures['totalCount'] = $figures['updates']
                    + $figures['prospectives']['paid']
                    + (int) $figures['syncPaused']
                    + (int) $figures['mailmanLastFetchOverdue']
                    + (int) $figures['listmonkLastFetchOverdue'];

                return $figures;
            },
        );

        return $this->data = $data;
    }

    #[Override]
    public function reset(): void
    {
        $this->data = null;
    }

    /**
     * The same figures under the names the dashboard template uses.
     *
     * @return array<string, mixed>
     */
    public function getStatusViewData(): array
    {
        $data = $this->getStatus();

        return [
            'members' => $data['members'],
            'graduates' => $data['graduates'],
            'expired' => $data['expired'],
            'prospectives' => $data['prospectives'],
            'updates' => $data['updates'],
            'sync_paused' => $data['syncPaused'],
            'sync_paused_until' => $data['syncPausedUntil'],
            'mailman_last_fetch' => $data['mailmanLastFetch'],
            'mailman_last_fetch_overdue' => $data['mailmanLastFetchOverdue'],
            'listmonk_last_fetch' => $data['listmonkLastFetch'],
            'listmonk_last_fetch_overdue' => $data['listmonkLastFetchOverdue'],
            'mailing_list_changes_pending' => $data['mailingListChangesPending'],
        ];
    }

    /**
     * How many members hold a current membership of each type, for the dashboard's breakdown.
     *
     * @return array<string, int>
     */
    public function getMembershipBreakdown(): array
    {
        return $this->memberService->getMembershipBreakdown();
    }
}
