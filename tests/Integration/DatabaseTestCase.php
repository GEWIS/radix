<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Security\User\MfaEnforcementSwitch;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base for integration tests that need a real Doctrine flush against a real database.
 *
 * Tests run against a MariaDB matching production (dev and CI both run one), isolated onto the `_test`
 * database that the `when@test` doctrine config targets. The schema and the full {@see \App\DataFixtures}
 * dataset are loaded once out of band (`make test-prepare`, or the CI prepare steps). `dama/doctrine-test-bundle`
 * wraps each test in a transaction that is rolled back afterwards, so every test sees the same seeded data yet its own
 * writes never leak. Reference the seeded entities by querying for them.
 *
 * Use this only for behaviour that is genuinely emergent from the UnitOfWork or real queries; pure domain logic stays a
 * plain unit test.
 */
abstract class DatabaseTestCase extends KernelTestCase
{
    /**
     * The MariaDB manager, which contains everything but the ledger. A test that writes decisions into the ledger
     * itself wants the default manager instead, and gets it by name.
     */
    protected EntityManagerInterface $entityManager;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        // MfaEnforcementSwitch is process-wide and is only assigned by the boot listener on a dispatched request, so
        // its value here is whatever the last test left behind. Assigned from the parameter this environment
        // configures (`false`, by `when@test:` in `config/services.yaml`), so that a test reading a member's roles
        // without dispatching a request reads the same ones whatever ran before it. Enforcement removes the roles the
        // fixtures grant from a member who has not enrolled, and the fixtures enrol no member.
        //
        // Here rather than in the tearDown of each test that changes it: one of those forgetting to restore it is
        // the ordering dependency this prevents.
        MfaEnforcementSwitch::setEnabled((bool) self::getContainer()->getParameter('app.mfa.enforcement_enabled'));

        $entityManager = self::getContainer()->get('doctrine')->getManager('web');
        self::assertInstanceOf(
            EntityManagerInterface::class,
            $entityManager,
        );

        $this->entityManager = $entityManager;
    }
}
