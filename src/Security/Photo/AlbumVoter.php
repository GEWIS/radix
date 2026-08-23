<?php

declare(strict_types=1);

namespace App\Security\Photo;

use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Decision\Member;
use App\Entity\Photo\Album;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Repository\Photo\MemberTagRepository;
use Override;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Authorizes viewing a photo {@see Album}.
 *
 * Unpublished albums are never shown through public browsing, not even to the board, so a draft is never mistaken
 * for a live album; they are managed and previewed in the photo admin instead. Graduates are gated by the
 * graduate-subtree rule, which is recursive because a graduate tagged in a sub-album could not view the parent.
 *
 * @extends Voter<string, Album>
 */
final class AlbumVoter extends Voter
{
    public const string VIEW = 'ALBUM_VIEW';

    public function __construct(
        private readonly Security $security,
        private readonly MemberTagRepository $memberTagRepository,
    ) {
    }

    #[Override]
    protected function supports(
        string $attribute,
        mixed $subject,
    ): bool {
        return self::VIEW === $attribute
            && $subject instanceof Album;
    }

    #[Override]
    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        TokenInterface $token,
        ?Vote $vote = null,
    ): bool {
        return match ($attribute) {
            self::VIEW => $this->canView(
                $subject,
                $token,
            ),
            default => false,
        };
    }

    private function canView(
        Album $album,
        TokenInterface $token,
    ): bool {
        if (!$album->isPublished()) {
            return false;
        }

        if ($this->security->isGranted(UserRoles::Board->value)) {
            return true;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            // Anonymous and company users cannot browse albums.
            return false;
        }

        $member = $user->getMember();
        if (MembershipTypes::Graduate === $member->getType()) {
            return $this->graduateMayView(
                $album,
                $member,
            );
        }

        // Ordinary, active and honorary members may view any published album.
        return true;
    }

    /**
     * An album dated before their membership ended, or any album whose subtree they are tagged in.
     */
    private function graduateMayView(
        Album $album,
        Member $member,
    ): bool {
        $endsOn = $member->getMembershipEndsOn();
        $startedOn = $album->getStartDateTime();
        if (
            null !== $endsOn
            && null !== $startedOn
            && $startedOn < $endsOn
        ) {
            return true;
        }

        $albumId = $album->getId();
        if (null === $albumId) {
            return false;
        }

        return $this->memberTagRepository->isTaggedInAlbumTree(
            $albumId,
            $member->getLidnr(),
        );
    }
}
