<?php

declare(strict_types=1);

namespace App\EventListener\Database;

use App\Entity\Database\AuditEmailChange;
use App\Entity\Database\MailingListMember;
use App\Entity\Database\Member;
use App\Entity\User\User;
use App\Repository\Database\MemberRepository;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use Symfony\Bundle\SecurityBundle\Security;

use function is_string;

/**
 * A subscription is an address on a list rather than a member on a list, so a change of address has to move them.
 * Here rather than in whichever page wrote the column, because all three of them write it.
 */
#[AsDoctrineListener(
    event: Events::onFlush,
    connection: 'default',
)]
final class MemberEmailChangeListener
{
    public function __construct(
        private readonly Security $security,
        private readonly MemberRepository $memberRepository,
    ) {
    }

    public function __invoke(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();
        $metadata = $entityManager->getClassMetadata(MailingListMember::class);

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Member) {
                continue;
            }

            $changeSet = $unitOfWork->getEntityChangeSet($entity);

            if (!isset($changeSet['email'])) {
                continue;
            }

            $oldEmail = $changeSet['email'][0] ?? null;
            $newEmail = $changeSet['email'][1] ?? null;

            // A member whose address is taken away is being cleared rather than reached somewhere else.
            if (
                !is_string($newEmail)
                || $oldEmail === $newEmail
            ) {
                continue;
            }

            if (!is_string($oldEmail)) {
                // Nothing to carry over, but still something to record.
                $this->audit(
                    $entity,
                    null,
                    $newEmail,
                    $entityManager,
                    $unitOfWork,
                );

                continue;
            }

            $this->repoint(
                $entity,
                $oldEmail,
                $newEmail,
                $entityManager,
                $unitOfWork,
                $metadata,
            );

            $this->audit(
                $entity,
                $oldEmail,
                $newEmail,
                $entityManager,
                $unitOfWork,
            );
        }
    }

    /**
     * @param ClassMetadata<MailingListMember> $metadata
     */
    private function repoint(
        Member $member,
        string $oldEmail,
        string $newEmail,
        EntityManagerInterface $entityManager,
        UnitOfWork $unitOfWork,
        ClassMetadata $metadata,
    ): void {
        // Read once: the loop adds to this collection.
        $subscriptions = $member->getMailingListMemberships()->toArray();

        /** @var array<string, MailingListMember> $underNewEmail */
        $underNewEmail = [];
        foreach ($subscriptions as $subscription) {
            if ($newEmail !== $subscription->getEmail()) {
                continue;
            }

            $underNewEmail[$subscription->getMailingList()->getName()] = $subscription;
        }

        foreach ($subscriptions as $subscription) {
            if (
                $oldEmail !== $subscription->getEmail()
                || $subscription->isToBeDeleted()
            ) {
                continue;
            }

            $subscription->setToBeDeleted(true);
            $unitOfWork->recomputeSingleEntityChangeSet(
                $metadata,
                $subscription,
            );

            $list = $subscription->getMailingList();
            $existing = $underNewEmail[$list->getName()] ?? null;

            // The pair (list, address) identifies a row, so one held before is revived rather than written again.
            if (null !== $existing) {
                $existing->setToBeDeleted(false);
                $existing->setToBeCreated(true);
                $unitOfWork->recomputeSingleEntityChangeSet(
                    $metadata,
                    $existing,
                );

                continue;
            }

            $carried = new MailingListMember();
            $carried->setMailingList($list);
            // Sets the address from the member, which by now is the new one.
            $carried->setMember($member);

            $entityManager->persist($carried);
            $unitOfWork->computeChangeSet(
                $metadata,
                $carried,
            );
        }
    }

    private function audit(
        Member $member,
        ?string $oldEmail,
        string $newEmail,
        EntityManagerInterface $entityManager,
        UnitOfWork $unitOfWork,
    ): void {
        $user = $this->security->getUser();
        $audit = AuditEmailChange::create(
            $member,
            $oldEmail,
            $newEmail,
            $user instanceof User
                ? $this->memberRepository->find($user->getMember()->getLidnr())
                : null,
        );

        $entityManager->persist($audit);
        $unitOfWork->computeChangeSet(
            $entityManager->getClassMetadata(AuditEmailChange::class),
            $audit,
        );
    }
}
