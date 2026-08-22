<?php

declare(strict_types=1);

namespace App\DataFixtures\User;

use App\Entity\Database\User\ApiPrincipal;
use App\Entity\User\Enums\ApiPermissions;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Override;

use function substr;

class ApiPrincipalFixture extends Fixture implements FixtureGroupInterface
{
    private const array PRINCIPALS = [
        [
            'token' => 'smoketest-token',
            'description' => 'Development smoke test - holds the `*` wildcard, so every endpoint answers it.',
            'permissions' => [ApiPermissions::All],
        ],
        [
            'token' => 'limited-token',
            'description' => 'Development smoke test - holds `members_read` and nothing else, so every other '
                . 'endpoint answers it with a 403.',
            'permissions' => [ApiPermissions::MembersR],
        ],
        [
            'token' => 'readonly-token',
            'description' => 'Development stand-in for a read-only consumer such as a screen in the association '
                . 'rooms: health, members, bodies and keyholders, and nothing that reveals a member property.',
            'permissions' => [
                ApiPermissions::HealthR,
                ApiPermissions::MembersR,
                ApiPermissions::MembersActiveR,
                ApiPermissions::BodiesR,
                ApiPermissions::BodyMembersR,
                ApiPermissions::KeyholdersR,
            ],
        ],
    ];

    #[Override]
    public function load(ObjectManager $manager): void
    {
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException('The ledger fixtures need the ORM to seed a principal\'s token.');
        }

        $metadata = $manager->getClassMetadata(ApiPrincipal::class);

        foreach (self::PRINCIPALS as $seed) {
            $principal = new ApiPrincipal();
            $principal->setDescription($seed['description']);
            $principal->setPermissions($seed['permissions']);
            $metadata->setFieldValue(
                $principal,
                'tokenHash',
                ApiPrincipal::hash($seed['token']),
            );
            $metadata->setFieldValue(
                $principal,
                'tokenHint',
                substr(
                    $seed['token'],
                    -5,
                ),
            );

            $manager->persist($principal);
        }

        $manager->flush();
    }

    /**
     * @return string[]
     */
    #[Override]
    public static function getGroups(): array
    {
        return ['ledger'];
    }
}
