<?php

declare(strict_types=1);

namespace App\Twig\Extensions;

use App\Service\Database\Member as MemberService;
use Override;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ApplicationExtension extends AbstractExtension
{
    public function __construct(private readonly MemberService $memberService)
    {
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
        return $this->memberService->getPaidProspectivesCount();
    }

    /**
     * Member-submitted changes waiting to be approved or rejected. Counted on its own, as the badge above is.
     */
    public function memberUpdatesPending(): int
    {
        return $this->memberService->getPendingUpdateCount();
    }
}
