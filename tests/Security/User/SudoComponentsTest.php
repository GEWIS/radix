<?php

declare(strict_types=1);

namespace App\Tests\Security\User;

use App\Security\User\SudoComponents;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\UX\TwigComponent\ComponentFactory;

/**
 * A component is served outside the sudo addresses, so one reachable only from the administration declares the grant
 * itself. A component this cannot place returns false, which costs a prompt and never extends a grant.
 */
final class SudoComponentsTest extends KernelTestCase
{
    public function testAComponentThatRequiresSudoIsCovered(): void
    {
        self::assertTrue($this->components()->covers($this->request('Activity:Admin:SignupOverview')));
    }

    public function testAComponentThatRequiresABoardSeatWithSudoIsCovered(): void
    {
        self::assertTrue($this->components()->covers($this->request('Decision:Admin:MeetingManage')));
    }

    public function testAComponentThatDoesNotRequireSudoIsNotCovered(): void
    {
        self::assertFalse($this->components()->covers($this->request('Activity:ActivityOverview')));
    }

    public function testAComponentThisCannotPlaceIsNotCovered(): void
    {
        self::assertFalse($this->components()->covers($this->request('No:Such:Component')));
    }

    public function testARequestThatIsNotALiveComponentIsNotCovered(): void
    {
        self::assertFalse($this->components()->covers(Request::create('/en/admin')));
    }

    private function components(): SudoComponents
    {
        self::bootKernel();

        $factory = self::getContainer()->get('ux.twig_component.component_factory');
        self::assertInstanceOf(
            ComponentFactory::class,
            $factory,
        );

        return new SudoComponents($factory);
    }

    private function request(string $component): Request
    {
        $request = Request::create('/_components');
        $request->attributes->set(
            '_live_component',
            $component,
        );

        return $request;
    }
}
