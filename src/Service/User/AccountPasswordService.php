<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\User\CompanyUser;
use App\Entity\User\User;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Changing the password of somebody who is signed in and still knows the old one. The reset-link route is
 * {@see PasswordResetService} instead.
 *
 * When the password changed is stamped in the same commit as the password itself, because that stamp is what every
 * session issued before it is measured against.
 */
final readonly class AccountPasswordService
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function change(
        User|CompanyUser $account,
        string $plainPassword,
    ): void {
        $account->setPassword($this->passwordHasher->hashPassword(
            $account,
            $plainPassword,
        ));
        $account->setPasswordChangedOn(new DateTime());

        $this->entityManager->flush();
    }
}
