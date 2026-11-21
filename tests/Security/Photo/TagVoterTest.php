<?php

declare(strict_types=1);

namespace App\Tests\Security\Photo;

use App\Entity\Decision\Member;
use App\Entity\Photo\MemberTag;
use App\Entity\Photo\OrganTag;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Security\Photo\TagVoter;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Any member may remove any tag. A graduate may remove only a member tag that concerns themselves.
 */
final class TagVoterTest extends TestCase
{
    public function testAnUnsupportedAttributeAbstains(): void
    {
        self::assertSame(
            Voter::ACCESS_ABSTAIN,
            $this->voter(member: true)->vote(
                $this->token($this->user()),
                new OrganTag(),
                ['SOMETHING_ELSE'],
            ),
        );
    }

    public function testAnotherSubjectAbstains(): void
    {
        self::assertSame(
            Voter::ACCESS_ABSTAIN,
            $this->voter(member: true)->vote(
                $this->token($this->user()),
                new stdClass(),
                [TagVoter::REMOVE],
            ),
        );
    }

    public function testAMemberMayRemoveAnyTag(): void
    {
        self::assertSame(
            Voter::ACCESS_GRANTED,
            $this->voter(member: true)->vote(
                $this->token($this->user()),
                new OrganTag(),
                [TagVoter::REMOVE],
            ),
        );
    }

    public function testAGraduateMayRemoveTheirOwnMemberTag(): void
    {
        $user = $this->user(8000);
        $tag = $this->memberTag(8000);

        self::assertSame(
            Voter::ACCESS_GRANTED,
            $this->voter(member: false)->vote(
                $this->token($user),
                $tag,
                [TagVoter::REMOVE],
            ),
        );
    }

    public function testAGraduateMayNotRemoveSomebodyElsesMemberTag(): void
    {
        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter(member: false)->vote(
                $this->token($this->user(8000)),
                $this->memberTag(8001),
                [TagVoter::REMOVE],
            ),
        );
    }

    public function testAGraduateMayNotRemoveAnOrganTag(): void
    {
        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter(member: false)->vote(
                $this->token($this->user(8000)),
                new OrganTag(),
                [TagVoter::REMOVE],
            ),
        );
    }

    public function testSomebodyWhoIsNotAMemberHasNoTagToRemove(): void
    {
        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter(member: false)->vote(
                new UsernamePasswordToken(
                    new InMemoryUser(
                        'someone',
                        null,
                    ),
                    'main',
                ),
                $this->memberTag(8000),
                [TagVoter::REMOVE],
            ),
        );
    }

    private function voter(bool $member): TagVoter
    {
        $security = self::createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(
            static fn (mixed $attribute): bool => UserRoles::Member->value === $attribute && $member,
        );

        return new TagVoter($security);
    }

    private function user(int $lidnr = 8000): User
    {
        $member = self::createStub(Member::class);
        $member->lidnr = $lidnr;

        $user = self::createStub(User::class);
        $user->member = $member;

        return $user;
    }

    private function memberTag(int $lidnr): MemberTag
    {
        $member = self::createStub(Member::class);
        $member->lidnr = $lidnr;

        $tag = self::createStub(MemberTag::class);
        $tag->member = $member;

        return $tag;
    }

    private function token(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken(
            $user,
            'main',
        );
    }
}
