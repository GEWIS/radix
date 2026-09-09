<?php

declare(strict_types=1);

namespace App\MessageHandler\Activity;

use App\Message\Activity\OrganiserAnnouncementEmail;
use App\Util\Activity\AnnouncementPlaceholders;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Exception\RfcComplianceException;

#[AsMessageHandler]
class OrganiserAnnouncementEmailHandler
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(OrganiserAnnouncementEmail $message): void
    {
        $replyTo = $message->getReplyTo();

        // One personalised email per recipient (no shared To/BCC, so nobody sees the others, and the greeting is by
        // name). A single recipient failing (a bad address, a transient transport error) is logged and skipped, not
        // thrown: throwing would make Messenger retry the whole message and re-send to everyone already mailed.
        foreach ($message->getRecipients() as $recipient) {
            try {
                // Build inside the try too: a malformed stored address makes `new Address()` throw an
                // RfcComplianceException, which outside the try would abort the whole batch and (on Messenger retry)
                // re-send to everyone already mailed. Skip just this recipient instead.
                //
                // The placeholders are replaced here rather than at dispatch, so the message stores one subject and
                // one body, and only the replacements differ per recipient.
                $subject = AnnouncementPlaceholders::apply(
                    $message->getSubject(),
                    $recipient['replacements'],
                );
                $body = AnnouncementPlaceholders::apply(
                    $message->getBody(),
                    $recipient['replacements'],
                );

                $email = new TemplatedEmail()
                    ->to(new Address(
                        $recipient['email'],
                        $recipient['name'],
                    ))
                    ->subject($subject)
                    ->htmlTemplate('emails/activity/organiser-announcement.html.twig')
                    ->replyTo($replyTo)
                    ->context([
                        'subject' => $subject,
                        'body' => $body,
                        'activityName' => $message->getActivityName(),
                        'name' => $recipient['name'],
                        'organiserEmail' => $replyTo,
                    ]);

                $this->mailer->send($email);
            } catch (TransportExceptionInterface | RfcComplianceException $exception) {
                $this->logger->error(
                    'Failed to send an activity sign-up bulk email to a recipient.',
                    ['exception' => $exception],
                );
            }
        }
    }
}
