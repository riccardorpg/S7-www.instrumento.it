<?php

declare(strict_types=1);

namespace DoctrineMigrations\Master;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Licenze come eaml.software: interruttore dell'amministratore sulla singola licenza.
 * Una licenza disattivata non è valida anche se non è scaduta (License::isValid).
 * Le licenze esistenti nascono attive.
 */
final class Version20261013160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Licenze: interruttore attiva/disattiva dell\'amministratore';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE eb_m_license ADD COLUMN IF NOT EXISTS is_admin_active TINYINT(1) NOT NULL DEFAULT 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE eb_m_license DROP COLUMN IF EXISTS is_admin_active');
    }
}
