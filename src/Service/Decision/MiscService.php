<?php

declare(strict_types=1);

namespace App\Service\Decision;

use App\Entity\Database\MailingList as DatabaseMailingList;
use App\Entity\Database\MailingListMember as DatabaseMailingListMember;
use App\Entity\Decision\MailingList as DecisionMailingList;
use App\Entity\Decision\MailingListMember as DecisionMailingListMember;
use App\Entity\Decision\Member as DecisionMember;
use App\Repository\Database\MailingListRepository;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class MiscService
{
    public function __construct(
        private readonly MailingListRepository $mailingListRepository,
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private readonly EntityManagerInterface $emWeb,
    ) {
    }

    /**
     * Generate the mailing lists themselves.
     *
     * The memberships of those lists are not generated here. A membership needs both its list and its member to be in
     * the projection already, and generating the members is what puts each of their memberships there, so a pass over
     * the memberships either runs before the members it needs, or after they have already been written.
     */
    public function generateLists(): void
    {
        foreach ($this->mailingListRepository->findAll() as $list) {
            $this->generateList($list);
        }

        $this->emWeb->flush();
    }

    /**
     * Generate a mailing list for usage in the projection.
     */
    public function generateList(DatabaseMailingList $list): void
    {
        $repo = $this->emWeb->getRepository(DecisionMailingList::class);
        $decisionList = $repo->find($list->name);

        if (null === $decisionList) {
            $decisionList = new DecisionMailingList();
            $decisionList->name = $list->name;
        }

        $decisionList->setEnDescription($list->getEnDescription());
        $decisionList->setNlDescription($list->getNlDescription());

        $this->emWeb->persist($decisionList);
    }

    /**
     * Delete a mailing list if it exists
     */
    public function deleteList(DatabaseMailingList $list): void
    {
        $repo = $this->emWeb->getRepository(DecisionMailingList::class);
        $decisionList = $repo->find($list->name);

        if (null === $decisionList) {
            return;
        }

        $this->emWeb->remove($decisionList);
    }

    /**
     * Generate a list membership for usage in the projection.
     */
    public function generateListMembership(DatabaseMailingListMember $mailingListMember): void
    {
        $repo = $this->emWeb->getRepository(DecisionMailingListMember::class);
        $decisionListMembership = $repo->find([
            'mailingList' => $mailingListMember->mailingList->name,
            'email' => $mailingListMember->email,
        ]);

        if (null === $decisionListMembership) {
            $decisionList = $this->emWeb->getRepository(DecisionMailingList::class)
                ->find($mailingListMember->mailingList->name);

            if (null === $decisionList) {
                throw new LogicException('List membership without list');
            }

            $decisionMember = $this->emWeb->getRepository(DecisionMember::class)
                ->find($mailingListMember->member->lidnr);

            if (null === $decisionMember) {
                throw new LogicException('List membership without member');
            }

            $decisionListMembership = new DecisionMailingListMember();
            $decisionListMembership->mailingList = $decisionList;
            $decisionListMembership->member = $decisionMember;
            $decisionListMembership->email = $mailingListMember->email;
        }

        // There is no possibility of updating an entry, all values are a key

        $this->emWeb->persist($decisionListMembership);
    }

    /**
     * Delete list membership if it exists (both when deleting a row and when setting toBeDeleted=true)
     */
    public function deleteListMembership(DatabaseMailingListMember $mailingListMember): void
    {
        $repo = $this->emWeb->getRepository(DecisionMailingListMember::class);
        $decisionListMembership = $repo->find([
            'mailingList' => $mailingListMember->mailingList->name,
            'email' => $mailingListMember->email,
        ]);

        if (null === $decisionListMembership) {
            return;
        }

        $this->emWeb->remove($decisionListMembership);
    }
}
