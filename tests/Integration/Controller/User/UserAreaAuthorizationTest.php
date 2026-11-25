<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\User;

use App\Entity\User\User;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\SignsInThroughTheKernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * What the member area and the site administration let through, decided at the HTTP layer: the firewall decides whether
 * there is an account, access_control and the controllers' guards decide what it may read. The actions are called
 * directly elsewhere; this is the part that only a dispatched request can show.
 */
final class UserAreaAuthorizationTest extends DatabaseTestCase
{
    use SignsInThroughTheKernel;

    private const int ADMIN = 8000;
    private const int BOARD = 8025;
    private const int SECRETARY = 8026;
    private const int MEMBER = 8030;

    public function testTheSecurityPageIsForSignedInMembers(): void
    {
        self::assertSame(
            Response::HTTP_FOUND,
            $this->statusFor(
                null,
                '/en/user/security',
            ),
        );
        self::assertSame(
            Response::HTTP_OK,
            $this->statusFor(
                self::MEMBER,
                '/en/user/security',
            ),
        );
    }

    public function testTheSiteAdministrationIsForTheAdministrator(): void
    {
        self::assertSame(
            Response::HTTP_OK,
            $this->statusFor(
                self::ADMIN,
                '/en/admin/users',
            ),
        );
        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $this->statusFor(
                self::MEMBER,
                '/en/admin/users',
            ),
        );
        // The board is not the website's administrator, and the register's administrator is not either.
        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $this->statusFor(
                self::BOARD,
                '/en/admin/users',
            ),
        );
        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $this->statusFor(
                self::SECRETARY,
                '/en/admin/users',
            ),
        );
    }

    public function testRegisteringExternalApplicationsIsForTheAdministrator(): void
    {
        self::assertSame(
            Response::HTTP_OK,
            $this->statusFor(
                self::ADMIN,
                '/en/admin/users/apps',
            ),
        );
        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $this->statusFor(
                self::BOARD,
                '/en/admin/users/apps',
            ),
        );
        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $this->statusFor(
                self::MEMBER,
                '/en/admin/users/apps',
            ),
        );
    }

    public function testIssuingApiPrincipalsIsForTheAdministrators(): void
    {
        self::assertSame(
            Response::HTTP_OK,
            $this->statusFor(
                self::ADMIN,
                '/en/admin/users/api-principals',
            ),
        );
        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $this->statusFor(
                self::MEMBER,
                '/en/admin/users/api-principals',
            ),
        );
        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $this->statusFor(
                self::BOARD,
                '/en/admin/users/api-principals',
            ),
        );
    }

    /**
     * The navbar switch is a signed POST; without the same-origin signal the stateless token is not valid, which is
     * treated as an access denial and sends the browser to sign in again rather than flipping the preference.
     */
    public function testTheCosmeticsSwitchIsRefusedWithoutASameOriginSignal(): void
    {
        self::assertSame(
            Response::HTTP_FOUND,
            $this->statusFor(
                self::MEMBER,
                '/en/user/cosmetics',
                'POST',
            ),
        );
    }

    private function statusFor(
        ?int $lidnr,
        string $path,
        ?string $method = null,
    ): int {
        $kernel = self::$kernel;
        self::assertInstanceOf(
            HttpKernelInterface::class,
            $kernel,
        );

        $request = Request::create(
            $path,
            $method ?? 'GET',
        );

        if (null !== $lidnr) {
            foreach ($this->signedInCookies($this->user($lidnr)) as $name => $value) {
                $request->cookies->set(
                    $name,
                    $value,
                );
            }
        }

        try {
            return $kernel->handle(
                $request,
                HttpKernelInterface::MAIN_REQUEST,
                true,
            )->getStatusCode();
        } catch (AccessDeniedException) {
            return Response::HTTP_FORBIDDEN;
        }
    }

    private function user(int $lidnr): User
    {
        $user = $this->entityManager->getRepository(User::class)->find($lidnr);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        return $user;
    }
}
