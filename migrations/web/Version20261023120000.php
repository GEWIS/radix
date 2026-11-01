<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20261023120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Master IST has been renamed to Master Cybersecurity.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE Member SET study = \'Master Cybersecurity\' WHERE study = \'Master Information Security Technology\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE Member SET study = \'Master Information Security Technology\' WHERE study = \'Master Cybersecurity\'');
    }
}
