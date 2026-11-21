<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent\Decision\Admin;

use App\Entity\Decision\ReferenceDocument;
use App\Entity\User\User;
use App\Repository\Decision\ReferenceDocumentRepository;
use App\Security\User\SudoMode;
use App\Tests\Integration\DatabaseTestCase;
use App\Twig\Components\Decision\Admin\ReferenceLibrary;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function array_key_first;
use function array_keys;

/**
 * The reference library writes on render, so the tests cover what a re-render does with the reader's input:
 * renaming, leaving unchanged, and removing a document that a meeting still selects.
 */
final class ReferenceLibraryActionsTest extends DatabaseTestCase
{
    public function testEveryDocumentIsGivenAPlaceToRenameIt(): void
    {
        $library = $this->library();

        $library->seedEdits();

        $documents = $library->getDocuments();
        self::assertNotEmpty($documents);

        foreach ($documents as [$document]) {
            self::assertSame(
                $document->name,
                $library->nameEdits[(string) $document->id]['name'] ?? null,
            );
        }
    }

    public function testRenamingADocumentStoresTheNewNameAndStampsTheTime(): void
    {
        $library = $this->library();
        $library->seedEdits();
        $id = (int) array_key_first($library->nameEdits);

        $library->nameEdits[$id]['name'] = 'Scenarios, Procedures and Everything Else';
        $library->syncEdits();

        $document = $this->repository()->find($id);
        self::assertInstanceOf(
            ReferenceDocument::class,
            $document,
        );
        self::assertSame(
            'Scenarios, Procedures and Everything Else',
            $document->name,
        );
        self::assertNotNull($library->savedAt);
        self::assertSame(
            'Scenarios, Procedures and Everything Else',
            $library->nameEdits[(string) $id]['name'],
            'The inputs are seeded again with the name the next render binds to.',
        );
    }

    /**
     * What is on screen is seeded into the edits, so on any given save most of them are not a rename at all.
     */
    public function testAnUnchangedOrEmptyNameIsNotARename(): void
    {
        $library = $this->library();
        $library->seedEdits();
        $ids = array_keys($library->nameEdits);

        $library->nameEdits[(int) $ids[0]]['name'] = '   ';
        if (isset($ids[1])) {
            $library->nameEdits[(int) $ids[1]]['name'] = $this->repository()->find((int) $ids[1])->name ?? '';
        }

        $library->syncEdits();

        self::assertNull($library->savedAt);
        self::assertNotSame(
            '   ',
            $library->nameEdits[$ids[0]]['name'],
        );
    }

    public function testRenamingSomethingThatIsNotThereChangesNothing(): void
    {
        $library = $this->library();
        $library->nameEdits = [999999 => ['name' => 'Nothing at all']];

        $library->syncEdits();

        self::assertNull($library->savedAt);
        self::assertNull($this->repository()->find(999999));
    }

    public function testDeletingADocumentAMeetingStillUsesIsRefusedWithTheReason(): void
    {
        $library = $this->library();
        $documents = $library->getDocuments();

        $inUse = null;
        foreach ($documents as [$document, $usage]) {
            if ($usage > 0) {
                $inUse = $document;

                break;
            }
        }

        self::assertInstanceOf(
            ReferenceDocument::class,
            $inUse,
            'The seed has a reference document a meeting selects.',
        );

        $library->deleteDocument((int) $inUse->id);

        self::assertNotNull($library->feedback);
        self::assertNotNull(
            $this->repository()->find((int) $inUse->id),
            'A document that is still selected is left alone.',
        );
    }

    public function testDeletingADocumentThatIsNotThereDoesNothing(): void
    {
        $library = $this->library();

        $library->deleteDocument(999999);

        self::assertNull($library->feedback);
    }

    public function testTheNextVersionLabelIsSuggestedFromTheLastOne(): void
    {
        $library = $this->library();

        self::assertSame(
            'v1.0',
            $library->suggestLabel(null),
        );
        self::assertSame(
            '1.4',
            $library->suggestLabel('1.3'),
        );
        self::assertSame(
            'V10.1',
            $library->suggestLabel('V10.0'),
            'The prefix the uploader wrote is kept as they wrote it.',
        );
        self::assertSame(
            'final',
            $library->suggestLabel('final'),
        );
    }

    public function testWhatHappenedToTheLibraryIsListed(): void
    {
        $library = $this->library();

        foreach ($library->getActivity() as $entry) {
            self::assertNotSame(
                '',
                $entry->subject,
                'Every line names what was done to what.',
            );
        }
    }

    private function repository(): ReferenceDocumentRepository
    {
        return self::getContainer()->get(ReferenceDocumentRepository::class);
    }

    private function library(): ReferenceLibrary
    {
        $user = $this->entityManager->getRepository(User::class)->find(8000);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $user,
            'main',
            [
                'ROLE_BOARD',
                'ROLE_DATABASE_ADMIN',
            ],
        ));

        $session = self::getContainer()->get('session.factory')->createSession();
        $request = new Request();
        $request->setSession($session);
        $request->cookies->set(
            $session->getName(),
            $session->getId(),
        );
        self::getContainer()->get('request_stack')->push($request);

        self::getContainer()->get(SudoMode::class)->grant();

        return self::getContainer()->get(ReferenceLibrary::class);
    }
}
