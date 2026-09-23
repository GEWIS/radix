<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Database;

use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\SignsInThroughTheKernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * What a board member reads of the register: the member pages in full, and nothing else of it.
 */
final class MemberAccessTest extends DatabaseTestCase
{
    use SignsInThroughTheKernel;

    /** A member of the board serving now, who is not its secretary. */
    private const int CHAIR = 8025;

    /** The secretary of that same board, who administers the register. */
    private const int SECRETARY = 8026;

    /** The member whose record is read, who is on no board. */
    private const int SUBJECT = 8030;

    public function testTheSeatIsWhatGrantsTheMemberPages(): void
    {
        $roles = $this->user(self::CHAIR)->getRoles();

        self::assertContains(
            UserRoles::DatabaseMemberReadOnly->value,
            $roles,
        );
        self::assertNotContains(
            UserRoles::DatabaseReadOnly->value,
            $roles,
        );
    }

    public function testTheBoardReadsTheOverviewAndOneRecord(): void
    {
        self::assertSame(
            Response::HTTP_OK,
            $this->statusFor(
                self::CHAIR,
                '/en/admin/members',
            ),
        );
        self::assertSame(
            Response::HTTP_OK,
            $this->statusFor(
                self::CHAIR,
                '/en/admin/members/' . self::SUBJECT,
            ),
        );
    }

    public function testTheBoardReadsTheRecordInFull(): void
    {
        self::assertStringContainsString(
            'Phone Number',
            $this->contents(
                self::CHAIR,
                '/en/admin/members/' . self::SUBJECT,
            ),
        );
    }

    public function testTheBoardIsNotOfferedWhatItMayNotChange(): void
    {
        $contents = $this->contents(
            self::CHAIR,
            '/en/admin/members/' . self::SUBJECT,
        );

        self::assertStringNotContainsString(
            'Change Data',
            $contents,
        );
        self::assertStringNotContainsString(
            'Delete Member',
            $contents,
        );
    }

    public function testTheSecretaryIsStillOfferedTheChanges(): void
    {
        $contents = $this->contents(
            self::SECRETARY,
            '/en/admin/members/' . self::SUBJECT,
        );

        self::assertStringContainsString(
            'Change Data',
            $contents,
        );
        self::assertStringContainsString(
            'Delete Member',
            $contents,
        );
    }

    public function testTheRestOfTheRegisterIsRefused(): void
    {
        foreach (
            [
                '/en/admin/members/attention-needed',
                '/en/admin/members/updates',
                '/en/admin/members/bulk-renewal',
                '/en/admin/query',
            ] as $path
        ) {
            self::assertSame(
                Response::HTTP_FORBIDDEN,
                $this->statusFor(
                    self::CHAIR,
                    $path,
                ),
                $path . ' is not part of what a seat on the board grants.',
            );
        }
    }

    /** The member page is also where the note form posts. */
    public function testTheBoardCannotWriteToTheMemberPage(): void
    {
        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $this->statusFor(
                self::CHAIR,
                '/en/admin/members/' . self::SUBJECT,
                'POST',
            ),
        );
    }

    private function user(int $lidnr): User
    {
        $user = $this->entityManager->getRepository(User::class)->find($lidnr);
        self::assertInstanceOf(
            User::class,
            $user,
            'The seed is expected to contain a user for this member.',
        );

        return $user;
    }

    private function statusFor(
        int $lidnr,
        string $path,
        string $method = 'GET',
    ): int {
        return $this->respond(
            $lidnr,
            $path,
            $method,
        )->getStatusCode();
    }

    private function contents(
        int $lidnr,
        string $path,
    ): string {
        $response = $this->respond(
            $lidnr,
            $path,
        );
        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        return (string) $response->getContent();
    }

    private function respond(
        int $lidnr,
        string $path,
        string $method = 'GET',
    ): Response {
        $kernel = self::$kernel;
        self::assertInstanceOf(
            HttpKernelInterface::class,
            $kernel,
        );

        $request = Request::create(
            $path,
            $method,
        );
        foreach ($this->signedInCookies($this->user($lidnr)) as $name => $value) {
            $request->cookies->set(
                $name,
                $value,
            );
        }

        try {
            return $kernel->handle(
                $request,
                HttpKernelInterface::MAIN_REQUEST,
                true,
            );
        } catch (AccessDeniedException) {
            // Thrown rather than rendered as an error page, depending on the path.
            return new Response(
                '',
                Response::HTTP_FORBIDDEN,
            );
        }
    }
}
