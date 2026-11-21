<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Decision;

use App\Controller\Decision\MemberController;
use App\Entity\Decision\Member;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function bin2hex;
use function file_put_contents;
use function is_dir;
use function json_decode;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sprintf;
use function str_ends_with;
use function unlink;

use const JSON_THROW_ON_ERROR;

/**
 * The members area: the dashboard, the directory and its autocompletes, the mirrored public archive, and one member's
 * page. The register's visibility rules are what most of these pin: an expired member is a page the board may not
 * read, and a deleted one is no page at all.
 */
final class MemberControllerTest extends DatabaseTestCase
{
    public function testTheMembersAreaShowsTheLatestMeetingsAndNews(): void
    {
        $this->authenticateMember(8030);
        $this->pushRequest();

        $response = $this->controller()->index();

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $content = (string) $response->getContent();
        self::assertStringContainsString(
            'Members area',
            $content,
        );
        self::assertStringContainsString(
            'Public archive',
            $content,
        );
    }

    public function testTheDirectorySearchPageRenders(): void
    {
        $this->authenticateMember(8030);
        $this->pushRequest();

        $response = $this->controller()->find();

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Find a member',
            (string) $response->getContent(),
        );
    }

    public function testAQueryShorterThanTwoCharactersFindsNobody(): void
    {
        $this->authenticateMember(8030);

        $response = $this->controller()->findResults('a');

        self::assertSame(
            [],
            $this->payload($response),
        );
    }

    public function testTheDirectoryAutocompleteLinksEveryMatchToItsPage(): void
    {
        $this->authenticateMember(8030);
        $member = $this->member(8030);

        $rows = $this->payload($this->controller()->findResults($member->firstName));

        $row = $this->rowFor(
            $rows,
            8030,
        );
        self::assertSame(
            $member->getFullName(),
            $row['fullName'],
        );
        self::assertTrue(
            str_ends_with(
                $row['url'],
                '/members/8030',
            ),
            'The directory links a match to its member page.',
        );
    }

    public function testThePhotoTagSearchReturnsNamesWithoutLinks(): void
    {
        $this->authenticateMember(8030);
        $member = $this->member(8030);

        $rows = $this->payload($this->controller()->search($member->firstName));

        $row = $this->rowFor(
            $rows,
            8030,
        );
        self::assertSame(
            $member->getFullName(),
            $row['fullName'],
        );
        self::assertArrayNotHasKey(
            'url',
            $row,
        );
    }

    public function testAShortTagSearchFindsNobody(): void
    {
        $this->authenticateMember(8030);

        self::assertSame(
            [],
            $this->payload($this->controller()->search('')),
        );
    }

    public function testTheArchiveRootListsItsEntries(): void
    {
        $this->authenticateMember(8030);
        $this->pushRequest();

        $directory = $this->aMirroredDirectory();

        try {
            $response = $this->controller()->archive($directory);

            self::assertSame(
                Response::HTTP_OK,
                $response->getStatusCode(),
            );
            self::assertStringContainsString(
                'Public archive',
                (string) $response->getContent(),
            );
            self::assertStringContainsString(
                'policy.pdf',
                (string) $response->getContent(),
            );
        } finally {
            $this->removeMirroredDirectory($directory);
        }
    }

    public function testAFileInTheArchiveIsServedAsADownload(): void
    {
        $this->authenticateMember(8030);

        $directory = $this->aMirroredDirectory();

        try {
            $response = $this->controller()->archive($directory . '/policy.pdf');

            self::assertSame(
                Response::HTTP_OK,
                $response->getStatusCode(),
            );
            self::assertStringContainsString(
                'policy.pdf',
                (string) $response->headers->get('Content-Disposition'),
            );
        } finally {
            $this->removeMirroredDirectory($directory);
        }
    }

    public function testAPathThatIsNotInTheArchiveIsNotFound(): void
    {
        $this->authenticateMember(8030);

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->archive('this-was-never-mirrored');
    }

    public function testMyProfileUsesTheSignedInMembersPage(): void
    {
        $this->authenticateMember(8030);
        $request = $this->pushRequest();

        $response = $this->controller()->member($request);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            $this->member(8030)->getFullName(),
            (string) $response->getContent(),
        );
    }

    public function testAMemberPageIsShownToAnotherMember(): void
    {
        $this->authenticateMember(8030);

        $member = $this->member(8005);
        $response = $this->controller()->member(
            $this->memberRequest(8005),
            $member,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            $member->getFullName(),
            (string) $response->getContent(),
        );
    }

    public function testADeletedMemberHasNoPage(): void
    {
        $this->authenticateMember(8000);

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->member(
            $this->memberRequest(8205),
            $this->member(8205),
        );
    }

    /**
     * An expired member's record is still the association's, but the board may not read it back from the website.
     */
    public function testTheBoardMayNotOpenAnExpiredMember(): void
    {
        $this->authenticate(
            8000,
            [UserRoles::Admin->value],
        );

        $member = $this->member(8157);
        self::assertTrue(
            $member->isExpired(),
            'The seed is expected to contain a lapsed graduate.',
        );

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->member(
            $this->memberRequest(8157),
            $member,
        );
    }

    private function controller(): MemberController
    {
        return self::getContainer()->get(MemberController::class);
    }

    /**
     * @return array<array-key, array<string, mixed>>
     */
    private function payload(JsonResponse $response): array
    {
        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        return json_decode(
            (string) $response->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param array<array-key, array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private function rowFor(
        array $rows,
        int $lidnr,
    ): array {
        foreach ($rows as $row) {
            if ($lidnr === $row['lidnr']) {
                return $row;
            }
        }

        self::fail(sprintf('The result is expected to contain member %d.', $lidnr));
    }

    private function member(int $lidnr): Member
    {
        $member = $this->entityManager->getRepository(Member::class)->find($lidnr);
        self::assertInstanceOf(
            Member::class,
            $member,
            sprintf(
                'The seed is expected to contain member %d.',
                $lidnr,
            ),
        );

        return $member;
    }

    private function memberRequest(int $lidnr): Request
    {
        $request = $this->pushRequest();
        $request->attributes->set(
            'member',
            (string) $lidnr,
        );

        return $request;
    }

    private function pushRequest(): Request
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request = new Request();
        $request->setLocale('en');
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);

        return $request;
    }

    private function authenticateMember(int $lidnr): void
    {
        $this->authenticate(
            $lidnr,
            [
                UserRoles::Member->value,
                UserRoles::User->value,
            ],
        );
    }

    /**
     * @param list<string> $roles
     */
    private function authenticate(
        int $lidnr,
        array $roles,
    ): void {
        $user = $this->entityManager->getRepository(User::class)->find($lidnr);
        self::assertInstanceOf(
            User::class,
            $user,
            sprintf(
                'The seed is expected to contain a user for member %d.',
                $lidnr,
            ),
        );

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(
                $user,
                'main',
                $roles,
            ),
        );
    }

    /**
     * Written straight to the archive root: unlike the application's own storage, the mirror is a real directory that
     * the browser reads from disk.
     */
    private function aMirroredDirectory(): string
    {
        $name = 'it-' . bin2hex(random_bytes(6));
        $root = self::getContainer()->getParameter('kernel.project_dir') . '/data/public-archive';
        $directory = $root . '/' . $name;

        mkdir(
            $directory,
            0o755,
            true,
        );
        file_put_contents(
            $directory . '/policy.pdf',
            '%PDF-1.4',
        );

        return $name;
    }

    private function removeMirroredDirectory(string $name): void
    {
        $root = self::getContainer()->getParameter('kernel.project_dir') . '/data/public-archive';
        $directory = $root . '/' . $name;

        if (!is_dir($directory)) {
            return;
        }

        unlink($directory . '/policy.pdf');
        rmdir($directory);
    }
}
