<?php

declare(strict_types=1);

namespace App\Twig\Components\Application\Admin;

use App\Entity\User\Enums\UserRoles;
use App\Security\User\SudoVoter;
use App\Service\Application\TransportStatusProvider;
use App\Twig\Components\Application\AbstractPaginatedOverview;
use App\ViewModel\Application\FailedMessageList;
use App\ViewModel\Application\FailedMessageRow;
use App\ViewModel\Application\ResultPage;
use Override;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;

use function array_slice;
use function array_values;
use function count;

/**
 * The messages that used up every retry, paged through.
 *
 * The transport takes no offset and answers in no defined order, so the readable set is fetched whole and a page is
 * cut out of it rather than queried. That is the plain overview's case rather than the Doctrine one, and it is why
 * the fetch is held: the base class asks twice when a page number turns out to be past the end, and reading a few
 * hundred envelopes off the broker is not worth doing twice to answer the same question.
 *
 * @extends AbstractPaginatedOverview<FailedMessageRow>
 */
#[AsLiveComponent(
    name: 'Application:Admin:FailedMessageOverview',
    template: 'components/Application/Admin/FailedMessageOverview.html.twig',
)]
#[IsGranted(UserRoles::Admin->value)]
#[IsGranted(SudoVoter::ATTRIBUTE)]
final class FailedMessageOverview extends AbstractPaginatedOverview
{
    private ?FailedMessageList $list = null;

    public function __construct(private readonly TransportStatusProvider $transportStatusProvider)
    {
    }

    /**
     * What the transport says it holds, which is more than what can be paged over here once the cap bites.
     */
    public function getTransportTotal(): ?int
    {
        return $this->list()->transportTotal;
    }

    public function isTruncated(): bool
    {
        return $this->list()->truncated;
    }

    /**
     * @return ResultPage<FailedMessageRow>
     */
    #[Override]
    protected function fetchPage(
        int $page,
        int $pageSize,
    ): ResultPage {
        $rows = $this->list()->rows;

        return new ResultPage(
            array_values(array_slice(
                $rows,
                ($page - 1) * $pageSize,
                $pageSize,
            )),
            count($rows),
        );
    }

    private function list(): FailedMessageList
    {
        return $this->list ??= $this->transportStatusProvider->failed();
    }
}
