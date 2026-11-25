<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Decision;

use App\Controller\Decision\AdminBodyController;
use App\Entity\Decision\Organ;
use App\Entity\User\User;
use App\Repository\Decision\OrganLabelRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\SignsInThroughTheKernel;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function count;
use function str_contains;

/**
 * The labels on a committee page appear on the overview, the committee page and the administration screen. A
 * body that is not a committee shows none, and its page form offers no picker.
 */
final class BodyLabelPagesTest extends DatabaseTestCase
{
    use SignsInThroughTheKernel;

    private const int ADMIN = 8000;

    /** BAC, a committee whose approved page has the Fun label in the seed. */
    private const int COMMITTEE = 5;

    /** KCC, a financial audit committee that may not carry labels. */
    private const int NON_COMMITTEE = 8;

    public function testTheCommitteeOverviewShowsTheLabelOnTheCard(): void
    {
        $crawler = $this->crawl('/en/association/committees');

        $card = $crawler->filter('.organ-card')->reduce(
            static fn (Crawler $card): bool => str_contains(
                $card->text(),
                'BAC',
            ),
        );

        self::assertCount(
            1,
            $card,
        );
        self::assertSame(
            'Fun',
            $card->filter('.badge.badge-tag')->text(),
        );
    }

    public function testTheCommitteePageShowsTheLabelInItsHeader(): void
    {
        $crawler = $this->crawl('/en/association/committee/BAC');

        self::assertSame(
            'Fun',
            $crawler->filter('.page-header-meta .badge.badge-tag')->text(),
        );
    }

    /**
     * The label belongs to a committee's page. A financial audit committee has an approved page in the seed but must
     * not get a label by appearing beside the committees.
     */
    public function testABodyThatIsNotACommitteeShowsNoLabel(): void
    {
        self::assertCount(
            0,
            $this->crawl('/en/association/kcc/KCC')->filter('.page-header-meta .badge.badge-tag'),
        );
        self::assertCount(
            0,
            $this->crawl('/en/association/gmm-bodies/financial-audit-committees')
                ->filter('.organ-card .badge.badge-tag'),
        );
    }

    public function testTheCommitteeOverviewOffersTheLabelFilterAndTheOtherOverviewsDoNot(): void
    {
        self::assertCount(
            1,
            $this->crawl('/en/association/committees')->filter('#body-overview-labels-committee-now'),
        );
        self::assertCount(
            0,
            $this->crawl('/en/association/gmm-bodies/financial-audit-committees')
                ->filter('#body-overview-labels-kcc-now'),
        );
    }

    public function testTheAdministrationListsTheLabelSetWithItsUsage(): void
    {
        $body = $this->crawl('/en/admin/bodies/labels', $this->admin())->filter('tbody')->text();

        foreach (
            [
                'Education',
                'Career',
                'Personal development',
                'Fun',
                'FYC',
            ] as $name
        ) {
            self::assertStringContainsString(
                $name,
                $body,
            );
        }

        // The seed puts exactly one label on a page, so the count comes from the join.
        self::assertStringContainsString(
            'used by 1 revision',
            $body,
        );
    }

    public function testALabelCanBeAddedFromTheAdministration(): void
    {
        $before = count($this->labels()->findAll());

        $response = $this->post(
            '/en/admin/bodies/labels',
            [
                'label' => [
                    '_csrf_token' => 'csrf-token',
                    'name' => [
                        'valueNL' => 'Test pillar',
                        'valueEN' => 'Test pillar',
                    ],
                ],
            ],
            $this->admin(),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertCount(
            $before + 1,
            $this->labels()->findAll(),
        );
        self::assertStringContainsString(
            'Test pillar',
            $this->crawl('/en/admin/bodies/labels', $this->admin())->filter('tbody')->text(),
        );
    }

    /**
     * The picker sits in the draft flow, so the test revises the page first and follows the redirect to the first
     * step.
     */
    public function testACommitteeDraftOffersTheActiveLabelsAndKeepsItsOwn(): void
    {
        $content = $this->draftEditContent(self::COMMITTEE);

        self::assertStringContainsString(
            'Labels',
            $content,
        );

        $picker = new Crawler($content)->filter('select[name*="labelIds"]');
        self::assertCount(
            1,
            $picker,
        );

        $options = $picker->filter('option')->each(
            static fn (Crawler $option): string => $option->text(),
        );

        foreach (
            [
                'Education',
                'Career',
                'Personal development',
                'Fun',
                'FYC',
            ] as $name
        ) {
            self::assertContains(
                $name,
                $options,
            );
        }

        self::assertNotContains(
            'Corona period',
            $options,
        );
        self::assertSame(
            'Fun',
            $picker->filter('option[selected]')->text(),
        );
    }

    public function testANonCommitteeDraftOffersNoPicker(): void
    {
        $crawler = new Crawler($this->draftEditContent(self::NON_COMMITTEE));

        self::assertCount(
            0,
            $crawler->filter('select[name*="labelIds"]'),
        );
        self::assertCount(
            0,
            $crawler->filter('h3')->reduce(
                static fn (Crawler $heading): bool => str_contains(
                    $heading->text(),
                    'Labels',
                ),
            ),
        );
    }

    /**
     * Revising the approved page starts a draft. The test renders the first step through the controller, with the
     * flow in the query as the redirect would have it.
     */
    private function draftEditContent(int $organId): string
    {
        $organ = $this->entityManager->find(
            Organ::class,
            $organId,
        );
        self::assertInstanceOf(
            Organ::class,
            $organ,
        );

        $controller = self::getContainer()->get(AdminBodyController::class);
        self::assertInstanceOf(
            AdminBodyController::class,
            $controller,
        );

        $user = $this->admin();
        $this->authenticate($user);

        $response = $controller->revise(
            $organ,
            $user,
        );
        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            '/en/admin/bodies/' . $organId . '/edit',
            $response->getTargetUrl(),
        );

        $session = self::getContainer()->get('session.factory')->createSession();
        $request = new Request(query: ['flow' => '0123456789abcdef']);
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);

        $edit = $controller->edit(
            $request,
            $organ,
            $user,
        );

        self::assertSame(
            Response::HTTP_OK,
            $edit->getStatusCode(),
        );

        return (string) $edit->getContent();
    }

    private function crawl(
        string $path,
        ?User $user = null,
    ): Crawler {
        return new Crawler((string) $this->fetch($path, $user)->getContent());
    }

    private function fetch(
        string $path,
        ?User $user = null,
    ): Response {
        $request = Request::create($path);
        if (null !== $user) {
            foreach ($this->signedInCookies($user) as $name => $value) {
                $request->cookies->set(
                    $name,
                    $value,
                );
            }
        }

        $kernel = self::bootKernel();

        return $kernel->handle(
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            true,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function post(
        string $path,
        array $data,
        User $user,
    ): Response {
        $request = Request::create(
            $path,
            Request::METHOD_POST,
            $data + ['_csrf_token' => 'csrf-token'],
            server: ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        );
        foreach ($this->signedInCookies($user) as $name => $value) {
            $request->cookies->set(
                $name,
                $value,
            );
        }

        $kernel = self::bootKernel();

        return $kernel->handle(
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            true,
        );
    }

    private function admin(): User
    {
        $user = $this->entityManager->find(
            User::class,
            self::ADMIN,
        );
        self::assertInstanceOf(
            User::class,
            $user,
        );

        return $user;
    }

    private function authenticate(User $user): void
    {
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $user,
            'main',
            $user->getRoles(),
        ));
    }

    private function labels(): OrganLabelRepository
    {
        return self::getContainer()->get(OrganLabelRepository::class);
    }
}
