<?php

declare(strict_types=1);

namespace App\Service\Application;

use Symfony\Component\Mime\Address;

/**
 * The mailboxes the website writes to when something is put in front of the association rather than in front of a
 * member: an officer, a committee, or a body that has to arrange something.
 *
 * Defined here rather than at each send site so a deployment names each address once, and so who reviews what is
 * decided by the domain that raises the message instead of by the author of the template.
 */
final readonly class AssociationMailboxes
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
        private string $mailToGeflitstKaneoAddress,
        private string $mailToGeflitstKaneoName,
        private string $mailToGeflitstKaneoProjectId,
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
     * The mailbox GEFLITST's task board (Kaneo, read by [kaneo-mailer]) takes the same message as a second
     * recipient and files it as a task.
     *
     * [kaneo-mailer]: https://github.com/GEWIS/kaneo-mailer
     */
    public function geflitstKaneo(): Address
    {
        return new Address(
            $this->mailToGeflitstKaneoAddress,
            $this->mailToGeflitstKaneoName,
        );
    }

    /**
     * Which Kaneo project the task belongs on, carried in the `X-Kaneo-Project-Id` header.
     */
    public function geflitstKaneoProjectId(): string
    {
        return $this->mailToGeflitstKaneoProjectId;
    }

    public function treasurer(): Address
    {
        return new Address(
            $this->mailToTreasurerAddress,
            $this->mailToTreasurerName,
        );
    }
}
