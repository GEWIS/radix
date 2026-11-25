<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Decision;

use App\Controller\Decision\AdminMeetingController;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Decision\Meeting;
use App\Entity\Decision\MeetingDocument;
use App\Entity\Decision\MeetingDocumentVersion;
use App\Entity\Decision\MeetingMinutes;
use App\Entity\Decision\MeetingPoint;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Repository\Decision\AuthorizationRepository;
use App\Repository\Decision\MeetingRepository;
use App\Security\User\SudoMode;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function file_put_contents;
use function is_file;
use function json_decode;
use function register_shutdown_function;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;

/**
 * The board's meeting management: the overview's filters and paging, the authorization tables, and the XHR uploads the
 * management page posts to. A refused upload is a 422 with the reason in the JSON, because the dropzone shows it in
 * place rather than navigating to an error page.
 */
final class AdminMeetingControllerTest extends DatabaseTestCase
{
    public function testTheOverviewListsMeetings(): void
    {
        $this->authenticateBoard();
        $this->pushRequest();

        $response = $this->controller()->index();

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Every meeting that has been minuted',
            (string) $response->getContent(),
        );
    }

    /**
     * Only the page sizes the selector offers are honoured; anything else falls back to the default rather than
     * deciding how much to load.
     */
    public function testAnUnlistedPageSizeFallsBackToTheDefault(): void
    {
        $this->authenticateBoard();
        $this->pushRequest();

        $response = $this->controller()->index(
            null,
            1,
            7,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'pageSize=10',
            (string) $response->getContent(),
        );
    }

    public function testAPageBelowOneIsClampedToTheFirstPage(): void
    {
        $this->authenticateBoard();
        $this->pushRequest();

        $response = $this->controller()->index(
            null,
            0,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Page 1 of',
            (string) $response->getContent(),
        );
    }

    /**
     * A virtual meeting is manageable too: the register minutes those, and this is the only overview of them.
     */
    public function testTheFilterCanNarrowTheOverviewToVirtualMeetings(): void
    {
        $this->authenticateBoard();
        $this->pushRequest();

        $response = $this->controller()->index(MeetingTypes::VIRT->value);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $content = (string) $response->getContent();
        self::assertStringContainsString(
            'Virtual Meeting',
            $content,
        );
        self::assertStringNotContainsString(
            'Board Meeting',
            $content,
        );
    }

    public function testAnUnknownTypeFilterIsIgnored(): void
    {
        $this->authenticateBoard();
        $this->pushRequest();

        $response = $this->controller()->index('every-meeting-ever');

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
    }

    public function testTheAuthorizationPageDefaultsToTheNewestMeeting(): void
    {
        $this->authenticateBoard();
        $this->pushRequest();

        $newest = self::getContainer()->get(MeetingRepository::class)->findByType(MeetingTypes::ALV)[0];

        $response = $this->controller()->authorizations();

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            sprintf(
                'GMM %d',
                $newest->number,
            ),
            (string) $response->getContent(),
        );
    }

    /**
     * The seeded authorizations hang off the second upcoming GMM, which is not the one the page opens on.
     */
    public function testAuthorizationsCanBeReadForAChosenMeeting(): void
    {
        $this->authenticateBoard();
        $this->pushRequest();

        $meetings = self::getContainer()->get(MeetingRepository::class)->findUpcomingALVs();
        $meeting = $meetings[1];

        $authorizations = self::getContainer()->get(AuthorizationRepository::class)->findAllByType(
            $meeting->number,
            false,
        );

        $response = $this->controller()->authorizations($meeting->number);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $content = (string) $response->getContent();
        self::assertStringContainsString(
            sprintf(
                'GMM %d',
                $meeting->number,
            ),
            $content,
        );
        foreach ($authorizations as $authorization) {
            self::assertStringContainsString(
                $authorization->authorizer->getFullName(),
                $content,
            );
        }
    }

    public function testAMeetingIsShownOnItsManagementPage(): void
    {
        $this->authenticateBoard();
        $this->pushRequestWithSudo();

        $meeting = $this->meetingOfType(MeetingTypes::ALV);

        $response = $this->controller()->view(
            MeetingTypes::ALV,
            $meeting->number,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            sprintf(
                'GMM %d',
                $meeting->number,
            ),
            (string) $response->getContent(),
        );
    }

    public function testAMeetingThatDoesNotExistHasNoManagementPage(): void
    {
        $this->authenticateBoard();

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->view(
            MeetingTypes::ALV,
            99999,
        );
    }

    public function testAnUploadWithoutAFileIsRefused(): void
    {
        $this->authenticateBoard();
        $meeting = $this->meetingOfType(MeetingTypes::BV);

        $response = $this->controller()->uploadDocument(
            new Request(),
            MeetingTypes::BV,
            $meeting->number,
        );

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
        self::assertSame(
            'No file was uploaded.',
            $this->json($response)['error'],
        );
    }

    public function testAnUploadWithATooShortNameIsRefused(): void
    {
        $this->authenticateBoard();
        $meeting = $this->meetingOfType(MeetingTypes::BV);

        $response = $this->uploadDocument(
            $meeting,
            Request::create(
                '/',
                'POST',
                ['name' => 'x'],
            ),
        );

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'between 2 and 128 characters',
            $this->json($response)['error'],
        );
    }

    public function testAnUploadUnderAnotherMeetingsPointIsRefused(): void
    {
        $this->authenticateBoard();

        $point = $this->entityManager->getRepository(MeetingPoint::class)->findOneBy([]);
        self::assertInstanceOf(
            MeetingPoint::class,
            $point,
            'The seed is expected to contain an agenda point.',
        );

        $target = $this->meetingOfType(MeetingTypes::BV);
        self::assertNotSame(
            $point->meeting->number,
            $target->number,
        );

        $request = Request::create(
            '/',
            'POST',
            [
                'name' => 'Agenda',
                'point' => (string) $point->id,
            ],
        );

        $response = $this->uploadDocument(
            $target,
            $request,
        );

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
        self::assertSame(
            'The agenda point does not belong to this meeting.',
            $this->json($response)['error'],
        );
    }

    public function testADocumentIsUploaded(): void
    {
        $this->authenticateBoard();
        $meeting = $this->meetingOfType(MeetingTypes::BV);

        $request = Request::create(
            '/',
            'POST',
            ['name' => 'Fresh agenda'],
        );

        $response = $this->uploadDocument(
            $meeting,
            $request,
        );

        self::assertSame(
            ['ok' => true],
            $this->json($response),
        );
        self::assertNotNull(
            $this->entityManager->getRepository(MeetingDocument::class)->findOneBy([
                'meeting' => $meeting,
                'name' => 'Fresh agenda',
            ]),
        );
    }

    public function testAVersionUploadWithoutAFileIsRefused(): void
    {
        $this->authenticateBoard();

        $response = $this->controller()->uploadDocumentVersion(
            new Request(),
            $this->aDocument(),
        );

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
        self::assertSame(
            'No file was uploaded.',
            $this->json($response)['error'],
        );
    }

    public function testADocumentVersionIsUploaded(): void
    {
        $this->authenticateBoard();
        $document = $this->aDocument();

        $request = Request::create(
            '/',
            'POST',
            ['versionLabel' => 'v9.9'],
        );
        $request->files->set(
            'file',
            $this->aPdf(),
        );

        $response = $this->controller()->uploadDocumentVersion(
            $request,
            $document,
        );

        self::assertSame(
            ['ok' => true],
            $this->json($response),
        );
        $versions = $this->entityManager->getRepository(MeetingDocumentVersion::class);
        self::assertNotNull(
            $versions->findOneBy([
                'document' => $document,
                'versionLabel' => 'v9.9',
            ]),
        );
    }

    public function testAMinutesUploadWithoutAFileIsRefused(): void
    {
        $this->authenticateBoard();
        $meeting = $this->meetingOfType(MeetingTypes::BV);

        $response = $this->controller()->uploadMinutes(
            new Request(),
            MeetingTypes::BV,
            $meeting->number,
        );

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
        self::assertSame(
            'No file was uploaded.',
            $this->json($response)['error'],
        );
    }

    public function testMinutesAreUploaded(): void
    {
        $this->authenticateBoard();
        $meeting = $this->meetingOfType(MeetingTypes::BV);

        $request = Request::create(
            '/',
            'POST',
        );
        $request->files->set(
            'file',
            $this->aPdf(),
        );

        $response = $this->controller()->uploadMinutes(
            $request,
            MeetingTypes::BV,
            $meeting->number,
        );

        self::assertSame(
            ['ok' => true],
            $this->json($response),
        );
        self::assertNotNull(
            $this->entityManager->getRepository(MeetingMinutes::class)->findOneBy(['meeting' => $meeting]),
        );
    }

    private function controller(): AdminMeetingController
    {
        return self::getContainer()->get(AdminMeetingController::class);
    }

    private function uploadDocument(
        Meeting $meeting,
        Request $request,
    ): JsonResponse {
        $request->files->set(
            'file',
            $this->aPdf(),
        );

        return $this->controller()->uploadDocument(
            $request,
            $meeting->type,
            $meeting->number,
        );
    }

    private function meetingOfType(MeetingTypes $type): Meeting
    {
        $meeting = self::getContainer()->get(MeetingRepository::class)->findByType($type)[0];
        self::assertInstanceOf(
            Meeting::class,
            $meeting,
            sprintf(
                'The seed is expected to contain a %s meeting.',
                $type->value,
            ),
        );

        return $meeting;
    }

    private function aDocument(): MeetingDocument
    {
        $document = $this->entityManager->getRepository(MeetingDocument::class)->findOneBy([]);
        self::assertInstanceOf(
            MeetingDocument::class,
            $document,
            'The seed is expected to contain a meeting document.',
        );

        return $document;
    }

    /**
     * A real temporary file, because the storage sniffs its bytes: an empty or misnamed file is refused before the
     * upload reaches the controller's own checks.
     */
    private function aPdf(): UploadedFile
    {
        $path = tempnam(
            sys_get_temp_dir(),
            'it-upload-',
        );
        self::assertNotFalse($path);
        file_put_contents(
            $path,
            "%PDF-1.4\n%% test\n",
        );

        $file = new UploadedFile(
            $path,
            'document.pdf',
            'application/pdf',
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

    /**
     * @return array<string, mixed>
     */
    private function json(JsonResponse $response): array
    {
        return json_decode(
            (string) $response->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /**
     * The management page's live component is behind sudo, which reads the grant from the request's session.
     */
    private function pushRequestWithSudo(): Request
    {
        $request = $this->pushRequest();
        $request->cookies->set(
            $request->getSession()->getName(),
            'test',
        );
        self::getContainer()->get(SudoMode::class)->grant();

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

    private function authenticateBoard(): void
    {
        $user = $this->entityManager->getRepository(User::class)->find(8025);
        self::assertInstanceOf(
            User::class,
            $user,
            'The seed is expected to contain a board member.',
        );

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(
                $user,
                'main',
                [UserRoles::Board->value],
            ),
        );
    }
}
