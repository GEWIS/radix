<?php

declare(strict_types=1);

namespace App\Twig\Extensions;

use App\Service\Application\RealtimeAuthorization;
use Override;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The page's way to {@see RealtimeAuthorization}.
 */
class RealtimeExtension extends AbstractExtension
{
    public function __construct(private readonly RealtimeAuthorization $realtime)
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
                'realtime_topics',
                $this->realtime->topics(...),
            ),
            new TwigFunction(
                'realtime_grants',
                $this->realtime->grants(...),
            ),
            new TwigFunction(
                'realtime_authorize',
                $this->realtime->authorize(...),
            ),
            new TwigFunction(
                'realtime_grant_route',
                $this->realtime->grantRoute(...),
            ),
        ];
    }
}
