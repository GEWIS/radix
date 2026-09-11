<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260911081205 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The web schema as it stood at release 5.5.0, dumped from a database the 67 migrations it replaces'
            . ' had just built. A database that already ran those is rolled up onto this one rather than'
            . ' running it; see the commit that added it for the order that takes.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MariaDBPlatform,
            'This migration can only be executed on \Doctrine\DBAL\Platforms\MariaDBPlatform.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE Activity (id INT AUTO_INCREMENT NOT NULL, creator_id INT DEFAULT NULL, currentRevision_id INT DEFAULT NULL, liveRevision_id INT DEFAULT NULL, cancelledAt DATETIME DEFAULT NULL, cancelledBy_id INT DEFAULT NULL, unpublishedAt DATETIME DEFAULT NULL, unpublishedBy_id INT DEFAULT NULL, INDEX IDX_55026B0C170541A2 (cancelledBy_id), INDEX IDX_55026B0C61220EA6 (creator_id), INDEX IDX_55026B0C35E74FC1 (unpublishedBy_id), INDEX IDX_55026B0C2796CA52 (currentRevision_id), INDEX IDX_55026B0CA892657C (liveRevision_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE ActivityDateOption (id INT AUTO_INCREMENT NOT NULL, proposal_id INT NOT NULL, decidedBy_id INT DEFAULT NULL, beginsAt DATE NOT NULL, endsAt DATE NOT NULL, timeOfDay VARCHAR(32) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, position SMALLINT NOT NULL, status VARCHAR(32) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, decidedAt DATETIME DEFAULT NULL, INDEX IDX_D57D3CDC85C5BBC (decidedBy_id), INDEX activity_date_option_span (beginsAt, endsAt), INDEX IDX_D57D3CDCF4792058 (proposal_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE ActivityLabel (id INT AUTO_INCREMENT NOT NULL, name_id INT NOT NULL, UNIQUE INDEX UNIQ_22F99F9071179CD6 (name_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE ActivityLocalisedText (id INT AUTO_INCREMENT NOT NULL, valueEN LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, valueNL LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE ActivityProposal (id INT AUTO_INCREMENT NOT NULL, period_id INT NOT NULL, organ_id INT DEFAULT NULL, createdBy_id INT DEFAULT NULL, chosenOption_id INT DEFAULT NULL, activity_id INT DEFAULT NULL, decidedBy_id INT DEFAULT NULL, budgetClearedBy_id INT DEFAULT NULL, name VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, description LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, status VARCHAR(32) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, decidedAt DATETIME DEFAULT NULL, budgetClearance VARCHAR(32) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, budgetClearedAt DATETIME DEFAULT NULL, budgetRemindedAt DATETIME DEFAULT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, INDEX IDX_25B61AC4E4445171 (organ_id), UNIQUE INDEX UNIQ_25B61AC46CD264D6 (chosenOption_id), INDEX activity_proposal_period_organ (period_id, organ_id), INDEX IDX_25B61AC43174800F (createdBy_id), UNIQUE INDEX UNIQ_25B61AC481C06096 (activity_id), INDEX IDX_25B61AC485C5BBC (decidedBy_id), INDEX IDX_25B61AC4EC8B7ADE (period_id), INDEX IDX_25B61AC43B1D491C (budgetClearedBy_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE ActivityRevision (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, revisionNumber INT NOT NULL, reviewedAt DATETIME DEFAULT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, beginTime DATETIME NOT NULL, endTime DATETIME NOT NULL, category VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, requireGEFLITST TINYINT NOT NULL, requireZettle TINYINT NOT NULL, author_id INT DEFAULT NULL, authorCompanyUser_id INT DEFAULT NULL, reviewer_id INT DEFAULT NULL, activity_id INT NOT NULL, previousRevision_id INT DEFAULT NULL, name_id INT NOT NULL, location_id INT NOT NULL, costs_id INT NOT NULL, description_id INT NOT NULL, organ_id INT DEFAULT NULL, company_id INT DEFAULT NULL, version INT DEFAULT 1 NOT NULL, lastEditedBy_id INT DEFAULT NULL, lastEditedByCompanyUser_id INT DEFAULT NULL, submittedAt DATETIME DEFAULT NULL, INDEX IDX_F7309B7A102DD120 (lastEditedByCompanyUser_id), INDEX IDX_F7309B7AE4445171 (organ_id), INDEX IDX_F7309B7A70574616 (reviewer_id), UNIQUE INDEX UNIQ_F7309B7AD9F966B (description_id), UNIQUE INDEX UNIQ_F7309B7A71179CD6 (name_id), INDEX IDX_F7309B7A979B1AD6 (company_id), INDEX IDX_F7309B7A81C06096 (activity_id), INDEX IDX_F7309B7AF675F31B (author_id), UNIQUE INDEX UNIQ_F7309B7A64D218E (location_id), INDEX IDX_F7309B7AA19E445F (lastEditedBy_id), INDEX IDX_F7309B7A8F2D4199 (previousRevision_id), INDEX IDX_F7309B7AFD16CEE4 (authorCompanyUser_id), UNIQUE INDEX UNIQ_F7309B7A27D66E0D (costs_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE ActivityRevisionComment (body LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, id INT AUTO_INCREMENT NOT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, author_id INT DEFAULT NULL, revision_id INT NOT NULL, authorCompanyUser_id INT DEFAULT NULL, INDEX IDX_DEE0948DFD16CEE4 (authorCompanyUser_id), INDEX IDX_DEE0948DF675F31B (author_id), INDEX IDX_DEE0948D1DFA7C8F (revision_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE ActivityRevisionEdit (editedAt DATETIME NOT NULL, changedFields JSON NOT NULL, id INT AUTO_INCREMENT NOT NULL, revision_id INT NOT NULL, editor_id INT DEFAULT NULL, INDEX IDX_285C37811DFA7C8F (revision_id), INDEX IDX_285C37816995AC4C (editor_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE ActivityRevisionLabelAssignment (activityrevision_id INT NOT NULL, activitylabel_id INT NOT NULL, INDEX IDX_AD4B45A22B53B2FF (activityrevision_id), INDEX IDX_AD4B45A247A3B8A4 (activitylabel_id), PRIMARY KEY (activityrevision_id, activitylabel_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE Address (type VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, lidnr INT NOT NULL, country VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, street VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, number VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, postalCode VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, city VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, phone VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, INDEX IDX_C2F3561DD665E01D (lidnr), PRIMARY KEY (lidnr, type)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE Album (id INT AUTO_INCREMENT NOT NULL, parent_id INT DEFAULT NULL, startDateTime DATETIME DEFAULT NULL, endDateTime DATETIME DEFAULT NULL, name VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, coverPath VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, published TINYINT NOT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, INDEX IDX_F8594147727ACA70 (parent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE Announcement (level VARCHAR(16) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, endsAt DATETIME NOT NULL, createdAt DATETIME NOT NULL, id INT AUTO_INCREMENT NOT NULL, title_id INT NOT NULL, body_id INT NOT NULL, UNIQUE INDEX UNIQ_558802E4A9F87BD (title_id), UNIQUE INDEX UNIQ_558802E49B621D84 (body_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE ApplicationLocalisedText (valueEN LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, valueNL LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, id INT AUTO_INCREMENT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE Authorization (id INT AUTO_INCREMENT NOT NULL, authorizer INT DEFAULT NULL, recipient INT DEFAULT NULL, meetingNumber INT NOT NULL, createdAt DATETIME NOT NULL, revokedAt DATETIME DEFAULT NULL, UNIQUE INDEX auth_idx (authorizer, recipient, meetingNumber, revokedAt), INDEX IDX_C913C01A34A1C897 (authorizer), INDEX IDX_C913C01A6804FB49 (recipient), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE BoardMember (id INT AUTO_INCREMENT NOT NULL, lidnr INT NOT NULL, r_meeting_type ENUM('BV', 'ALV', 'VV', 'Virt') CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, r_meeting_number INT DEFAULT NULL, r_decision_point INT DEFAULT NULL, r_decision_number INT DEFAULT NULL, r_sequence INT DEFAULT NULL, function VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, installDate DATE NOT NULL, releaseDate DATE DEFAULT NULL, dischargeDate DATE DEFAULT NULL, INDEX IDX_D9517B2ED665E01D (lidnr), UNIQUE INDEX installationDec_uniq (r_meeting_type, r_meeting_number, r_decision_point, r_decision_number, r_sequence), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE CareerLocalisedText (id INT AUTO_INCREMENT NOT NULL, valueEN LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, valueNL LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE Company (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, slugName VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, published TINYINT NOT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, currentRevision_id INT DEFAULT NULL, liveRevision_id INT DEFAULT NULL, primaryContact_id INT DEFAULT NULL, INDEX IDX_800230D32796CA52 (currentRevision_id), INDEX IDX_800230D3A892657C (liveRevision_id), INDEX IDX_800230D32FFB3A60 (primaryContact_id), UNIQUE INDEX company_slug_uniq (slugName), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE CompanyAuditLog (verb ENUM('company_created', 'representative_invited', 'invite_resent', 'invite_revoked', 'representative_joined', 'representative_disabled', 'representative_enabled', 'representative_removed', 'primary_contact_changed', 'package_created', 'package_updated', 'package_deleted', 'banner_proposed', 'banner_approved', 'banner_rejected', 'banner_replaced', 'highlight_selection_changed', 'logo_uploaded') CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, detail VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, createdAt DATETIME NOT NULL, id INT AUTO_INCREMENT NOT NULL, company_id INT NOT NULL, actor INT DEFAULT NULL, actorCompanyUser INT DEFAULT NULL, INDEX IDX_49B3BF2EB3C28E59 (actorCompanyUser), INDEX company_audit_log_created_idx (createdAt), INDEX IDX_49B3BF2E979B1AD6 (company_id), INDEX IDX_49B3BF2E447556F9 (actor), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE CompanyHighlightPackageVacancy (companyhighlightpackage_id INT NOT NULL, vacancy_id INT NOT NULL, INDEX IDX_48FA547F433B78C4 (vacancy_id), INDEX IDX_48FA547F8C97BFF1 (companyhighlightpackage_id), PRIMARY KEY (companyhighlightpackage_id, vacancy_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE CompanyPackage (id INT AUTO_INCREMENT NOT NULL, company_id INT DEFAULT NULL, article_id INT DEFAULT NULL, contractNumber VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, starts DATE NOT NULL, expires DATE NOT NULL, published TINYINT NOT NULL, packageType VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, image VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, format VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, pendingImage VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, pendingImageSubmittedAt DATETIME DEFAULT NULL, pendingImageSubmittedBy_id INT DEFAULT NULL, INDEX IDX_181DA5271E68BA3B (pendingImageSubmittedBy_id), INDEX IDX_181DA527979B1AD6 (company_id), INDEX IDX_181DA5277294869C (article_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE CompanyRevision (status VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, revisionNumber INT NOT NULL, reviewedAt DATETIME DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, squareLogo VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, contactName VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, contactAddress VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, contactEmail VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, contactPhone VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, author_id INT DEFAULT NULL, authorCompanyUser_id INT DEFAULT NULL, reviewer_id INT DEFAULT NULL, company_id INT NOT NULL, previousRevision_id INT DEFAULT NULL, slogan_id INT NOT NULL, description_id INT NOT NULL, website_id INT NOT NULL, version INT DEFAULT 1 NOT NULL, lastEditedBy_id INT DEFAULT NULL, lastEditedByCompanyUser_id INT DEFAULT NULL, bannerLogo VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, submittedAt DATETIME DEFAULT NULL, INDEX IDX_48CAB2AE8F2D4199 (previousRevision_id), INDEX IDX_48CAB2AEFD16CEE4 (authorCompanyUser_id), UNIQUE INDEX UNIQ_48CAB2AED9F966B (description_id), INDEX IDX_48CAB2AEA19E445F (lastEditedBy_id), INDEX IDX_48CAB2AE70574616 (reviewer_id), UNIQUE INDEX UNIQ_48CAB2AE18F45C82 (website_id), INDEX IDX_48CAB2AE102DD120 (lastEditedByCompanyUser_id), INDEX IDX_48CAB2AE979B1AD6 (company_id), INDEX IDX_48CAB2AEF675F31B (author_id), UNIQUE INDEX UNIQ_48CAB2AE26C79F4B (slogan_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE CompanyRevisionComment (body LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, id INT AUTO_INCREMENT NOT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, author_id INT DEFAULT NULL, revision_id INT NOT NULL, authorCompanyUser_id INT DEFAULT NULL, INDEX IDX_E65AF115F675F31B (author_id), INDEX IDX_E65AF1151DFA7C8F (revision_id), INDEX IDX_E65AF115FD16CEE4 (authorCompanyUser_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE CompanySocialLink (id INT AUTO_INCREMENT NOT NULL, revision_id INT NOT NULL, platform VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, handle VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, INDEX IDX_AAD03511DFA7C8F (revision_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE CompanyUser (id INT AUTO_INCREMENT NOT NULL, password VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, passwordChangedOn DATETIME DEFAULT NULL, forceReloginAt DATETIME DEFAULT NULL, totpSecret LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, backupCodes LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, email VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, name VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, disabledAt DATETIME DEFAULT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, company_id INT NOT NULL, INDEX IDX_E2A56B32979B1AD6 (company_id), UNIQUE INDEX company_user_email_uniq (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE CompanyUserInvite (email VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, name VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, id INT AUTO_INCREMENT NOT NULL, selector VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, hashedToken VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, expiresAt DATETIME NOT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, company_id INT NOT NULL, invitedBy INT DEFAULT NULL, INDEX IDX_B7CD18E2D709EC86 (invitedBy), INDEX IDX_company_user_invite_selector (selector), UNIQUE INDEX company_user_invite_email_uniq (email), INDEX IDX_B7CD18E2979B1AD6 (company_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE Course (code VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, name VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, PRIMARY KEY (code)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE CourseDocument (id INT AUTO_INCREMENT NOT NULL, course_code VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, date DATE NOT NULL, language VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, path VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, scanned TINYINT NOT NULL, type VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, examType VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, author VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, flattenStatus VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, flattenedAt DATETIME DEFAULT NULL, flattenError LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, INDEX IDX_90F07469BFB7ED9E (course_code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE CourseDocumentDownload (id INT AUTO_INCREMENT NOT NULL, document_id INT NOT NULL, requested_by INT DEFAULT NULL, token BINARY(16) NOT NULL, requestedByName VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, requestedFrom VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, requestedAt DATETIME NOT NULL, collectedAt DATETIME DEFAULT NULL, status VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, path VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, INDEX IDX_927B918718C491A5 (requested_by), UNIQUE INDEX UNIQ_927B91875F37A13B (token), INDEX IDX_927B9187C33F7837 (document_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE CourseDocumentPage (id INT AUTO_INCREMENT NOT NULL, document_id INT NOT NULL, pageNumber INT NOT NULL, path VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, width INT NOT NULL, height INT NOT NULL, UNIQUE INDEX UNIQ_455D13E2C33F78377D850928 (document_id, pageNumber), INDEX IDX_455D13E2C33F7837 (document_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE CourseDocumentStaging (id INT AUTO_INCREMENT NOT NULL, uploaded_by INT DEFAULT NULL, originalFilename VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, path VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, uploadedAt DATETIME NOT NULL, courseCode VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, date DATE DEFAULT NULL, language VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, type VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, examType VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, author VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, scanned TINYINT NOT NULL, INDEX IDX_90647A1E3E73126 (uploaded_by), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE DataExportRequest (id INT AUTO_INCREMENT NOT NULL, requestedAt DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_2E59BBF8A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE Decision (meeting_type ENUM('BV', 'ALV', 'VV', 'Virt') CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, meeting_number INT NOT NULL, point INT NOT NULL, number INT NOT NULL, contentNL LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, contentEN LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, c_meeting_type ENUM('BV', 'ALV', 'VV', 'Virt') CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, c_meeting_number INT DEFAULT NULL, c_point INT DEFAULT NULL, c_number INT DEFAULT NULL, INDEX IDX_7DDADC1E602FAFFB96F82E16 (meeting_type, meeting_number), INDEX IDX_7DDADC1E140758E56160025EAB6D371DC9895F98 (c_meeting_type, c_meeting_number, c_point, c_number), PRIMARY KEY (meeting_type, meeting_number, point, number)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE DecisionLocalisedText (id INT AUTO_INCREMENT NOT NULL, valueEN LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, valueNL LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE EditLock (resourceId VARCHAR(32) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, resourceKey INT NOT NULL, acquiredAt DATETIME NOT NULL, lastPingAt DATETIME NOT NULL, id INT AUTO_INCREMENT NOT NULL, lockedBy_id INT DEFAULT NULL, lockedByCompanyUser_id INT DEFAULT NULL, INDEX IDX_5EF688A71E253D71 (lockedBy_id), INDEX IDX_5EF688A7B7C41E8 (lockedByCompanyUser_id), UNIQUE INDEX edit_lock_resource_uniq (resourceId, resourceKey), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE ExternalApp (id INT AUTO_INCREMENT NOT NULL, appId VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, secret VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, callback VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, url VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, claims LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, enabled TINYINT DEFAULT 1 NOT NULL, expiresAt DATETIME DEFAULT NULL, signature VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT 'EdDSA' NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, tokenDelivery VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT 'fragment' NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE ExternalAppAuthentication (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, app_id INT NOT NULL, time DATETIME NOT NULL, INDEX IDX_2AF91860A76ED395 (user_id), INDEX IDX_2AF918607987212D (app_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE ExternalSignupVerification (purpose VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, selector VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, hashedToken VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, expiresAt DATETIME NOT NULL, id INT AUTO_INCREMENT NOT NULL, external_signup_id INT NOT NULL, INDEX IDX_D55257277B3A307A (external_signup_id), INDEX IDX_external_signup_verification_selector (selector), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE FrontpageLocalisedText (id INT AUTO_INCREMENT NOT NULL, valueEN LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, valueNL LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE HiddenPhoto (id INT AUTO_INCREMENT NOT NULL, member_id INT NOT NULL, photo_id INT NOT NULL, INDEX IDX_87F4497C7E9E4C8C (photo_id), UNIQUE INDEX hidden_photo_uniq (member_id, photo_id), INDEX IDX_87F4497C7597D3FE (member_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE Keyholder (id INT AUTO_INCREMENT NOT NULL, lidnr INT NOT NULL, r_meeting_type ENUM('BV', 'ALV', 'VV', 'Virt') CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, r_meeting_number INT DEFAULT NULL, r_decision_point INT DEFAULT NULL, r_decision_number INT DEFAULT NULL, r_sequence INT DEFAULT NULL, expirationDate DATE NOT NULL, withdrawnDate DATE DEFAULT NULL, INDEX IDX_3C5F7B4DD665E01D (lidnr), UNIQUE INDEX grantingDec_uniq (r_meeting_type, r_meeting_number, r_decision_point, r_decision_number, r_sequence), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE KnownDevice (userIdentifier VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, firewallName VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, fingerprint VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, browser VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, operatingSystem VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, firstSeenAt DATETIME NOT NULL, lastSeenAt DATETIME NOT NULL, id INT AUTO_INCREMENT NOT NULL, UNIQUE INDEX UNIQ_1047E8DB750FAC4349EB2E5FC0B754A (userIdentifier, firewallName, fingerprint), INDEX IDX_1047E8DB72C2D33A (lastSeenAt), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE KnownDeviceToken (userIdentifier VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, firewallName VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, firstSeenAt DATETIME NOT NULL, lastSeenAt DATETIME NOT NULL, id INT AUTO_INCREMENT NOT NULL, tokenHash VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, browser VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, operatingSystem VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, INDEX IDX_4C0F20B472C2D33A (lastSeenAt), UNIQUE INDEX UNIQ_4C0F20B4750FAC4349EB2E5E5C96920 (userIdentifier, firewallName, tokenHash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE KnownNetwork (userIdentifier VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, firewallName VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, firstSeenAt DATETIME NOT NULL, lastSeenAt DATETIME NOT NULL, id INT AUTO_INCREMENT NOT NULL, fingerprint VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, INDEX IDX_7B9C4A9972C2D33A (lastSeenAt), UNIQUE INDEX UNIQ_7B9C4A99750FAC4349EB2E5FC0B754A (userIdentifier, firewallName, fingerprint), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE MailingList (name VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, nl_description LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, en_description LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, PRIMARY KEY (name)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE MailingListMember (email VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, member INT NOT NULL, mailingList VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, INDEX IDX_3A8467A97B1AC3ED (mailingList), INDEX IDX_3A8467A970E4FA78 (member), PRIMARY KEY (mailingList, email)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE MaintenanceWindow (status VARCHAR(16) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, startsAt DATETIME DEFAULT NULL, endsAt DATETIME DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE Meeting (type ENUM('BV', 'ALV', 'VV', 'Virt') CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, number INT NOT NULL, date DATE NOT NULL, PRIMARY KEY (type, number)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE MeetingActivityLog (verb ENUM('point_created', 'point_updated', 'point_deleted', 'points_reordered', 'document_uploaded', 'document_version_uploaded', 'document_renamed', 'document_deleted', 'documents_reordered', 'minutes_uploaded', 'minutes_deleted', 'reference_selected', 'reference_deselected', 'reference_pinned', 'reference_carried_over', 'reference_document_created', 'reference_document_renamed', 'reference_document_deleted', 'details_updated') CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, subject VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, createdAt DATETIME NOT NULL, id INT AUTO_INCREMENT NOT NULL, actor INT DEFAULT NULL, meeting_type ENUM('BV', 'ALV', 'VV', 'Virt') CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, meeting_number INT DEFAULT NULL, INDEX meeting_activity_log_created_idx (createdAt), INDEX IDX_51F2B2A2447556F9 (actor), INDEX IDX_51F2B2A2602FAFFB96F82E16 (meeting_type, meeting_number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE MeetingDocument (name VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, displayPosition INT DEFAULT 0 NOT NULL, id INT AUTO_INCREMENT NOT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, meeting_type ENUM('BV', 'ALV', 'VV', 'Virt') CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, meeting_number INT NOT NULL, point_id INT DEFAULT NULL, INDEX IDX_45407F4E602FAFFB96F82E16 (meeting_type, meeting_number), INDEX IDX_45407F4EC028CEA2 (point_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE MeetingDocumentVersion (versionLabel VARCHAR(32) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, path VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, uploadedAt DATETIME DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, uploadedBy INT DEFAULT NULL, document_id INT NOT NULL, INDEX IDX_6AD8AB29FE59E127 (uploadedBy), INDEX IDX_6AD8AB29C33F7837 (document_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE MeetingLocalDetails (meeting_type ENUM('BV', 'ALV', 'VV', 'Virt') CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, meeting_number INT NOT NULL, startTime TIME DEFAULT NULL, location VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, PRIMARY KEY (meeting_type, meeting_number)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE MeetingMinutes (meeting_type ENUM('BV', 'ALV', 'VV', 'Virt') CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, meeting_number INT NOT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, PRIMARY KEY (meeting_type, meeting_number)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE MeetingMinutesVersion (versionLabel VARCHAR(32) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, path VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, uploadedAt DATETIME DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, uploadedBy INT DEFAULT NULL, meeting_type ENUM('BV', 'ALV', 'VV', 'Virt') CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, meeting_number INT NOT NULL, INDEX IDX_4BA4C405FE59E127 (uploadedBy), INDEX IDX_4BA4C405602FAFFB96F82E16 (meeting_type, meeting_number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE MeetingPoint (number VARCHAR(16) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, title VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, displayPosition INT DEFAULT 0 NOT NULL, id INT AUTO_INCREMENT NOT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, meeting_type ENUM('BV', 'ALV', 'VV', 'Virt') CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, meeting_number INT NOT NULL, INDEX IDX_DF818F11602FAFFB96F82E16 (meeting_type, meeting_number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE MeetingReferenceSelection (id INT AUTO_INCREMENT NOT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, meeting_type ENUM('BV', 'ALV', 'VV', 'Virt') CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, meeting_number INT NOT NULL, referenceDocument_id INT NOT NULL, pinnedVersion_id INT NOT NULL, INDEX IDX_2DFDBAF372F632D7 (referenceDocument_id), INDEX IDX_2DFDBAF33551486 (pinnedVersion_id), INDEX IDX_2DFDBAF3602FAFFB96F82E16 (meeting_type, meeting_number), UNIQUE INDEX meeting_reference_unique (meeting_type, meeting_number, referenceDocument_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE Member (lidnr INT NOT NULL, email VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, lastName VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, middleName VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, initials VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, firstName VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, generation INT NOT NULL, type VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, changedOn DATE NOT NULL, membershipEndsOn DATE DEFAULT NULL, birth DATE NOT NULL, expiration DATE NOT NULL, supremum VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, hidden TINYINT DEFAULT 0 NOT NULL, deleted TINYINT DEFAULT 0 NOT NULL, study VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, PRIMARY KEY (lidnr)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, headers LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, queue_name VARCHAR(190) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE NewsItem (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, pinned TINYINT NOT NULL, category VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, title_id INT NOT NULL, content_id INT NOT NULL, UNIQUE INDEX UNIQ_B6839EAEA9F87BD (title_id), UNIQUE INDEX UNIQ_B6839EAE84A0A3ED (content_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE Notification (type VARCHAR(64) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, subjectId INT DEFAULT NULL, level VARCHAR(16) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, createdAt DATETIME NOT NULL, id INT AUTO_INCREMENT NOT NULL, context JSON DEFAULT NULL, recipientUser INT DEFAULT NULL, recipientCompanyUser INT DEFAULT NULL, recipientRole VARCHAR(32) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, UNIQUE INDEX notification_type_subject (type, subjectId), INDEX IDX_A765AD32F9EED134 (recipientCompanyUser), INDEX notification_created_at (createdAt), INDEX notification_recipient_user (recipientUser, createdAt), INDEX IDX_A765AD325529DE9F (recipientUser), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE NotificationEmailSubscription (category VARCHAR(64) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, frequency VARCHAR(16) CHARACTER SET utf8mb4 DEFAULT 'immediately' NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, lastSentAt DATETIME DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_280C6A9AA76ED39564C19C1 (user_id, category), INDEX IDX_280C6A9AA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE NotificationInteraction (readAt DATETIME DEFAULT NULL, dismissedAt DATETIME DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, notification_id INT NOT NULL, INDEX IDX_2BCD2BB2EF1A9D84 (notification_id), UNIQUE INDEX UNIQ_2BCD2BB2A76ED395EF1A9D84 (user_id, notification_id), INDEX IDX_2BCD2BB2A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE OptionPeriod (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, submissionOpensAt DATETIME NOT NULL, submissionClosesAt DATETIME NOT NULL, startsAt DATE NOT NULL, endsAt DATE NOT NULL, defaultMaxProposals INT DEFAULT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, INDEX option_period_starts_at (startsAt), INDEX option_period_submission_window (submissionOpensAt, submissionClosesAt), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE Organ (id INT AUTO_INCREMENT NOT NULL, r_meeting_type ENUM('BV', 'ALV', 'VV', 'Virt') CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, r_meeting_number INT DEFAULT NULL, r_decision_point INT DEFAULT NULL, r_decision_number INT DEFAULT NULL, r_sequence INT DEFAULT NULL, abbr VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, name VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, type VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, foundationDate DATE NOT NULL, abrogationDate DATE DEFAULT NULL, UNIQUE INDEX foundation_uniq (r_meeting_type, r_meeting_number, r_decision_point, r_decision_number, r_sequence), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE OrganInformation (id INT AUTO_INCREMENT NOT NULL, organ_id INT NOT NULL, currentRevision_id INT DEFAULT NULL, liveRevision_id INT DEFAULT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, UNIQUE INDEX organ_information_organ_uniq (organ_id), INDEX IDX_DD810E342796CA52 (currentRevision_id), INDEX IDX_DD810E34A892657C (liveRevision_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE OrganInformationRevision (id INT AUTO_INCREMENT NOT NULL, organInformation_id INT NOT NULL, previousRevision_id INT DEFAULT NULL, shortDescription_id INT NOT NULL, description_id INT NOT NULL, author_id INT DEFAULT NULL, authorCompanyUser_id INT DEFAULT NULL, reviewer_id INT DEFAULT NULL, lastEditedBy_id INT DEFAULT NULL, lastEditedByCompanyUser_id INT DEFAULT NULL, status VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, revisionNumber INT NOT NULL, reviewedAt DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, email VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, website VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, bannerSource VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, bannerCrop JSON DEFAULT NULL, bannerPath VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, logoSource VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, logoCrop JSON DEFAULT NULL, logoPath VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, submittedAt DATETIME DEFAULT NULL, INDEX IDX_61242DD5F675F31B (author_id), UNIQUE INDEX UNIQ_61242DD5D9F966B (description_id), INDEX IDX_61242DD5102DD120 (lastEditedByCompanyUser_id), INDEX IDX_61242DD5FD16CEE4 (authorCompanyUser_id), INDEX IDX_61242DD5679B608D (organInformation_id), INDEX IDX_61242DD570574616 (reviewer_id), INDEX IDX_61242DD58F2D4199 (previousRevision_id), UNIQUE INDEX UNIQ_61242DD581CB52B6 (shortDescription_id), INDEX IDX_61242DD5A19E445F (lastEditedBy_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE OrganInformationRevisionComment (id INT AUTO_INCREMENT NOT NULL, revision_id INT NOT NULL, author_id INT DEFAULT NULL, authorCompanyUser_id INT DEFAULT NULL, body LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, INDEX IDX_AEDB48581DFA7C8F (revision_id), INDEX IDX_AEDB4858F675F31B (author_id), INDEX IDX_AEDB4858FD16CEE4 (authorCompanyUser_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE OrganMember (id INT AUTO_INCREMENT NOT NULL, organ_id INT DEFAULT NULL, lidnr INT DEFAULT NULL, r_meeting_type ENUM('BV', 'ALV', 'VV', 'Virt') CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, r_meeting_number INT DEFAULT NULL, r_decision_point INT DEFAULT NULL, r_decision_number INT DEFAULT NULL, r_sequence INT DEFAULT NULL, function VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, installDate DATE NOT NULL, dischargeDate DATE DEFAULT NULL, INDEX IDX_E5CB2C7DE4445171 (organ_id), INDEX IDX_E5CB2C7DD665E01D (lidnr), UNIQUE INDEX installation_uniq (r_meeting_type, r_meeting_number, r_decision_point, r_decision_number, r_sequence), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE OrganSocialLink (id INT AUTO_INCREMENT NOT NULL, revision_id INT NOT NULL, platform VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, handle VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, INDEX IDX_3977FF801DFA7C8F (revision_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE organs_subdecisions (organ_id INT NOT NULL, meeting_type ENUM('BV', 'ALV', 'VV', 'Virt') CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, meeting_number INT NOT NULL, decision_point INT NOT NULL, decision_number INT NOT NULL, subdecision_sequence INT NOT NULL, INDEX IDX_6177E308E4445171 (organ_id), INDEX IDX_6177E308602FAFFB96F82E1690E0342DEF6BE237DD50EB88 (meeting_type, meeting_number, decision_point, decision_number, subdecision_sequence), PRIMARY KEY (organ_id, meeting_type, meeting_number, decision_point, decision_number, subdecision_sequence)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE Page (id INT AUTO_INCREMENT NOT NULL, category_id INT NOT NULL, name_id INT NOT NULL, title_id INT NOT NULL, content_id INT NOT NULL, requiredRole VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, subCategory_id INT NOT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, UNIQUE INDEX UNIQ_B438191EDB5A7180 (subCategory_id), UNIQUE INDEX UNIQ_B438191E71179CD6 (name_id), UNIQUE INDEX UNIQ_B438191EA9F87BD (title_id), UNIQUE INDEX UNIQ_B438191E12469DE2 (category_id), UNIQUE INDEX UNIQ_B438191E84A0A3ED (content_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE PasswordReset (id INT AUTO_INCREMENT NOT NULL, userType VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, selector VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, hashedToken VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, expiresAt DATETIME NOT NULL, tempHash VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, tempHashExpiresAt DATETIME DEFAULT NULL, lidnr INT DEFAULT NULL, companyUser_id INT DEFAULT NULL, INDEX IDX_password_reset_selector (selector), INDEX IDX_password_reset_temp_hash (tempHash), INDEX IDX_ED52ACECD665E01D (lidnr), INDEX IDX_ED52ACECAC7F69FF (companyUser_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE PendingNotificationEmail (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, notification_id INT NOT NULL, INDEX IDX_946F4830A76ED395 (user_id), INDEX IDX_946F4830EF1A9D84 (notification_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE PeriodProposalLimit (id INT AUTO_INCREMENT NOT NULL, period_id INT NOT NULL, organ_id INT NOT NULL, maxProposals INT NOT NULL, INDEX IDX_2F1F4328EC8B7ADE (period_id), INDEX IDX_2F1F4328E4445171 (organ_id), UNIQUE INDEX period_proposal_limit_period_organ_uniq (period_id, organ_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE Photo (id INT AUTO_INCREMENT NOT NULL, album_id INT NOT NULL, dateTime DATETIME NOT NULL, artist VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, camera VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, flash TINYINT DEFAULT NULL, focalLength DOUBLE PRECISION DEFAULT NULL, exposureTime DOUBLE PRECISION DEFAULT NULL, shutterSpeed VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, aperture VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, iso SMALLINT DEFAULT NULL, path VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, longitude DOUBLE PRECISION DEFAULT NULL, latitude DOUBLE PRECISION DEFAULT NULL, aspectRatio DOUBLE PRECISION DEFAULT NULL, INDEX IDX_D576AB1C1137ABCF (album_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE Poll (id INT AUTO_INCREMENT NOT NULL, creator_id INT DEFAULT NULL, expiryDate DATE DEFAULT NULL, currentRevision_id INT DEFAULT NULL, liveRevision_id INT DEFAULT NULL, votesAnonymisedAt DATETIME DEFAULT NULL, INDEX IDX_248E557B61220EA6 (creator_id), INDEX IDX_248E557B2796CA52 (currentRevision_id), INDEX IDX_248E557BA892657C (liveRevision_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE PollComment (id INT AUTO_INCREMENT NOT NULL, poll_id INT NOT NULL, user_lidnr INT DEFAULT NULL, author VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, content LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, createdOn DATETIME NOT NULL, parent_id INT DEFAULT NULL, INDEX IDX_C86340FF3C947C0F (poll_id), INDEX IDX_C86340FF34A71B45 (user_lidnr), INDEX IDX_C86340FF727ACA70 (parent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE PollCommentReaction (id INT AUTO_INCREMENT NOT NULL, comment_id INT NOT NULL, member_lidnr INT DEFAULT NULL, type VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, INDEX IDX_68E4C8D0F8697D13 (comment_id), INDEX IDX_68E4C8D0B44475EE (member_lidnr), UNIQUE INDEX poll_comment_reaction_uniq (comment_id, member_lidnr), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE PollOption (id INT AUTO_INCREMENT NOT NULL, text_id INT NOT NULL, anonymousVotes INT DEFAULT 0 NOT NULL, revision_id INT NOT NULL, UNIQUE INDEX UNIQ_FEFE970B698D3548 (text_id), INDEX IDX_FEFE970B1DFA7C8F (revision_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE PollRevision (id INT AUTO_INCREMENT NOT NULL, poll_id INT NOT NULL, previousRevision_id INT DEFAULT NULL, question_id INT NOT NULL, author_id INT DEFAULT NULL, authorCompanyUser_id INT DEFAULT NULL, reviewer_id INT DEFAULT NULL, lastEditedBy_id INT DEFAULT NULL, lastEditedByCompanyUser_id INT DEFAULT NULL, status VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, revisionNumber INT NOT NULL, reviewedAt DATETIME DEFAULT NULL, submittedAt DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, INDEX IDX_439C020A19E445F (lastEditedBy_id), INDEX IDX_439C020F675F31B (author_id), UNIQUE INDEX UNIQ_439C0201E27F6BF (question_id), INDEX IDX_439C020102DD120 (lastEditedByCompanyUser_id), INDEX IDX_439C020FD16CEE4 (authorCompanyUser_id), INDEX IDX_439C0203C947C0F (poll_id), INDEX poll_revision_chain_idx (poll_id, revisionNumber), INDEX IDX_439C02070574616 (reviewer_id), INDEX IDX_439C0208F2D4199 (previousRevision_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE PollRevisionComment (id INT AUTO_INCREMENT NOT NULL, revision_id INT NOT NULL, author_id INT DEFAULT NULL, authorCompanyUser_id INT DEFAULT NULL, body LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, INDEX IDX_A6CF0178F675F31B (author_id), INDEX IDX_A6CF0178FD16CEE4 (authorCompanyUser_id), INDEX IDX_A6CF01781DFA7C8F (revision_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE PollVote (option_id INT NOT NULL, user_id INT NOT NULL, poll_id INT NOT NULL, INDEX IDX_B568B088A76ED395 (user_id), INDEX IDX_B568B0883C947C0F (poll_id), INDEX IDX_B568B088A7C41D6F (option_id), UNIQUE INDEX vote_idx (poll_id, user_id), PRIMARY KEY (option_id, user_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE ProfilePhoto (id INT AUTO_INCREMENT NOT NULL, photo_id INT NOT NULL, member_id INT NOT NULL, dateTime DATETIME NOT NULL, explicit TINYINT NOT NULL, UNIQUE INDEX UNIQ_26AA8F6B7597D3FE (member_id), INDEX IDX_26AA8F6B7E9E4C8C (photo_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE ProposalLimit (id INT AUTO_INCREMENT NOT NULL, organ_id INT NOT NULL, maxProposals INT NOT NULL, UNIQUE INDEX proposal_limit_organ_uniq (organ_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE ReferenceDocument (name VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, id INT AUTO_INCREMENT NOT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE ReferenceDocumentVersion (versionLabel VARCHAR(32) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, path VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, uploadedAt DATETIME DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, uploadedBy INT DEFAULT NULL, referenceDocument_id INT NOT NULL, INDEX IDX_C40AE4F3FE59E127 (uploadedBy), INDEX IDX_C40AE4F372F632D7 (referenceDocument_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE SecurityLog (id INT AUTO_INCREMENT NOT NULL, occurredAt DATETIME NOT NULL, event VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, userIdentifier VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, firewallName VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, actorIdentifier VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, ipAddress VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, browser VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, operatingSystem VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, requestId VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, detail JSON NOT NULL, INDEX security_log_occurred_idx (occurredAt), INDEX security_log_event_idx (event), INDEX security_log_user_idx (userIdentifier, occurredAt), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE Session (id INT AUTO_INCREMENT NOT NULL, series VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, hashedToken VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, signature VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, signaturePropertiesHash VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, firewallName VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, userIdentifier VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, createdAt DATETIME NOT NULL, expiresAt DATETIME NOT NULL, lastUsedAt DATETIME NOT NULL, userAgent LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, ipAddress VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, deviceType VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, browser VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, operatingSystem VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, phpSessionId VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, previousHashedToken VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, previousTokenValidUntil DATETIME DEFAULT NULL, INDEX IDX_1FF9EC483A10012D (series), UNIQUE INDEX UNIQ_1FF9EC483A10012D (series), INDEX IDX_1FF9EC482B8C7D2F (expiresAt), INDEX IDX_1FF9EC48750FAC4349EB2E5 (userIdentifier, firewallName), INDEX IDX_1FF9EC481E699685 (phpSessionId), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE Signup (id INT AUTO_INCREMENT NOT NULL, signuplist_id INT NOT NULL, user_lidnr INT DEFAULT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, type VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, fullName VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, email VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, present TINYINT NOT NULL, drawn TINYINT NOT NULL, addedManually TINYINT DEFAULT NULL, verifiedAt DATETIME DEFAULT NULL, role_id INT DEFAULT NULL, drawPosition INT DEFAULT NULL, INDEX IDX_490F1BD9BDD669D6 (signuplist_id), INDEX IDX_490F1BD934A71B45 (user_lidnr), UNIQUE INDEX signup_list_email_uniq (signuplist_id, email), INDEX IDX_490F1BD9D60322AC (role_id), UNIQUE INDEX signup_list_user_uniq (signuplist_id, user_lidnr), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE SignupField (id INT AUTO_INCREMENT NOT NULL, signuplist_id INT NOT NULL, name_id INT NOT NULL, isSensitive TINYINT NOT NULL, type VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, minimumValue INT DEFAULT NULL, maximumValue INT DEFAULT NULL, position INT DEFAULT 0 NOT NULL, INDEX IDX_B32E149BBDD669D6 (signuplist_id), UNIQUE INDEX UNIQ_B32E149B71179CD6 (name_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE SignupFieldValue (id INT AUTO_INCREMENT NOT NULL, field_id INT NOT NULL, signup_id INT NOT NULL, option_id INT DEFAULT NULL, value VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, INDEX IDX_582037685F619F6E (signup_id), INDEX IDX_58203768A7C41D6F (option_id), INDEX IDX_58203768443707B0 (field_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE SignupList (id INT AUTO_INCREMENT NOT NULL, name_id INT NOT NULL, openDate DATETIME DEFAULT NULL, closeDate DATETIME DEFAULT NULL, onlyGEWIS TINYINT NOT NULL, displaySubscribedNumber TINYINT NOT NULL, limitedCapacity TINYINT NOT NULL, presenceTaken TINYINT NOT NULL, promoted TINYINT NOT NULL, activity_revision_id INT NOT NULL, lineageId BINARY(16) NOT NULL, capacity INT DEFAULT NULL, drawnAt DATETIME DEFAULT NULL, drawnBy_id INT DEFAULT NULL, allocationMethod VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, drawCutoffRule VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, drawCutoffAt DATETIME DEFAULT NULL, drawAfterDurationHours INT DEFAULT NULL, externalPolicyUrl VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, externalForceOrdering TINYINT NOT NULL, externalPaymentByExternal TINYINT NOT NULL, customMethodDescription LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, remindedAt DATETIME DEFAULT NULL, membershipTierOrder JSON DEFAULT NULL, membershipPriorityMode VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, membershipPlaces JSON DEFAULT NULL, cohortTierOrder JSON DEFAULT NULL, programTypeOrder JSON DEFAULT NULL, organisingCommitteePlaces INT DEFAULT NULL, INDEX IDX_274D085F13741683 (activity_revision_id), UNIQUE INDEX UNIQ_274D085F71179CD6 (name_id), INDEX IDX_274D085F4FA7FF98 (drawnBy_id), UNIQUE INDEX signup_list_revision_lineage_uniq (activity_revision_id, lineageId), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE SignupOption (id INT AUTO_INCREMENT NOT NULL, field_id INT NOT NULL, value_id INT NOT NULL, position INT DEFAULT 0 NOT NULL, isDefault TINYINT NOT NULL, UNIQUE INDEX UNIQ_580348EBF920BBA2 (value_id), INDEX IDX_580348EB443707B0 (field_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE SignupRole (name VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, minimum INT NOT NULL, position INT DEFAULT 0 NOT NULL, id INT AUTO_INCREMENT NOT NULL, signuplist_id INT NOT NULL, INDEX IDX_34EC7A2DBDD669D6 (signuplist_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE SimilarCourse (course_code VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, similar_course_code VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, INDEX IDX_C56C679ACD579B90 (similar_course_code), INDEX IDX_C56C679ABFB7ED9E (course_code), PRIMARY KEY (course_code, similar_course_code)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE SubDecision (meeting_type ENUM('BV', 'ALV', 'VV', 'Virt') CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, meeting_number INT NOT NULL, decision_point INT NOT NULL, decision_number INT NOT NULL, sequence INT NOT NULL, lidnr INT DEFAULT NULL, r_meeting_type ENUM('BV', 'ALV', 'VV', 'Virt') CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, r_meeting_number INT DEFAULT NULL, r_decision_point INT DEFAULT NULL, r_decision_number INT DEFAULT NULL, r_sequence INT DEFAULT NULL, contentNL LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, type VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, abbr VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, name VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, organType VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, function VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, version VARCHAR(32) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, date DATE DEFAULT NULL, approval TINYINT DEFAULT NULL, changes TINYINT DEFAULT NULL, until DATE DEFAULT NULL, withdrawnOn DATE DEFAULT NULL, contentEN LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, purpose VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, since DATE DEFAULT NULL, boardYear INT DEFAULT NULL, INDEX IDX_F0D6EE40D665E01D (lidnr), INDEX IDX_F0D6EE40EFBA85FF292FAD51 (r_meeting_type, r_meeting_number), INDEX IDX_F0D6EE40EFBA85FF292FAD512F37B76A76CE1878B79BB36 (r_meeting_type, r_meeting_number, r_decision_point, r_decision_number, r_sequence), INDEX IDX_F0D6EE40602FAFFB96F82E1690E0342DEF6BE237 (meeting_type, meeting_number, decision_point, decision_number), INDEX IDX_F0D6EE40EFBA85FF292FAD512F37B76A76CE187 (r_meeting_type, r_meeting_number, r_decision_point, r_decision_number), PRIMARY KEY (meeting_type, meeting_number, decision_point, decision_number, sequence)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE Tag (id INT AUTO_INCREMENT NOT NULL, photo_id INT NOT NULL, member_id INT DEFAULT NULL, positionX DOUBLE PRECISION DEFAULT NULL, positionY DOUBLE PRECISION DEFAULT NULL, dtype VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, organ_id INT DEFAULT NULL, INDEX IDX_3BC4F1637597D3FE (member_id), UNIQUE INDEX tag_organ_uniq (photo_id, organ_id), INDEX IDX_3BC4F163E4445171 (organ_id), UNIQUE INDEX tag_member_uniq (photo_id, member_id), INDEX IDX_3BC4F1637E9E4C8C (photo_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE User (lidnr INT NOT NULL, password VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, passwordChangedOn DATETIME DEFAULT NULL, forceReloginAt DATETIME DEFAULT NULL, totpSecret LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, backupCodes LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, PRIMARY KEY (lidnr)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE UserRole (id INT AUTO_INCREMENT NOT NULL, lidnr_id INT NOT NULL, role VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, expiration DATETIME DEFAULT NULL, INDEX IDX_A8503F73111993FE (lidnr_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE UserSettings (lidnr INT NOT NULL, disableCosmetics TINYINT DEFAULT 0 NOT NULL, photoTaggingOptOut TINYINT DEFAULT 0 NOT NULL, hideYearOfBirth TINYINT DEFAULT 0 NOT NULL, hideBirthdayOnFrontpage TINYINT DEFAULT 0 NOT NULL, photoVisibility VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT 'selected' NOT NULL COLLATE `utf8mb4_unicode_ci`, notificationsReadAt DATETIME DEFAULT NULL, notificationsPaused TINYINT DEFAULT 0 NOT NULL, PRIMARY KEY (lidnr)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE Vacancy (id INT AUTO_INCREMENT NOT NULL, package_id INT NOT NULL, slugName VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, published TINYINT NOT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, currentRevision_id INT DEFAULT NULL, liveRevision_id INT DEFAULT NULL, INDEX IDX_668955212796CA52 (currentRevision_id), INDEX IDX_66895521A892657C (liveRevision_id), INDEX IDX_66895521F44CABFF (package_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE VacancyLabel (id INT AUTO_INCREMENT NOT NULL, name_id INT NOT NULL, UNIQUE INDEX UNIQ_12576AE671179CD6 (name_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE VacancyRevision (status VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, revisionNumber INT NOT NULL, reviewedAt DATETIME DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, contactName VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, contactPhone VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, contactEmail VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, author_id INT DEFAULT NULL, authorCompanyUser_id INT DEFAULT NULL, reviewer_id INT DEFAULT NULL, vacancy_id INT NOT NULL, previousRevision_id INT DEFAULT NULL, name_id INT NOT NULL, location_id INT NOT NULL, website_id INT NOT NULL, description_id INT NOT NULL, attachment_id INT NOT NULL, version INT DEFAULT 1 NOT NULL, lastEditedBy_id INT DEFAULT NULL, lastEditedByCompanyUser_id INT DEFAULT NULL, category VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, startDate DATE DEFAULT NULL, endDate DATE NOT NULL, submittedAt DATETIME DEFAULT NULL, INDEX IDX_FFE914BF8F2D4199 (previousRevision_id), INDEX IDX_FFE914BFFD16CEE4 (authorCompanyUser_id), UNIQUE INDEX UNIQ_FFE914BFD9F966B (description_id), UNIQUE INDEX UNIQ_FFE914BF71179CD6 (name_id), INDEX IDX_FFE914BFA19E445F (lastEditedBy_id), INDEX IDX_FFE914BF70574616 (reviewer_id), UNIQUE INDEX UNIQ_FFE914BF464E68B (attachment_id), UNIQUE INDEX UNIQ_FFE914BF64D218E (location_id), INDEX IDX_FFE914BF102DD120 (lastEditedByCompanyUser_id), INDEX IDX_FFE914BF433B78C4 (vacancy_id), INDEX IDX_FFE914BFF675F31B (author_id), UNIQUE INDEX UNIQ_FFE914BF18F45C82 (website_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE VacancyRevisionComment (body LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, id INT AUTO_INCREMENT NOT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, author_id INT DEFAULT NULL, revision_id INT NOT NULL, authorCompanyUser_id INT DEFAULT NULL, INDEX IDX_EE72B76BF675F31B (author_id), INDEX IDX_EE72B76B1DFA7C8F (revision_id), INDEX IDX_EE72B76BFD16CEE4 (authorCompanyUser_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE VacancyRevisionLabelAssignment (vacancyrevision_id INT NOT NULL, vacancylabel_id INT NOT NULL, INDEX IDX_E72E458BD0807282 (vacancylabel_id), INDEX IDX_E72E458B84E1C68C (vacancyrevision_id), PRIMARY KEY (vacancyrevision_id, vacancylabel_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE Vote (id INT AUTO_INCREMENT NOT NULL, photo_id INT NOT NULL, voter_id INT NOT NULL, dateTime DATETIME NOT NULL, INDEX IDX_FA222A5A7E9E4C8C (photo_id), INDEX IDX_FA222A5AEBB4B8AD (voter_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE WeeklyPhoto (id INT AUTO_INCREMENT NOT NULL, photo_id INT NOT NULL, week DATE NOT NULL, hidden TINYINT NOT NULL, UNIQUE INDEX UNIQ_E5D6E8E07E9E4C8C (photo_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Activity ADD CONSTRAINT `FK_55026B0C170541A2` FOREIGN KEY (cancelledBy_id) REFERENCES Member (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Activity ADD CONSTRAINT `FK_55026B0C2796CA52` FOREIGN KEY (currentRevision_id) REFERENCES ActivityRevision (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Activity ADD CONSTRAINT `FK_55026B0C35E74FC1` FOREIGN KEY (unpublishedBy_id) REFERENCES Member (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Activity ADD CONSTRAINT `FK_55026B0C61220EA6` FOREIGN KEY (creator_id) REFERENCES Member (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Activity ADD CONSTRAINT `FK_55026B0CA892657C` FOREIGN KEY (liveRevision_id) REFERENCES ActivityRevision (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ActivityDateOption ADD CONSTRAINT `FK_D57D3CDC85C5BBC` FOREIGN KEY (decidedBy_id) REFERENCES Member (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ActivityDateOption ADD CONSTRAINT `FK_D57D3CDCF4792058` FOREIGN KEY (proposal_id) REFERENCES ActivityProposal (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ActivityLabel ADD CONSTRAINT `FK_22F99F9071179CD6` FOREIGN KEY (name_id) REFERENCES ActivityLocalisedText (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ActivityProposal ADD CONSTRAINT `FK_25B61AC43174800F` FOREIGN KEY (createdBy_id) REFERENCES Member (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ActivityProposal ADD CONSTRAINT `FK_25B61AC43B1D491C` FOREIGN KEY (budgetClearedBy_id) REFERENCES Member (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ActivityProposal ADD CONSTRAINT `FK_25B61AC46CD264D6` FOREIGN KEY (chosenOption_id) REFERENCES ActivityDateOption (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ActivityProposal ADD CONSTRAINT `FK_25B61AC481C06096` FOREIGN KEY (activity_id) REFERENCES Activity (id) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ActivityProposal ADD CONSTRAINT `FK_25B61AC485C5BBC` FOREIGN KEY (decidedBy_id) REFERENCES Member (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ActivityProposal ADD CONSTRAINT `FK_25B61AC4E4445171` FOREIGN KEY (organ_id) REFERENCES Organ (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ActivityProposal ADD CONSTRAINT `FK_25B61AC4EC8B7ADE` FOREIGN KEY (period_id) REFERENCES OptionPeriod (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ActivityRevision ADD CONSTRAINT `FK_F7309B7A102DD120` FOREIGN KEY (lastEditedByCompanyUser_id) REFERENCES CompanyUser (id) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ActivityRevision ADD CONSTRAINT `FK_F7309B7A27D66E0D` FOREIGN KEY (costs_id) REFERENCES ActivityLocalisedText (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ActivityRevision ADD CONSTRAINT `FK_F7309B7A64D218E` FOREIGN KEY (location_id) REFERENCES ActivityLocalisedText (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ActivityRevision ADD CONSTRAINT `FK_F7309B7A70574616` FOREIGN KEY (reviewer_id) REFERENCES Member (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ActivityRevision ADD CONSTRAINT `FK_F7309B7A71179CD6` FOREIGN KEY (name_id) REFERENCES ActivityLocalisedText (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ActivityRevision ADD CONSTRAINT `FK_F7309B7A81C06096` FOREIGN KEY (activity_id) REFERENCES Activity (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ActivityRevision ADD CONSTRAINT `FK_F7309B7A8F2D4199` FOREIGN KEY (previousRevision_id) REFERENCES ActivityRevision (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ActivityRevision ADD CONSTRAINT `FK_F7309B7A979B1AD6` FOREIGN KEY (company_id) REFERENCES Company (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ActivityRevision ADD CONSTRAINT `FK_F7309B7AA19E445F` FOREIGN KEY (lastEditedBy_id) REFERENCES User (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ActivityRevision ADD CONSTRAINT `FK_F7309B7AD9F966B` FOREIGN KEY (description_id) REFERENCES ActivityLocalisedText (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ActivityRevision ADD CONSTRAINT `FK_F7309B7AE4445171` FOREIGN KEY (organ_id) REFERENCES Organ (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ActivityRevision ADD CONSTRAINT `FK_F7309B7AF675F31B` FOREIGN KEY (author_id) REFERENCES Member (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ActivityRevision ADD CONSTRAINT `FK_F7309B7AFD16CEE4` FOREIGN KEY (authorCompanyUser_id) REFERENCES CompanyUser (id) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ActivityRevisionComment ADD CONSTRAINT `FK_DEE0948D1DFA7C8F` FOREIGN KEY (revision_id) REFERENCES ActivityRevision (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ActivityRevisionComment ADD CONSTRAINT `FK_DEE0948DF675F31B` FOREIGN KEY (author_id) REFERENCES User (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ActivityRevisionComment ADD CONSTRAINT `FK_DEE0948DFD16CEE4` FOREIGN KEY (authorCompanyUser_id) REFERENCES CompanyUser (id) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ActivityRevisionEdit ADD CONSTRAINT `FK_285C37811DFA7C8F` FOREIGN KEY (revision_id) REFERENCES ActivityRevision (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ActivityRevisionEdit ADD CONSTRAINT `FK_285C37816995AC4C` FOREIGN KEY (editor_id) REFERENCES User (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ActivityRevisionLabelAssignment ADD CONSTRAINT `FK_AD4B45A22B53B2FF` FOREIGN KEY (activityrevision_id) REFERENCES ActivityRevision (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ActivityRevisionLabelAssignment ADD CONSTRAINT `FK_AD4B45A247A3B8A4` FOREIGN KEY (activitylabel_id) REFERENCES ActivityLabel (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Address ADD CONSTRAINT `FK_C2F3561DD665E01D` FOREIGN KEY (lidnr) REFERENCES Member (lidnr) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Album ADD CONSTRAINT `FK_F8594147727ACA70` FOREIGN KEY (parent_id) REFERENCES Album (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Announcement ADD CONSTRAINT `FK_558802E49B621D84` FOREIGN KEY (body_id) REFERENCES ApplicationLocalisedText (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Announcement ADD CONSTRAINT `FK_558802E4A9F87BD` FOREIGN KEY (title_id) REFERENCES ApplicationLocalisedText (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Authorization ADD CONSTRAINT `FK_C913C01A34A1C897` FOREIGN KEY (authorizer) REFERENCES Member (lidnr) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Authorization ADD CONSTRAINT `FK_C913C01A6804FB49` FOREIGN KEY (recipient) REFERENCES Member (lidnr) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE BoardMember ADD CONSTRAINT `FK_D9517B2ED665E01D` FOREIGN KEY (lidnr) REFERENCES Member (lidnr)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE BoardMember ADD CONSTRAINT `FK_D9517B2EEFBA85FF292FAD512F37B76A76CE1878B79BB36` FOREIGN KEY (r_meeting_type, r_meeting_number, r_decision_point, r_decision_number, r_sequence) REFERENCES SubDecision (meeting_type, meeting_number, decision_point, decision_number, sequence)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Company ADD CONSTRAINT `FK_800230D32796CA52` FOREIGN KEY (currentRevision_id) REFERENCES CompanyRevision (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Company ADD CONSTRAINT `FK_800230D32FFB3A60` FOREIGN KEY (primaryContact_id) REFERENCES CompanyUser (id) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Company ADD CONSTRAINT `FK_800230D3A892657C` FOREIGN KEY (liveRevision_id) REFERENCES CompanyRevision (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE CompanyAuditLog ADD CONSTRAINT `FK_49B3BF2E447556F9` FOREIGN KEY (actor) REFERENCES User (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE CompanyAuditLog ADD CONSTRAINT `FK_49B3BF2E979B1AD6` FOREIGN KEY (company_id) REFERENCES Company (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE CompanyAuditLog ADD CONSTRAINT `FK_49B3BF2EB3C28E59` FOREIGN KEY (actorCompanyUser) REFERENCES CompanyUser (id) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE CompanyHighlightPackageVacancy ADD CONSTRAINT `FK_48FA547F433B78C4` FOREIGN KEY (vacancy_id) REFERENCES Vacancy (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE CompanyHighlightPackageVacancy ADD CONSTRAINT `FK_48FA547F8C97BFF1` FOREIGN KEY (companyhighlightpackage_id) REFERENCES CompanyPackage (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE CompanyPackage ADD CONSTRAINT `FK_181DA5271E68BA3B` FOREIGN KEY (pendingImageSubmittedBy_id) REFERENCES CompanyUser (id) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE CompanyPackage ADD CONSTRAINT `FK_181DA5277294869C` FOREIGN KEY (article_id) REFERENCES CareerLocalisedText (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE CompanyPackage ADD CONSTRAINT `FK_181DA527979B1AD6` FOREIGN KEY (company_id) REFERENCES Company (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE CompanyRevision ADD CONSTRAINT `FK_48CAB2AE102DD120` FOREIGN KEY (lastEditedByCompanyUser_id) REFERENCES CompanyUser (id) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE CompanyRevision ADD CONSTRAINT `FK_48CAB2AE18F45C82` FOREIGN KEY (website_id) REFERENCES CareerLocalisedText (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE CompanyRevision ADD CONSTRAINT `FK_48CAB2AE26C79F4B` FOREIGN KEY (slogan_id) REFERENCES CareerLocalisedText (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE CompanyRevision ADD CONSTRAINT `FK_48CAB2AE70574616` FOREIGN KEY (reviewer_id) REFERENCES Member (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE CompanyRevision ADD CONSTRAINT `FK_48CAB2AE8F2D4199` FOREIGN KEY (previousRevision_id) REFERENCES CompanyRevision (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE CompanyRevision ADD CONSTRAINT `FK_48CAB2AE979B1AD6` FOREIGN KEY (company_id) REFERENCES Company (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE CompanyRevision ADD CONSTRAINT `FK_48CAB2AEA19E445F` FOREIGN KEY (lastEditedBy_id) REFERENCES User (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE CompanyRevision ADD CONSTRAINT `FK_48CAB2AED9F966B` FOREIGN KEY (description_id) REFERENCES CareerLocalisedText (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE CompanyRevision ADD CONSTRAINT `FK_48CAB2AEF675F31B` FOREIGN KEY (author_id) REFERENCES Member (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE CompanyRevision ADD CONSTRAINT `FK_48CAB2AEFD16CEE4` FOREIGN KEY (authorCompanyUser_id) REFERENCES CompanyUser (id) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE CompanyRevisionComment ADD CONSTRAINT `FK_E65AF1151DFA7C8F` FOREIGN KEY (revision_id) REFERENCES CompanyRevision (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE CompanyRevisionComment ADD CONSTRAINT `FK_E65AF115F675F31B` FOREIGN KEY (author_id) REFERENCES User (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE CompanyRevisionComment ADD CONSTRAINT `FK_E65AF115FD16CEE4` FOREIGN KEY (authorCompanyUser_id) REFERENCES CompanyUser (id) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE CompanySocialLink ADD CONSTRAINT `FK_AAD03511DFA7C8F` FOREIGN KEY (revision_id) REFERENCES CompanyRevision (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE CompanyUser ADD CONSTRAINT `FK_E2A56B32979B1AD6` FOREIGN KEY (company_id) REFERENCES Company (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE CompanyUserInvite ADD CONSTRAINT `FK_B7CD18E2979B1AD6` FOREIGN KEY (company_id) REFERENCES Company (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE CompanyUserInvite ADD CONSTRAINT `FK_B7CD18E2D709EC86` FOREIGN KEY (invitedBy) REFERENCES User (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE CourseDocument ADD CONSTRAINT `FK_90F07469BFB7ED9E` FOREIGN KEY (course_code) REFERENCES Course (code)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE CourseDocumentDownload ADD CONSTRAINT `FK_927B918718C491A5` FOREIGN KEY (requested_by) REFERENCES User (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE CourseDocumentDownload ADD CONSTRAINT `FK_927B9187C33F7837` FOREIGN KEY (document_id) REFERENCES CourseDocument (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE CourseDocumentPage ADD CONSTRAINT `FK_455D13E2C33F7837` FOREIGN KEY (document_id) REFERENCES CourseDocument (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE CourseDocumentStaging ADD CONSTRAINT `FK_90647A1E3E73126` FOREIGN KEY (uploaded_by) REFERENCES User (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE DataExportRequest ADD CONSTRAINT `FK_2E59BBF8A76ED395` FOREIGN KEY (user_id) REFERENCES User (lidnr) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Decision ADD CONSTRAINT `FK_7DDADC1E140758E56160025EAB6D371DC9895F98` FOREIGN KEY (c_meeting_type, c_meeting_number, c_point, c_number) REFERENCES Decision (meeting_type, meeting_number, point, number)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Decision ADD CONSTRAINT `FK_7DDADC1E602FAFFB96F82E16` FOREIGN KEY (meeting_type, meeting_number) REFERENCES Meeting (type, number)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE EditLock ADD CONSTRAINT `FK_5EF688A71E253D71` FOREIGN KEY (lockedBy_id) REFERENCES User (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE EditLock ADD CONSTRAINT `FK_5EF688A7B7C41E8` FOREIGN KEY (lockedByCompanyUser_id) REFERENCES CompanyUser (id) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ExternalAppAuthentication ADD CONSTRAINT `FK_D9FD7EB67987212D` FOREIGN KEY (app_id) REFERENCES ExternalApp (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ExternalAppAuthentication ADD CONSTRAINT `FK_D9FD7EB6A76ED395` FOREIGN KEY (user_id) REFERENCES User (lidnr) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ExternalSignupVerification ADD CONSTRAINT `FK_D55257277B3A307A` FOREIGN KEY (external_signup_id) REFERENCES Signup (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE HiddenPhoto ADD CONSTRAINT `FK_HiddenPhoto_Member` FOREIGN KEY (member_id) REFERENCES Member (lidnr) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE HiddenPhoto ADD CONSTRAINT `FK_HiddenPhoto_Photo` FOREIGN KEY (photo_id) REFERENCES Photo (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Keyholder ADD CONSTRAINT `FK_3C5F7B4DD665E01D` FOREIGN KEY (lidnr) REFERENCES Member (lidnr)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Keyholder ADD CONSTRAINT `FK_3C5F7B4DEFBA85FF292FAD512F37B76A76CE1878B79BB36` FOREIGN KEY (r_meeting_type, r_meeting_number, r_decision_point, r_decision_number, r_sequence) REFERENCES SubDecision (meeting_type, meeting_number, decision_point, decision_number, sequence)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE MailingListMember ADD CONSTRAINT `FK_3A8467A970E4FA78` FOREIGN KEY (member) REFERENCES Member (lidnr) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE MailingListMember ADD CONSTRAINT `FK_3A8467A97B1AC3ED` FOREIGN KEY (mailingList) REFERENCES MailingList (name)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE MeetingActivityLog ADD CONSTRAINT `FK_51F2B2A2447556F9` FOREIGN KEY (actor) REFERENCES User (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE MeetingActivityLog ADD CONSTRAINT `FK_51F2B2A2602FAFFB96F82E16` FOREIGN KEY (meeting_type, meeting_number) REFERENCES Meeting (type, number)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE MeetingDocument ADD CONSTRAINT `FK_45407F4E602FAFFB96F82E16` FOREIGN KEY (meeting_type, meeting_number) REFERENCES Meeting (type, number)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE MeetingDocument ADD CONSTRAINT `FK_45407F4EC028CEA2` FOREIGN KEY (point_id) REFERENCES MeetingPoint (id) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE MeetingDocumentVersion ADD CONSTRAINT `FK_6AD8AB29C33F7837` FOREIGN KEY (document_id) REFERENCES MeetingDocument (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE MeetingDocumentVersion ADD CONSTRAINT `FK_6AD8AB29FE59E127` FOREIGN KEY (uploadedBy) REFERENCES User (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE MeetingLocalDetails ADD CONSTRAINT `FK_FB2E677602FAFFB96F82E16` FOREIGN KEY (meeting_type, meeting_number) REFERENCES Meeting (type, number)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE MeetingMinutes ADD CONSTRAINT `FK_5BE9DD26602FAFFB96F82E16` FOREIGN KEY (meeting_type, meeting_number) REFERENCES Meeting (type, number)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE MeetingMinutesVersion ADD CONSTRAINT `FK_4BA4C405602FAFFB96F82E16` FOREIGN KEY (meeting_type, meeting_number) REFERENCES MeetingMinutes (meeting_type, meeting_number)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE MeetingMinutesVersion ADD CONSTRAINT `FK_4BA4C405FE59E127` FOREIGN KEY (uploadedBy) REFERENCES User (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE MeetingPoint ADD CONSTRAINT `FK_DF818F11602FAFFB96F82E16` FOREIGN KEY (meeting_type, meeting_number) REFERENCES Meeting (type, number)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE MeetingReferenceSelection ADD CONSTRAINT `FK_2DFDBAF33551486` FOREIGN KEY (pinnedVersion_id) REFERENCES ReferenceDocumentVersion (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE MeetingReferenceSelection ADD CONSTRAINT `FK_2DFDBAF3602FAFFB96F82E16` FOREIGN KEY (meeting_type, meeting_number) REFERENCES Meeting (type, number)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE MeetingReferenceSelection ADD CONSTRAINT `FK_2DFDBAF372F632D7` FOREIGN KEY (referenceDocument_id) REFERENCES ReferenceDocument (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE NewsItem ADD CONSTRAINT `FK_B6839EAE84A0A3ED` FOREIGN KEY (content_id) REFERENCES FrontpageLocalisedText (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE NewsItem ADD CONSTRAINT `FK_B6839EAEA9F87BD` FOREIGN KEY (title_id) REFERENCES FrontpageLocalisedText (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Notification ADD CONSTRAINT `FK_A765AD32DE12AB56` FOREIGN KEY (recipientUser) REFERENCES User (lidnr) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Notification ADD CONSTRAINT `FK_A765AD32F9EED134` FOREIGN KEY (recipientCompanyUser) REFERENCES CompanyUser (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE NotificationEmailSubscription ADD CONSTRAINT `FK_280C6A9AA76ED395` FOREIGN KEY (user_id) REFERENCES User (lidnr) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE NotificationInteraction ADD CONSTRAINT `FK_2BCD2BB2A76ED395` FOREIGN KEY (user_id) REFERENCES User (lidnr) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE NotificationInteraction ADD CONSTRAINT `FK_2BCD2BB2EF1A9D84` FOREIGN KEY (notification_id) REFERENCES Notification (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Organ ADD CONSTRAINT `FK_46C39B8EEFBA85FF292FAD512F37B76A76CE1878B79BB36` FOREIGN KEY (r_meeting_type, r_meeting_number, r_decision_point, r_decision_number, r_sequence) REFERENCES SubDecision (meeting_type, meeting_number, decision_point, decision_number, sequence)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE OrganInformation ADD CONSTRAINT `FK_DD810E342796CA52` FOREIGN KEY (currentRevision_id) REFERENCES OrganInformationRevision (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE OrganInformation ADD CONSTRAINT `FK_DD810E34A892657C` FOREIGN KEY (liveRevision_id) REFERENCES OrganInformationRevision (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE OrganInformation ADD CONSTRAINT `FK_DD810E34E4445171` FOREIGN KEY (organ_id) REFERENCES Organ (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE OrganInformationRevision ADD CONSTRAINT `FK_61242DD5102DD120` FOREIGN KEY (lastEditedByCompanyUser_id) REFERENCES CompanyUser (id) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE OrganInformationRevision ADD CONSTRAINT `FK_61242DD5679B608D` FOREIGN KEY (organInformation_id) REFERENCES OrganInformation (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE OrganInformationRevision ADD CONSTRAINT `FK_61242DD570574616` FOREIGN KEY (reviewer_id) REFERENCES Member (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE OrganInformationRevision ADD CONSTRAINT `FK_61242DD581CB52B6` FOREIGN KEY (shortDescription_id) REFERENCES DecisionLocalisedText (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE OrganInformationRevision ADD CONSTRAINT `FK_61242DD58F2D4199` FOREIGN KEY (previousRevision_id) REFERENCES OrganInformationRevision (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE OrganInformationRevision ADD CONSTRAINT `FK_61242DD5A19E445F` FOREIGN KEY (lastEditedBy_id) REFERENCES User (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE OrganInformationRevision ADD CONSTRAINT `FK_61242DD5D9F966B` FOREIGN KEY (description_id) REFERENCES DecisionLocalisedText (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE OrganInformationRevision ADD CONSTRAINT `FK_61242DD5F675F31B` FOREIGN KEY (author_id) REFERENCES Member (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE OrganInformationRevision ADD CONSTRAINT `FK_61242DD5FD16CEE4` FOREIGN KEY (authorCompanyUser_id) REFERENCES CompanyUser (id) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE OrganInformationRevisionComment ADD CONSTRAINT `FK_AEDB48581DFA7C8F` FOREIGN KEY (revision_id) REFERENCES OrganInformationRevision (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE OrganInformationRevisionComment ADD CONSTRAINT `FK_AEDB4858F675F31B` FOREIGN KEY (author_id) REFERENCES User (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE OrganInformationRevisionComment ADD CONSTRAINT `FK_AEDB4858FD16CEE4` FOREIGN KEY (authorCompanyUser_id) REFERENCES CompanyUser (id) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE OrganMember ADD CONSTRAINT `FK_E5CB2C7DD665E01D` FOREIGN KEY (lidnr) REFERENCES Member (lidnr)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE OrganMember ADD CONSTRAINT `FK_E5CB2C7DE4445171` FOREIGN KEY (organ_id) REFERENCES Organ (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE OrganMember ADD CONSTRAINT `FK_E5CB2C7DEFBA85FF292FAD512F37B76A76CE1878B79BB36` FOREIGN KEY (r_meeting_type, r_meeting_number, r_decision_point, r_decision_number, r_sequence) REFERENCES SubDecision (meeting_type, meeting_number, decision_point, decision_number, sequence)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE OrganSocialLink ADD CONSTRAINT `FK_3977FF801DFA7C8F` FOREIGN KEY (revision_id) REFERENCES OrganInformationRevision (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE organs_subdecisions ADD CONSTRAINT `FK_6177E308602FAFFB96F82E1690E0342DEF6BE237DD50EB88` FOREIGN KEY (meeting_type, meeting_number, decision_point, decision_number, subdecision_sequence) REFERENCES SubDecision (meeting_type, meeting_number, decision_point, decision_number, sequence)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE organs_subdecisions ADD CONSTRAINT `FK_6177E308E4445171` FOREIGN KEY (organ_id) REFERENCES Organ (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Page ADD CONSTRAINT `FK_B438191E12469DE2` FOREIGN KEY (category_id) REFERENCES FrontpageLocalisedText (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Page ADD CONSTRAINT `FK_B438191E71179CD6` FOREIGN KEY (name_id) REFERENCES FrontpageLocalisedText (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Page ADD CONSTRAINT `FK_B438191E84A0A3ED` FOREIGN KEY (content_id) REFERENCES FrontpageLocalisedText (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Page ADD CONSTRAINT `FK_B438191EA9F87BD` FOREIGN KEY (title_id) REFERENCES FrontpageLocalisedText (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Page ADD CONSTRAINT `FK_B438191EDB5A7180` FOREIGN KEY (subCategory_id) REFERENCES FrontpageLocalisedText (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE PasswordReset ADD CONSTRAINT `FK_ED52ACECAC7F69FF` FOREIGN KEY (companyUser_id) REFERENCES CompanyUser (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE PasswordReset ADD CONSTRAINT `FK_ED52ACECD665E01D` FOREIGN KEY (lidnr) REFERENCES Member (lidnr) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE PendingNotificationEmail ADD CONSTRAINT `FK_946F4830A76ED395` FOREIGN KEY (user_id) REFERENCES User (lidnr) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE PendingNotificationEmail ADD CONSTRAINT `FK_946F4830EF1A9D84` FOREIGN KEY (notification_id) REFERENCES Notification (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE PeriodProposalLimit ADD CONSTRAINT `FK_2F1F4328E4445171` FOREIGN KEY (organ_id) REFERENCES Organ (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE PeriodProposalLimit ADD CONSTRAINT `FK_2F1F4328EC8B7ADE` FOREIGN KEY (period_id) REFERENCES OptionPeriod (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Photo ADD CONSTRAINT `FK_D576AB1C1137ABCF` FOREIGN KEY (album_id) REFERENCES Album (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Poll ADD CONSTRAINT `FK_248E557B2796CA52` FOREIGN KEY (currentRevision_id) REFERENCES PollRevision (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Poll ADD CONSTRAINT `FK_248E557B61220EA6` FOREIGN KEY (creator_id) REFERENCES Member (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Poll ADD CONSTRAINT `FK_248E557BA892657C` FOREIGN KEY (liveRevision_id) REFERENCES PollRevision (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE PollComment ADD CONSTRAINT `FK_C86340FF34A71B45` FOREIGN KEY (user_lidnr) REFERENCES Member (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE PollComment ADD CONSTRAINT `FK_C86340FF3C947C0F` FOREIGN KEY (poll_id) REFERENCES Poll (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE PollComment ADD CONSTRAINT `FK_C86340FF727ACA70` FOREIGN KEY (parent_id) REFERENCES PollComment (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE PollCommentReaction ADD CONSTRAINT `FK_68E4C8D0B44475EE` FOREIGN KEY (member_lidnr) REFERENCES Member (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE PollCommentReaction ADD CONSTRAINT `FK_68E4C8D0F8697D13` FOREIGN KEY (comment_id) REFERENCES PollComment (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE PollOption ADD CONSTRAINT `FK_FEFE970B1DFA7C8F` FOREIGN KEY (revision_id) REFERENCES PollRevision (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE PollOption ADD CONSTRAINT `FK_FEFE970B698D3548` FOREIGN KEY (text_id) REFERENCES FrontpageLocalisedText (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE PollRevision ADD CONSTRAINT `FK_439C020102DD120` FOREIGN KEY (lastEditedByCompanyUser_id) REFERENCES CompanyUser (id) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE PollRevision ADD CONSTRAINT `FK_439C0201E27F6BF` FOREIGN KEY (question_id) REFERENCES FrontpageLocalisedText (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE PollRevision ADD CONSTRAINT `FK_439C0203C947C0F` FOREIGN KEY (poll_id) REFERENCES Poll (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE PollRevision ADD CONSTRAINT `FK_439C02070574616` FOREIGN KEY (reviewer_id) REFERENCES Member (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE PollRevision ADD CONSTRAINT `FK_439C0208F2D4199` FOREIGN KEY (previousRevision_id) REFERENCES PollRevision (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE PollRevision ADD CONSTRAINT `FK_439C020A19E445F` FOREIGN KEY (lastEditedBy_id) REFERENCES User (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE PollRevision ADD CONSTRAINT `FK_439C020F675F31B` FOREIGN KEY (author_id) REFERENCES Member (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE PollRevision ADD CONSTRAINT `FK_439C020FD16CEE4` FOREIGN KEY (authorCompanyUser_id) REFERENCES CompanyUser (id) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE PollRevisionComment ADD CONSTRAINT `FK_A6CF01781DFA7C8F` FOREIGN KEY (revision_id) REFERENCES PollRevision (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE PollRevisionComment ADD CONSTRAINT `FK_A6CF0178F675F31B` FOREIGN KEY (author_id) REFERENCES User (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE PollRevisionComment ADD CONSTRAINT `FK_A6CF0178FD16CEE4` FOREIGN KEY (authorCompanyUser_id) REFERENCES CompanyUser (id) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE PollVote ADD CONSTRAINT `FK_B568B0883C947C0F` FOREIGN KEY (poll_id) REFERENCES Poll (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE PollVote ADD CONSTRAINT `FK_B568B088A76ED395` FOREIGN KEY (user_id) REFERENCES Member (lidnr) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE PollVote ADD CONSTRAINT `FK_B568B088A7C41D6F` FOREIGN KEY (option_id) REFERENCES PollOption (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ProfilePhoto ADD CONSTRAINT `FK_26AA8F6B7597D3FE` FOREIGN KEY (member_id) REFERENCES Member (lidnr) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ProfilePhoto ADD CONSTRAINT `FK_26AA8F6B7E9E4C8C` FOREIGN KEY (photo_id) REFERENCES Photo (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ProposalLimit ADD CONSTRAINT `FK_706DE9F1E4445171` FOREIGN KEY (organ_id) REFERENCES Organ (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ReferenceDocumentVersion ADD CONSTRAINT `FK_C40AE4F372F632D7` FOREIGN KEY (referenceDocument_id) REFERENCES ReferenceDocument (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ReferenceDocumentVersion ADD CONSTRAINT `FK_C40AE4F3FE59E127` FOREIGN KEY (uploadedBy) REFERENCES User (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Signup ADD CONSTRAINT `FK_490F1BD934A71B45` FOREIGN KEY (user_lidnr) REFERENCES Member (lidnr) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Signup ADD CONSTRAINT `FK_490F1BD9BDD669D6` FOREIGN KEY (signuplist_id) REFERENCES SignupList (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Signup ADD CONSTRAINT `FK_490F1BD9D60322AC` FOREIGN KEY (role_id) REFERENCES SignupRole (id) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE SignupField ADD CONSTRAINT `FK_B32E149B71179CD6` FOREIGN KEY (name_id) REFERENCES ActivityLocalisedText (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE SignupField ADD CONSTRAINT `FK_B32E149BBDD669D6` FOREIGN KEY (signuplist_id) REFERENCES SignupList (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE SignupFieldValue ADD CONSTRAINT `FK_58203768443707B0` FOREIGN KEY (field_id) REFERENCES SignupField (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE SignupFieldValue ADD CONSTRAINT `FK_582037685F619F6E` FOREIGN KEY (signup_id) REFERENCES Signup (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE SignupFieldValue ADD CONSTRAINT `FK_58203768A7C41D6F` FOREIGN KEY (option_id) REFERENCES SignupOption (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE SignupList ADD CONSTRAINT `FK_274D085F13741683` FOREIGN KEY (activity_revision_id) REFERENCES ActivityRevision (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE SignupList ADD CONSTRAINT `FK_274D085F4FA7FF98` FOREIGN KEY (drawnBy_id) REFERENCES Member (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE SignupList ADD CONSTRAINT `FK_274D085F71179CD6` FOREIGN KEY (name_id) REFERENCES ActivityLocalisedText (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE SignupOption ADD CONSTRAINT `FK_580348EB443707B0` FOREIGN KEY (field_id) REFERENCES SignupField (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE SignupOption ADD CONSTRAINT `FK_580348EBF920BBA2` FOREIGN KEY (value_id) REFERENCES ActivityLocalisedText (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE SignupRole ADD CONSTRAINT `FK_34EC7A2DBDD669D6` FOREIGN KEY (signuplist_id) REFERENCES SignupList (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE SimilarCourse ADD CONSTRAINT `FK_C56C679ABFB7ED9E` FOREIGN KEY (course_code) REFERENCES Course (code)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE SimilarCourse ADD CONSTRAINT `FK_C56C679ACD579B90` FOREIGN KEY (similar_course_code) REFERENCES Course (code)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE SubDecision ADD CONSTRAINT `FK_F0D6EE40602FAFFB96F82E1690E0342DEF6BE237` FOREIGN KEY (meeting_type, meeting_number, decision_point, decision_number) REFERENCES Decision (meeting_type, meeting_number, point, number)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE SubDecision ADD CONSTRAINT `FK_F0D6EE40D665E01D` FOREIGN KEY (lidnr) REFERENCES Member (lidnr)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE SubDecision ADD CONSTRAINT `FK_F0D6EE40EFBA85FF292FAD51` FOREIGN KEY (r_meeting_type, r_meeting_number) REFERENCES Meeting (type, number)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE SubDecision ADD CONSTRAINT `FK_F0D6EE40EFBA85FF292FAD512F37B76A76CE187` FOREIGN KEY (r_meeting_type, r_meeting_number, r_decision_point, r_decision_number) REFERENCES Decision (meeting_type, meeting_number, point, number)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE SubDecision ADD CONSTRAINT `FK_F0D6EE40EFBA85FF292FAD512F37B76A76CE1878B79BB36` FOREIGN KEY (r_meeting_type, r_meeting_number, r_decision_point, r_decision_number, r_sequence) REFERENCES SubDecision (meeting_type, meeting_number, decision_point, decision_number, sequence)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Tag ADD CONSTRAINT `FK_3BC4F1637597D3FE` FOREIGN KEY (member_id) REFERENCES Member (lidnr) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Tag ADD CONSTRAINT `FK_3BC4F1637E9E4C8C` FOREIGN KEY (photo_id) REFERENCES Photo (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Tag ADD CONSTRAINT `FK_3BC4F163E4445171` FOREIGN KEY (organ_id) REFERENCES Organ (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE User ADD CONSTRAINT `FK_2DA17977D665E01D` FOREIGN KEY (lidnr) REFERENCES Member (lidnr) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE UserRole ADD CONSTRAINT `FK_A8503F73111993FE` FOREIGN KEY (lidnr_id) REFERENCES User (lidnr) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE UserSettings ADD CONSTRAINT `FK_UserSettings_User` FOREIGN KEY (lidnr) REFERENCES User (lidnr) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Vacancy ADD CONSTRAINT `FK_668955212796CA52` FOREIGN KEY (currentRevision_id) REFERENCES VacancyRevision (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Vacancy ADD CONSTRAINT `FK_66895521A892657C` FOREIGN KEY (liveRevision_id) REFERENCES VacancyRevision (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Vacancy ADD CONSTRAINT `FK_C395A618F44CABFF` FOREIGN KEY (package_id) REFERENCES CompanyPackage (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE VacancyLabel ADD CONSTRAINT `FK_ECF91BE071179CD6` FOREIGN KEY (name_id) REFERENCES CareerLocalisedText (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE VacancyRevision ADD CONSTRAINT `FK_FFE914BF102DD120` FOREIGN KEY (lastEditedByCompanyUser_id) REFERENCES CompanyUser (id) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE VacancyRevision ADD CONSTRAINT `FK_FFE914BF18F45C82` FOREIGN KEY (website_id) REFERENCES CareerLocalisedText (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE VacancyRevision ADD CONSTRAINT `FK_FFE914BF433B78C4` FOREIGN KEY (vacancy_id) REFERENCES Vacancy (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE VacancyRevision ADD CONSTRAINT `FK_FFE914BF464E68B` FOREIGN KEY (attachment_id) REFERENCES CareerLocalisedText (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE VacancyRevision ADD CONSTRAINT `FK_FFE914BF64D218E` FOREIGN KEY (location_id) REFERENCES CareerLocalisedText (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE VacancyRevision ADD CONSTRAINT `FK_FFE914BF70574616` FOREIGN KEY (reviewer_id) REFERENCES Member (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE VacancyRevision ADD CONSTRAINT `FK_FFE914BF71179CD6` FOREIGN KEY (name_id) REFERENCES CareerLocalisedText (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE VacancyRevision ADD CONSTRAINT `FK_FFE914BF8F2D4199` FOREIGN KEY (previousRevision_id) REFERENCES VacancyRevision (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE VacancyRevision ADD CONSTRAINT `FK_FFE914BFA19E445F` FOREIGN KEY (lastEditedBy_id) REFERENCES User (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE VacancyRevision ADD CONSTRAINT `FK_FFE914BFD9F966B` FOREIGN KEY (description_id) REFERENCES CareerLocalisedText (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE VacancyRevision ADD CONSTRAINT `FK_FFE914BFF675F31B` FOREIGN KEY (author_id) REFERENCES Member (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE VacancyRevision ADD CONSTRAINT `FK_FFE914BFFD16CEE4` FOREIGN KEY (authorCompanyUser_id) REFERENCES CompanyUser (id) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE VacancyRevisionComment ADD CONSTRAINT `FK_EE72B76B1DFA7C8F` FOREIGN KEY (revision_id) REFERENCES VacancyRevision (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE VacancyRevisionComment ADD CONSTRAINT `FK_EE72B76BF675F31B` FOREIGN KEY (author_id) REFERENCES User (lidnr) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE VacancyRevisionComment ADD CONSTRAINT `FK_EE72B76BFD16CEE4` FOREIGN KEY (authorCompanyUser_id) REFERENCES CompanyUser (id) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE VacancyRevisionLabelAssignment ADD CONSTRAINT `FK_E72E458B84E1C68C` FOREIGN KEY (vacancyrevision_id) REFERENCES VacancyRevision (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE VacancyRevisionLabelAssignment ADD CONSTRAINT `FK_E72E458BD0807282` FOREIGN KEY (vacancylabel_id) REFERENCES VacancyLabel (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Vote ADD CONSTRAINT `FK_FA222A5A7E9E4C8C` FOREIGN KEY (photo_id) REFERENCES Photo (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE Vote ADD CONSTRAINT `FK_FA222A5AEBB4B8AD` FOREIGN KEY (voter_id) REFERENCES Member (lidnr) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE WeeklyPhoto ADD CONSTRAINT `FK_E5D6E8E07E9E4C8C` FOREIGN KEY (photo_id) REFERENCES Photo (id)
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

    public function isTransactional(): bool
    {
        return false;
    }
}
