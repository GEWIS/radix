<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260911081204 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The ledger schema as it stood at release 5.5.0, dumped from a database the 28 migrations it replaces'
            . ' had just built. A database that already ran those is rolled up onto this one rather than'
            . ' running it; see the commit that added it for the order that takes.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'This migration can only be executed on \Doctrine\DBAL\Platforms\PostgreSQLPlatform.',
        );

        $this->addSql(<<<'SQL'
            CREATE SCHEMA IF NOT EXISTS public
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE actionlink_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE apiprincipal_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE auditentry_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE checkoutsession_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE configitem_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE member_lidnr_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE prospectivemember_lidnr_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE savedquery_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE actionlink (id INT NOT NULL, prospective_member INT DEFAULT NULL, member INT DEFAULT NULL, used BOOLEAN NOT NULL, token VARCHAR(255) NOT NULL, type VARCHAR(255) NOT NULL, currentexpiration DATE DEFAULT NULL, newexpiration DATE DEFAULT NULL, PRIMARY KEY (id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_a952b2a570e4fa78 ON actionlink (member)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_a952b2a5740ee3e7 ON actionlink (prospective_member)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE address (type VARCHAR(255) NOT NULL, lidnr INT NOT NULL, country VARCHAR(255) NOT NULL, street VARCHAR(255) NOT NULL, number VARCHAR(255) NOT NULL, postalcode VARCHAR(255) NOT NULL, city VARCHAR(255) NOT NULL, phone VARCHAR(255) NOT NULL, PRIMARY KEY (lidnr, type))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_c2f3561dd665e01d ON address (lidnr)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE apiprincipal (id INT NOT NULL, description VARCHAR(255) DEFAULT NULL, permissions TEXT DEFAULT NULL, createdat TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updatedat TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, tokenhash VARCHAR(64) NOT NULL, tokenhint VARCHAR(5) NOT NULL, lastusedat DATE DEFAULT NULL, expiresat DATE DEFAULT NULL, revokedat TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX apiprincipal_token_hash_unique_idx ON apiprincipal (tokenhash)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE auditentry (id INT NOT NULL, member INT DEFAULT NULL, createdat TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updatedat TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, type VARCHAR(255) NOT NULL, note VARCHAR(255) DEFAULT NULL, oldexpiration TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, newexpiration TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, action VARCHAR(255) DEFAULT NULL, mailing_list VARCHAR(255) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, origin VARCHAR(255) DEFAULT NULL, member_lidnr INT DEFAULT NULL, PRIMARY KEY (id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_de382fbbb44475ee ON auditentry (member_lidnr)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_de382fbb15c473af ON auditentry (mailing_list)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_de382fbb70e4fa78 ON auditentry (member)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE checkoutsession (id INT NOT NULL, prospective_member INT DEFAULT NULL, recovered_from_id INT DEFAULT NULL, checkoutid VARCHAR(255) NOT NULL, created TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expiration TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, paymentintentid VARCHAR(255) DEFAULT NULL, recoveryurl VARCHAR(255) DEFAULT NULL, state INT NOT NULL, PRIMARY KEY (id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_bc63300e740ee3e7 ON checkoutsession (prospective_member)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_bc63300ee03e402d ON checkoutsession (recovered_from_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_bc63300e198d234 ON checkoutsession (checkoutid)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE configitem (id INT NOT NULL, namespace VARCHAR(255) NOT NULL, key VARCHAR(255) NOT NULL, valuestring VARCHAR(255) DEFAULT NULL, valuedate TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, createdat TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updatedat TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, valuebool BOOLEAN DEFAULT NULL, version INT DEFAULT 1 NOT NULL, PRIMARY KEY (id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX configitem_unique_idx ON configitem (namespace, key)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE decision (meeting_type VARCHAR(255) NOT NULL, meeting_number INT NOT NULL, point INT NOT NULL, number INT NOT NULL, c_meeting_type VARCHAR(255) DEFAULT NULL, c_meeting_number INT DEFAULT NULL, c_point INT DEFAULT NULL, c_number INT DEFAULT NULL, PRIMARY KEY (meeting_type, meeting_number, point, number))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_7ddadc1e602faffb96f82e16 ON decision (meeting_type, meeting_number)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_7ddadc1e140758e56160025eab6d371dc9895f98 ON decision (c_meeting_type, c_meeting_number, c_point, c_number)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE listmonkmailinglist (id INT NOT NULL, name VARCHAR(255) NOT NULL, lastseen TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, lastcheck TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE mailinglist (name VARCHAR(255) NOT NULL, nl_description TEXT NOT NULL, en_description TEXT NOT NULL, onform BOOLEAN NOT NULL, defaultsub BOOLEAN NOT NULL, mailmanid VARCHAR(255) DEFAULT NULL, listmonkid INT DEFAULT NULL, PRIMARY KEY (name))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_fd864c3afd6980d2 ON mailinglist (mailmanid)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_fd864c3ab97ed0d8 ON mailinglist (listmonkid)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE mailinglistmember (email VARCHAR(255) NOT NULL, member INT DEFAULT NULL, lastsyncon TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, lastsyncsuccess BOOLEAN NOT NULL, tobecreated BOOLEAN NOT NULL, tobedeleted BOOLEAN NOT NULL, mailinglist VARCHAR(255) NOT NULL, PRIMARY KEY (mailinglist, email))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_3a8467a97b1ac3ed ON mailinglistmember (mailinglist)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_3a8467a970e4fa78 ON mailinglistmember (member)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX mailinglistmember_unique_idx ON mailinglistmember (mailinglist, member, email)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE mailmanmailinglist (id VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, lastseen TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, lastcheck TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE meeting (type VARCHAR(255) NOT NULL, number INT NOT NULL, date DATE NOT NULL, PRIMARY KEY (type, number))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE member (lidnr INT NOT NULL, email VARCHAR(255) DEFAULT NULL, lastname VARCHAR(255) NOT NULL, middlename VARCHAR(255) NOT NULL, initials VARCHAR(255) NOT NULL, firstname VARCHAR(255) NOT NULL, studentnumber VARCHAR(255) DEFAULT NULL, study VARCHAR(255) NOT NULL, changedon DATE NOT NULL, lastcheckedon DATE DEFAULT NULL, birth DATE NOT NULL, supremum VARCHAR(255) DEFAULT NULL, hidden BOOLEAN DEFAULT false NOT NULL, deleted BOOLEAN DEFAULT false NOT NULL, PRIMARY KEY (lidnr))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE membership (member_lidnr INT NOT NULL, startdate TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, enddate DATE NOT NULL, paid INT NOT NULL, type VARCHAR(255) NOT NULL, PRIMARY KEY (member_lidnr, startdate))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX membership_member_idx ON membership (member_lidnr)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE memberupdate (lidnr INT NOT NULL, requesteddate DATE NOT NULL, email VARCHAR(255) NOT NULL, lastname VARCHAR(255) NOT NULL, middlename VARCHAR(255) NOT NULL, initials VARCHAR(255) NOT NULL, firstname VARCHAR(255) NOT NULL, PRIMARY KEY (lidnr))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE prospectivemember (lidnr INT NOT NULL, email VARCHAR(255) NOT NULL, lastname VARCHAR(255) NOT NULL, middlename VARCHAR(255) NOT NULL, initials VARCHAR(255) NOT NULL, firstname VARCHAR(255) NOT NULL, studentnumber VARCHAR(255) DEFAULT NULL, study VARCHAR(255) NOT NULL, changedon DATE NOT NULL, birth DATE NOT NULL, paid INT NOT NULL, country VARCHAR(255) NOT NULL, street VARCHAR(255) NOT NULL, number VARCHAR(255) NOT NULL, postalcode VARCHAR(255) NOT NULL, city VARCHAR(255) NOT NULL, phone VARCHAR(255) NOT NULL, lists TEXT DEFAULT NULL, PRIMARY KEY (lidnr))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE savedquery (id INT NOT NULL, name VARCHAR(255) NOT NULL, query TEXT NOT NULL, category VARCHAR(255) NOT NULL, PRIMARY KEY (id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE subdecision (meeting_type VARCHAR(255) NOT NULL, meeting_number INT NOT NULL, decision_point INT NOT NULL, decision_number INT NOT NULL, sequence INT NOT NULL, lidnr INT DEFAULT NULL, r_meeting_type VARCHAR(255) DEFAULT NULL, r_meeting_number INT DEFAULT NULL, r_decision_point INT DEFAULT NULL, r_decision_number INT DEFAULT NULL, r_sequence INT DEFAULT NULL, type VARCHAR(255) NOT NULL, name VARCHAR(255) DEFAULT NULL, organtype VARCHAR(255) DEFAULT NULL, version VARCHAR(32) DEFAULT NULL, date DATE DEFAULT NULL, approval BOOLEAN DEFAULT NULL, changes BOOLEAN DEFAULT NULL, abbr VARCHAR(255) DEFAULT NULL, function VARCHAR(255) DEFAULT NULL, contentnl TEXT DEFAULT NULL, until DATE DEFAULT NULL, withdrawnon DATE DEFAULT NULL, purpose VARCHAR(255) DEFAULT NULL, since DATE DEFAULT NULL, boardyear INT DEFAULT NULL, contenten TEXT DEFAULT NULL, PRIMARY KEY (meeting_type, meeting_number, decision_point, decision_number, sequence))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_f0d6ee40efba85ff292fad512f37b76a76ce187 ON subdecision (r_meeting_type, r_meeting_number, r_decision_point, r_decision_number)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_f0d6ee40602faffb96f82e1690e0342def6be237 ON subdecision (meeting_type, meeting_number, decision_point, decision_number)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_f0d6ee40d665e01d ON subdecision (lidnr)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_f0d6ee40efba85ff292fad512f37b76a76ce1878b79bb36 ON subdecision (r_meeting_type, r_meeting_number, r_decision_point, r_decision_number, r_sequence)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_f0d6ee40efba85ff292fad51 ON subdecision (r_meeting_type, r_meeting_number)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE actionlink ADD CONSTRAINT fk_a952b2a5740ee3e7 FOREIGN KEY (prospective_member) REFERENCES prospectivemember (lidnr) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE actionlink ADD CONSTRAINT fk_a952b2a570e4fa78 FOREIGN KEY (member) REFERENCES member (lidnr) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE address ADD CONSTRAINT fk_c2f3561dd665e01d FOREIGN KEY (lidnr) REFERENCES member (lidnr) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE auditentry ADD CONSTRAINT fk_de382fbbb44475ee FOREIGN KEY (member_lidnr) REFERENCES member (lidnr) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE auditentry ADD CONSTRAINT fk_de382fbb15c473af FOREIGN KEY (mailing_list) REFERENCES mailinglist (name) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE auditentry ADD CONSTRAINT fk_de382fbb70e4fa78 FOREIGN KEY (member) REFERENCES member (lidnr) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE checkoutsession ADD CONSTRAINT fk_bc63300ee03e402d FOREIGN KEY (recovered_from_id) REFERENCES checkoutsession (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE checkoutsession ADD CONSTRAINT fk_bc63300e740ee3e7 FOREIGN KEY (prospective_member) REFERENCES prospectivemember (lidnr) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE decision ADD CONSTRAINT fk_7ddadc1e140758e56160025eab6d371dc9895f98 FOREIGN KEY (c_meeting_type, c_meeting_number, c_point, c_number) REFERENCES decision (meeting_type, meeting_number, point, number) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE decision ADD CONSTRAINT fk_7ddadc1e602faffb96f82e16 FOREIGN KEY (meeting_type, meeting_number) REFERENCES meeting (type, number) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE mailinglist ADD CONSTRAINT fk_fd864c3ab97ed0d8 FOREIGN KEY (listmonkid) REFERENCES listmonkmailinglist (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE mailinglist ADD CONSTRAINT fk_fd864c3afd6980d2 FOREIGN KEY (mailmanid) REFERENCES mailmanmailinglist (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE mailinglistmember ADD CONSTRAINT fk_3a8467a970e4fa78 FOREIGN KEY (member) REFERENCES member (lidnr) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE mailinglistmember ADD CONSTRAINT fk_3a8467a97b1ac3ed FOREIGN KEY (mailinglist) REFERENCES mailinglist (name) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE membership ADD CONSTRAINT fk_c9a2d155b44475ee FOREIGN KEY (member_lidnr) REFERENCES member (lidnr) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE memberupdate ADD CONSTRAINT fk_6fa192d9d665e01d FOREIGN KEY (lidnr) REFERENCES member (lidnr) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE subdecision ADD CONSTRAINT fk_f0d6ee40efba85ff292fad51 FOREIGN KEY (r_meeting_type, r_meeting_number) REFERENCES meeting (type, number) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE subdecision ADD CONSTRAINT fk_f0d6ee40d665e01d FOREIGN KEY (lidnr) REFERENCES member (lidnr) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE subdecision ADD CONSTRAINT fk_f0d6ee40efba85ff292fad512f37b76a76ce187 FOREIGN KEY (r_meeting_type, r_meeting_number, r_decision_point, r_decision_number) REFERENCES decision (meeting_type, meeting_number, point, number) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE subdecision ADD CONSTRAINT fk_f0d6ee40602faffb96f82e1690e0342def6be237 FOREIGN KEY (meeting_type, meeting_number, decision_point, decision_number) REFERENCES decision (meeting_type, meeting_number, point, number) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE subdecision ADD CONSTRAINT fk_f0d6ee40efba85ff292fad512f37b76a76ce1878b79bb36 FOREIGN KEY (r_meeting_type, r_meeting_number, r_decision_point, r_decision_number, r_sequence) REFERENCES subdecision (meeting_type, meeting_number, decision_point, decision_number, sequence) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        // The inverse of a base is dropping every table in the database, which is not something a migration should
        // offer: nothing that ran this needs to step back past it, and the rollup that puts an existing database on
        // it does not run up() either. `toDropSql()` does produce a working revert, and it was verified before being
        // withheld here, but a schema this size is a poor thing to leave one mistyped command away.
        $this->throwIrreversibleMigrationException(
            'This migration is the schema base; reverting it would drop the entire database.',
        );
    }
}
