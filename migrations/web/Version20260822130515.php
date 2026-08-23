<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260822130515 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retire the GEWISWEB-era API token. `ApiUser` was an id, a name and a plaintext token with blanket'
            . ' access; `App\Entity\Database\User\ApiPrincipal` on the ledger replaces it with a token that carries'
            . ' the permissions it may use. DESTRUCTIVE — the table goes, and with it any token still in it.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE ApiUser');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ApiUser (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, token VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
    }
}
