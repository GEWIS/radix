<?php

declare(strict_types=1);

namespace App\Service\Application;

use Symfony\Component\Mime\Address;

/**
 * The mailboxes the website writes to when something is put in front of the association rather than in front of a
 * member: an officer, a committee, or a body that has to arrange something.
 *
 * Held here rather than at each send site so a deployment names each address once, and so who reviews what is a
 * question answered by the domain that raises the message instead of by whoever wrote the template.
 */
final readonly class OfficeMailboxes
{
    public function __construct(
        private string $mailToInternalAffairsAddress,
        private string $mailToInternalAffairsName,
        private string $mailToExternalAffairsAddress,
        private string $mailToExternalAffairsName,
        private string $mailToC4Address,
        private string $mailToC4Name,
        private string $mailToGeflitstAddress,
        private string $mailToGeflitstName,
        private string $mailToGeflitstPlankaAddress,
        private string $mailToGeflitstPlankaName,
        private string $mailToGeflitstPlankaKey,
        private string $mailToTreasurerAddress,
        private string $mailToTreasurerName,
    ) {
    }

    public function internalAffairs(): Address
    {
        return new Address(
            $this->mailToInternalAffairsAddress,
            $this->mailToInternalAffairsName,
        );
    }

    public function externalAffairs(): Address
    {
        return new Address(
            $this->mailToExternalAffairsAddress,
            $this->mailToExternalAffairsName,
        );
    }

    public function c4(): Address
    {
        return new Address(
            $this->mailToC4Address,
            $this->mailToC4Name,
        );
    }

    public function geflitst(): Address
    {
        return new Address(
            $this->mailToGeflitstAddress,
            $this->mailToGeflitstName,
        );
    }

    /**
     * GEFLITST's Planka board, which takes the same message as a second recipient and files it as a card.
     */
    public function geflitstPlanka(): Address
    {
        return new Address(
            $this->mailToGeflitstPlankaAddress,
            $this->mailToGeflitstPlankaName,
        );
    }

    /**
     * Which board the card belongs on, carried in the `X-Planka-Board-Id` header.
     */
    public function geflitstPlankaKey(): string
    {
        return $this->mailToGeflitstPlankaKey;
    }

    public function treasurer(): Address
    {
        return new Address(
            $this->mailToTreasurerAddress,
            $this->mailToTreasurerName,
        );
    }
}
