<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\Entity\Database\Address as DatabaseAddress;
use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\MailingListMember as DatabaseMailingListMember;
use App\Entity\Database\Member as DatabaseMember;
use App\Entity\Decision\Address as ReportAddress;
use App\Entity\Decision\MailingList as ReportMailingList;
use App\Entity\Decision\MailingListMember as ReportMailingListMember;
use App\Entity\Decision\Member as ReportMember;
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
        private readonly EntityManagerInterface $emReport,
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
                $this->emReport->flush();
                $this->emReport->clear();

                if (null !== $onProgress) {
                    $onProgress($num, $total);
                }
            }

            $this->generateMember($member);
        }

        $this->emReport->flush();
        $this->emReport->clear();
    }

    public function generateMember(DatabaseMember $member): void
    {
        $repo = $this->emReport->getRepository(ReportMember::class);
        // first try to find an existing member
        $reportMember = $repo->find($member->getLidnr());

        if (null === $reportMember) {
            $reportMember = new ReportMember();
        }

        $reportMember->setLidnr($member->getLidnr());
        $reportMember->setEmail($member->getEmail());
        $reportMember->setLastName($member->getLastName());
        $reportMember->setMiddleName($member->getMiddleName());
        $reportMember->setInitials($member->getInitials());
        $reportMember->setFirstName($member->getFirstName());
        $reportMember->setGeneration($member->getGeneration());
        $reportMember->setType($member->getCurrentOrLastMembership()?->getType() ?? MembershipTypes::Graduate);
        $reportMember->setMembershipEndsOn($member->getMembershipEndsOn());
        $reportMember->setExpiration($member->getExpiration());
        $reportMember->setBirth($member->getBirth());
        $reportMember->setChangedOn($member->getChangedOn());
        $reportMember->setSupremum($member->getSupremum());
        $reportMember->setHidden($member->getHidden());
        $reportMember->setDeleted($member->getDeleted());
        $reportMember->setAuthenticationKey($member->getAuthenticationKey());

        // go through addresses
        foreach ($member->getAddresses() as $address) {
            $this->generateAddress(
                $address,
                $reportMember,
            );
        }

        // process mailing lists
        $this->generateLists(
            $member,
            $reportMember,
        );
        $this->emReport->persist($reportMember);
    }

    public function generateLists(
        DatabaseMember $member,
        ReportMember $reportMember,
    ): void {
        $reportListRepo = $this->emReport->getRepository(ReportMailingList::class);

        $reportLists = array_map(
            static function ($list) {
                return $list->getMailingList()->getName();
            },
            $reportMember->getMailingListMemberships()->toArray(),
        );
        $lists = array_map(
            static function ($list) {
                return $list->getMailingList()->getName();
            },
            array_filter(
                $member->getMailingListMemberships()->toArray(),
                static function (DatabaseMailingListMember $list) use ($reportMember) {
                    return !$list->isToBeDeleted() && $list->getEmail() === $reportMember->getEmail();
                },
            ),
        );

        foreach (
            array_diff(
                $lists,
                $reportLists,
            ) as $list
        ) {
            $reportList = $reportListRepo->find($list);

            if (null === $reportList) {
                throw new LogicException('mailing list missing from the projection');
            }

            $reportMailingListMember = new ReportMailingListMember();
            $reportMailingListMember->setMailingList($reportList);
            $reportMailingListMember->setEmail($reportMember->getEmail());

            $reportMember->addList($reportMailingListMember);
            $this->emReport->persist($reportList);
        }

        foreach (
            array_diff(
                $reportLists,
                $lists,
            ) as $list
        ) {
            $reportList = $reportListRepo->find($list);

            if (null === $reportList) {
                throw new LogicException('mailing list missing from the projection');
            }

            foreach ($reportMember->getMailingListMemberships() as $repMLM) {
                // NOTE: $list is a mailing list name while getMailingList() is a MailingList, so this never matches
                // and a membership that disappeared from the ledger is never removed from the projection. Left
                // as-is; correcting it changes what a regeneration writes.
                if ($repMLM->getMailingList() !== $list) {
                    continue;
                }

                $this->emReport->remove($repMLM);
            }
        }
    }

    public function generateAddress(
        DatabaseAddress $address,
        ?ReportMember $reportMember = null,
    ): void {
        $addrRepo = $this->emReport->getRepository(ReportAddress::class);

        if (null === $reportMember) {
            $reportMember = $this->emReport->getRepository(ReportMember::class)
                ->find($address->getMember()->getLidnr());
            if (null === $reportMember) {
                throw new LogicException('Address without member');
            }
        }

        $reportAddress = $addrRepo->find([
            'member' => $reportMember->getLidnr(),
            'type' => $address->getType(),
        ]);

        if (null === $reportAddress) {
            $reportAddress = new ReportAddress();
        }

        $reportAddress->setType($address->getType());
        $reportAddress->setCountry($address->getCountry());
        $reportAddress->setStreet($address->getStreet());
        $reportAddress->setNumber($address->getNumber());
        $reportAddress->setPostalCode($address->getPostalCode());
        $reportAddress->setCity($address->getCity());
        $reportAddress->setPhone($address->getPhone());
        $reportMember->addAddress($reportAddress);
        $this->emReport->persist($reportAddress);
    }

    /**
     * Take a member out of the projection, because the ledger has taken them out of the register.
     *
     * Everything on this connection that hangs off the member goes with them, and the database is what takes it: every
     * association that cannot outlive a member (their account and what hangs off it, their sign-ups, tags, votes,
     * photo preferences and address) declares `ON DELETE CASCADE` on its join column, and every association that names
     * a member only for attribution (who created an activity, who asked a poll, who wrote a comment, who reviewed a
     * revision) declares `ON DELETE SET NULL`. Doing it in the one statement is not merely cheaper than walking the
     * graph here: it is the only version that stays right, since this service would otherwise have to know about every
     * corner of the website and would quietly start leaving orphans the day a new one is added. The cost is that the
     * unit of work does not learn what the database took with the member, which is why this runs at the end of a
     * removal and nothing reads those rows again afterwards.
     *
     * What the database will not do is drop a row that records a decision: sub-decisions, organ and board
     * installations and key grants keep a plain foreign key, so a member the association decided something about
     * cannot be removed at all. {@see \App\Repository\Database\MemberRepository::canRemove()} is what keeps such a
     * member out of here — they are stripped of their data instead — and the constraint is the backstop for it.
     */
    public function deleteMember(DatabaseMember $member): void
    {
        $reportMember = $this->emReport->getRepository(ReportMember::class)
            ->find($member->getLidnr());

        // The ledger is free to create and remove a member without the projection ever having seen them, in which
        // case there is nothing here to take out.
        if (null === $reportMember) {
            return;
        }

        $this->emReport->remove($reportMember);
    }

    public function deleteAddress(DatabaseAddress $address): void
    {
        $repo = $this->emReport->getRepository(ReportAddress::class);

        // first try to find an existing member
        $reportAddress = $repo->find([
            'member' => $address->getMember()->getLidnr(),
            'type' => $address->getType(),
        ]);

        // If the report address has already been deleted, we don't need to do anything here.
        if (null === $reportAddress) {
            return;
        }

        $this->emReport->remove($reportAddress);
    }
}
