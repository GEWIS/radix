<?php

declare(strict_types=1);

namespace App\Service\Decision;

use App\Entity\Database\Address as DatabaseAddress;
use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\MailingListMember as DatabaseMailingListMember;
use App\Entity\Database\Member as DatabaseMember;
use App\Entity\Decision\Address as DecisionAddress;
use App\Entity\Decision\MailingList as DecisionMailingList;
use App\Entity\Decision\MailingListMember as DecisionMailingListMember;
use App\Entity\Decision\Member as DecisionMember;
use App\Repository\Database\MemberRepository;
use Closure;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function array_diff;
use function array_filter;
use function array_map;
use function count;

class MemberService
{
    public function __construct(
        private readonly MemberRepository $memberRepository,
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private readonly EntityManagerInterface $emWeb,
    ) {
    }

    /**
     * Export members.
     *
     * Progress is reported through the callback rather than written to the console here, so that the service stays
     * usable outside of a command.
     *
     * @param (Closure(int $current, int $total): void)|null $onProgress
     */
    public function generate(?Closure $onProgress = null): void
    {
        $memberCollection = $this->memberRepository->findAll();
        $total = count($memberCollection);

        $num = 0;
        foreach ($memberCollection as $member) {
            if (0 === $num++ % 20) {
                $this->emWeb->flush();
                $this->emWeb->clear();

                if (null !== $onProgress) {
                    $onProgress($num, $total);
                }
            }

            $this->generateMember($member);
        }

        $this->emWeb->flush();
        $this->emWeb->clear();
    }

    public function generateMember(DatabaseMember $member): void
    {
        $repo = $this->emWeb->getRepository(DecisionMember::class);
        $decisionMember = $repo->find($member->lidnr);

        if (null === $decisionMember) {
            $decisionMember = new DecisionMember();
        }

        $decisionMember->lidnr = $member->lidnr;
        $decisionMember->email = $member->email;
        $decisionMember->lastName = $member->lastName;
        $decisionMember->middleName = $member->middleName;
        $decisionMember->initials = $member->initials;
        $decisionMember->firstName = $member->firstName;
        $decisionMember->generation = $member->getGeneration();
        $decisionMember->type = $member->getCurrentOrLastMembership()->type ?? MembershipTypes::Graduate;
        $decisionMember->study = $member->study;
        $decisionMember->membershipEndsOn = $member->getMembershipEndsOn();
        $decisionMember->expiration = $member->getExpiration();
        $decisionMember->birth = $member->birth;
        $decisionMember->changedOn = $member->changedOn;
        $decisionMember->supremum = $member->supremum;
        $decisionMember->hidden = $member->hidden;
        $decisionMember->deleted = $member->deleted;

        foreach ($member->getAddresses() as $address) {
            $this->generateAddress(
                $address,
                $decisionMember,
            );
        }

        $this->generateLists(
            $member,
            $decisionMember,
        );
        $this->emWeb->persist($decisionMember);
    }

    public function generateLists(
        DatabaseMember $member,
        DecisionMember $decisionMember,
    ): void {
        $decisionListRepo = $this->emWeb->getRepository(DecisionMailingList::class);

        $decisionLists = array_map(
            static function ($list) {
                return $list->mailingList->name;
            },
            $decisionMember->getMailingListMemberships()->toArray(),
        );
        $email = $decisionMember->email;
        $lists = array_map(
            static function ($list) {
                return $list->mailingList->name;
            },
            array_filter(
                $member->getMailingListMemberships()->toArray(),
                static function (DatabaseMailingListMember $list) use ($email) {
                    return !$list->toBeDeleted && $list->email === $email;
                },
            ),
        );

        foreach (
            array_diff(
                $lists,
                $decisionLists,
            ) as $list
        ) {
            $decisionList = $decisionListRepo->find($list);

            if (null === $decisionList) {
                throw new LogicException('mailing list missing from the projection');
            }

            // A membership only reaches here when the address it was entered with equals the member's own, and a
            // membership always has one, so the member has one too.
            if (null === $email) {
                throw new LogicException('mailing list membership without an e-mail address');
            }

            $decisionMailingListMember = new DecisionMailingListMember();
            $decisionMailingListMember->mailingList = $decisionList;
            $decisionMailingListMember->email = $email;

            $decisionMember->addList($decisionMailingListMember);
            $this->emWeb->persist($decisionList);
        }

        foreach (
            array_diff(
                $decisionLists,
                $lists,
            ) as $list
        ) {
            $decisionList = $decisionListRepo->find($list);

            if (null === $decisionList) {
                throw new LogicException('mailing list missing from the projection');
            }

            foreach ($decisionMember->getMailingListMemberships() as $decisionMLM) {
                // NOTE: $list is a mailing list name while getMailingList() is a MailingList, so this never matches
                // and a membership that disappeared from the ledger is never removed from the projection. Left
                if ($decisionMLM->mailingList !== $list) {
                    continue;
                }

                $this->emWeb->remove($decisionMLM);
            }
        }
    }

    public function generateAddress(
        DatabaseAddress $address,
        ?DecisionMember $decisionMember = null,
    ): void {
        $addrRepo = $this->emWeb->getRepository(DecisionAddress::class);

        if (null === $decisionMember) {
            $decisionMember = $this->emWeb->getRepository(DecisionMember::class)
                ->find($address->getMember()->lidnr);
            if (null === $decisionMember) {
                throw new LogicException('Address without member');
            }
        }

        $decisionAddress = $addrRepo->find([
            'member' => $decisionMember->lidnr,
            'type' => $address->type,
        ]);

        if (null === $decisionAddress) {
            $decisionAddress = new DecisionAddress();
        }

        $decisionAddress->type = $address->type;
        $decisionAddress->country = $address->country;
        $decisionAddress->street = $address->street;
        $decisionAddress->number = $address->number;
        $decisionAddress->postalCode = $address->postalCode;
        $decisionAddress->city = $address->city;
        $decisionAddress->phone = $address->phone;
        $decisionMember->addAddress($decisionAddress);
        $this->emWeb->persist($decisionAddress);
    }

    /**
     * Take a member out of the projection, because the ledger has taken them out of the register.
     *
     * The database cascades the rest: what cannot outlive a member declares `ON DELETE CASCADE`, what names one only
     * for attribution `ON DELETE SET NULL`. Traversing the graph here would mean knowing every corner of the website
     * and leaving orphans the day a new one is added. The unit of work does not learn what the database took, so this
     * runs at the end of a removal.
     *
     * Rows that record a decision keep a plain foreign key, so a member the association decided something about
     * cannot be removed at all; {@see \App\Repository\Database\MemberRepository::canRemove()} keeps them out of
     * here, and the constraint is the backstop.
     */
    public function deleteMember(DatabaseMember $member): void
    {
        $decisionMember = $this->emWeb->getRepository(DecisionMember::class)
            ->find($member->lidnr);

        if (null === $decisionMember) {
            return;
        }

        $this->emWeb->remove($decisionMember);
    }

    public function deleteAddress(DatabaseAddress $address): void
    {
        $repo = $this->emWeb->getRepository(DecisionAddress::class);

        $decisionAddress = $repo->find([
            'member' => $address->getMember()->lidnr,
            'type' => $address->type,
        ]);

        if (null === $decisionAddress) {
            return;
        }

        $this->emWeb->remove($decisionAddress);
    }
}
