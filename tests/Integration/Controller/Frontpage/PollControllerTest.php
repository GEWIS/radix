<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Frontpage;

use App\Controller\Frontpage\PollController;
use App\Entity\Application\Enums\RevisionStatus;
use App\Entity\Frontpage\Poll;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Repository\Frontpage\PollRepository;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function count;
use function strval;

/**
 * What members see of the polls: the archive, one question with the questions asked around it, and the form for
 * asking a new one. A question is public only once the board has agreed to it; until then it belongs to its author
 * and the board.
 */
final class PollControllerTest extends DatabaseTestCase
{
    public function testTheArchiveLeadsWithWhatIsRunning(): void
    {
        $this->request();

        $content = strval($this->controller()->index()->getContent());

        self::assertStringContainsString(
            'When should the weekly social drink start?',
            $content,
        );
    }

    public function testTheRequestFormIsShown(): void
    {
        $this->request();

        $response = $this->controller()->request(
            Request::create('/en/polls/request'),
            $this->user(8030),
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Your question',
            strval($response->getContent()),
        );
    }

    /**
     * A poll is asked in one go: the form being valid is the submission, and there is no draft left behind.
     */
    public function testAQuestionIsSentToTheBoard(): void
    {
        $before = count($this->repository()->findAll());
        $author = $this->user(8030);
        $this->authenticate(
            $author,
            UserRoles::Member,
        );

        $request = $this->form();
        $this->request($request);

        $response = $this->controller()->request(
            $request,
            $author,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            '/polls/',
            strval($response->headers->get('Location')),
        );
        self::assertCount(
            $before + 1,
            $this->repository()->findAll(),
        );

        $poll = $this->repository()->findOneBy(
            [],
            ['id' => 'DESC'],
        );
        self::assertInstanceOf(
            Poll::class,
            $poll,
        );
        self::assertSame(
            8030,
            $poll->getCreator()?->lidnr,
        );
        self::assertSame(
            RevisionStatus::Submitted,
            $poll->getCurrentRevision()?->getStatus(),
        );
    }

