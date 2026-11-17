<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Decision;

use App\Controller\Decision\AdminOrganLabelController;
use App\Entity\Application\LabelInterface;
use App\Entity\Decision\DecisionLocalisedText;
use App\Entity\Decision\OrganLabel;
use App\Entity\User\User;
use App\Repository\Decision\OrganLabelRepository;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function array_map;

/**
 * The rules are shared with the activity and vacancy labels through
 * {@see \App\Controller\Application\AbstractLabelController}. This checks that they apply to the labels a body's page
 * carries, and what retiring one does to where it is offered.
 */
final class AdminOrganLabelControllerTest extends DatabaseTestCase
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
            'Education',
            (string) $response->getContent(),
        );
        self::assertStringContainsString(
            'FYC',
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

        $label = new OrganLabel();
        $label->name = new DecisionLocalisedText(
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

        $label = new OrganLabel();
        $label->name = new DecisionLocalisedText(
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

    private function label(bool $inUse): OrganLabel
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

    private function repository(): OrganLabelRepository
    {
        return self::getContainer()->get(OrganLabelRepository::class);
    }

    private function controller(): AdminOrganLabelController
    {
        return self::getContainer()->get(AdminOrganLabelController::class);
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
