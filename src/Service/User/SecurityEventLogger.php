<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\User\Enums\SecurityEventType;
use App\Entity\User\SecurityLog;
use App\Monolog\Processor\RequestIdProcessor;
use App\Repository\User\SecurityLogRepository;
use App\Security\User\UserAgentParser;
use Monolog\Attribute\WithMonologChannel;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Throwable;

use function is_scalar;
use function is_string;
use function mb_substr;

/**
 * The one way to record that something happened to an account.
 *
 * Every caller states what happened and who it happened to. Everything else, meaning where the request came from,
 * what it was running under and who was signed in at the time, is gathered here, so that two places reporting the
 * same event cannot describe it differently.
 *
 * It writes twice. The `security` log channel takes every event and is never buffered or sampled, which makes it the
 * copy that survives a request that later failed. {@see SecurityLog} takes the events worth querying later, and is
 * what the administration reads and a data export includes.
 *
 * Recording is best effort in the same sense {@see SecurityNotifier} is: the thing being recorded has already
 * happened, and a database that will not take the row must not turn a completed sign-in into an error page. What it
 * must never do is fail silently, so the failure is itself logged.
 */
#[WithMonologChannel('security')]
final readonly class SecurityEventLogger
{
    /** Long enough for an address, a series or a route; short enough that nothing can put a document in a column. */
    private const int MAX_DETAIL_LENGTH = 255;

    public function __construct(
        private SecurityLogRepository $repository,
        private RequestStack $requestStack,
        private UserAgentParser $userAgentParser,
        private TokenStorageInterface $tokenStorage,
        private RequestIdProcessor $requestId,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, scalar|null> $detail  what this event needs beyond the account and the firewall: the
     *                                            series that ended, the route that refused, the reason it did.
     *                                            Never a token, a password or a session identifier.
     * @param Request|null               $request the request being answered, for the few callers with a request the
     *                                            stack does not have (a sub-request, a listener acting on an event's
     *                                            own request)
     */
    public function record(
        SecurityEventType $event,
        ?string $userIdentifier = null,
        ?string $firewallName = null,
        array $detail = [],
        ?Request $request = null,
    ): void {
        $request ??= $this->requestStack->getMainRequest();
        $detail = $this->sanitise($detail);
        $actor = $this->actor($userIdentifier);

        $browser = null;
        $operatingSystem = null;
        if (null !== $request) {
            $meta = $this->userAgentParser->parseRequest($request);
            $browser = $meta['browser'];
            $operatingSystem = $meta['operatingSystem'];
        }

        $address = $request?->getClientIp();
        $requestId = $this->requestId->current();

        // The message is the event's own value rather than a sentence: it is what an alert on the file matches on,
        // and a sentence is something somebody will reword next year.
        $this->logger->log(
            $event->level(),
            $event->value,
            [
                'event' => $event->value,
                'category' => $event->category()->value,
                'user' => $userIdentifier,
                'actor' => $actor,
                'firewall' => $firewallName,
                'ip' => $address,
                'browser' => $browser,
                'operating_system' => $operatingSystem,
                'request_id' => $requestId,
                'detail' => $detail,
            ],
        );

        if (!$event->isRecorded()) {
            return;
        }

        try {
            $log = new SecurityLog();
            $log->setOccurredAt($this->clock->now());
            $log->setEvent($event);
            $log->setUserIdentifier($userIdentifier);
            $log->setFirewallName($firewallName);
            $log->setActorIdentifier($actor);
            $log->setIpAddress($address);
            $log->setBrowser($browser);
            $log->setOperatingSystem($operatingSystem);
            $log->setRequestId($requestId);
            $log->setDetail($detail);

            $this->repository->append($log);
        } catch (Throwable $e) {
            // Only ever the file from here, never `record()` again: a table that will not take rows would otherwise
            // meet every failure with another attempt to write one.
            $this->logger->error(
                'A security event could not be recorded.',
                [
                    'event' => $event->value,
                    'user' => $userIdentifier,
                    'request_id' => $requestId,
                    'exception' => $e,
                ],
            );
        }
    }

    /**
     * Who did it, when that is somebody other than the account it was done to.
     *
     * An impersonating administrator is named even though the token reports them as the member they are acting as;
     * that is the whole reason the column exists. Somebody acting on their own account is not an actor, because the
     * row already names them.
     */
    private function actor(?string $userIdentifier): ?string
    {
        try {
            $token = $this->tokenStorage->getToken();
        } catch (Throwable) {
            // Reading the token during authentication itself can fail on a half-built container; an event with no
            // actor is worth more than an event that was never written.
            return null;
        }

        while ($token instanceof SwitchUserToken) {
            $token = $token->getOriginalToken();
        }

        $signedIn = $token?->getUserIdentifier();

        if (
            null === $signedIn
            || '' === $signedIn
            || $signedIn === $userIdentifier
        ) {
            return null;
        }

        return $signedIn;
    }

    /**
     * Keeps `detail` to what it promises to be: flat, scalar and bounded. A caller passing something larger is a
     * caller about to put a request body in the database.
     *
     * @param array<string, scalar|null> $detail
     *
     * @return array<string, scalar|null>
     */
    private function sanitise(array $detail): array
    {
        $clean = [];

        foreach ($detail as $key => $value) {
            if (
                null === $value
                || !is_scalar($value)
            ) {
                $clean[$key] = null;

                continue;
            }

            $clean[$key] = is_string($value)
                ? mb_substr(
                    $value,
                    0,
                    self::MAX_DETAIL_LENGTH,
                )
                : $value;
        }

        return $clean;
    }
}
