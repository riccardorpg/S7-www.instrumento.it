<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schema iniziale del database unico: sostituisce le migration del master e degli slave.
 * I dati di prova delle vecchie agenzie si portano qui con app:merge-legacy-databases.
 */
final class Version20261020100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Database unico: schema iniziale (amministrazione, notai e agenzie con company_id)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE eb_city (name VARCHAR(191) NOT NULL, code VARCHAR(32) DEFAULT NULL, id BIGINT AUTO_INCREMENT NOT NULL, province_id BIGINT DEFAULT NULL, region_id BIGINT DEFAULT NULL, INDEX IDX_B782975FE946114A (province_id), INDEX IDX_B782975F98260155 (region_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eb_company (code VARCHAR(64) NOT NULL, name VARCHAR(191) NOT NULL, client_type VARCHAR(20) DEFAULT \'company\' NOT NULL, vat_number VARCHAR(16) DEFAULT NULL, tax_code VARCHAR(32) DEFAULT NULL, address VARCHAR(191) DEFAULT NULL, civic VARCHAR(16) DEFAULT NULL, sdi VARCHAR(7) DEFAULT NULL, pec VARCHAR(191) DEFAULT NULL, logo VARCHAR(191) DEFAULT NULL, terms_accepted_at DATETIME DEFAULT NULL, id BIGINT AUTO_INCREMENT NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, city_id BIGINT DEFAULT NULL, zip_id BIGINT DEFAULT NULL, license_id BIGINT DEFAULT NULL, space_extra_id BIGINT DEFAULT NULL, UNIQUE INDEX UNIQ_F804393B77153098 (code), UNIQUE INDEX UNIQ_F804393B460F904B (license_id), INDEX IDX_F804393B8BAC62AF (city_id), INDEX IDX_F804393B7D662686 (zip_id), INDEX IDX_F804393B531242BF (space_extra_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eb_customer (name VARCHAR(190) NOT NULL, surname VARCHAR(190) NOT NULL, birth_place VARCHAR(190) DEFAULT NULL, birth_date DATE DEFAULT NULL, address VARCHAR(255) DEFAULT NULL, city VARCHAR(190) DEFAULT NULL, zip VARCHAR(10) DEFAULT NULL, email VARCHAR(190) DEFAULT NULL, phone VARCHAR(40) DEFAULT NULL, fiscal_code VARCHAR(32) DEFAULT NULL, vat_number VARCHAR(20) DEFAULT NULL, pec VARCHAR(190) DEFAULT NULL, sdi VARCHAR(7) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, id BIGINT AUTO_INCREMENT NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, company_id BIGINT NOT NULL, INDEX idx_customer_fiscal_code (fiscal_code), INDEX IDX_D6E6801C979B1AD6 (company_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eb_demo_request (account_type VARCHAR(20) NOT NULL, email VARCHAR(191) NOT NULL, business_name VARCHAR(191) DEFAULT NULL, address VARCHAR(191) DEFAULT NULL, civic VARCHAR(16) DEFAULT NULL, city VARCHAR(128) DEFAULT NULL, zip VARCHAR(5) DEFAULT NULL, sdi VARCHAR(7) DEFAULT NULL, pec VARCHAR(191) DEFAULT NULL, status VARCHAR(20) DEFAULT \'new\' NOT NULL, processed_at DATETIME DEFAULT NULL, processed_by VARCHAR(191) DEFAULT NULL, id BIGINT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, company_id BIGINT DEFAULT NULL, INDEX IDX_62CA4C7C979B1AD6 (company_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eb_document (name VARCHAR(190) NOT NULL, original_filename VARCHAR(255) DEFAULT NULL, storage_path VARCHAR(255) DEFAULT NULL, mime_type VARCHAR(120) DEFAULT NULL, size_bytes BIGINT DEFAULT NULL, agent_note LONGTEXT DEFAULT NULL, notary_note LONGTEXT DEFAULT NULL, id BIGINT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, practice_document_id BIGINT NOT NULL, INDEX IDX_8FB68463DEA77742 (practice_document_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eb_document_type (value VARCHAR(190) NOT NULL, priority INT DEFAULT 0 NOT NULL, mortgage TINYINT DEFAULT 0 NOT NULL, id BIGINT AUTO_INCREMENT NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, company_id BIGINT NOT NULL, INDEX IDX_109A0DE5979B1AD6 (company_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eb_external_account (name VARCHAR(128) NOT NULL, surname VARCHAR(128) NOT NULL, email VARCHAR(191) NOT NULL, phone VARCHAR(32) DEFAULT NULL, id BIGINT AUTO_INCREMENT NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, company_id BIGINT DEFAULT NULL, INDEX IDX_A9AB76D4979B1AD6 (company_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eb_license (creation_date DATE NOT NULL, start_date DATE DEFAULT NULL, expiration_date DATE DEFAULT NULL, notice_sent_at DATETIME DEFAULT NULL, is_admin_active TINYINT DEFAULT 1 NOT NULL, id BIGINT AUTO_INCREMENT NOT NULL, type_id BIGINT NOT NULL, INDEX IDX_E0D3C46DC54C8C93 (type_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eb_license_type (type VARCHAR(20) NOT NULL, space_included INT NOT NULL, id BIGINT AUTO_INCREMENT NOT NULL, UNIQUE INDEX UNIQ_98C7951A8CDE5729 (type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eb_permission (slug VARCHAR(64) NOT NULL, value VARCHAR(190) NOT NULL, priority INT DEFAULT 0 NOT NULL, id BIGINT AUTO_INCREMENT NOT NULL, UNIQUE INDEX UNIQ_30440E66989D9B62 (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eb_practice (number VARCHAR(60) NOT NULL, mortgage TINYINT DEFAULT 0 NOT NULL, subject LONGTEXT DEFAULT NULL, address VARCHAR(255) DEFAULT NULL, city VARCHAR(190) DEFAULT NULL, zip VARCHAR(10) DEFAULT NULL, city_ref_id INT DEFAULT NULL, zip_ref_id INT DEFAULT NULL, status VARCHAR(20) NOT NULL, id BIGINT AUTO_INCREMENT NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, notary_id BIGINT DEFAULT NULL, buyer_customer_id BIGINT DEFAULT NULL, seller_customer_id BIGINT DEFAULT NULL, mark_id BIGINT DEFAULT NULL, company_id BIGINT NOT NULL, INDEX IDX_28333A5BACC994D3 (notary_id), INDEX IDX_28333A5BDF0C0A51 (buyer_customer_id), INDEX IDX_28333A5B3E3D1044 (seller_customer_id), INDEX IDX_28333A5B4290F12B (mark_id), INDEX IDX_28333A5B979B1AD6 (company_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eb_practice_tag_map (practice_id BIGINT NOT NULL, practice_tag_id BIGINT NOT NULL, INDEX IDX_913BD58EED33821 (practice_id), INDEX IDX_913BD58E16465519 (practice_tag_id), PRIMARY KEY (practice_id, practice_tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eb_practice_staff (practice_id BIGINT NOT NULL, user_id BIGINT NOT NULL, INDEX IDX_914E070AED33821 (practice_id), INDEX IDX_914E070AA76ED395 (user_id), PRIMARY KEY (practice_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eb_practice_alert (remind_at DATE NOT NULL, message LONGTEXT NOT NULL, visible_to_notary TINYINT DEFAULT 0 NOT NULL, by_notary TINYINT DEFAULT 0 NOT NULL, visible_to_agency TINYINT DEFAULT 0 NOT NULL, id BIGINT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, practice_id BIGINT NOT NULL, INDEX IDX_C4DDB259ED33821 (practice_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eb_practice_document (label VARCHAR(190) NOT NULL, visible TINYINT DEFAULT 1 NOT NULL, status VARCHAR(20) NOT NULL, priority INT DEFAULT 0 NOT NULL, id BIGINT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, practice_id BIGINT NOT NULL, document_type_id BIGINT DEFAULT NULL, UNIQUE INDEX uniq_practice_document_type (practice_id, document_type_id), INDEX IDX_60D7679CED33821 (practice_id), INDEX IDX_60D7679C61232A4F (document_type_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eb_practice_mark (id BIGINT AUTO_INCREMENT NOT NULL, value VARCHAR(190) NOT NULL, color VARCHAR(7) DEFAULT \'#1D4ED8\' NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, company_id BIGINT NOT NULL, INDEX IDX_90749116979B1AD6 (company_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eb_practice_tag (id BIGINT AUTO_INCREMENT NOT NULL, value VARCHAR(190) NOT NULL, color VARCHAR(7) DEFAULT \'#1D4ED8\' NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, company_id BIGINT NOT NULL, INDEX IDX_BACC8B56979B1AD6 (company_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eb_province (name VARCHAR(191) NOT NULL, sign VARCHAR(8) NOT NULL, id BIGINT AUTO_INCREMENT NOT NULL, region_id BIGINT DEFAULT NULL, INDEX IDX_1D05DA1E98260155 (region_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eb_region (name VARCHAR(191) NOT NULL, id BIGINT AUTO_INCREMENT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eb_space_extra (type VARCHAR(20) NOT NULL, space INT NOT NULL, id BIGINT AUTO_INCREMENT NOT NULL, UNIQUE INDEX UNIQ_D23B92268CDE5729 (type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eb_user (is_admin TINYINT DEFAULT 0 NOT NULL, staff_role VARCHAR(16) DEFAULT \'internal\' NOT NULL, id BIGINT AUTO_INCREMENT NOT NULL, email VARCHAR(191) NOT NULL, name VARCHAR(191) DEFAULT NULL, surname VARCHAR(191) DEFAULT NULL, password VARCHAR(255) DEFAULT NULL, role VARCHAR(64) NOT NULL, theme VARCHAR(16) DEFAULT \'light\' NOT NULL, one_time_code VARCHAR(191) DEFAULT NULL, expiration_one_time_code DATETIME DEFAULT NULL, is_active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, company_id BIGINT DEFAULT NULL, UNIQUE INDEX UNIQ_174A4322E7927C74 (email), INDEX IDX_174A4322979B1AD6 (company_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eb_user_permission (value VARCHAR(2) NOT NULL, id BIGINT AUTO_INCREMENT NOT NULL, user_id BIGINT NOT NULL, permission_id BIGINT NOT NULL, UNIQUE INDEX uniq_user_permission (user_id, permission_id), INDEX IDX_15BD59CCA76ED395 (user_id), INDEX IDX_15BD59CCFED90CCA (permission_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eb_zip (code VARCHAR(16) NOT NULL, id BIGINT AUTO_INCREMENT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eb_join_table_city_zip (zip_id BIGINT NOT NULL, city_id BIGINT NOT NULL, INDEX IDX_77BC3F9B7D662686 (zip_id), INDEX IDX_77BC3F9B8BAC62AF (city_id), PRIMARY KEY (zip_id, city_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE eb_city ADD CONSTRAINT FK_B782975FE946114A FOREIGN KEY (province_id) REFERENCES eb_province (id)');
        $this->addSql('ALTER TABLE eb_city ADD CONSTRAINT FK_B782975F98260155 FOREIGN KEY (region_id) REFERENCES eb_region (id)');
        $this->addSql('ALTER TABLE eb_company ADD CONSTRAINT FK_F804393B8BAC62AF FOREIGN KEY (city_id) REFERENCES eb_city (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE eb_company ADD CONSTRAINT FK_F804393B7D662686 FOREIGN KEY (zip_id) REFERENCES eb_zip (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE eb_company ADD CONSTRAINT FK_F804393B460F904B FOREIGN KEY (license_id) REFERENCES eb_license (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE eb_company ADD CONSTRAINT FK_F804393B531242BF FOREIGN KEY (space_extra_id) REFERENCES eb_space_extra (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE eb_customer ADD CONSTRAINT FK_D6E6801C979B1AD6 FOREIGN KEY (company_id) REFERENCES eb_company (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eb_demo_request ADD CONSTRAINT FK_62CA4C7C979B1AD6 FOREIGN KEY (company_id) REFERENCES eb_company (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE eb_document ADD CONSTRAINT FK_8FB68463DEA77742 FOREIGN KEY (practice_document_id) REFERENCES eb_practice_document (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eb_document_type ADD CONSTRAINT FK_109A0DE5979B1AD6 FOREIGN KEY (company_id) REFERENCES eb_company (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eb_external_account ADD CONSTRAINT FK_A9AB76D4979B1AD6 FOREIGN KEY (company_id) REFERENCES eb_company (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE eb_license ADD CONSTRAINT FK_E0D3C46DC54C8C93 FOREIGN KEY (type_id) REFERENCES eb_license_type (id)');
        $this->addSql('ALTER TABLE eb_practice ADD CONSTRAINT FK_28333A5BACC994D3 FOREIGN KEY (notary_id) REFERENCES eb_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE eb_practice ADD CONSTRAINT FK_28333A5BDF0C0A51 FOREIGN KEY (buyer_customer_id) REFERENCES eb_customer (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE eb_practice ADD CONSTRAINT FK_28333A5B3E3D1044 FOREIGN KEY (seller_customer_id) REFERENCES eb_customer (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE eb_practice ADD CONSTRAINT FK_28333A5B4290F12B FOREIGN KEY (mark_id) REFERENCES eb_practice_mark (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE eb_practice ADD CONSTRAINT FK_28333A5B979B1AD6 FOREIGN KEY (company_id) REFERENCES eb_company (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eb_practice_tag_map ADD CONSTRAINT FK_913BD58EED33821 FOREIGN KEY (practice_id) REFERENCES eb_practice (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eb_practice_tag_map ADD CONSTRAINT FK_913BD58E16465519 FOREIGN KEY (practice_tag_id) REFERENCES eb_practice_tag (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eb_practice_staff ADD CONSTRAINT FK_914E070AED33821 FOREIGN KEY (practice_id) REFERENCES eb_practice (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eb_practice_staff ADD CONSTRAINT FK_914E070AA76ED395 FOREIGN KEY (user_id) REFERENCES eb_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eb_practice_alert ADD CONSTRAINT FK_C4DDB259ED33821 FOREIGN KEY (practice_id) REFERENCES eb_practice (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eb_practice_document ADD CONSTRAINT FK_60D7679CED33821 FOREIGN KEY (practice_id) REFERENCES eb_practice (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eb_practice_document ADD CONSTRAINT FK_60D7679C61232A4F FOREIGN KEY (document_type_id) REFERENCES eb_document_type (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE eb_practice_mark ADD CONSTRAINT FK_90749116979B1AD6 FOREIGN KEY (company_id) REFERENCES eb_company (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eb_practice_tag ADD CONSTRAINT FK_BACC8B56979B1AD6 FOREIGN KEY (company_id) REFERENCES eb_company (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eb_province ADD CONSTRAINT FK_1D05DA1E98260155 FOREIGN KEY (region_id) REFERENCES eb_region (id)');
        $this->addSql('ALTER TABLE eb_user ADD CONSTRAINT FK_174A4322979B1AD6 FOREIGN KEY (company_id) REFERENCES eb_company (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eb_user_permission ADD CONSTRAINT FK_15BD59CCA76ED395 FOREIGN KEY (user_id) REFERENCES eb_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eb_user_permission ADD CONSTRAINT FK_15BD59CCFED90CCA FOREIGN KEY (permission_id) REFERENCES eb_permission (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eb_join_table_city_zip ADD CONSTRAINT FK_77BC3F9B7D662686 FOREIGN KEY (zip_id) REFERENCES eb_zip (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eb_join_table_city_zip ADD CONSTRAINT FK_77BC3F9B8BAC62AF FOREIGN KEY (city_id) REFERENCES eb_city (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE eb_city DROP FOREIGN KEY FK_B782975FE946114A');
        $this->addSql('ALTER TABLE eb_city DROP FOREIGN KEY FK_B782975F98260155');
        $this->addSql('ALTER TABLE eb_company DROP FOREIGN KEY FK_F804393B8BAC62AF');
        $this->addSql('ALTER TABLE eb_company DROP FOREIGN KEY FK_F804393B7D662686');
        $this->addSql('ALTER TABLE eb_company DROP FOREIGN KEY FK_F804393B460F904B');
        $this->addSql('ALTER TABLE eb_company DROP FOREIGN KEY FK_F804393B531242BF');
        $this->addSql('ALTER TABLE eb_customer DROP FOREIGN KEY FK_D6E6801C979B1AD6');
        $this->addSql('ALTER TABLE eb_demo_request DROP FOREIGN KEY FK_62CA4C7C979B1AD6');
        $this->addSql('ALTER TABLE eb_document DROP FOREIGN KEY FK_8FB68463DEA77742');
        $this->addSql('ALTER TABLE eb_document_type DROP FOREIGN KEY FK_109A0DE5979B1AD6');
        $this->addSql('ALTER TABLE eb_external_account DROP FOREIGN KEY FK_A9AB76D4979B1AD6');
        $this->addSql('ALTER TABLE eb_license DROP FOREIGN KEY FK_E0D3C46DC54C8C93');
        $this->addSql('ALTER TABLE eb_practice DROP FOREIGN KEY FK_28333A5BACC994D3');
        $this->addSql('ALTER TABLE eb_practice DROP FOREIGN KEY FK_28333A5BDF0C0A51');
        $this->addSql('ALTER TABLE eb_practice DROP FOREIGN KEY FK_28333A5B3E3D1044');
        $this->addSql('ALTER TABLE eb_practice DROP FOREIGN KEY FK_28333A5B4290F12B');
        $this->addSql('ALTER TABLE eb_practice DROP FOREIGN KEY FK_28333A5B979B1AD6');
        $this->addSql('ALTER TABLE eb_practice_tag_map DROP FOREIGN KEY FK_913BD58EED33821');
        $this->addSql('ALTER TABLE eb_practice_tag_map DROP FOREIGN KEY FK_913BD58E16465519');
        $this->addSql('ALTER TABLE eb_practice_staff DROP FOREIGN KEY FK_914E070AED33821');
        $this->addSql('ALTER TABLE eb_practice_staff DROP FOREIGN KEY FK_914E070AA76ED395');
        $this->addSql('ALTER TABLE eb_practice_alert DROP FOREIGN KEY FK_C4DDB259ED33821');
        $this->addSql('ALTER TABLE eb_practice_document DROP FOREIGN KEY FK_60D7679CED33821');
        $this->addSql('ALTER TABLE eb_practice_document DROP FOREIGN KEY FK_60D7679C61232A4F');
        $this->addSql('ALTER TABLE eb_practice_mark DROP FOREIGN KEY FK_90749116979B1AD6');
        $this->addSql('ALTER TABLE eb_practice_tag DROP FOREIGN KEY FK_BACC8B56979B1AD6');
        $this->addSql('ALTER TABLE eb_province DROP FOREIGN KEY FK_1D05DA1E98260155');
        $this->addSql('ALTER TABLE eb_user DROP FOREIGN KEY FK_174A4322979B1AD6');
        $this->addSql('ALTER TABLE eb_user_permission DROP FOREIGN KEY FK_15BD59CCA76ED395');
        $this->addSql('ALTER TABLE eb_user_permission DROP FOREIGN KEY FK_15BD59CCFED90CCA');
        $this->addSql('ALTER TABLE eb_join_table_city_zip DROP FOREIGN KEY FK_77BC3F9B7D662686');
        $this->addSql('ALTER TABLE eb_join_table_city_zip DROP FOREIGN KEY FK_77BC3F9B8BAC62AF');
        $this->addSql('DROP TABLE eb_city');
        $this->addSql('DROP TABLE eb_company');
        $this->addSql('DROP TABLE eb_customer');
        $this->addSql('DROP TABLE eb_demo_request');
        $this->addSql('DROP TABLE eb_document');
        $this->addSql('DROP TABLE eb_document_type');
        $this->addSql('DROP TABLE eb_external_account');
        $this->addSql('DROP TABLE eb_license');
        $this->addSql('DROP TABLE eb_license_type');
        $this->addSql('DROP TABLE eb_permission');
        $this->addSql('DROP TABLE eb_practice');
        $this->addSql('DROP TABLE eb_practice_tag_map');
        $this->addSql('DROP TABLE eb_practice_staff');
        $this->addSql('DROP TABLE eb_practice_alert');
        $this->addSql('DROP TABLE eb_practice_document');
        $this->addSql('DROP TABLE eb_practice_mark');
        $this->addSql('DROP TABLE eb_practice_tag');
        $this->addSql('DROP TABLE eb_province');
        $this->addSql('DROP TABLE eb_region');
        $this->addSql('DROP TABLE eb_space_extra');
        $this->addSql('DROP TABLE eb_user');
        $this->addSql('DROP TABLE eb_user_permission');
        $this->addSql('DROP TABLE eb_zip');
        $this->addSql('DROP TABLE eb_join_table_city_zip');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
