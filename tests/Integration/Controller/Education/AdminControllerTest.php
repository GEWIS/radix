<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Education;

use App\Controller\Education\AdminController;
use App\Entity\Application\Enums\AlertTypes;
use App\Entity\Education\Course;
use App\Entity\Education\CourseDocument;
use App\Entity\Education\Exam;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Repository\Education\CourseDocumentRepository;
use App\Repository\Education\CourseRepository;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Courses are plain reference data, so there is no revision workflow here: a course is created, renamed and removed
 * directly, and a document can be dropped or sent through the rasterizer again when it came out unreadable.
 */
final class AdminControllerTest extends DatabaseTestCase
{
    public function testTheOverviewLeadsWithTheQueueAndTheArchive(): void
    {
        $this->authenticate();
        $this->pushRequest(new Request());

        $response = $this->controller()->index();

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Course material',
            (string) $response->getContent(),
        );
    }

    public function testACourseCanBeAdded(): void
    {
        $this->authenticate();
        $session = $this->pushRequest($this->form([
            'code' => '2QA01',
            'name' => 'Quality Assurance',
            'similarCoursesTo' => [],
        ]));

        $response = $this->controller()->addCourse($this->currentRequest());

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertSame(
            '/en/admin/education',
            $response->headers->get('Location'),
        );
        self::assertInstanceOf(
            Course::class,
            $this->courses()->find('2QA01'),
        );
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Success->value));
    }

    /**
     * A code identifies a course and documents are filed under it, so a duplicate would silently split the archive.
     */
    public function testACourseCodeAlreadyInUseIsRefused(): void
    {
        $this->authenticate();
        $this->pushRequest($this->form([
            'code' => '2IL50',
            'name' => 'Another data structures',
            'similarCoursesTo' => [],
        ]));

        $response = $this->controller()->addCourse($this->currentRequest());

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'already exists',
            (string) $response->getContent(),
        );
    }

    public function testTheEditFormIsShown(): void
    {
        $this->authenticate();
        $this->pushRequest(new Request());

        $response = $this->controller()->editCourse(
            $this->currentRequest(),
            '2IL50',
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Data structures',
            (string) $response->getContent(),
        );
    }

    public function testACourseCanBeRenamed(): void
    {
        $this->authenticate();
        $session = $this->pushRequest($this->form([
            'code' => '2IL50',
            'name' => 'Data structures and algorithms',
            'similarCoursesTo' => [],
        ]));

        $response = $this->controller()->editCourse(
            $this->currentRequest(),
            '2IL50',
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertSame(
            '/en/admin/education/courses/2IL50',
            $response->headers->get('Location'),
        );

        $course = $this->courses()->find('2IL50');
        self::assertInstanceOf(
            Course::class,
            $course,
        );
        self::assertSame(
            'Data structures and algorithms',
            $course->name,
        );
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Success->value));
    }

    public function testEditingACourseThatDoesNotExistIsNotFound(): void
    {
        $this->authenticate();
        $this->pushRequest(new Request());

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->editCourse(
            $this->currentRequest(),
            '9ZZZZ',
        );
    }

    public function testACourseAndEverythingFiledUnderItCanBeRemoved(): void
    {
        $this->authenticate();
        $session = $this->pushRequest(new Request());

        $response = $this->controller()->deleteCourse('2ITX0');

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertNull($this->courses()->find('2ITX0'));
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Success->value));
    }

    public function testTheDocumentsOfACourseAreListed(): void
    {
        $this->authenticate();
        $this->pushRequest(new Request());

        $response = $this->controller()->courseDocuments('2IL50');

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Data structures',
            (string) $response->getContent(),
        );
    }

    public function testListingTheDocumentsOfAnUnknownCourseIsNotFound(): void
    {
        $this->authenticate();
        $this->pushRequest(new Request());

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->courseDocuments('9ZZZZ');
    }

    public function testADocumentCanBeRemoved(): void
    {
        $this->authenticate();
        $session = $this->pushRequest(new Request());
        $document = $this->seededExam();
        $id = $document->id;
        self::assertNotNull($id);

        $response = $this->controller()->deleteDocument($document);

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertSame(
            '/en/admin/education/courses/2IL50',
            $response->headers->get('Location'),
        );
        self::assertNull($this->documents()->find($id));
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Success->value));
    }

    public function testADocumentCanBeQueuedForProcessingAgain(): void
    {
        $this->authenticate();
        $session = $this->pushRequest(new Request());
        $document = $this->seededExam();

        $response = $this->controller()->reprocessDocument($document);

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertSame(
            '/en/admin/education/courses/2IL50',
            $response->headers->get('Location'),
        );
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Success->value));
    }

    private function controller(): AdminController
    {
        return self::getContainer()->get(AdminController::class);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function form(array $fields): Request
    {
        $fields['_csrf_token'] = 'csrf-token';

        $request = new Request(
            request: ['course' => $fields],
            server: ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        );
        $request->setMethod(Request::METHOD_POST);

        return $request;
    }

    private function currentRequest(): Request
    {
        $request = self::getContainer()->get('request_stack')->getCurrentRequest();
        self::assertInstanceOf(
            Request::class,
            $request,
        );

        return $request;
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

    private function authenticate(): void
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
    }

    private function seededExam(): Exam
    {
        $course = $this->courses()->find('2IL50');
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

    private function courses(): CourseRepository
    {
        return $this->entityManager->getRepository(Course::class);
    }

    private function documents(): CourseDocumentRepository
    {
        return $this->entityManager->getRepository(CourseDocument::class);
    }
}
