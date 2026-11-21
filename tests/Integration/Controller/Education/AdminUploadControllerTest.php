<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Education;

use App\Controller\Education\AdminUploadController;
use App\Entity\Application\Enums\AlertTypes;
use App\Entity\Application\Enums\Languages;
use App\Entity\Education\CourseDocument;
use App\Entity\Education\CourseDocumentStaging;
use App\Entity\Education\Enums\CourseDocumentTypes;
use App\Entity\Education\Enums\ExamTypes;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Repository\Education\CourseDocumentRepository;
use App\Repository\Education\CourseDocumentStagingRepository;
use App\Service\Application\FileStorage;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use FPDF;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function bin2hex;
use function file_put_contents;
use function json_decode;
use function random_bytes;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;

/**
 * Files go up first and are filed later. The tests cover both halves: what staging guesses from a filename is shown
 * for correction, and nothing reaches the archive until a valid form publishes it, or until it is discarded.
 */
final class AdminUploadControllerTest extends DatabaseTestCase
{
    public function testTheUploadPageListsWhatIsWaitingToBePublished(): void
    {
        $this->authenticate();
        $this->stagingRow();
        $this->pushRequest(new Request());

        $response = $this->controller()->upload();

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        $content = (string) $response->getContent();
        self::assertStringContainsString(
            '2IL50-2025-01-22-exam.pdf',
            $content,
        );
        self::assertStringContainsString(
            'Waiting to be published',
            $content,
        );
    }

    public function testARequestWithoutAFileIsRejected(): void
    {
        $user = $this->authenticate();
        $request = new Request();
        $this->pushRequest($request);

        $response = $this->controller()->stage(
            $request,
            $user,
        );

        self::assertSame(
            Response::HTTP_BAD_REQUEST,
            $response->getStatusCode(),
        );
        self::assertSame(
            ['error' => 'No file was uploaded.'],
            $this->json($response),
        );
    }

    public function testAFileThatIsNotAPdfIsRejected(): void
    {
        $user = $this->authenticate();
        $path = $this->temporaryFile('this is not a pdf');
        $request = new Request(
            files: [
                'file' => new UploadedFile(
                    $path,
                    '2IL50-2025-01-22-exam.pdf',
                    'text/plain',
                    null,
                    true,
                ),
            ],
        );
        $this->pushRequest($request);

        try {
            $response = $this->controller()->stage(
                $request,
                $user,
            );
        } finally {
            unlink($path);
        }

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
        self::assertArrayHasKey(
            'error',
            $this->json($response),
        );
    }

    public function testAPdfIsStagedWithWhatItsNameSays(): void
    {
        $user = $this->authenticate();
        $path = $this->temporaryPdf();
        $request = new Request(
            files: [
                'file' => new UploadedFile(
                    $path,
                    '2IL50-2025-01-22-exam.pdf',
                    'application/pdf',
                    null,
                    true,
                ),
            ],
        );
        $this->pushRequest($request);

        try {
            $response = $this->controller()->stage(
                $request,
                $user,
            );
        } finally {
            unlink($path);
        }

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $body = $this->json($response);
        self::assertIsInt($body['id']);
        self::assertSame(
            '2IL50-2025-01-22-exam.pdf',
            $body['filename'],
        );

        $staged = $this->staging()->find($body['id']);
        self::assertInstanceOf(
            CourseDocumentStaging::class,
            $staged,
        );
        self::assertSame(
            '2IL50',
            $staged->courseCode,
        );
        self::assertSame(
            '2025-01-22',
            $staged->date?->format('Y-m-d'),
        );
        self::assertSame(
            CourseDocumentTypes::Exam,
            $staged->type,
        );
        self::assertSame(
            ExamTypes::Final,
            $staged->examType,
        );
        self::assertSame(
            $user->getId(),
            $staged->uploadedBy?->getId(),
        );
        self::assertTrue(
            self::getContainer()->get(FileStorage::class)->exists($staged->path),
        );
    }

