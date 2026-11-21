<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Decision;

use App\Controller\Decision\AdminBodyController;
use App\Entity\Application\Enums\RevisionStatus;
use App\Entity\Decision\Organ;
use App\Entity\Decision\OrganInformation;
use App\Entity\Decision\OrganInformationRevision;
use App\Entity\Decision\OrganLabel;
use App\Entity\Decision\SubDecision\FoundationReference;
use App\Entity\Decision\SubDecision\Installation;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Service\Application\EditLockService;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function array_filter;
use function array_values;
use function count;
use function json_decode;
use function sprintf;

/**
 * The body page's own actions beyond the label picker: the page a body sees, the edit flow that writes a draft, the
 * lock endpoints behind the editor, and the revise control that starts the next draft. The board's side of the
 * review lives in {@see AdminBodyApprovalControllerTest}.
 *
 * The seeded bodies are read rather than built: BAC is a committee with an approved page, GETÉST a committee whose
 * draft is in progress, KEUR a committee with a page at the board, and ATT a body that has never had one.
 */
final class AdminBodyPageActionsTest extends DatabaseTestCase
{
    private const int BOARD = 8025;
    private const int SECRETARY = 8026;
    private const int MEMBER = 8030;

    private const int BAC = 5;
    private const int KCC = 8;
    private const int ATT = 11;
    private const int GETEST = 14;
    private const int KEUR = 15;

