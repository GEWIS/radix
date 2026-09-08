<?php

declare(strict_types=1);

namespace App\Entity\User\Enums;

use Symfony\Component\Translation\TranslatableMessage;

/**
 * What part of security an event belongs to. Only ever a grouping: it decides how the administration's security log
 * is filtered and nothing else. {@see SecurityEventType} is what is actually recorded.
 */
enum SecurityEventCategory: string
{
    case Authentication = 'authentication';
    case Session = 'session';
    case MultiFactor = 'multi_factor';
    case Password = 'password';
    case Elevation = 'elevation';
    case Delegation = 'delegation';
    case Api = 'api';
    case Network = 'network';
    case Account = 'account';

    public function label(): TranslatableMessage
    {
        return match ($this) {
            self::Authentication => new TranslatableMessage('Authentication'),
            self::Session => new TranslatableMessage('Sessions'),
            self::MultiFactor => new TranslatableMessage('Multi-factor authentication'),
            self::Password => new TranslatableMessage('Passwords'),
            self::Elevation => new TranslatableMessage('Confirmation'),
            self::Delegation => new TranslatableMessage('Impersonation'),
            self::Api => new TranslatableMessage('API'),
            self::Network => new TranslatableMessage('Networks'),
            self::Account => new TranslatableMessage('Account'),
        };
    }
}
