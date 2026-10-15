<?php

declare(strict_types=1);

namespace DoctrineMigrations\Slave;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Avvisi della pratica: "mostra al notaio". Gli avvisi esistenti restano interni all'agenzia.
 * Idempotente (le agenzie non sono tutte allo stesso punto): la colonna si aggiunge solo se manca.
 */
final class Version20261015100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Avvisi della pratica: visibilità al notaio';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE eb_s_practice_alert ADD COLUMN IF NOT EXISTS visible_to_notary TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE eb_s_practice_alert DROP COLUMN IF EXISTS visible_to_notary');
    }
}
