<?php

declare(strict_types=1);

namespace DoctrineMigrations\Slave;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pratiche: niente più stato "archiviabile". Una pratica completata è già archiviabile, quindi
 * le pratiche ancora in "archiviabile" (il via libera del notaio c'era già) tornano "completata".
 * Idempotente: ripetuta non trova più nulla da aggiornare.
 */
final class Version20261019100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pratiche: lo stato "archiviabile" confluisce in "completata"';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE eb_s_practice SET status = 'completed' WHERE status = 'archivable'");
    }

    /** Non reversibile: non si sa più quali completate fossero state segnate come archiviabili. */
    public function down(Schema $schema): void
    {
    }
}
