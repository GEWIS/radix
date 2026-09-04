<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260904150222 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let a sign-up list stand without a window. A list is added before it is filled in, because the steps'
            . ' that fill it in are built from the lists that exist, and inventing an opening and closing moment on'
            . ' its behalf would mean an activity could go out with a window nobody chose. Nothing can be saved while'
            . ' it is missing, so a list that goes live still always has one.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE SignupList CHANGE openDate openDate DATETIME DEFAULT NULL, CHANGE closeDate closeDate DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE SignupList CHANGE openDate openDate DATETIME NOT NULL, CHANGE closeDate closeDate DATETIME NOT NULL');
    }
}
