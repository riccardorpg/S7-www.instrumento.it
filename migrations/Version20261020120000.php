<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Codici pratica con il prefisso dell'agenzia: P-0001/2026 diventa AG001-0001/2026.
 * Prima si rinominano i codici esistenti (fra agenzie diverse erano uguali), poi la colonna
 * si allarga (il codice agenzia arriva a 64 caratteri) e diventa unica.
 */
final class Version20261020120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Codici pratica con il prefisso dell\'agenzia (AG001-0001/2026), unici';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE eb_practice CHANGE number number VARCHAR(80) NOT NULL');
        // Solo i codici generati col vecchio formato (P-0001/2026): gli altri restano come sono.
        $this->addSql("UPDATE eb_practice p JOIN eb_company c ON c.id = p.company_id
            SET p.number = CONCAT(UPPER(c.code), '-', SUBSTRING(p.number, 3))
            WHERE p.number REGEXP '^P-[0-9]+/[0-9]{4}$'");
        $this->addSql('CREATE UNIQUE INDEX UNIQ_28333A5B96901F54 ON eb_practice (number)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_28333A5B96901F54 ON eb_practice');
        $this->addSql("UPDATE eb_practice p JOIN eb_company c ON c.id = p.company_id
            SET p.number = CONCAT('P-', SUBSTRING(p.number, CHAR_LENGTH(c.code) + 2))
            WHERE p.number LIKE CONCAT(UPPER(c.code), '-%')");
        $this->addSql('ALTER TABLE eb_practice CHANGE number number VARCHAR(60) NOT NULL');
    }
}
