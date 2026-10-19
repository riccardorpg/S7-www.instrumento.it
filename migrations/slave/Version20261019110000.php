<?php

declare(strict_types=1);

namespace DoctrineMigrations\Slave;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Avvisi della pratica scritti dal notaio: chi l'ha scritto (by_notary) e se l'agenzia lo vede
 * (visible_to_agency). Gli avvisi esistenti sono tutti dell'agenzia.
 * Idempotente (le agenzie non sono tutte allo stesso punto): le colonne si aggiungono solo se mancano.
 */
final class Version20261019110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Avvisi della pratica: autore notaio e visibilità all\'agenzia';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE eb_s_practice_alert ADD COLUMN IF NOT EXISTS by_notary TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE eb_s_practice_alert ADD COLUMN IF NOT EXISTS visible_to_agency TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE eb_s_practice_alert DROP COLUMN IF EXISTS visible_to_agency');
        $this->addSql('ALTER TABLE eb_s_practice_alert DROP COLUMN IF EXISTS by_notary');
    }
}
