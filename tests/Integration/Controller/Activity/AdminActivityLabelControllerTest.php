<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Activity;

use App\Controller\Activity\AdminActivityLabelController;
use App\Entity\Activity\ActivityLabel;
use App\Entity\Activity\ActivityLocalisedText;
use App\Entity\Application\LabelInterface;
use App\Entity\User\User;
use App\Repository\Activity\ActivityLabelRepository;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function array_map;

/**
 * The rules are shared with the vacancy labels through
 * {@see \App\Controller\Application\AbstractLabelController}. This checks that they apply to activity labels, and
 * what retiring a label does to where it is offered.
 */
final class AdminActivityLabelControllerTest extends DatabaseTestCase
{
    public function testTheOverviewListsEveryLabelWithItsUsage(): void
    {
        $this->authenticate();
        $this->pushRequestWithSession();

        $response = $this->controller()->index(new Request());

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Open to externals',
            (string) $response->getContent(),
        );
        self::assertStringContainsString(
            'Corona-proof',
            (string) $response->getContent(),
        );
    }

    public function testALabelStillInUseCannotBeRemoved(): void
    {
        $this->authenticate();
        $session = $this->pushRequestWithSession();

        $label = $this->label(inUse: true);
        $labelId = (int) $label->id;

        $this->controller()->delete($label);

        self::assertNotNull($this->repository()->find($labelId));
        self::assertNotEmpty($session->getFlashBag()->peek('warning'));
    }

    public function testALabelNobodyUsesCanBeRemoved(): void
    {
        $this->authenticate();
        $this->pushRequestWithSession();

        $label = new ActivityLabel();
        $label->name = new ActivityLocalisedText(
            'Temporary',
            'Tijdelijk',
        );
        $this->entityManager->persist($label);
        $this->entityManager->flush();
        $labelId = (int) $label->id;

        $this->controller()->delete($label);

        self::assertNull($this->repository()->find($labelId));
    }

    public function testALabelNobodyUsesCannotBeRetired(): void
    {
        $this->authenticate();
        $session = $this->pushRequestWithSession();

        $label = new ActivityLabel();
        $label->name = new ActivityLocalisedText(
            'Temporary',
            'Tijdelijk',
        );
        $this->entityManager->persist($label);
        $this->entityManager->flush();

        $this->controller()->retire($label);

        self::assertFalse($label->retired);
        self::assertNotEmpty($session->getFlashBag()->peek('warning'));
    }

    public function testALabelThatIsOfferedCannotBeRestored(): void
    {
        $this->authenticate();
        $session = $this->pushRequestWithSession();

        $this->controller()->restore($this->label(inUse: true));

        self::assertNotEmpty($session->getFlashBag()->peek('warning'));
    }

    public function testARetiredLabelIsOnlyOfferedWhereItIsAlreadyApplied(): void
    {
        $this->authenticate();
        $this->pushRequestWithSession();

        $label = $this->label(inUse: true);
        $labelId = (int) $label->id;

        $this->controller()->retire($label);

        self::assertTrue($label->retired);
        self::assertNotContains(
            $labelId,
            $this->offeredIds(),
        );
        self::assertContains(
            $labelId,
            $this->offeredIds([$labelId]),
        );

        $this->controller()->restore($label);

        self::assertFalse($label->retired);
        self::assertContains(
            $labelId,
            $this->offeredIds(),
        );
    }

    /**
     * @param int[] $andIds
     *
     * @return int[]
     */
    private function offeredIds(array $andIds = []): array
    {
        return array_map(
            static fn (LabelInterface $label): int => (int) $label->id,
            $this->repository()->findActiveWithName($andIds),
        );
    }

    private function label(bool $inUse): ActivityLabel
    {
        foreach ($this->repository()->findAll() as $label) {
            if (
                $label->retired
                || $label->isInUse() !== $inUse
            ) {
                continue;
            }

            return $label;
        }

        self::fail('The seed is expected to contain a label that is in use.');
    }

    private function repository(): ActivityLabelRepository
    {
        return self::getContainer()->get(ActivityLabelRepository::class);
    }

    private function controller(): AdminActivityLabelController
    {
        return self::getContainer()->get(AdminActivityLabelController::class);
    }

    private function authenticate(int $lidnr = 8025): void
    {
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $this->user($lidnr),
            'main',
            ['ROLE_BOARD'],
        ));
    }

    private function pushRequestWithSession(): FlashBagAwareSessionInterface
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request = new Request();
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);

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
}
