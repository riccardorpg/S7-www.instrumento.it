<?php

declare(strict_types=1);

namespace DoctrineMigrations\Master;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Allinea le licenze esistenti al nuovo modello (durata dall'assegnazione):
 * - licenze assegnate senza data di partenza: la partenza è ricostruita dalla scadenza
 *   (un anno prima; 30 giorni prima per le demo, che nascono dalla conversione di una richiesta);
 * - licenze libere: tolta la scadenza calcolata alla creazione, il loro anno non è ancora partito.
 * Si può rilanciare: tocca solo le righe ancora da sistemare.
 */
final class Version20261013140100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Licenze: data di partenza per quelle assegnate, niente scadenza per quelle libere';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE eb_m_license l
            JOIN eb_m_company c ON c.license_id = l.id
            JOIN eb_m_license_type t ON t.id = l.type_id
            SET l.start_date = IF(t.type = 'demo', DATE_SUB(l.expiration_date, INTERVAL 30 DAY), DATE_SUB(l.expiration_date, INTERVAL 1 YEAR))
            WHERE l.start_date IS NULL AND l.expiration_date IS NOT NULL");
        $this->addSql('UPDATE eb_m_license l
            LEFT JOIN eb_m_company c ON c.license_id = l.id
            SET l.expiration_date = NULL, l.notice_sent_at = NULL
            WHERE c.id IS NULL AND l.start_date IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE eb_m_license SET expiration_date = DATE_ADD(creation_date, INTERVAL 1 YEAR) WHERE expiration_date IS NULL');
        $this->addSql('UPDATE eb_m_license SET start_date = NULL');
    }
}