    public function testAStagedUploadIsNotPublishedWithoutAValidForm(): void
    {
        $this->authenticate();
        $staged = $this->stagingRow();
        $id = $staged->id;
        self::assertNotNull($id);

        // The form is the correction; a course that does not exist is exactly what it has to refuse.
        $request = $this->publishRequest([
            'courseCode' => '9ZZZZ',
            'date' => '2027-03-04',
        ]);
        $session = $this->pushRequest($request);

        $response = $this->controller()->publish(
            $request,
            $staged,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertNotNull($this->staging()->find($id));
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Danger->value));
    }

    public function testAValidFormPublishesTheStagedUpload(): void
    {
        $this->authenticate();
        $staged = $this->stagingRow();
        $id = $staged->id;
        self::assertNotNull($id);
        self::getContainer()->get(FileStorage::class)->write(
            $staged->path,
            $this->pdfBytes(),
        );

        $before = $this->documents()->countAll();

        $request = $this->publishRequest([
            'courseCode' => '2IL50',
            'date' => '2027-03-04',
        ]);
        $session = $this->pushRequest($request);

        $response = $this->controller()->publish(
            $request,
            $staged,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertNull($this->staging()->find($id));
        self::assertSame(
            $before + 1,
            $this->documents()->countAll(),
        );
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Success->value));
    }

    /**
     * Publishing is a copy of the staged file, so a file that has gone missing is reported rather than leaving a
     * document that points at nothing.
     */
    public function testPublishingFailsWhenTheStagedFileIsGone(): void
    {
        $this->authenticate();
        $staged = $this->stagingRow();
        $before = $this->documents()->countAll();

        $request = $this->publishRequest([
            'courseCode' => '2IL50',
            'date' => '2027-03-04',
        ]);
        $session = $this->pushRequest($request);

        $response = $this->controller()->publish(
            $request,
            $staged,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertSame(
            $before,
            $this->documents()->countAll(),
        );
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Danger->value));
    }

    public function testAStagedUploadCanBeDiscarded(): void
    {
        $this->authenticate();
        $session = $this->pushRequest(new Request());
        $staged = $this->stagingRow();
        $id = $staged->id;
        self::assertNotNull($id);

        $response = $this->controller()->discard($staged);

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertSame(
            '/en/admin/education/documents/upload',
            $response->headers->get('Location'),
        );
        self::assertNull($this->staging()->find($id));
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Success->value));
    }

    private function controller(): AdminUploadController
    {
        return self::getContainer()->get(AdminUploadController::class);
    }

    /**
     * @param array<string, string> $overrides
     */
    private function publishRequest(array $overrides): Request
    {
        $fields = $overrides + [
            'type' => CourseDocumentTypes::Exam->value,
            'examType' => ExamTypes::Final->value,
            'author' => '',
            'language' => Languages::English->value,
            '_csrf_token' => 'csrf-token',
        ];

        $request = new Request(
            request: ['staged_document' => $fields],
            server: ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        );
        $request->setMethod(Request::METHOD_POST);

        return $request;
    }

    private function stagingRow(): CourseDocumentStaging
    {
        $staged = new CourseDocumentStaging();
        $staged->originalFilename = '2IL50-2025-01-22-exam.pdf';
        $staged->path = 'education/courses/staging/' . bin2hex(random_bytes(8)) . '.pdf';
        $staged->uploadedAt = new DateTimeImmutable();
        $staged->courseCode = '2IL50';
        $staged->date = new DateTimeImmutable('2025-01-22');
        $staged->language = Languages::English;
        $staged->type = CourseDocumentTypes::Exam;
        $staged->examType = ExamTypes::Final;

        $this->entityManager->persist($staged);
        $this->entityManager->flush();

        return $staged;
    }

    private function temporaryPdf(): string
    {
        return $this->temporaryFile($this->pdfBytes());
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(
            sys_get_temp_dir(),
            'education-upload-',
        );
        self::assertIsString($path);
        file_put_contents(
            $path,
            $contents,
        );

        return $path;
    }

    private function pdfBytes(): string
    {
        $pdf = new FPDF();
        $pdf->AddPage();
        $pdf->SetFont(
            'Helvetica',
            '',
            14,
        );
        $pdf->Text(
            20,
            40,
            'Education',
        );

        return $pdf->Output('S');
    }

    private function authenticate(): User
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

        return $user;
    }

    private function pushRequest(Request $request): FlashBagAwareSessionInterface
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request->setLocale('en');
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $body = json_decode(
            (string) $response->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($body);

        return $body;
    }

    private function staging(): CourseDocumentStagingRepository
    {
        return $this->entityManager->getRepository(CourseDocumentStaging::class);
    }

    private function documents(): CourseDocumentRepository
    {
        return $this->entityManager->getRepository(CourseDocument::class);
    }
}
