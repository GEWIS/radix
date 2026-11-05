<?php

declare(strict_types=1);

namespace App\Service\Database;

use App\Entity\Database\ActionLink;
use App\Entity\Database\EmailChangeLink;
use App\Entity\Database\GraduateConversionLink;
use App\Entity\Database\PaymentLink;
use App\Entity\Database\RenewalLink;
use App\Repository\Database\ActionLinkRepository;
use App\Util\Application\SplitToken;
use DateInterval;
use DateTimeImmutable;

use function bin2hex;
use function random_bytes;

/**
 * A click from a mailbox is a cross-site navigation: the token would be in the referrer of every request the page
 * makes, and the `SameSite=Strict` session cookie is not sent on it. The token is therefore exchanged for a
 * single-use hash and the page is served behind that hash, as the password reset in
 * {@see \App\Controller\User\AbstractSecurityController} is.
 */
class ActionLinkService
{
    private const string TEMP_HASH_LIFETIME = 'PT3M';

    public function __construct(
        private readonly ActionLinkRepository $actionLinkRepository,
    ) {
    }

    public function resolveRenewal(string $token): ?RenewalLink
    {
        $split = SplitToken::split($token);

        if (null === $split) {
            return null;
        }

        $link = $this->actionLinkRepository->findRenewalBySelector($split['selector']);

        if (
            null === $link
            || $link->used
            || $link->linkExpired()
            || !$link->tokenMatches($split['verifier'])
        ) {
            return null;
        }

        return $link;
    }

    /**
     * A payment link does not expire and may be followed more than once, so only the token is checked.
     */
    public function resolvePayment(string $token): ?PaymentLink
    {
        $split = SplitToken::split($token);

        if (null === $split) {
            return null;
        }

        $link = $this->actionLinkRepository->findPaymentBySelector($split['selector']);

        if (
            null === $link
            || !$link->tokenMatches($split['verifier'])
        ) {
            return null;
        }

        return $link;
    }

    public function resolveEmailChange(string $token): ?EmailChangeLink
    {
        $split = SplitToken::split($token);

        if (null === $split) {
            return null;
        }

        $link = $this->actionLinkRepository->findEmailChangeBySelector($split['selector']);

        if (
            null === $link
            || $link->used
            || $link->linkExpired()
            || !$link->tokenMatches($split['verifier'])
        ) {
            return null;
        }

        return $link;
    }

    public function resolveGraduateConversion(string $token): ?GraduateConversionLink
    {
        $split = SplitToken::split($token);

        if (null === $split) {
            return null;
        }

        $link = $this->actionLinkRepository->findGraduateConversionBySelector($split['selector']);

        if (
            null === $link
            || $link->used
            || $link->linkExpired()
            || !$link->tokenMatches($split['verifier'])
        ) {
            return null;
        }

        // The ending the offer was about has been settled since -- by the secretary's bulk conversion, or by the
        // member somewhere else -- so following it would write the membership a second time.
        $membership = $link->member->getCurrentOrLastMembership();

        if (
            null === $membership
            || $membership->endDate->getTimestamp() !== $link->currentExpiration->getTimestamp()
        ) {
            return null;
        }

        return $link;
    }

    public function claim(ActionLink $link): string
    {
        $tempHash = bin2hex(random_bytes(32));

        $link->tempHash = $tempHash;
        $link->tempHashExpiresAt = new DateTimeImmutable()->add(new DateInterval(self::TEMP_HASH_LIFETIME));

        $this->actionLinkRepository->persist($link);

        return $tempHash;
    }

    public function findByTempHash(string $tempHash): ?ActionLink
    {
        $link = $this->actionLinkRepository->findByTempHash($tempHash);

        if (
            null === $link
            || $link->isTempHashExpired()
        ) {
            return null;
        }

        return $link;
    }

    public function consumeTempHash(ActionLink $link): void
    {
        $link->tempHash = null;
        $link->tempHashExpiresAt = null;

        $this->actionLinkRepository->persist($link);
    }

    public function reissue(ActionLink $link): string
    {
        $token = $link->rotateToken();

        $this->actionLinkRepository->persist($link);

        return $token;
    }
}
