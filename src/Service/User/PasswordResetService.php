<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\User\CompanyUser;
use App\Entity\User\PasswordReset;
use App\Entity\User\User;
use App\Repository\User\PasswordResetRepository;
use DateInterval;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function bin2hex;
use function random_bytes;

/**
 * The three writes the password-reset link makes on its way to a new password.
 *
 * The emailed token is exchanged for a single-use temp hash and then redirected to, so the token itself never reaches
 * a page that may load third-party resources; the hash is spent on first use. Finishing the reset retires every
 * outstanding reset for whoever it belonged to in the same commit as the new password, so a link that was requested
 * twice cannot be replayed against an account that has already been recovered.
 */
final readonly class PasswordResetService
{
    private const string TEMP_HASH_LIFETIME = 'PT3M';

    public function __construct(
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private EntityManagerInterface $entityManager,
        private PasswordResetRepository $passwordResetRepository,
    ) {
    }

    /**
     * Mint the single-use hash the reset link is exchanged for, and return it for the redirect.
     */
    public function claim(PasswordReset $passwordReset): string
    {
        $tempHash = bin2hex(random_bytes(32));

        $passwordReset->setTempHash($tempHash);
        $passwordReset->setTempHashExpiresAt(
            new DateTimeImmutable('now')->add(new DateInterval(self::TEMP_HASH_LIFETIME)),
        );

        $this->entityManager->flush();

        return $tempHash;
    }

    /**
     * Spend the temp hash. It is good for exactly one exchange, so it is cleared before the reset is bound to the
     * session rather than after the new password is set.
     */
    public function consumeTempHash(PasswordReset $passwordReset): void
    {
        $passwordReset->setTempHash(null);
        $passwordReset->setTempHashExpiresAt(null);

        $this->entityManager->flush();
    }

    /**
     * Commit the new password — the form has already hashed and assigned it — and retire every reset outstanding for
     * the account it belonged to.
     */
    public function complete(
        PasswordReset $passwordReset,
        User|CompanyUser $target,
    ): void {
        $target->setPasswordChangedOn(new DateTime());
        $this->entityManager->persist($target);

        $this->deleteAllForTarget($passwordReset);

        $this->entityManager->flush();
    }

    /**
     * A reset names either a member or a company user, never both, and which one has already been checked against the
     * surface it came in on by the time this is reached.
     */
    private function deleteAllForTarget(PasswordReset $passwordReset): void
    {
        $member = $passwordReset->getMember();
        if (null !== $member) {
            $this->passwordResetRepository->deleteAllForMember($member);

            return;
        }

        $companyUser = $passwordReset->getCompanyUser();
        if (null === $companyUser) {
            return;
        }

        $this->passwordResetRepository->deleteAllForCompanyUser($companyUser);
    }
}
