<?php

declare(strict_types=1);

namespace App\DataFixtures\User;

use App\DataFixtures\Decision\ProjectionReferenceFixture;
use App\Entity\Decision\Member;
use App\Entity\User\User;
use DateTime;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Override;

class UserFixture extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    #[Override]
    public function load(ObjectManager $manager): void
    {
        // Every member the replay produced gets an account, rather than a fixed run of numbers: the population is
        // numbered in blocks with gaps between them, and a gap is not somebody to make an account for.
        foreach ($manager->getRepository(Member::class)->findAll() as $member) {
            $lidnr = $member->getLidnr();

            $user = new User();
            $user->setLidnr($lidnr);
            $user->setMember($member);
            // == gewiswebgewis. The cost (argon2id m=10, t=3) matches the configured hasher in dev and test
            // (config/packages/security.yaml), so logging in as a seeded user triggers no rehash-on-login UPDATE.
            $user->setPassword(
                '$argon2id$v=19$m=10,t=3,p=1$8fI5jXSYT4a/nmlANyW5iw$1eFNdB11zahtXd/ooeCWprWuCvAGDx+OrUsH2lBZNVM',
            );
            $user->setPasswordChangedOn(new DateTime());

            $manager->persist($user);
            $this->addReference(
                'user-' . $lidnr,
                $user,
            );
        }

        $manager->flush();
    }

    /**
     * @return array<array-key, class-string<Fixture>>
     */
    #[Override]
    public function getDependencies(): array
    {
        return [
            ProjectionReferenceFixture::class,
        ];
    }

    /**
     * @return string[]
     */
    #[Override]
    public static function getGroups(): array
    {
        return ['web'];
    }
}
