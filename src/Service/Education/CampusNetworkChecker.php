<?php

declare(strict_types=1);

namespace App\Service\Education;

use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Only ever used to widen access to course material, and this should stay the exception: an address is a weak thing to
 * grant on. The two other places that read one -- {@see \App\Service\Database\RegistrationService} for the July
 * sign-up window and {@see \App\Security\Database\RegisterNetworkChecker} for the register -- only ever take
 * something away with it, which is why they refuse what they cannot establish and this refuses nothing.
 */
final readonly class CampusNetworkChecker
{
    /**
     * @param string[] $tueRanges subnets in CIDR notation
     */
    public function __construct(
        private RequestStack $requestStack,
        private array $tueRanges,
    ) {
    }

    public function isOnCampus(): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return false;
        }

        return $this->matches($request->getClientIp());
    }

    public function matches(?string $clientIp): bool
    {
        if (
            null === $clientIp
            || [] === $this->tueRanges
        ) {
            return false;
        }

        // The proxy in front stands on TU/e space, inside the ranges below, so believing its own address would put
        // every visitor on campus as soon as a request arrives without a forwarded one.
        if (
            IpUtils::checkIp(
                $clientIp,
                Request::getTrustedProxies(),
            )
        ) {
            return false;
        }

        return IpUtils::checkIp(
            $clientIp,
            $this->tueRanges,
        );
    }
}
