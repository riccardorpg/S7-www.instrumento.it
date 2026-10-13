<?php

declare(strict_types=1);

namespace DoctrineMigrations\Master;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Licenze: la durata parte dall'assegnazione al cliente, non dalla creazione.
 * - start_date: data di partenza dell'utilizzo (il giorno dell'assegnazione);
 * - expiration_date diventa facoltativa: una licenza libera non ha ancora scadenza.
 * I dati delle licenze esistenti li allinea la Version successiva.
 */
final class Version20261013140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Licenze: data di partenza utilizzo e scadenza facoltativa per le licenze libere';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE eb_m_license ADD COLUMN IF NOT EXISTS start_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE eb_m_license MODIFY expiration_date DATE DEFAULT NULL');
    }

    /** Prima di rendere di nuovo obbligatoria la scadenza, le licenze libere ne ricevono una (un anno dalla creazione). */
    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE eb_m_license SET expiration_date = DATE_ADD(creation_date, INTERVAL 1 YEAR) WHERE expiration_date IS NULL');
        $this->addSql('ALTER TABLE eb_m_license MODIFY expiration_date DATE NOT NULL');
        $this->addSql('ALTER TABLE eb_m_license DROP COLUMN IF EXISTS start_date');
    }
}
