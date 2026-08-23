<?php

declare(strict_types=1);

namespace App\Service\Application;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Sending one of the application's e-mails.
 *
 * Every template extends `email/_base.html.twig` and fills its blocks, so the branding lives in one file and a
 * message cannot go out without it. The template is named here and rendered by the mailer, rather than rendered to a
 * string first and handed to a wrapper — that arrangement let a caller send a body on its own, which is how five of
 * these went out unbranded.
 *
 * A message carries no reply-to unless the caller asks for one. The register's mail about a member or a prospective
 * member does, {@see self::secretary()} being the secretary who answers for it; everything else is the association
 * writing, and a reply to it belongs wherever the message itself says.
 */
class Email
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $mailFromAddress,
        private readonly string $mailFromName,
        private readonly string $mailReplyToSecretaryAddress,
        private readonly string $mailReplyToSecretaryName,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function send(
        Address $recipient,
        string $subject,
        string $template,
        array $context = [],
        ?Address $replyTo = null,
        bool $bccReplyTo = false,
    ): void {
        $message = new TemplatedEmail()
            ->from(new Address($this->mailFromAddress, $this->mailFromName))
            ->to($recipient)
            ->subject($subject)
            ->htmlTemplate($template);

        if (null === $replyTo) {
            $this->mailer->send($message->context($context));

            return;
        }

        // The footer of the register's base template offers this address as the way to reach a person.
        $message->replyTo($replyTo)
            ->context($context + ['secretary_email' => $replyTo->getAddress()]);

        if ($bccReplyTo) {
            $message->bcc($replyTo);
        }

        $this->mailer->send($message);
    }

    /**
     * The secretary, who answers for what the register sends about a member, and the only reply-to the application
     * has: every other message is the association writing, and says in its own words where an answer belongs.
     */
    public function secretary(): Address
    {
        return new Address(
            $this->mailReplyToSecretaryAddress,
            $this->mailReplyToSecretaryName,
        );
    }
}