    public function testAQuestionWithoutBothLanguagesComesBackWithTheForm(): void
    {
        $before = count($this->repository()->findAll());
        $request = $this->form(['question' => ['valueNL' => 'Alleen Nederlands', 'valueEN' => '']]);
        $this->request($request);

        $response = $this->controller()->request(
            $request,
            $this->user(8030),
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertCount(
            $before,
            $this->repository()->findAll(),
        );
        self::assertStringContainsString(
            'Fill this in for both languages.',
            strval($response->getContent()),
        );
    }

    /**
     * A question the board turned down is rewritten from the page it was asked on, so the old wording is already in
     * the form and the reader knows why it is there.
     */
    public function testARejectedQuestionIsPrefilledForItsAuthor(): void
    {
        $poll = $this->pollWithQuestion('worst board member');
        $request = Request::create(
            '/en/polls/request',
            'GET',
            ['from' => strval($poll->id)],
        );
        $this->request($request);

        $content = strval($this->controller()->request(
            $request,
            $this->user(8008),
        )->getContent());

        self::assertStringContainsString(
            'worst board member',
            $content,
        );
        self::assertStringContainsString(
            'The board did not agree to this question',
            $content,
        );
    }

    /**
     * A chain belongs to the member who asked it, and a question that was not turned down has nothing to continue.
     */
    public function testARejectedQuestionOfSomebodyElseIsNotPrefilled(): void
    {
        $poll = $this->pollWithQuestion('worst board member');
        $request = Request::create(
            '/en/polls/request',
            'GET',
            ['from' => strval($poll->id)],
        );
        $this->request($request);

        $content = strval($this->controller()->request(
            $request,
            $this->user(8030),
        )->getContent());

        self::assertStringNotContainsString(
            'worst board member',
            $content,
        );
        self::assertStringNotContainsString(
            'The board did not agree to this question',
            $content,
        );
    }

    public function testAnUnknownQuestionStartsANewOne(): void
    {
        $request = Request::create(
            '/en/polls/request',
            'GET',
            ['from' => '999999'],
        );
        $this->request($request);

        $response = $this->controller()->request(
            $request,
            $this->user(8030),
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringNotContainsString(
            'The board did not agree to this question',
            strval($response->getContent()),
        );
    }

    public function testALiveQuestionIsPublic(): void
    {
        $this->request();

        $content = strval($this->controller()->view(
            $this->pollWithQuestion('weekly social drink'),
            null,
        )->getContent());

        self::assertStringContainsString(
            'weekly social drink',
            $content,
        );
        // A question asked around the same time is offered as further reading.
        self::assertStringContainsString(
            'board photo',
            $content,
        );
    }

    public function testAQuestionTheBoardHasNotAgreedToIsNotFoundForThePublic(): void
    {
        $this->request();

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->view(
            $this->pollWithQuestion('second coffee machine'),
            null,
        );
    }

    public function testTheBoardMayReadAQuestionItHasNotAgreedTo(): void
    {
        $this->authenticate(
            $this->user(8000),
            UserRoles::Board,
        );
        $this->request();

        self::assertSame(
            Response::HTTP_OK,
            $this->controller()->view(
                $this->pollWithQuestion('second coffee machine'),
                $this->user(8000),
            )->getStatusCode(),
        );
    }

    public function testTheAuthorIsOfferedToAskARejectedQuestionAgain(): void
    {
        $author = $this->user(8008);
        $this->authenticate(
            $author,
            UserRoles::Member,
        );
        $this->request();

        $content = strval($this->controller()->view(
            $this->pollWithQuestion('worst board member'),
            $author,
        )->getContent());

        self::assertStringContainsString(
            'You can rewrite it and ask again.',
            $content,
        );
    }

    public function testSomebodyElseIsNotOfferedToAskARejectedQuestionAgain(): void
    {
        $reader = $this->user(8000);
        $this->authenticate(
            $reader,
            UserRoles::Board,
        );
        $this->request();

        $content = strval($this->controller()->view(
            $this->pollWithQuestion('worst board member'),
            $reader,
        )->getContent());

        self::assertStringNotContainsString(
            'You can rewrite it and ask again.',
            $content,
        );
    }

    /**
     * Owning a poll is not enough to be offered to ask it again: only a question the board turned down can be
     * continued, and an approved one has nothing to rewrite.
     */
    public function testTheOwnerOfALiveQuestionIsNotOfferedToAskItAgain(): void
    {
        $owner = $this->user(8005);
        $this->authenticate(
            $owner,
            UserRoles::Member,
        );
        $this->request();

        $content = strval($this->controller()->view(
            $this->pollWithQuestion('weekly social drink'),
            $owner,
        )->getContent());

        self::assertStringNotContainsString(
            'You can rewrite it and ask again.',
            $content,
        );
    }

    private function controller(): PollController
    {
        return self::getContainer()->get(PollController::class);
    }

    private function repository(): PollRepository
    {
        return self::getContainer()->get(PollRepository::class);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function form(array $overrides = []): Request
    {
        $fields = $overrides + [
            'question' => [
                'valueNL' => 'Moet de borrel eerder beginnen?',
                'valueEN' => 'Should the drinks start earlier?',
            ],
            'options' => [
                [
                    'text' => [
                        'valueNL' => 'Ja',
                        'valueEN' => 'Yes',
                    ],
                ],
                [
                    'text' => [
                        'valueNL' => 'Nee',
                        'valueEN' => 'No',
                    ],
                ],
            ],
        ];

        $request = new Request(
            request: ['poll_request' => $fields + ['_csrf_token' => 'csrf-token']],
            server: ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        );
        $request->setMethod(Request::METHOD_POST);

        return $request;
    }

    private function request(?Request $request = null): void
    {
        $request ??= Request::create('/en/polls');
        $request->setSession($this->session());
        self::getContainer()->get('request_stack')->push($request);
    }

    private function session(): FlashBagAwareSessionInterface
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        return $session;
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

    private function authenticate(
        User $user,
        UserRoles $role,
    ): void {
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $user,
            'main',
            [$role->value],
        ));
    }

    private function pollWithQuestion(string $question): Poll
    {
        $poll = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(
                Poll::class,
                'p',
            )
            ->join(
                'p.currentRevision',
                'r',
            )
            ->join(
                'r.question',
                'q',
            )
            ->where('q.valueEN LIKE :question')
            ->setParameter(
                'question',
                '%' . $question . '%',
            )
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(
            Poll::class,
            $poll,
            'The seed is expected to contain a poll matching the question.',
        );

        return $poll;
    }
}
