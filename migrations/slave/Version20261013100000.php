<?php

declare(strict_types=1);

namespace DoctrineMigrations\Slave;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Baseline dello schema dei database delle agenzie (uno per cliente), com'era prima di introdurre le migration
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
        return 'Baseline dello schema delle agenzie (pratiche, clienti, documenti, staff)';
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
            'eb_s_customer' => 'CREATE TABLE eb_s_customer (name VARCHAR(190) NOT NULL, surname VARCHAR(190) NOT NULL, birth_place VARCHAR(190) DEFAULT NULL, birth_date DATE DEFAULT NULL, address VARCHAR(255) DEFAULT NULL, city VARCHAR(190) DEFAULT NULL, zip VARCHAR(10) DEFAULT NULL, email VARCHAR(190) DEFAULT NULL, phone VARCHAR(40) DEFAULT NULL, fiscal_code VARCHAR(32) DEFAULT NULL, vat_number VARCHAR(20) DEFAULT NULL, pec VARCHAR(190) DEFAULT NULL, sdi VARCHAR(7) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, id BIGINT AUTO_INCREMENT NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, INDEX idx_customer_fiscal_code (fiscal_code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
            'eb_s_document' => 'CREATE TABLE eb_s_document (name VARCHAR(190) NOT NULL, original_filename VARCHAR(255) DEFAULT NULL, storage_path VARCHAR(255) DEFAULT NULL, mime_type VARCHAR(120) DEFAULT NULL, size_bytes BIGINT DEFAULT NULL, agent_note LONGTEXT DEFAULT NULL, notary_note LONGTEXT DEFAULT NULL, id BIGINT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, practice_document_id BIGINT NOT NULL, INDEX IDX_3E591199DEA77742 (practice_document_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
            'eb_s_document_type' => 'CREATE TABLE eb_s_document_type (value VARCHAR(190) NOT NULL, priority INT DEFAULT 0 NOT NULL, mortgage TINYINT DEFAULT 0 NOT NULL, id BIGINT AUTO_INCREMENT NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
            'eb_s_permission' => 'CREATE TABLE eb_s_permission (slug VARCHAR(64) NOT NULL, value VARCHAR(190) NOT NULL, priority INT DEFAULT 0 NOT NULL, id BIGINT AUTO_INCREMENT NOT NULL, UNIQUE INDEX UNIQ_5E72D175989D9B62 (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
            'eb_s_practice' => 'CREATE TABLE eb_s_practice (number VARCHAR(60) NOT NULL, mortgage TINYINT DEFAULT 0 NOT NULL, subject LONGTEXT DEFAULT NULL, address VARCHAR(255) DEFAULT NULL, city VARCHAR(190) DEFAULT NULL, zip VARCHAR(10) DEFAULT NULL, city_ref_id INT DEFAULT NULL, zip_ref_id INT DEFAULT NULL, status VARCHAR(20) NOT NULL, notary_email VARCHAR(190) DEFAULT NULL, id BIGINT AUTO_INCREMENT NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, buyer_customer_id BIGINT DEFAULT NULL, seller_customer_id BIGINT DEFAULT NULL, mark_id BIGINT DEFAULT NULL, INDEX IDX_99DCAFA1DF0C0A51 (buyer_customer_id), INDEX IDX_99DCAFA13E3D1044 (seller_customer_id), INDEX IDX_99DCAFA14290F12B (mark_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
            'eb_s_practice_tag_map' => 'CREATE TABLE eb_s_practice_tag_map (practice_id BIGINT NOT NULL, practice_tag_id BIGINT NOT NULL, INDEX IDX_88D50C64ED33821 (practice_id), INDEX IDX_88D50C6416465519 (practice_tag_id), PRIMARY KEY (practice_id, practice_tag_id)) DEFAULT CHARACTER SET utf8mb4',
            'eb_s_practice_staff' => 'CREATE TABLE eb_s_practice_staff (practice_id BIGINT NOT NULL, user_id BIGINT NOT NULL, INDEX IDX_7621505CED33821 (practice_id), INDEX IDX_7621505CA76ED395 (user_id), PRIMARY KEY (practice_id, user_id)) DEFAULT CHARACTER SET utf8mb4',
            'eb_s_practice_alert' => 'CREATE TABLE eb_s_practice_alert (remind_at DATE NOT NULL, message LONGTEXT NOT NULL, id BIGINT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, practice_id BIGINT NOT NULL, INDEX IDX_23B2E50FED33821 (practice_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
            'eb_s_practice_document' => 'CREATE TABLE eb_s_practice_document (label VARCHAR(190) NOT NULL, visible TINYINT DEFAULT 1 NOT NULL, status VARCHAR(20) NOT NULL, priority INT DEFAULT 0 NOT NULL, id BIGINT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, practice_id BIGINT NOT NULL, document_type_id BIGINT DEFAULT NULL, INDEX IDX_20118223ED33821 (practice_id), INDEX IDX_2011822361232A4F (document_type_id), UNIQUE INDEX uniq_practice_document_type (practice_id, document_type_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
            'eb_s_practice_mark' => 'CREATE TABLE eb_s_practice_mark (id BIGINT AUTO_INCREMENT NOT NULL, value VARCHAR(190) NOT NULL, color VARCHAR(7) DEFAULT \'#1D4ED8\' NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
            'eb_s_practice_tag' => 'CREATE TABLE eb_s_practice_tag (id BIGINT AUTO_INCREMENT NOT NULL, value VARCHAR(190) NOT NULL, color VARCHAR(7) DEFAULT \'#1D4ED8\' NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
            'eb_s_user' => 'CREATE TABLE eb_s_user (is_admin TINYINT DEFAULT 0 NOT NULL, staff_role VARCHAR(16) DEFAULT \'internal\' NOT NULL, id BIGINT AUTO_INCREMENT NOT NULL, email VARCHAR(191) NOT NULL, name VARCHAR(191) DEFAULT NULL, surname VARCHAR(191) DEFAULT NULL, password VARCHAR(255) DEFAULT NULL, role VARCHAR(64) NOT NULL, theme VARCHAR(16) DEFAULT \'light\' NOT NULL, one_time_code VARCHAR(191) DEFAULT NULL, expiration_one_time_code DATETIME DEFAULT NULL, is_active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_2C8CC0FDE7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
            'eb_s_user_permission' => 'CREATE TABLE eb_s_user_permission (value VARCHAR(2) NOT NULL, id BIGINT AUTO_INCREMENT NOT NULL, user_id BIGINT NOT NULL, permission_id BIGINT NOT NULL, INDEX IDX_9752C25AA76ED395 (user_id), INDEX IDX_9752C25AFED90CCA (permission_id), UNIQUE INDEX uniq_user_permission (user_id, permission_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        ];
    }

    /** @return array<string, string[]> tabella => ALTER TABLE ... ADD CONSTRAINT */
    private function foreignKeys(): array
    {
        return [
            'eb_s_document' => [
                'ALTER TABLE eb_s_document ADD CONSTRAINT FK_3E591199DEA77742 FOREIGN KEY (practice_document_id) REFERENCES eb_s_practice_document (id) ON DELETE CASCADE',
            ],
            'eb_s_practice' => [
                'ALTER TABLE eb_s_practice ADD CONSTRAINT FK_99DCAFA1DF0C0A51 FOREIGN KEY (buyer_customer_id) REFERENCES eb_s_customer (id) ON DELETE RESTRICT',
                'ALTER TABLE eb_s_practice ADD CONSTRAINT FK_99DCAFA13E3D1044 FOREIGN KEY (seller_customer_id) REFERENCES eb_s_customer (id) ON DELETE RESTRICT',
                'ALTER TABLE eb_s_practice ADD CONSTRAINT FK_99DCAFA14290F12B FOREIGN KEY (mark_id) REFERENCES eb_s_practice_mark (id) ON DELETE SET NULL',
            ],
            'eb_s_practice_tag_map' => [
                'ALTER TABLE eb_s_practice_tag_map ADD CONSTRAINT FK_88D50C64ED33821 FOREIGN KEY (practice_id) REFERENCES eb_s_practice (id) ON DELETE CASCADE',
                'ALTER TABLE eb_s_practice_tag_map ADD CONSTRAINT FK_88D50C6416465519 FOREIGN KEY (practice_tag_id) REFERENCES eb_s_practice_tag (id) ON DELETE CASCADE',
            ],
            'eb_s_practice_staff' => [
                'ALTER TABLE eb_s_practice_staff ADD CONSTRAINT FK_7621505CED33821 FOREIGN KEY (practice_id) REFERENCES eb_s_practice (id) ON DELETE CASCADE',
                'ALTER TABLE eb_s_practice_staff ADD CONSTRAINT FK_7621505CA76ED395 FOREIGN KEY (user_id) REFERENCES eb_s_user (id) ON DELETE CASCADE',
            ],
            'eb_s_practice_alert' => [
                'ALTER TABLE eb_s_practice_alert ADD CONSTRAINT FK_23B2E50FED33821 FOREIGN KEY (practice_id) REFERENCES eb_s_practice (id) ON DELETE CASCADE',
            ],
            'eb_s_practice_document' => [
                'ALTER TABLE eb_s_practice_document ADD CONSTRAINT FK_20118223ED33821 FOREIGN KEY (practice_id) REFERENCES eb_s_practice (id) ON DELETE CASCADE',
                'ALTER TABLE eb_s_practice_document ADD CONSTRAINT FK_2011822361232A4F FOREIGN KEY (document_type_id) REFERENCES eb_s_document_type (id) ON DELETE RESTRICT',
            ],
            'eb_s_user_permission' => [
                'ALTER TABLE eb_s_user_permission ADD CONSTRAINT FK_9752C25AA76ED395 FOREIGN KEY (user_id) REFERENCES eb_s_user (id) ON DELETE CASCADE',
                'ALTER TABLE eb_s_user_permission ADD CONSTRAINT FK_9752C25AFED90CCA FOREIGN KEY (permission_id) REFERENCES eb_s_permission (id) ON DELETE CASCADE',
            ],
        ];
    }
}
