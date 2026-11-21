<?php

declare(strict_types=1);

namespace App\Tests\EventListener\Api;

use App\Entity\Database\User\ApiPrincipal;
use App\EventListener\Api\RateLimitListener;
use App\Monolog\Processor\RequestIdProcessor;
use App\Repository\User\SecurityLogRepository;
use App\Security\Api\ApiPrincipalUser;
use App\Security\Api\ApiToken;
use App\Security\User\UserAgentParser;
use App\Service\User\SecurityEventLogger;
use DateTimeImmutable;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function str_contains;

/**
 * A principal that has spent its budget is told to slow down, and the refusal is recorded against the principal.
 */
final class RateLimitListenerTest extends TestCase
{
    private TestHandler $log;

    public function testAPrincipalThatHasSpentItsBudgetIsRefused(): void
    {
        $event = $this->event('/api/members');

        try {
            $this->listener(
                $this->token(),
                $this->limit(
                    accepted: false,
                    retryAfterSeconds: 30,
                ),
            )($event);
            self::fail('The request was allowed past a spent budget.');
        } catch (TooManyRequestsHttpException $exception) {
            self::assertSame(
                429,
                $exception->getStatusCode(),
            );
            $retryAfter = (int) $exception->getHeaders()['Retry-After'];
            self::assertGreaterThanOrEqual(
                28,
                $retryAfter,
            );
            self::assertLessThanOrEqual(
                30,
                $retryAfter,
            );
        }

        self::assertTrue($this->logged('api_rate_limit_exceeded'));
    }

    private function logged(string $message): bool
    {
        foreach ($this->log->getRecords() as $record) {
            if (
                str_contains(
                    (string) $record->message,
                    $message,
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * A retry time in the past still states at least a second, because a client told to retry in zero seconds retries
     * immediately.
     */
    public function testARetryTimeThatHasPassedStillAsksForASecond(): void
    {
        $event = $this->event('/api/members');

        try {
            $this->listener(
                $this->token(),
                $this->limit(
                    accepted: false,
                    retryAfterSeconds: -10,
                ),
            )($event);
            self::fail('The request was allowed past a spent budget.');
        } catch (TooManyRequestsHttpException $exception) {
            self::assertSame(
                1,
                $exception->getHeaders()['Retry-After'],
            );
        }
    }

    public function testAPrincipalWithinItsBudgetIsAllowed(): void
    {
        $this->expectNotToPerformAssertions();

        $this->listener(
            $this->token(),
            $this->limit(accepted: true),
        )($this->event('/api/members'));
    }

    public function testTheWebsiteIsNotRateLimitedHere(): void
    {
        $this->expectNotToPerformAssertions();

        $this->listener(
            $this->token(),
            $this->limit(accepted: false),
        )($this->event('/en/members'));
    }

    public function testASubRequestIsNotRateLimited(): void
    {
        $this->expectNotToPerformAssertions();

        $this->listener(
            $this->token(),
            $this->limit(accepted: false),
        )($this->event(
            '/api/members',
            HttpKernelInterface::SUB_REQUEST,
        ));
    }

    /**
     * An interactive session is limited by the login throttle, not by the API principal's budget.
     */
    public function testASessionThatIsNotAnApiPrincipalIsNotRateLimitedHere(): void
    {
        $this->expectNotToPerformAssertions();

        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(
            new InMemoryUser(
                '8000',
                null,
            ),
            'main',
        ));

        $this->listener(
            $tokenStorage,
            $this->limit(accepted: false),
        )($this->event('/api/members'));
    }

    private function listener(
        TokenStorage $tokenStorage,
        RateLimit $limit,
    ): RateLimitListener {
        $limiter = self::createStub(LimiterInterface::class);
        $limiter->method('consume')->willReturn($limit);

        $factory = self::createStub(RateLimiterFactoryInterface::class);
        $factory->method('create')->willReturn($limiter);

        $requestStack = new RequestStack();
        $this->log = new TestHandler();

        return new RateLimitListener(
            $tokenStorage,
            new SecurityEventLogger(
                self::createStub(SecurityLogRepository::class),
                $requestStack,
                new UserAgentParser(),
                $tokenStorage,
                new RequestIdProcessor(),
                new MockClock('2026-08-31 12:00:00'),
                new Logger(
                    'security',
                    [$this->log],
                ),
            ),
            $factory,
        );
    }

    private function token(): TokenStorage
    {
        $principal = new class extends ApiPrincipal {
            public function withId(int $id): self
            {
                $this->id = $id;

                return $this;
            }
        };

        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new ApiToken(
            new ApiPrincipalUser($principal->withId(5)),
            'api',
        ));

        return $tokenStorage;
    }

    private function limit(
        bool $accepted,
        int $retryAfterSeconds = 10,
    ): RateLimit {
        return new RateLimit(
            0,
            new DateTimeImmutable()->modify($retryAfterSeconds . ' seconds'),
            $accepted,
            60,
        );
    }

    private function event(
        string $path,
        int $type = HttpKernelInterface::MAIN_REQUEST,
    ): RequestEvent {
        return new RequestEvent(
            self::createStub(HttpKernelInterface::class),
            Request::create($path),
            $type,
        );
    }
}
