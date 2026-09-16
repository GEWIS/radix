<?php

declare(strict_types=1);

namespace App\Entity\User;

use App\Entity\Application\Traits\IdentifiableTrait;
use App\Entity\User\Enums\SecurityEventType;
use App\Repository\User\SecurityLogRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Index;

/**
 * One thing that happened to an account, kept so it can be audited later: a sign-in, a session torn down, a second
 * factor turned off, an administrator acting as another user.
 *
 * Written beside the `security` log channel rather than instead of it. The file is the copy that survives a request
 * that later failed and rolled back, and is kept slightly over half a year. The table is the copy that can be queried:
 * everything for this member, everything from this address, every failed sign-in this week.
 *
 * What is *not* here is as deliberate as what is. No full user agent (the browser and system columns already record
 * what it is needed for), no request body, no cookie, no token, no session identifier: the row has to be enough to
 * recognise an event and no more. {@see $detail} is for what a particular event needs to make sense and is not a place
 * to put the request.
 *
 * Rows are personal data and are treated as such. They are included in a member's data export
 * ({@see self::toGdprArray()}) and pruned on a retention period by `app:user:prune-security-log`, which is what keeps
 * the table to what it is still needed for. An erasure request is handled by
 * {@see \App\Repository\User\SecurityLogRepository::deleteAllForUser()}; nothing calls it on its own, exactly as
 * nothing yet clears a removed member's {@see Session} and {@see KnownDevice} rows either.
 *
 * @phpstan-type SecurityLogGdprArrayType = array{
 *     occurredAt: string,
 *     event: string,
 *     category: string,
 *     firewall: ?string,
 *     actor: ?string,
 *     ipAddress: ?string,
 *     browser: ?string,
 *     operatingSystem: ?string,
 *     detail: array<string, scalar|null>,
 * }
 */
#[Entity(repositoryClass: SecurityLogRepository::class)]
// Named rather than left to Doctrine, because the migration that creates them is written by hand and a generated
// name would leave the schema and the mapping disagreeing about what the index is called.
#[Index(
    name: 'security_log_user_idx',
    columns: [
        'userIdentifier',
        'occurredAt',
    ],
)]
#[Index(
    name: 'security_log_occurred_idx',
    columns: ['occurredAt'],
)]
#[Index(
    name: 'security_log_event_idx',
    columns: ['event'],
)]
class SecurityLog
{
    use IdentifiableTrait;

    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $occurredAt;

    #[Column(enumType: SecurityEventType::class)]
    public SecurityEventType $event;

    /**
     * The account the event is about. Null for an event that belongs to no account at all, such as a token refused
     * before it named one, and for a sign-in attempt on an identifier that does not exist, which is deliberately not
     * recorded: an attacker who guesses addresses must not be able to fill the table with them, and the address of a
     * non-member is not ours to keep.
     */
    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $userIdentifier = null;

    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $firewallName = null;

    /**
     * Who performed the action, when that is an account other than the one it was done to: the administrator who ended
     * a session or started an impersonation. Null when the account acted for itself, which is the usual case.
     *
     * It stays in a member's own export. A member is entitled to know that their account was acted on and by whom;
     * withholding it would leave them with a row saying their session ended and no way to ask why.
     */
    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $actorIdentifier = null;

    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $ipAddress = null;

    /** As {@see Session::$browser}: the name and major version, never the user agent it was read from. */
    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $browser = null;

    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $operatingSystem = null;

    /**
     * Ties the row to the lines the same request wrote in the log file. Only useful together with the file, which is
     * the point: the row says what happened, the file says what the application was doing at the time.
     */
    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $requestId = null;

    /**
     * What this event needs beyond the columns above: the series of the session that ended, the route that refused,
     * the reason a sign-in was thrown out. Flat and scalar, so it can be rendered and exported without knowing what
     * is in it.
     *
     * @var array<string, scalar|null>
     */
    #[Column(
        type: Types::JSON,
        nullable: false,
    )]
    public array $detail = [];

    /**
     * @return SecurityLogGdprArrayType
     */
    public function toGdprArray(): array
    {
        return [
            'occurredAt' => $this->occurredAt->format(DateTimeInterface::ATOM),
            'event' => $this->event->value,
            'category' => $this->event->category()->value,
            'firewall' => $this->firewallName,
            'actor' => $this->actorIdentifier,
            'ipAddress' => $this->ipAddress,
            'browser' => $this->browser,
            'operatingSystem' => $this->operatingSystem,
            'detail' => $this->detail,
        ];
    }
}
