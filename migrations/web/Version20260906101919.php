<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260906101919 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the place the draw gave every sign-up, admitted or not, so the waiting list stays in the order'
            . ' it was ranked in. Who moves up when somebody drops out is a question about the moment the draw ran,'
            . ' and working it out again later would answer it against a pool that has since changed.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Signup ADD drawPosition INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Signup DROP drawPosition');
    }
}
