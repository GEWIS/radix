<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Decision;

use App\Controller\Decision\AdminMeetingController;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Decision\Meeting;
use App\Entity\Decision\MeetingDocument;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Repository\Decision\AuthorizationRepository;
use App\Repository\Decision\MeetingPointRepository;
use App\Repository\Decision\MeetingRepository;
use App\Service\Decision\MeetingDocumentService;
use App\Service\Decision\MeetingMinutesService;
use App\Service\Decision\VersionLabelSuggester;
use App\Tests\Integration\DatabaseTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Contracts\Translation\TranslatorInterface;

use function file_put_contents;
use function is_file;
use function json_decode;
use function register_shutdown_function;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * The three upload endpoints respond with the reason when the storage refuses the file, rather than letting the
 * exception reach the dropzone. The same file that is refused here is what
 * {@see AdminMeetingControllerTest::testADocumentIsUploaded()} shows being accepted in each form.
 */
#[AllowMockObjectsWithoutExpectations]
final class AdminMeetingUploadFailuresTest extends DatabaseTestCase
{
    public function testADocumentThatCannotBeStoredIsReported(): void
    {
        $this->authenticateBoard();
        $meeting = $this->meetingOfType(MeetingTypes::BV);

        $request = Request::create(
            '/',
            'POST',
            ['name' => 'Not a document'],
        );
        $request->files->set(
            'file',
            $this->aTextFile(),
        );

        $response = $this->controller()->uploadDocument(
            $request,
            MeetingTypes::BV,
            $meeting->number,
        );

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'MIME type',
            $this->json($response)['error'],
        );
    }

    public function testAVersionThatCannotBeStoredIsReported(): void
    {
        $this->authenticateBoard();

        $request = Request::create(
            '/',
            'POST',
            ['versionLabel' => 'v9.9'],
        );
        $request->files->set(
            'file',
            $this->aTextFile(),
        );

        $response = $this->controller()->uploadDocumentVersion(
            $request,
            $this->aDocument(),
        );

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'MIME type',
            $this->json($response)['error'],
        );
    }

    public function testMinutesThatCannotBeStoredAreReported(): void
    {
        $this->authenticateBoard();
        $meeting = $this->meetingOfType(MeetingTypes::BV);

        $request = Request::create(
            '/',
            'POST',
        );
        $request->files->set(
            'file',
            $this->aTextFile(),
        );

        $response = $this->controller()->uploadMinutes(
            $request,
            MeetingTypes::BV,
            $meeting->number,
        );

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'MIME type',
            $this->json($response)['error'],
        );
    }

    /**
     * The authorization overview needs a GMM to exist at all. With none there is no table to show and no meeting to
     * default to, so the page is a 404 rather than an empty one.
     */
    public function testTheAuthorizationOverviewWithoutAnyGMMIsNotFound(): void
    {
        $repository = $this->createMock(MeetingRepository::class);
        $repository->method('findByType')->willReturn([]);

        $controller = new AdminMeetingController(
            $repository,
            self::getContainer()->get(AuthorizationRepository::class),
            self::getContainer()->get(MeetingPointRepository::class),
            self::getContainer()->get(MeetingDocumentService::class),
            self::getContainer()->get(MeetingMinutesService::class),
            self::getContainer()->get(VersionLabelSuggester::class),
            self::getContainer()->get(TranslatorInterface::class),
        );

        $this->expectException(NotFoundHttpException::class);

        $controller->authorizations();
    }

    private function controller(): AdminMeetingController
    {
        return self::getContainer()->get(AdminMeetingController::class);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function json(JsonResponse $response): array
    {
        $decoded = json_decode(
            (string) $response->getContent(),
            true,
        );
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function meetingOfType(MeetingTypes $type): Meeting
    {
        $meeting = self::getContainer()->get(MeetingRepository::class)->findByType($type)[0];
        self::assertInstanceOf(
            Meeting::class,
            $meeting,
        );

        return $meeting;
    }

    private function aDocument(): MeetingDocument
    {
        $document = $this->entityManager->getRepository(MeetingDocument::class)->findOneBy([]);
        self::assertInstanceOf(
            MeetingDocument::class,
            $document,
        );

        return $document;
    }

    private function aTextFile(): UploadedFile
    {
        $path = tempnam(
            sys_get_temp_dir(),
            'it-upload-fail-',
        );
        self::assertNotFalse($path);
        file_put_contents(
            $path,
            'This is not a document.',
        );

        $file = new UploadedFile(
            $path,
            'notes.txt',
            'text/plain',
            null,
            true,
        );

        register_shutdown_function(static function () use ($path): void {
            if (!is_file($path)) {
                return;
            }

            unlink($path);
        });

        return $file;
    }

    private function authenticateBoard(): void
    {
        $user = $this->entityManager->getRepository(User::class)->find(8025);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(
                $user,
                'main',
                [UserRoles::Board->value],
            ),
        );

        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request = new Request();
        $request->setLocale('en');
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);
    }
}
