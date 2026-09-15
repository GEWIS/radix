<?php

declare(strict_types=1);

namespace App\Entity\User;

use App\Entity\Application\Traits\IdentifiableTrait;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;

/**
 * Log when a member has authenticated for an external app.
 *
 * @phpstan-type ExternalAppAuthenticationGdprArrayType = array{
 *     id: ?int,
 *     app_id: string,
 *     time: string,
 * }
 */
#[Entity]
class ExternalAppAuthentication
{
    use IdentifiableTrait;

    /**
     * The user who was authenticated. This is a log of one account's sign-ins to other applications, so it goes with
     * the account rather than lingering as a trail nobody can be held to.
     */
    #[ManyToOne(targetEntity: User::class)]
    #[JoinColumn(
        name: 'user_id',
        referencedColumnName: 'lidnr',
        nullable: false,
        onDelete: 'CASCADE',
    )]
    public User $user;

    /**
     * The application that got the authentication.
     */
    #[ManyToOne(targetEntity: ExternalApp::class)]
    #[JoinColumn(
        name: 'app_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    public ExternalApp $externalApp;

    /**
     * Time of authentication.
     */
    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $time;

    /**
     * @return ExternalAppAuthenticationGdprArrayType
     */
    public function toGdprArray(): array
    {
        return [
            'id' => $this->id,
            'app_id' => $this->externalApp->appId,
            'time' => $this->time->format(DateTimeInterface::ATOM),
        ];
    }
}
