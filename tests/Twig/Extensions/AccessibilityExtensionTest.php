<?php

declare(strict_types=1);

namespace App\Tests\Twig\Extensions;

use App\Entity\User\CompanyUser;
use App\Entity\User\Enums\ColourVision;
use App\Entity\User\User;
use App\Twig\Extensions\AccessibilityExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Only a member can have chosen a palette. A company user and a visitor without an account cannot. The default is
 * reported as null, so the body gets no attribute for it.
 */
final class AccessibilityExtensionTest extends TestCase
{
    public function testAMemberWhoChoseAPaletteGetsIt(): void
    {
        $user = self::createStub(User::class);
        $user->method('getColourVision')->willReturn(ColourVision::RedGreen);

        self::assertSame(
            'red-green',
            $this->extension($user)->colourVision(),
        );
    }

    public function testAMemberOnTheDefaultGetsNone(): void
    {
        $user = self::createStub(User::class);
        $user->method('getColourVision')->willReturn(ColourVision::Default);

        self::assertNull($this->extension($user)->colourVision());
    }

    public function testACompanyUserGetsNone(): void
    {
        self::assertNull($this->extension(self::createStub(CompanyUser::class))->colourVision());
    }

    public function testAPasserByGetsNone(): void
    {
        self::assertNull($this->extension(null)->colourVision());
    }

    private function extension(?UserInterface $user): AccessibilityExtension
    {
        $tokenStorage = self::createStub(TokenStorageInterface::class);

        if (null !== $user) {
            $token = self::createStub(TokenInterface::class);
            $token->method('getUser')->willReturn($user);
            $tokenStorage->method('getToken')->willReturn($token);
        }

        return new AccessibilityExtension($tokenStorage);
    }
}
