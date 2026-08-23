<?php

declare(strict_types=1);

namespace App\Entity\User\Enums;

use Override;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum ApiPermissionGroups: string implements TranslatableInterface
{
    case Basic = 'basic';
    case Members = 'members';
    case MemberProperties = 'member_properties';
    case Bodies = 'bodies';
    case Boards = 'boards';
    case Keys = 'keys';
    case MailingLists = 'mailing_lists';
    case Activities = 'activities';
    case Photos = 'photos';
    case Everything = 'everything';

    public function getName(): TranslatableMessage
    {
        return match ($this) {
            self::Basic => new TranslatableMessage('Basic'),
            self::Members => new TranslatableMessage('Members'),
            self::MemberProperties => new TranslatableMessage('Member properties'),
            self::Bodies => new TranslatableMessage('Bodies'),
            self::Boards => new TranslatableMessage('Boards'),
            self::Keys => new TranslatableMessage('Keys'),
            self::MailingLists => new TranslatableMessage('Mailing lists'),
            self::Activities => new TranslatableMessage('Activities'),
            self::Photos => new TranslatableMessage('Photos'),
            self::Everything => new TranslatableMessage('Everything'),
        };
    }

    #[Override]
    public function trans(
        TranslatorInterface $translator,
        ?string $locale = null,
    ): string {
        return $this->getName()->trans(
            $translator,
            $locale,
        );
    }
}
