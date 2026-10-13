<?php

declare(strict_types=1);

namespace DoctrineMigrations\Master;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Licenze e spazio diventano tabelle a sé, gestite dall'amministratore:
 * - eb_m_license_type: tipi di licenza con lo spazio compreso (demo, base);
 * - eb_m_license: licenze create dall'admin, libere finché non vengono assegnate;
 * - eb_m_space_extra: pacchetti di spazio aggiuntivo;
 * - eb_m_company.license_id e space_extra_id: licenza e pacchetto del cliente (uno per tipo).
 *
 * Le colonne vecchie (license_type, license_expires_at, ...) restano qui: i dati li converte la
 * Version successiva e le colonne le toglie quella dopo ancora.
 */
final class Version20261013100100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Licenze e pacchetti spazio: nuove tabelle e collegamento ai clienti';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS eb_m_license_type (type VARCHAR(20) NOT NULL, space_included INT NOT NULL, id BIGINT AUTO_INCREMENT NOT NULL, UNIQUE INDEX UNIQ_426D1C178CDE5729 (type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE IF NOT EXISTS eb_m_space_extra (type VARCHAR(20) NOT NULL, space INT NOT NULL, id BIGINT AUTO_INCREMENT NOT NULL, UNIQUE INDEX UNIQ_180A4F4D8CDE5729 (type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        if (!$this->connection->createSchemaManager()->tablesExist(['eb_m_license'])) {
            $this->addSql('CREATE TABLE eb_m_license (creation_date DATE NOT NULL, expiration_date DATE NOT NULL, notice_sent_at DATETIME DEFAULT NULL, id BIGINT AUTO_INCREMENT NOT NULL, type_id BIGINT NOT NULL, INDEX IDX_60AD83B5C54C8C93 (type_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
            $this->addSql('ALTER TABLE eb_m_license ADD CONSTRAINT FK_60AD83B5C54C8C93 FOREIGN KEY (type_id) REFERENCES eb_m_license_type (id)');
        }

        if (!$this->columnExists('eb_m_company', 'license_id')) {
            $this->addSql('ALTER TABLE eb_m_company ADD license_id BIGINT DEFAULT NULL');
            $this->addSql('ALTER TABLE eb_m_company ADD CONSTRAINT FK_787A7EE3460F904B FOREIGN KEY (license_id) REFERENCES eb_m_license (id) ON DELETE SET NULL');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_787A7EE3460F904B ON eb_m_company (license_id)');
        }
        if (!$this->columnExists('eb_m_company', 'space_extra_id')) {
            $this->addSql('ALTER TABLE eb_m_company ADD space_extra_id BIGINT DEFAULT NULL');
            $this->addSql('ALTER TABLE eb_m_company ADD CONSTRAINT FK_787A7EE3531242BF FOREIGN KEY (space_extra_id) REFERENCES eb_m_space_extra (id) ON DELETE SET NULL');
            $this->addSql('CREATE INDEX IDX_787A7EE3531242BF ON eb_m_company (space_extra_id)');
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->columnExists('eb_m_company', 'space_extra_id')) {
            $this->addSql('ALTER TABLE eb_m_company DROP FOREIGN KEY FK_787A7EE3531242BF');
            $this->addSql('ALTER TABLE eb_m_company DROP COLUMN space_extra_id');
        }
        if ($this->columnExists('eb_m_company', 'license_id')) {
            $this->addSql('ALTER TABLE eb_m_company DROP FOREIGN KEY FK_787A7EE3460F904B');
            $this->addSql('ALTER TABLE eb_m_company DROP COLUMN license_id');
        }
        $this->addSql('DROP TABLE IF EXISTS eb_m_license');
        $this->addSql('DROP TABLE IF EXISTS eb_m_space_extra');
        $this->addSql('DROP TABLE IF EXISTS eb_m_license_type');
    }

    private function columnExists(string $table, string $column): bool
    {
        foreach ($this->connection->createSchemaManager()->listTableColumns($table) as $c) {
            if (strtolower($c->getName()) === $column) {
                return true;
            }
        }

        return false;
    }
}
