<?php

declare(strict_types=1);

namespace DoctrineMigrations\Master;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Baseline dello schema del database master (registro clienti, utenti amministratori e notai, catalogo geografico), com'era prima di introdurre le migration
 * (fino ad allora lo schema si allineava con doctrine:schema:update).
 *
 * Crea solo le tabelle che mancano, con le loro chiavi esterne: su un database già allineato
 * non fa nulla e si può marcare come eseguita. Serve a far nascere da zero un database nuovo
 * e a dare un punto di partenza al tracking delle migration successive.
 */
final class Version20261013100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Baseline dello schema del master (clienti, utenti, catalogo geografico)';
    }

    public function up(Schema $schema): void
    {
        $existing = $this->connection->createSchemaManager()->listTableNames();
        $created = [];
        foreach ($this->tables() as $table => $sql) {
            if (!in_array($table, $existing, true)) {
                $this->addSql($sql);
                $created[] = $table;
            }
        }
        // Le chiavi esterne solo sulle tabelle appena create: quelle esistenti le hanno già.
        foreach ($this->foreignKeys() as $table => $statements) {
            if (in_array($table, $created, true)) {
                foreach ($statements as $sql) {
                    $this->addSql($sql);
                }
            }
        }
    }

    /**
     * Non reversibile: la baseline non sa quali tabelle ha creato lei e quali c'erano già,
     * e cancellarle tutte vorrebbe dire cancellare i dati.
     */
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('La baseline dello schema non si annulla.');
    }

    /** @return array<string, string> tabella => CREATE TABLE */
    private function tables(): array
    {
        return [
            'eb_m_user' => 'CREATE TABLE eb_m_user (id BIGINT AUTO_INCREMENT NOT NULL, email VARCHAR(191) NOT NULL, name VARCHAR(191) DEFAULT NULL, surname VARCHAR(191) DEFAULT NULL, password VARCHAR(255) DEFAULT NULL, role VARCHAR(64) NOT NULL, theme VARCHAR(16) DEFAULT \'light\' NOT NULL, one_time_code VARCHAR(191) DEFAULT NULL, expiration_one_time_code DATETIME DEFAULT NULL, is_active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_1550A316E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
            'eb_m_notary_company' => 'CREATE TABLE eb_m_notary_company (user_id BIGINT NOT NULL, company_id BIGINT NOT NULL, INDEX IDX_23246BD6A76ED395 (user_id), INDEX IDX_23246BD6979B1AD6 (company_id), PRIMARY KEY (user_id, company_id)) DEFAULT CHARACTER SET utf8mb4',
            'eb_m_company' => 'CREATE TABLE eb_m_company (code VARCHAR(64) NOT NULL, name VARCHAR(191) NOT NULL, db_name VARCHAR(191) NOT NULL, client_type VARCHAR(20) DEFAULT \'company\' NOT NULL, vat_number VARCHAR(16) DEFAULT NULL, tax_code VARCHAR(32) DEFAULT NULL, address VARCHAR(191) DEFAULT NULL, civic VARCHAR(16) DEFAULT NULL, sdi VARCHAR(7) DEFAULT NULL, pec VARCHAR(191) DEFAULT NULL, license_type VARCHAR(20) DEFAULT \'demo\' NOT NULL, license_expires_at DATE DEFAULT NULL, license_notice_sent_at DATETIME DEFAULT NULL, storage_quota_mb INT DEFAULT 5120 NOT NULL, logo VARCHAR(191) DEFAULT NULL, terms_accepted_at DATETIME DEFAULT NULL, id BIGINT AUTO_INCREMENT NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, city_id BIGINT DEFAULT NULL, zip_id BIGINT DEFAULT NULL, UNIQUE INDEX UNIQ_787A7EE377153098 (code), UNIQUE INDEX UNIQ_787A7EE3628DE0D9 (db_name), INDEX IDX_787A7EE38BAC62AF (city_id), INDEX IDX_787A7EE37D662686 (zip_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
            'eb_m_demo_request' => 'CREATE TABLE eb_m_demo_request (account_type VARCHAR(20) NOT NULL, email VARCHAR(191) NOT NULL, business_name VARCHAR(191) DEFAULT NULL, address VARCHAR(191) DEFAULT NULL, civic VARCHAR(16) DEFAULT NULL, city VARCHAR(128) DEFAULT NULL, zip VARCHAR(5) DEFAULT NULL, sdi VARCHAR(7) DEFAULT NULL, pec VARCHAR(191) DEFAULT NULL, status VARCHAR(20) DEFAULT \'new\' NOT NULL, processed_at DATETIME DEFAULT NULL, processed_by VARCHAR(191) DEFAULT NULL, id BIGINT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, company_id BIGINT DEFAULT NULL, INDEX IDX_B860C571979B1AD6 (company_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
            'eb_m_external_account' => 'CREATE TABLE eb_m_external_account (name VARCHAR(128) NOT NULL, surname VARCHAR(128) NOT NULL, email VARCHAR(191) NOT NULL, phone VARCHAR(32) DEFAULT NULL, id BIGINT AUTO_INCREMENT NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, company_id BIGINT DEFAULT NULL, INDEX IDX_87CBE1AF979B1AD6 (company_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
            'eb_m_city' => 'CREATE TABLE eb_m_city (name VARCHAR(191) NOT NULL, code VARCHAR(32) DEFAULT NULL, id BIGINT AUTO_INCREMENT NOT NULL, province_id BIGINT DEFAULT NULL, region_id BIGINT DEFAULT NULL, INDEX IDX_B598776BE946114A (province_id), INDEX IDX_B598776B98260155 (region_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
            'eb_m_province' => 'CREATE TABLE eb_m_province (name VARCHAR(191) NOT NULL, sign VARCHAR(8) NOT NULL, id BIGINT AUTO_INCREMENT NOT NULL, region_id BIGINT DEFAULT NULL, INDEX IDX_958DFEBF98260155 (region_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
            'eb_m_region' => 'CREATE TABLE eb_m_region (name VARCHAR(191) NOT NULL, id BIGINT AUTO_INCREMENT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
            'eb_m_zip' => 'CREATE TABLE eb_m_zip (code VARCHAR(16) NOT NULL, id BIGINT AUTO_INCREMENT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
            'eb_m_join_table_city_zip' => 'CREATE TABLE eb_m_join_table_city_zip (zip_id BIGINT NOT NULL, city_id BIGINT NOT NULL, INDEX IDX_5CAAC5817D662686 (zip_id), INDEX IDX_5CAAC5818BAC62AF (city_id), PRIMARY KEY (zip_id, city_id)) DEFAULT CHARACTER SET utf8mb4',
            'messenger_messages' => 'CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        ];
    }

    /** @return array<string, string[]> tabella => ALTER TABLE ... ADD CONSTRAINT */
    private function foreignKeys(): array
    {
        return [
            'eb_m_notary_company' => [
                'ALTER TABLE eb_m_notary_company ADD CONSTRAINT FK_23246BD6A76ED395 FOREIGN KEY (user_id) REFERENCES eb_m_user (id) ON DELETE CASCADE',
                'ALTER TABLE eb_m_notary_company ADD CONSTRAINT FK_23246BD6979B1AD6 FOREIGN KEY (company_id) REFERENCES eb_m_company (id) ON DELETE CASCADE',
            ],
            'eb_m_company' => [
                'ALTER TABLE eb_m_company ADD CONSTRAINT FK_787A7EE38BAC62AF FOREIGN KEY (city_id) REFERENCES eb_m_city (id) ON DELETE SET NULL',
                'ALTER TABLE eb_m_company ADD CONSTRAINT FK_787A7EE37D662686 FOREIGN KEY (zip_id) REFERENCES eb_m_zip (id) ON DELETE SET NULL',
            ],
            'eb_m_demo_request' => [
                'ALTER TABLE eb_m_demo_request ADD CONSTRAINT FK_B860C571979B1AD6 FOREIGN KEY (company_id) REFERENCES eb_m_company (id) ON DELETE SET NULL',
            ],
            'eb_m_external_account' => [
                'ALTER TABLE eb_m_external_account ADD CONSTRAINT FK_87CBE1AF979B1AD6 FOREIGN KEY (company_id) REFERENCES eb_m_company (id) ON DELETE SET NULL',
            ],
            'eb_m_city' => [
                'ALTER TABLE eb_m_city ADD CONSTRAINT FK_B598776BE946114A FOREIGN KEY (province_id) REFERENCES eb_m_province (id)',
                'ALTER TABLE eb_m_city ADD CONSTRAINT FK_B598776B98260155 FOREIGN KEY (region_id) REFERENCES eb_m_region (id)',
            ],
            'eb_m_province' => [
                'ALTER TABLE eb_m_province ADD CONSTRAINT FK_958DFEBF98260155 FOREIGN KEY (region_id) REFERENCES eb_m_region (id)',
            ],
            'eb_m_join_table_city_zip' => [
                'ALTER TABLE eb_m_join_table_city_zip ADD CONSTRAINT FK_5CAAC5817D662686 FOREIGN KEY (zip_id) REFERENCES eb_m_zip (id) ON DELETE CASCADE',
                'ALTER TABLE eb_m_join_table_city_zip ADD CONSTRAINT FK_5CAAC5818BAC62AF FOREIGN KEY (city_id) REFERENCES eb_m_city (id) ON DELETE CASCADE',
            ],
        ];
    }
}