    public function testAPublishedBodyPageIsShownToAReviewer(): void
    {
        $this->authenticate(
            self::BOARD,
            [UserRoles::Board->value],
        );
        $this->pushRequest();

        $response = $this->controller()->view($this->organ(self::BAC));

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'What visitors see',
            (string) $response->getContent(),
        );
    }

    /**
     * A register administrator comes for the composition rather than the page, so the page's own permissions decide
     * what is shown of it rather than whether the screen opens at all.
     */
    public function testARegisterAdministratorSeesTheCompositionBesideThePage(): void
    {
        $this->authenticate(
            self::BOARD,
            [
                UserRoles::ActiveMember->value,
                UserRoles::DatabaseReadOnly->value,
            ],
        );
        $this->pushRequest();

        $organ = $this->organ(self::BAC);
        $response = $this->controller()->view($organ);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $installations = array_filter(
            $organ->foundation->getReferences()->toArray(),
            static fn (FoundationReference $reference): bool => $reference instanceof Installation,
        );
        self::assertNotEmpty($installations);

        $installed = array_values($installations)[0];
        self::assertInstanceOf(
            Installation::class,
            $installed,
        );

        $content = (string) $response->getContent();
        self::assertStringContainsString(
            'Members',
            $content,
        );
        self::assertStringContainsString(
            $installed->getMember()->getFullName(),
            $content,
        );
        // Not installed and not a reviewer, so the page half itself stays hidden while the composition shows.
        self::assertStringNotContainsString(
            'What visitors see',
            $content,
        );
    }

    public function testAMemberOutsideTheBodyCannotReadItsPage(): void
    {
        $this->authenticate(
            self::MEMBER,
            [UserRoles::ActiveMember->value],
        );
        $this->pushRequest();

        $this->expectException(AccessDeniedException::class);

        $this->controller()->view($this->organ(self::BAC));
    }

    /**
     * A body with no page yet only opens for the board or an installed member, which is the same right as starting
     * one.
     */
    public function testABodyWithoutAPageIsOfferedTheStartToTheBoard(): void
    {
        $this->authenticate(
            self::BOARD,
            [UserRoles::Board->value],
        );
        $this->pushRequest();

        $response = $this->controller()->view($this->organ(self::ATT));

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $content = (string) $response->getContent();
        self::assertStringContainsString(
            'This body has no page yet.',
            $content,
        );
        self::assertStringContainsString(
            'Start a page',
            $content,
        );
    }

    public function testAMemberOutsideTheBodyCannotStartItsPage(): void
    {
        $this->authenticate(
            self::MEMBER,
            [UserRoles::ActiveMember->value],
        );
        $this->pushRequest();

        $this->expectException(AccessDeniedException::class);

        $this->controller()->view($this->organ(self::ATT));
    }

    /**
     * Only a draft is editable in place; anything the board is looking at has to be revised into a new one.
     */
    public function testAPageThatIsNotADraftCannotBeEdited(): void
    {
        $this->authenticate(
            self::BOARD,
            [UserRoles::Board->value],
        );
        $request = $this->pushRequest();

        $response = $this->controller()->edit(
            $request,
            $this->organ(self::KEUR),
            $this->user(self::BOARD),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            '/en/admin/bodies/' . self::KEUR,
            $response->getTargetUrl(),
        );
        self::assertContains(
            'This page is not a draft right now. Revise it to start a new one.',
            self::flashBag($request)->get('warning'),
        );
    }

    /**
     * A first arrival opens a run of the flow and redirects to it, so a reload can never re-post a step.
     */
    public function testOpeningTheEditorStartsAFlowRun(): void
    {
        $this->authenticate(
            self::BOARD,
            [UserRoles::Board->value],
        );
        $request = $this->pushRequest();

        $response = $this->controller()->edit(
            $request,
            $this->organ(self::GETEST),
            $this->user(self::BOARD),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            'flow=',
            $response->getTargetUrl(),
        );
    }

    public function testAnEditorHeldByAnotherWriterIsNotOpened(): void
    {
        $organ = $this->organ(self::GETEST);

        self::getContainer()->get(EditLockService::class)->acquire(
            self::organPage($organ),
            $this->user(self::SECRETARY),
        );

        $this->authenticate(
            self::BOARD,
            [UserRoles::Board->value],
        );
        $request = $this->pushRequest();

        $response = $this->controller()->edit(
            $request,
            $organ,
            $this->user(self::BOARD),
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Already being edited',
            (string) $response->getContent(),
        );
    }

    /**
     * A submitted step is answered with a redirect instead of a page: refreshing what a POST rendered would submit
     * it a second time.
     */
    public function testHandingInAStepAnswersWithARedirect(): void
    {
        $organ = $this->organ(self::GETEST);
        $draft = $this->draftOf($organ);
        $this->authenticate(
            self::BOARD,
            [UserRoles::Board->value],
        );
        $session = $this->session();

        $response = $this->controller()->edit(
            $this->flowRequest(
                $session,
                $this->pageStep($draft),
                self::organId($organ),
            ),
            $organ,
            $this->user(self::BOARD),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            'flow=run-1',
            $response->getTargetUrl(),
        );
    }

    /**
     * Walking every step to the end saves the draft: the text is applied, the labels the committee picked are put on
     * it, and the edit lock is released so the author can come back later.
     */
    public function testTheWholeFlowSavesTheDraftAndItsLabels(): void
    {
        $organ = $this->organ(self::BAC);
        $this->authenticate(
            self::BOARD,
            [UserRoles::Board->value],
        );
        $session = $this->session();

        // Revising the approved page is what gives the committee a draft to write in.
        $revise = $this->controller()->revise(
            $organ,
            $this->user(self::BOARD),
        );
        self::assertInstanceOf(
            RedirectResponse::class,
            $revise,
        );

        $draft = $this->draftOf($organ);
        self::assertSame(
            RevisionStatus::Draft,
            $draft->getStatus(),
        );

        $label = $draft->getLabels()->first();
        self::assertInstanceOf(
            OrganLabel::class,
            $label,
        );

        $fields = $this->pageStep($draft);
        $fields['page']['shortDescriptionEN'] = 'Rewritten for the test';
        $fields['page']['labelIds'] = [(string) $label->id];

        $this->controller()->edit(
            $this->flowRequest(
                $session,
                $fields,
                self::organId($organ),
            ),
            $organ,
            $this->user(self::BOARD),
        );
        $this->controller()->edit(
            $this->flowRequest(
                $session,
                $this->contactStep($draft),
                self::organId($organ),
            ),
            $organ,
            $this->user(self::BOARD),
        );

        $request = $this->flowRequest(
            $session,
            $this->imagesStep(),
            self::organId($organ),
        );
        $response = $this->controller()->edit(
            $request,
            $organ,
            $this->user(self::BOARD),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            '/en/admin/bodies/' . self::BAC,
            $response->getTargetUrl(),
        );
        self::assertContains(
            'Your changes are saved. Submit them for review when you are ready.',
            self::flashBag($request)->get('success'),
        );
        self::assertSame(
            'Rewritten for the test',
            $draft->shortDescription->getValueEN(),
        );
        self::assertCount(
            1,
            $draft->getLabels(),
        );
        $storedLabel = $draft->getLabels()->first();
        self::assertInstanceOf(
            OrganLabel::class,
            $storedLabel,
        );
        self::assertSame(
            $label->id,
            $storedLabel->id,
        );
        self::assertNull(
            self::getContainer()->get(EditLockService::class)->blockingLock(
                self::organPage($organ),
                $this->user(self::SECRETARY),
            ),
            'Saving releases the lock the editor held.',
        );
    }

    /**
     * The text is saved even when a crop cannot be applied, but the author is told the image was not stored rather
     * than being reassured into submitting the page with the old one.
     */
    public function testACropThatCannotBeAppliedStillSavesTheTextWithAWarning(): void
    {
        $organ = $this->organ(self::GETEST);
        $draft = $this->draftOf($organ);
        self::assertNotNull($draft->bannerSource);

        $this->authenticate(
            self::BOARD,
            [UserRoles::Board->value],
        );
        $session = $this->session();

        $this->controller()->edit(
            $this->flowRequest(
                $session,
                $this->pageStep($draft),
                self::organId($organ),
            ),
            $organ,
            $this->user(self::BOARD),
        );
        $this->controller()->edit(
            $this->flowRequest(
                $session,
                $this->contactStep($draft),
                self::organId($organ),
            ),
            $organ,
            $this->user(self::BOARD),
        );

        $fields = [
            'images' => [
                'bannerCropData' => [
                    'x' => '0',
                    'y' => '0',
                    'width' => '0',
                    'height' => '1',
                ],
                'logoCropData' => [
                    'x' => '',
                    'y' => '',
                    'width' => '',
                    'height' => '',
                ],
            ],
            'finish' => '',
        ];

        $request = $this->flowRequest(
            $session,
            $fields,
            self::organId($organ),
        );
        $response = $this->controller()->edit(
            $request,
            $organ,
            $this->user(self::BOARD),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertContains(
            'Your changes are saved, but an image could not be stored. Try uploading it again.',
            self::flashBag($request)->get('warning'),
        );
    }

    public function testTheLockEndpointsReportTheHoldAndTheRelease(): void
    {
        $organ = $this->organ(self::GETEST);
        $user = $this->user(self::BOARD);
        $this->authenticate(
            self::BOARD,
            [UserRoles::Board->value],
        );
        $this->pushRequest();

        self::getContainer()->get(EditLockService::class)->acquire(
            self::organPage($organ),
            $user,
        );

        $held = $this->controller()->editPing(
            $organ,
            $user,
        );
        self::assertSame(
            ['held' => true],
            $this->json($held),
        );

        $released = $this->controller()->editRelease(
            $organ,
            $user,
        );
        self::assertSame(
            ['released' => true],
            $this->json($released),
        );
        self::assertSame(
            ['held' => false],
            $this->json($this->controller()->editPing($organ, $user)),
        );
    }

    public function testTheLockEndpointsAreNotThereForABodyWithoutAPage(): void
    {
        $organ = $this->organ(self::ATT);
        $this->authenticate(
            self::BOARD,
            [UserRoles::Board->value],
        );
        $this->pushRequest();

        try {
            $this->controller()->editPing(
                $organ,
                $this->user(self::BOARD),
            );
            self::fail('A body without a page has no lock to ping.');
        } catch (NotFoundHttpException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(NotFoundHttpException::class);
        $this->controller()->editRelease(
            $organ,
            $this->user(self::BOARD),
        );
    }

    /**
     * Revising a decided page starts the next draft off it.
     */
    public function testAnApprovedPageIsRevisedIntoANewDraft(): void
    {
        $organ = $this->organ(self::KCC);
        $before = count(self::organPage($organ)->getRevisions());
        $this->authenticate(
            self::BOARD,
            [UserRoles::Board->value],
        );
        $this->pushRequest();

        $response = $this->controller()->revise(
            $organ,
            $this->user(self::BOARD),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            '/en/admin/bodies/' . self::KCC . '/edit',
            $response->getTargetUrl(),
        );
        self::assertCount(
            $before + 1,
            self::organPage($organ)->getRevisions(),
        );
        self::assertSame(
            RevisionStatus::Draft,
            self::organPage($organ)->getCurrentRevision()?->getStatus(),
        );
    }

    public function testRevisingADraftOpensItWithoutAWarning(): void
    {
        $organ = $this->organ(self::GETEST);
        $this->authenticate(
            self::BOARD,
            [UserRoles::Board->value],
        );
        $request = $this->pushRequest();

        $response = $this->controller()->revise(
            $organ,
            $this->user(self::BOARD),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            '/en/admin/bodies/' . self::GETEST . '/edit',
            $response->getTargetUrl(),
        );
        self::assertSame(
            [],
            self::flashBag($request)->peek('warning'),
        );
    }

    public function testRevisingWhileTheBoardHasItIsRefused(): void
    {
        $this->authenticate(
            self::BOARD,
            [UserRoles::Board->value],
        );
        $request = $this->pushRequest();

        $response = $this->controller()->revise(
            $this->organ(self::KEUR),
            $this->user(self::BOARD),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertContains(
            'This page is with the board. Wait for their decision before revising it again.',
            self::flashBag($request)->get('warning'),
        );
    }

    public function testRevisingAClosedPageIsRefused(): void
    {
        $organ = $this->organ(self::KEUR);
        $current = $organ->organInformation?->getCurrentRevision();
        self::assertInstanceOf(
            OrganInformationRevision::class,
            $current,
        );
        $current->setStatus(RevisionStatus::Closed);
        $this->entityManager->flush();

        $this->authenticate(
            self::BOARD,
            [UserRoles::Board->value],
        );
        $request = $this->pushRequest();

        $response = $this->controller()->revise(
            $organ,
            $this->user(self::BOARD),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertContains(
            'This page was closed. Get in touch with the board to reopen it.',
            self::flashBag($request)->get('warning'),
        );
    }

    /**
     * A body that has never had a page gets one and its first draft from the revise control, because that is the only
     * control its screen offers.
     */
    public function testRevisingABodyWithoutAPageStartsOne(): void
    {
        $organ = $this->organ(self::ATT);
        self::assertNull($organ->organInformation);
        $this->authenticate(
            self::BOARD,
            [UserRoles::Board->value],
        );
        $this->pushRequest();

        $response = $this->controller()->revise(
            $organ,
            $this->user(self::BOARD),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );

        $organ = $this->organ(self::ATT);
        self::assertInstanceOf(
            OrganInformation::class,
            $organ->organInformation,
        );
        self::assertInstanceOf(
            OrganInformationRevision::class,
            $organ->organInformation->getCurrentRevision(),
        );
        self::assertSame(
            RevisionStatus::Draft,
            $organ->organInformation->getCurrentRevision()->getStatus(),
        );
    }

    public function testAMemberOutsideTheBodyCannotStartItsPageByRevising(): void
    {
        $this->authenticate(
            self::MEMBER,
            [UserRoles::ActiveMember->value],
        );
        $this->pushRequest();

        $this->expectException(AccessDeniedException::class);

        $this->controller()->revise(
            $this->organ(self::ATT),
            $this->user(self::MEMBER),
        );
    }

    /**
     * A page whose current revision is missing (a row that lost its pointer) gets one, rather than being editable
     * with nothing to edit.
     */
    public function testAPageWithoutACurrentRevisionIsGivenOneBeforeEditing(): void
    {
        $organ = $this->organ(self::GETEST);
        $page = $organ->organInformation;
        self::assertInstanceOf(
            OrganInformation::class,
            $page,
        );
        $before = count($page->getRevisions());
        $page->setCurrentRevision(null);
        $this->entityManager->flush();

        $this->authenticate(
            self::BOARD,
            [UserRoles::Board->value],
        );
        $request = $this->pushRequest();

        $response = $this->controller()->edit(
            $request,
            $organ,
            $this->user(self::BOARD),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            'flow=',
            $response->getTargetUrl(),
        );
        self::assertCount(
            $before + 1,
            $page->getRevisions(),
        );
        self::assertSame(
            RevisionStatus::Draft,
            $page->getCurrentRevision()?->getStatus(),
        );
    }

    private function controller(): AdminBodyController
    {
        $controller = self::getContainer()->get(AdminBodyController::class);
        self::assertInstanceOf(
            AdminBodyController::class,
            $controller,
        );

        return $controller;
    }

    private static function organId(Organ $organ): int
    {
        self::assertNotNull($organ->id);

        return $organ->id;
    }

    private static function organPage(Organ $organ): OrganInformation
    {
        $page = $organ->organInformation;
        self::assertInstanceOf(
            OrganInformation::class,
            $page,
        );

        return $page;
    }

    private function organ(int $id): Organ
    {
        $organ = $this->entityManager->find(
            Organ::class,
            $id,
        );
        self::assertInstanceOf(
            Organ::class,
            $organ,
            sprintf(
                'The seed is expected to contain body %d.',
                $id,
            ),
        );

        return $organ;
    }

    private function draftOf(Organ $organ): OrganInformationRevision
    {
        $draft = $organ->organInformation?->getCurrentRevision();
        self::assertInstanceOf(
            OrganInformationRevision::class,
            $draft,
        );

        return $draft;
    }

    /**
     * @return array<string, mixed>
     */
    private function pageStep(OrganInformationRevision $draft): array
    {
        return [
            'page' => [
                'languageDutch' => '1',
                'languageEnglish' => '1',
                'shortDescriptionNL' => $draft->shortDescription->getValueNL(),
                'shortDescriptionEN' => $draft->shortDescription->getValueEN(),
                'descriptionNL' => $draft->description->getValueNL(),
                'descriptionEN' => $draft->description->getValueEN(),
            ],
            'next' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contactStep(OrganInformationRevision $draft): array
    {
        return [
            'contact' => [
                'email' => $draft->email ?? '',
                'website' => $draft->website ?? '',
            ],
            'next' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function imagesStep(): array
    {
        return [
            'images' => [
                'bannerCropData' => [
                    'x' => '',
                    'y' => '',
                    'width' => '',
                    'height' => '',
                ],
                'logoCropData' => [
                    'x' => '',
                    'y' => '',
                    'width' => '',
                    'height' => '',
                ],
            ],
            'finish' => '',
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function flowRequest(
        SessionInterface $session,
        array $body,
        int $organId,
    ): Request {
        $body['_csrf_token'] = self::getContainer()->get('security.csrf.token_manager')
            ->getToken('submit')
            ->getValue();

        $request = new Request(
            query: ['flow' => 'run-1'],
            request: ['organ_page_flow' => $body],
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_SEC_FETCH_SITE' => 'same-origin',
            ],
        );
        $request->setMethod('POST');
        $request->attributes->set(
            '_route',
            'admin/bodies/edit',
        );
        $request->attributes->set(
            '_route_params',
            ['organ' => $organId],
        );
        $this->attachSession(
            $request,
            $session,
        );

        return $request;
    }

    private function session(): SessionInterface
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        return $session;
    }

    private function pushRequest(int $organId = self::GETEST): Request
    {
        $request = new Request();
        $request->attributes->set(
            '_route',
            'admin/bodies/edit',
        );
        $request->attributes->set(
            '_route_params',
            ['organ' => $organId],
        );
        $this->attachSession(
            $request,
            $this->session(),
        );

        return $request;
    }

    private function attachSession(
        Request $request,
        SessionInterface $session,
    ): void {
        $request->setLocale('en');
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);
    }

    /**
     * @return array<string, mixed>
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

    private function user(int $lidnr): User
    {
        $user = $this->entityManager->find(
            User::class,
            $lidnr,
        );
        self::assertInstanceOf(
            User::class,
            $user,
            sprintf(
                'The seed is expected to contain a user for member %d.',
                $lidnr,
            ),
        );

        return $user;
    }

    /**
     * @param list<string> $roles
     */
    private function authenticate(
        int $lidnr,
        array $roles,
    ): void {
        $user = $this->user($lidnr);

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(
                $user,
                'main',
                $roles,
            ),
        );
    }
}
