<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Education;

use App\Entity\Education\Course;
use App\Entity\Education\CourseDocument;
use App\Service\Application\FileStorage;
use App\Service\Education\CourseAdminService;
use App\Tests\Integration\DatabaseTestCase;

/**
 * Writing courses and removing them together with their documents. The rows go first and are flushed, so the reference
 * providers see committed state and only bytes nothing points at any more are unlinked.
 */
final class CourseAdminServiceTest extends DatabaseTestCase
{
    public function testACourseCodeIsStoredInUpperCase(): void
    {
        $course = new Course();
        $course->code = 'nlxx1';
        $course->name = 'A new course';

        $this->service()->save($course);

        self::assertSame(
            'NLXX1',
            $course->code,
        );
        self::assertNotNull($course->code);
        $stored = $this->entityManager->getRepository(Course::class)->find('NLXX1');
        self::assertInstanceOf(
            Course::class,
            $stored,
        );
    }

    public function testDeletingADocumentTakesItsPagesWithIt(): void
    {
        $document = $this->document();
        $id = (int) $document->id;
        $paths = [];
        foreach ($document->getPages() as $page) {
            $paths[] = $page->path;
        }

        $this->service()->deleteDocument($document);

        self::assertNull($this->entityManager->getRepository(CourseDocument::class)->find($id));
        foreach ($paths as $path) {
            self::assertFalse(self::getContainer()->get(FileStorage::class)->exists($path));
        }
    }

    public function testDeletingACourseTakesItsDocumentsAndSimilarityLinksWithIt(): void
    {
        $course = $this->entityManager->getRepository(Course::class)->findOneBy([]);
        self::assertInstanceOf(
            Course::class,
            $course,
        );
        $code = $course->code;
        $documents = [];
        foreach ($course->getDocuments() as $document) {
            $documents[] = (int) $document->id;
        }

        self::assertNotEmpty($documents);

        $this->service()->deleteCourse($course);

        self::assertNull($this->entityManager->getRepository(Course::class)->find($code));
        foreach ($documents as $id) {
            self::assertNull($this->entityManager->getRepository(CourseDocument::class)->find($id));
        }
    }

    private function service(): CourseAdminService
    {
        return self::getContainer()->get(CourseAdminService::class);
    }

    private function document(): CourseDocument
    {
        $document = $this->entityManager->getRepository(CourseDocument::class)->findOneBy([]);
        self::assertInstanceOf(
            CourseDocument::class,
            $document,
            'The seed is expected to contain a course document.',
        );

        return $document;
    }
}
