<?php

declare(strict_types=1);

namespace App\Tests\Security\Activity;

use App\Entity\Activity\ActivityProposal;
use App\Entity\Activity\Enums\ProposalStatus;
use App\Entity\Decision\Member;
use App\Entity\Decision\Organ;
use App\Entity\Decision\OrganMember;
use App\Entity\User\User;
use App\Security\Activity\ActivityProposalVoter;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * A proposal belongs to the body hosting it, so whoever is installed there may look at it, change it while it is still
 * waiting, and withdraw it. Deciding is the board's, and so is everything about a proposal the board hosts itself.
 */
final class ActivityProposalVoterTest extends TestCase
{
    public function testAnUnsupportedAttributeAbstains(): void
    {
        self::assertSame(
            Voter::ACCESS_ABSTAIN,
            $this->voter(false)->vote(
                $this->token($this->user()),
                new ActivityProposal(),
                ['SOMETHING_ELSE'],
            ),
        );
    }

    public function testAnotherSubjectAbstains(): void
    {
        self::assertSame(
            Voter::ACCESS_ABSTAIN,
            $this->voter(false)->vote(
                $this->token($this->user()),
                new stdClass(),
                [ActivityProposalVoter::VIEW],
            ),
        );
    }

    public function testAnAnonymousTokenIsDenied(): void
    {
        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter(false)->vote(
                new NullToken(),
                new ActivityProposal(),
                [ActivityProposalVoter::VIEW],
            ),
        );
    }

    public function testTheBoardMayDoEverything(): void
    {
        $proposal = $this->proposal();

        foreach (
            [
                ActivityProposalVoter::VIEW,
                ActivityProposalVoter::EDIT,
                ActivityProposalVoter::WITHDRAW,
                ActivityProposalVoter::DECIDE,
            ] as $attribute
        ) {
            self::assertSame(
                Voter::ACCESS_GRANTED,
                $this->voter(true)->vote(
                    $this->token($this->user()),
                    $proposal,
                    [$attribute],
                ),
                $attribute,
            );
        }
    }

    public function testTheMemberWhoSubmittedItMayLookAtChangeAndWithdrawIt(): void
    {
        $member = $this->member(8000);
        $proposal = $this->proposal();
        $proposal->setCreatedBy($member);

        $token = $this->token($this->user($member));

        foreach (
            [
                ActivityProposalVoter::VIEW,
                ActivityProposalVoter::EDIT,
                ActivityProposalVoter::WITHDRAW,
            ] as $attribute
        ) {
            self::assertSame(
                Voter::ACCESS_GRANTED,
                $this->voter(false)->vote(
                    $token,
                    $proposal,
                    [$attribute],
                ),
                $attribute,
            );
        }

        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter(false)->vote(
                $token,
                $proposal,
                [ActivityProposalVoter::DECIDE],
            ),
        );
    }

    /**
     * Once a date is reserved, changing the dates would mean reserving one nobody approved.
     */
    public function testAProposalThatIsNoLongerWaitingCannotBeChanged(): void
    {
        $member = $this->member(8000);
        $proposal = $this->proposal();
        $proposal->setCreatedBy($member);
        $proposal->status = ProposalStatus::Scheduled;

        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter(false)->vote(
                $this->token($this->user($member)),
                $proposal,
                [ActivityProposalVoter::EDIT],
            ),
        );
    }

    public function testWhoeverIsInstalledInTheHostingBodyOwnsIt(): void
    {
        $organ = $this->organ(3);
        $member = $this->member(
            8000,
            $organ,
        );
        $proposal = $this->proposal();
        $proposal->organ = $organ;

        self::assertSame(
            Voter::ACCESS_GRANTED,
            $this->voter(false)->vote(
                $this->token($this->user($member)),
                $proposal,
                [ActivityProposalVoter::VIEW],
            ),
        );
    }

    public function testSomebodyOutsideTheHostingBodyIsDenied(): void
    {
        $proposal = $this->proposal();
        $proposal->organ = $this->organ(3);

        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter(false)->vote(
                $this->token($this->user($this->member(8000))),
                $proposal,
                [ActivityProposalVoter::VIEW],
            ),
        );
    }

    private function voter(bool $isBoard): ActivityProposalVoter
    {
        $security = self::createStub(Security::class);
        $security->method('isGranted')->willReturn($isBoard);

        return new ActivityProposalVoter($security);
    }

    private function proposal(): ActivityProposal
    {
        return new ActivityProposal();
    }

    private function member(
        int $lidnr,
        ?Organ $organ = null,
    ): Member {
        $member = self::createStub(Member::class);
        $member->lidnr = $lidnr;

        $installations = [];
        if (null !== $organ) {
            $installation = self::createStub(OrganMember::class);
            $installation->organ = $organ;
            $installations[] = $installation;
        }

        $member->method('getCurrentOrganInstallations')->willReturn(new ArrayCollection($installations));

        return $member;
    }

    private function organ(int $id): Organ
    {
        $organ = self::createStub(Organ::class);
        new ReflectionProperty(
            Organ::class,
            'id',
        )->setValue(
            $organ,
            $id,
        );

        return $organ;
    }

    private function user(?Member $member = null): User
    {
        $user = self::createStub(User::class);
        $user->member = $member ?? $this->member(8000);

        return $user;
    }

    private function token(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken(
            $user,
            'main',
        );
    }
}
