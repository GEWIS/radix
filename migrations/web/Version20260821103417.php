<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260821103417 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move a news item\'s title and body onto FrontpageLocalisedText, which is how every other localised'
            . ' text on the website is stored. The four columns become two rows per item, carried across in place.';
    }

    /**
     * The two new columns are added empty, filled from the four they replace, and only then made to stand for
     * something: a news item without a title or a body is not a news item.
     *
     * The rows are matched back to the item they came from through a column that exists for the length of this
     * migration. Relying on the order the ids happen to come out in would be a guess; a column that says which item a
     * row was written for is not.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE NewsItem ADD title_id INT DEFAULT NULL, ADD content_id INT DEFAULT NULL');

        $this->addSql('ALTER TABLE FrontpageLocalisedText ADD migration_news_title INT DEFAULT NULL, ADD migration_news_content INT DEFAULT NULL');

        $this->addSql('INSERT INTO FrontpageLocalisedText (valueEN, valueNL, migration_news_title) SELECT englishTitle, dutchTitle, id FROM NewsItem');
        $this->addSql('INSERT INTO FrontpageLocalisedText (valueEN, valueNL, migration_news_content) SELECT englishContent, dutchContent, id FROM NewsItem');

        $this->addSql('UPDATE NewsItem item INNER JOIN FrontpageLocalisedText title ON title.migration_news_title = item.id SET item.title_id = title.id');
        $this->addSql('UPDATE NewsItem item INNER JOIN FrontpageLocalisedText content ON content.migration_news_content = item.id SET item.content_id = content.id');

        $this->addSql('ALTER TABLE FrontpageLocalisedText DROP migration_news_title, DROP migration_news_content');

        $this->addSql('ALTER TABLE NewsItem MODIFY title_id INT NOT NULL, MODIFY content_id INT NOT NULL');
        $this->addSql('ALTER TABLE NewsItem ADD CONSTRAINT FK_B6839EAEA9F87BD FOREIGN KEY (title_id) REFERENCES FrontpageLocalisedText (id)');
        $this->addSql('ALTER TABLE NewsItem ADD CONSTRAINT FK_B6839EAE84A0A3ED FOREIGN KEY (content_id) REFERENCES FrontpageLocalisedText (id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B6839EAEA9F87BD ON NewsItem (title_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B6839EAE84A0A3ED ON NewsItem (content_id)');

        $this->addSql('ALTER TABLE NewsItem DROP dutchTitle, DROP englishTitle, DROP englishContent, DROP dutchContent');
    }

    /**
     * The four columns come back with what the localised rows hold, and those rows go with them: nothing else points
     * at them, so leaving them behind would leave the table carrying two rows per news item that nothing can reach.
     *
     * A localised text may hold no value at all, which the columns it is going back into may not. An item that was
     * written in one language only after this ran therefore comes back with an empty string on the other side rather
     * than refusing to step back at all.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE NewsItem ADD dutchTitle VARCHAR(255) NOT NULL, ADD englishTitle VARCHAR(255) NOT NULL, ADD englishContent LONGTEXT NOT NULL, ADD dutchContent LONGTEXT NOT NULL');

        $this->addSql(<<<'SQL'
            UPDATE NewsItem item
            INNER JOIN FrontpageLocalisedText title ON title.id = item.title_id
            INNER JOIN FrontpageLocalisedText content ON content.id = item.content_id
            SET item.dutchTitle = COALESCE(title.valueNL, ''),
                item.englishTitle = COALESCE(title.valueEN, ''),
                item.dutchContent = COALESCE(content.valueNL, ''),
                item.englishContent = COALESCE(content.valueEN, '')
            SQL);

        // The keys come off before the indexes: a unique index a foreign key is standing on cannot be dropped, and
        // MariaDB refuses the whole statement rather than dropping the key with it.
        $this->addSql('ALTER TABLE NewsItem DROP FOREIGN KEY FK_B6839EAEA9F87BD');
        $this->addSql('ALTER TABLE NewsItem DROP FOREIGN KEY FK_B6839EAE84A0A3ED');
        $this->addSql('DROP INDEX UNIQ_B6839EAEA9F87BD ON NewsItem');
        $this->addSql('DROP INDEX UNIQ_B6839EAE84A0A3ED ON NewsItem');

        // Collected before the columns naming them are dropped, because afterwards there is nothing left to find them
        // by. A temporary table rather than a sub-query, which MariaDB will not read from the table it deletes from.
        $this->addSql('CREATE TEMPORARY TABLE migration_news_localised (id INT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('INSERT INTO migration_news_localised (id) SELECT title_id FROM NewsItem UNION SELECT content_id FROM NewsItem');

        $this->addSql('ALTER TABLE NewsItem DROP title_id, DROP content_id');

        $this->addSql('DELETE text FROM FrontpageLocalisedText text INNER JOIN migration_news_localised gone ON gone.id = text.id');
        $this->addSql('DROP TEMPORARY TABLE migration_news_localised');
    }
}
