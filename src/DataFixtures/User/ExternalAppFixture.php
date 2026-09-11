<?php

declare(strict_types=1);

namespace App\DataFixtures\User;

use App\Entity\User\Enums\ExternalAppSignature;
use App\Entity\User\Enums\ExternalAppTokenDelivery;
use App\Entity\User\Enums\JWTClaims;
use App\Entity\User\ExternalApp;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Override;

class ExternalAppFixture extends Fixture implements FixtureGroupInterface
{
    #[Override]
    public function load(ObjectManager $manager): void
    {
        // SudoSOS is linked from the members dropdown in the navbar, so it needs a registered app to authenticate
        // against. It is a legacy HS512 application; the secret is development-only seed data.
        $sudosos = new ExternalApp();
        $sudosos->appId = 'sudosos';
        $sudosos->signature = ExternalAppSignature::HS512;
        $sudosos->tokenDelivery = ExternalAppTokenDelivery::Query;
        $sudosos->secret = 'sudosos-development-secret-0123456789abcdef0123456789abcdef0123456789';
        $sudosos->callback = 'https://sudosos.test.gewis.nl/token';
        $sudosos->url = 'https://sudosos.test.gewis.nl';
        $sudosos->setClaims([
            JWTClaims::Lidnr,
            JWTClaims::GivenName,
            JWTClaims::FamilyName,
            JWTClaims::Email,
        ]);
        $manager->persist($sudosos);

        // StarCommunity's test application. It verifies RS512, so it stays on that profile even though new
        // applications should prefer a stronger algorithm. The token is returned in the URL fragment.
        $hubble = new ExternalApp();
        $hubble->appId = 'hubble-test';
        $hubble->signature = ExternalAppSignature::RS512;
        $hubble->tokenDelivery = ExternalAppTokenDelivery::Fragment;
        $hubble->callback = 'https://login.test.starcommunity.app/auth/callback/gewis/';
        $hubble->url = 'https://test.starcommunity.app';
        $hubble->setClaims([
            JWTClaims::Name,
            JWTClaims::GivenName,
            JWTClaims::Email,
            JWTClaims::EmailVerified,
            JWTClaims::IsMember,
        ]);
        $manager->persist($hubble);

        // A test application on the recommended EdDSA profile, so the default modern path has something to exercise.
        $eddsa = new ExternalApp();
        $eddsa->appId = 'eddsa-test';
        $eddsa->signature = ExternalAppSignature::EdDSA;
        $eddsa->tokenDelivery = ExternalAppTokenDelivery::Fragment;
        $eddsa->callback = 'https://eddsa.test.gewis.nl/auth/callback';
        $eddsa->url = 'https://eddsa.test.gewis.nl';
        $eddsa->setClaims([
            JWTClaims::Name,
            JWTClaims::GivenName,
            JWTClaims::Email,
            JWTClaims::EmailVerified,
            JWTClaims::IsMember,
        ]);
        $manager->persist($eddsa);

        $manager->flush();
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
