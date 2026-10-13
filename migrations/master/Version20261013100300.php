<?php

declare(strict_types=1);

namespace DoctrineMigrations\Master;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Toglie dal cliente le colonne della gestione licenza e spazio di prima, ora sostituite
 * da eb_m_license ed eb_m_space_extra. Va eseguita dopo aver controllato la conversione
 * della Version precedente: è quella che rende definitivo il passaggio.
 *
 * Il down() rimette le colonne e le ricalcola dalla licenza e dal pacchetto assegnati.
 */
final class Version20261013100300 extends AbstractMigration
{
    private const COLUMNS = ['license_type', 'license_expires_at', 'license_notice_sent_at', 'storage_quota_mb'];

    public function getDescription(): string
    {
        return 'Licenze e pacchetti spazio: rimozione dei campi licenza e quota dal cliente';
    }

    public function up(Schema $schema): void
    {
        foreach (self::COLUMNS as $column) {
            $this->addSql(sprintf('ALTER TABLE eb_m_company DROP COLUMN IF EXISTS %s', $column));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE eb_m_company ADD COLUMN IF NOT EXISTS license_type VARCHAR(20) DEFAULT 'demo' NOT NULL");
        $this->addSql('ALTER TABLE eb_m_company ADD COLUMN IF NOT EXISTS license_expires_at DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE eb_m_company ADD COLUMN IF NOT EXISTS license_notice_sent_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE eb_m_company ADD COLUMN IF NOT EXISTS storage_quota_mb INT DEFAULT 5120 NOT NULL');
        $this->addSql('UPDATE eb_m_company c
            JOIN eb_m_license l ON l.id = c.license_id
            JOIN eb_m_license_type t ON t.id = l.type_id
            LEFT JOIN eb_m_space_extra e ON e.id = c.space_extra_id
            SET c.license_type = t.type,
                c.license_expires_at = l.expiration_date,
                c.license_notice_sent_at = l.notice_sent_at,
                c.storage_quota_mb = t.space_included + COALESCE(e.space, 0)');
    }
}
