<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260921120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A vacancy now closes at the start of its closing day, like a package expires at the start of its'
            . ' expiry day. The stored closing days were chosen as the last day shown, so they move one day on.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE VacancyRevision SET endDate = DATE_ADD(endDate, INTERVAL 1 DAY)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE VacancyRevision SET endDate = DATE_SUB(endDate, INTERVAL 1 DAY)');
    }
}
