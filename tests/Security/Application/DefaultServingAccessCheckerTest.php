<?php

declare(strict_types=1);

namespace App\Tests\Security\Application;

use App\Entity\Application\Enums\StorageNamespace;
use App\Entity\User\Enums\UserRoles;
use App\Security\Application\DefaultServingAccessChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The catch-all: public namespaces are freely servable, and any private namespace requires at least a signed-in user.
 */
final class DefaultServingAccessCheckerTest extends TestCase
{
    public function testItSupportsEveryNamespace(): void
    {
        $checker = $this->checker(false);

        foreach (StorageNamespace::cases() as $namespace) {
            self::assertTrue($checker->supports($namespace));
        }
    }

    public function testAPublicFileIsFreelyServable(): void
    {
        self::assertTrue(
            $this->checker(false)->isGranted(
                'photos/weekly/1.jpg',
                StorageNamespace::PhotoWeekly,
            ),
        );
    }

    public function testAPrivateFileRequiresASignedInUser(): void
    {
        self::assertTrue(
            $this->checker(true)->isGranted(
                'education/downloads/1.pdf',
                StorageNamespace::EducationDownload,
            ),
        );
        self::assertFalse(
            $this->checker(false)->isGranted(
                'education/downloads/1.pdf',
                StorageNamespace::EducationDownload,
            ),
        );
    }

    private function checker(bool $signedIn): DefaultServingAccessChecker
    {
        $security = self::createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(
            static fn (mixed $attribute): bool => UserRoles::User->value === $attribute && $signedIn,
        );

        return new DefaultServingAccessChecker($security);
    }
}
