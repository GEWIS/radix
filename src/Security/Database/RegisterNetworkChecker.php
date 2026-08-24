<?php

declare(strict_types=1);

namespace App\Security\Database;

use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Where the register may be reached from.
 *
 * The two register roles follow the office of secretary rather than an account, so the address a request arrives from
 * is the only thing that can narrow them; {@see RegisterNetworkRoleHierarchy} is where they are withheld. Unlike
 * {@see \App\Service\Education\CampusNetworkChecker} this only ever takes access away, so anything it cannot
 * establish is refused rather than allowed.
 */
final readonly class RegisterNetworkChecker
{
    /** @param string[] $registerRanges subnets in CIDR notation; an empty list restricts nothing */
    public function __construct(
        private RequestStack $requestStack,
        private array $registerRanges,
    ) {
    }

    public function isRestricted(): bool
    {
        return [] !== $this->registerRanges;
    }

    public function allowsCurrentRequest(): bool
    {
        if ([] === $this->registerRanges) {
            return true;
        }

        // Sub-requests are not somebody arriving from somewhere; answer on the address of the visitor who caused it.
        $request = $this->requestStack->getMainRequest();

        // No request means a console command, a Messenger worker or the scheduler; nobody is reaching for the register.
        if (null === $request) {
            return true;
        }

        return $this->matches($request->getClientIp());
    }

    public function matches(?string $clientIp): bool
    {
        if ([] === $this->registerRanges) {
            return true;
        }

        if (null === $clientIp) {
            return false;
        }

        // A proxy's own address arrives when the forwarded chain is missing, and the proxies stand on the very
        // networks the register is opened to; believing one would hand the register to everybody behind it.
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
            $this->registerRanges,
        );
    }
}
