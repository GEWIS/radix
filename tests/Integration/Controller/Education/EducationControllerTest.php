<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Education;

use App\Controller\Education\EducationController;
use App\Entity\Education\Course;
use App\Entity\Education\CourseDocumentDownload;
use App\Entity\Education\Enums\DocumentFlattenStatus;
use App\Entity\Education\Enums\DownloadStatus;
use App\Entity\Education\Exam;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Repository\Education\CourseDocumentDownloadRepository;
use App\Service\Application\FileStorage;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * The archive is browsable by anyone, but a download is requested, built and collected under a token. These pin the
 * two things that follow from that: a course code is matched regardless of how it was typed, and possession of a
 * token is not enough to collect a file that names somebody else.
 */
final class EducationControllerTest extends DatabaseTestCase
{
    public function testTheOverviewShowsTheArchiveAndItsPopularCourses(): void
    {
        $this->pushRequest(new Request());

        $response = $this->controller()->index();

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '2IL50',
            (string) $response->getContent(),
        );
    }

    public function testTheCourseOverviewRenders(): void
    {
        $this->pushRequest(new Request());

        $response = $this->controller()->courses();

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Search the archive by code or name.',
            (string) $response->getContent(),
        );
    }

    public function testACourseIsShownWhateverTheCaseOfItsCode(): void
    {
        $this->pushRequest(new Request());

        $response = $this->controller()->course('2il50');

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Data structures',
            (string) $response->getContent(),
        );
    }

    public function testACourseThatIsNotInTheArchiveIsNotFound(): void
    {
        $this->pushRequest(new Request());

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->course('9ZZZZ');
    }

    public function testRequestingADownloadQueuesItAndSendsTheRequesterToWait(): void
    {
        $user = $this->authenticate();
        $document = $this->seededExam();
        $request = $this->pushRequest(new Request());

        $response = $this->controller()->requestDownload(
            $request,
            $document,
            $user,
        );

        $download = $this->downloadRepository()->findOneBy([
            'document' => $document,
            'requestedBy' => $user,
        ]);
        self::assertInstanceOf(
            CourseDocumentDownload::class,
            $download,
        );
        self::assertSame(
            DownloadStatus::Pending,
            $download->status,
        );
        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/education/download/' . $download->token->toRfc4122(),
            (string) $response->headers->get('Location'),
        );
    }

    /**
     * A download is rebuilt from the rasterized pages, so a document the pipeline has not finished cannot be asked for.
     */
    public function testADocumentThatIsNotRasterizedCannotBeDownloaded(): void
    {
        $user = $this->authenticate();
        $document = $this->seededExam();
        $document->flattenStatus = DocumentFlattenStatus::Pending;

        $this->pushRequest(new Request());

        $this->expectException(AccessDeniedException::class);

        $this->controller()->requestDownload(
            new Request(),
            $document,
            $user,
        );
    }

    public function testTheWaitingPageIsShownToTheRequester(): void
    {
        $user = $this->authenticate();
        $download = $this->aDownload($user);
        $this->pushRequest(new Request());

        $response = $this->controller()->download(
            new Request(),
            $download,
            $user,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Preparing your download',
            (string) $response->getContent(),
        );
    }

    public function testAnotherMemberCannotOpenSomebodyElsesDownload(): void
    {
        $requester = $this->authenticate();
        $other = $this->user(8000);
        $download = $this->aDownload($requester);
        $this->pushRequest(new Request());

        $this->expectException(AccessDeniedException::class);

        $this->controller()->download(
            new Request(),
            $download,
            $other,
        );
    }

    public function testAPendingDownloadReportsNoFileYet(): void
    {
        $user = $this->authenticate();
        $download = $this->aDownload($user);
        $this->pushRequest(new Request());

        $response = $this->controller()->downloadStatus(
            new Request(),
            $download,
            $user,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertSame(
            [
                'status' => DownloadStatus::Pending->value,
                'url' => null,
            ],
            $this->json($response),
        );
    }

    public function testAReadyDownloadPointsAtTheFile(): void
    {
        $user = $this->authenticate();
        $download = $this->aDownload(
            $user,
            DownloadStatus::Ready,
        );
        $this->pushRequest(new Request());

        $response = $this->controller()->downloadStatus(
            new Request(),
            $download,
            $user,
        );

        $body = $this->json($response);
        self::assertSame(
            DownloadStatus::Ready->value,
            $body['status'],
        );
        self::assertIsString($body['url']);
        self::assertStringContainsString(
            '/education/download/' . $download->token->toRfc4122() . '/file',
            $body['url'],
        );
    }

    public function testAFailedDownloadReportsNoFile(): void
    {
        $user = $this->authenticate();
        $download = $this->aDownload(
            $user,
            DownloadStatus::Failed,
        );
        $this->pushRequest(new Request());

        $body = $this->json($this->controller()->downloadStatus(
            new Request(),
            $download,
            $user,
        ));

        self::assertNull($body['url']);
    }

    public function testAReadyDownloadIsServedAndMarkedCollected(): void
    {
        $user = $this->authenticate();
        $download = $this->aDownload(
            $user,
            DownloadStatus::Ready,
        );
        $path = $download->path;
        self::assertNotNull($path);
        self::getContainer()->get(FileStorage::class)->write(
            $path,
            '%PDF-1.4' . "\n" . 'watermarked',
        );
        $this->pushRequest(new Request());

        $response = $this->controller()->downloadFile(
            new Request(),
            $download,
            $user,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertSame(
            'application/pdf',
            $response->headers->get('Content-Type'),
        );

        $disposition = (string) $response->headers->get('Content-Disposition');
        self::assertStringContainsString(
            $download->document->course->code,
            $disposition,
        );
        self::assertStringContainsString(
            $download->document->date->format('Y-m-d') . '.pdf',
            $disposition,
        );
        self::assertNotNull($download->collectedAt);
    }

    /**
     * The token exists, but nothing has been built for it, so there is no file to serve, and an explicit error is
     * better than an empty download.
     */
    public function testADownloadWithoutAFileIsNotFound(): void
    {
        $user = $this->authenticate();
        $download = $this->aDownload($user);
        $this->pushRequest(new Request());

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->downloadFile(
            new Request(),
            $download,
            $user,
        );
    }

    private function controller(): EducationController
    {
        return self::getContainer()->get(EducationController::class);
    }

    private function authenticate(int $lidnr = 8030): User
    {
        $user = $this->user($lidnr);

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(
                $user,
                'main',
                [UserRoles::User->value],
            ),
        );

        return $user;
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

    private function seededExam(): Exam
    {
        $course = $this->entityManager->getRepository(Course::class)->find('2IL50');
        self::assertInstanceOf(
            Course::class,
            $course,
        );

        foreach ($course->getDocuments() as $document) {
            if ($document instanceof Exam) {
                return $document;
            }
        }

        self::fail('The seed is expected to contain an exam for 2IL50.');
    }

    private function aDownload(
        User $user,
        DownloadStatus $status = DownloadStatus::Pending,
    ): CourseDocumentDownload {
        $download = new CourseDocumentDownload();
        $download->token = Uuid::v4();
        $download->document = $this->seededExam();
        $download->requestedBy = $user;
        $download->requestedByName = $user->getDisplayName();
        $download->requestedFrom = '192.0.2.1';
        $download->requestedAt = new DateTimeImmutable();
        $download->status = $status;

        if (DownloadStatus::Ready === $status) {
            $download->path = 'education/downloads/' . $download->token->toRfc4122() . '.pdf';
        }

        $this->entityManager->persist($download);
        $this->entityManager->flush();

        return $download;
    }

    private function pushRequest(Request $request): Request
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request->setLocale('en');
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);

        return $request;
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

    private function downloadRepository(): CourseDocumentDownloadRepository
    {
        return $this->entityManager->getRepository(CourseDocumentDownload::class);
    }
}
