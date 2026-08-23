<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

use function array_combine;
use function array_keys;
use function array_map;
use function array_values;
use function implode;
use function sprintf;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260823093304 extends AbstractMigration
{
    /**
     * The Laminas-era backing value of every `UserRoles` case against the one it carries now. The move to Symfony
     * rewrote the enum so its values are the role names the firewall knows, but nothing rewrote the rows already
     * holding the old ones, so `UserRoles::from()` throws on every page and role written before that. Mapped by
     * case name, which is the same on both sides: the old hierarchy put `graduate` under `user`, exactly as
     * `ROLE_GRADUATE` sits on `ROLE_USER` now, so `user` carries over unchanged in meaning. `ROLE_MEMBER` and the
     * two register roles have no old counterpart and appear on neither side of this map.
     */
    private const array ROLES = [
        'guest' => 'PUBLIC_ACCESS',
        'tueguest' => 'ROLE_TUE_GUEST',
        'apiuser' => 'ROLE_API_USER',
        'company' => 'ROLE_COMPANY_USER',
        'user' => 'ROLE_USER',
        'graduate' => 'ROLE_GRADUATE',
        'active_member' => 'ROLE_ACTIVE_MEMBER',
        'company_admin' => 'ROLE_COMPANY_ADMIN',
        'board' => 'ROLE_BOARD',
        'admin' => 'ROLE_ADMIN',
    ];

    /**
     * Every column typed as `UserRoles` that predates the rewrite. `Notification.recipientRole` is the third such
     * column and is deliberately absent: it was added long afterwards and can only ever have held new values.
     */
    private const array COLUMNS = [
        'Page' => 'requiredRole',
        'UserRole' => 'role',
    ];

    public function getDescription(): string
    {
        return 'Carry the roles stored against custom pages and user roles over to the backing values `UserRoles`'
            . ' has held since the move to Symfony. Without this every page read fails on `"guest" is not a valid'
            . ' backing value for enum App\Entity\User\Enums\UserRoles`.';
    }

    public function up(Schema $schema): void
    {
        $this->rewrite(self::ROLES);
    }

    public function down(Schema $schema): void
    {
        $this->rewrite(array_combine(array_values(self::ROLES), array_keys(self::ROLES)));
    }

    /**
     * A single CASE per column rather than one statement per role: the tables are small, but a row is then read and
     * written once whichever value it holds. Anything already on the target side is left alone by the ELSE.
     *
     * @param array<string, string> $roles
     */
    private function rewrite(array $roles): void
    {
        $cases = implode(' ', array_map(
            fn (string $from, string $to): string => sprintf('WHEN %s THEN %s', $this->quote($from), $this->quote($to)),
            array_keys($roles),
            array_values($roles),
        ));

        foreach (self::COLUMNS as $table => $column) {
            $this->addSql(sprintf(
                'UPDATE %1$s SET %2$s = CASE %2$s %3$s ELSE %2$s END',
                $table,
                $column,
                $cases,
            ));
        }
    }

    private function quote(string $value): string
    {
        return $this->connection->quote($value);
    }
}
