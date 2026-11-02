<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Archivio delle pratiche su Aruba: percorso dello ZIP nel bucket (eb_practice.archive_path).
 */
final class Version20261102100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Archivio delle pratiche su Aruba: percorso dello ZIP';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE eb_practice ADD archive_path VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE eb_practice DROP archive_path');
    }
}
