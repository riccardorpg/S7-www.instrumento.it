<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Staff del notaio: collaboratori (eb_notary_staff) e pratiche assegnate loro (eb_practice_notary_staff).
 */
final class Version20261020110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Staff del notaio: collaboratori e pratiche assegnate';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE eb_practice_notary_staff (practice_id BIGINT NOT NULL, user_id BIGINT NOT NULL, INDEX IDX_84354C1DED33821 (practice_id), INDEX IDX_84354C1DA76ED395 (user_id), PRIMARY KEY (practice_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eb_notary_staff (notary_id BIGINT NOT NULL, staff_id BIGINT NOT NULL, INDEX IDX_BDC7811AACC994D3 (notary_id), INDEX IDX_BDC7811AD4D57CD (staff_id), PRIMARY KEY (notary_id, staff_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE eb_practice_notary_staff ADD CONSTRAINT FK_84354C1DED33821 FOREIGN KEY (practice_id) REFERENCES eb_practice (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eb_practice_notary_staff ADD CONSTRAINT FK_84354C1DA76ED395 FOREIGN KEY (user_id) REFERENCES eb_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eb_notary_staff ADD CONSTRAINT FK_BDC7811AACC994D3 FOREIGN KEY (notary_id) REFERENCES eb_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eb_notary_staff ADD CONSTRAINT FK_BDC7811AD4D57CD FOREIGN KEY (staff_id) REFERENCES eb_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE eb_practice_notary_staff DROP FOREIGN KEY FK_84354C1DED33821');
        $this->addSql('ALTER TABLE eb_practice_notary_staff DROP FOREIGN KEY FK_84354C1DA76ED395');
        $this->addSql('ALTER TABLE eb_notary_staff DROP FOREIGN KEY FK_BDC7811AACC994D3');
        $this->addSql('ALTER TABLE eb_notary_staff DROP FOREIGN KEY FK_BDC7811AD4D57CD');
        $this->addSql('DROP TABLE eb_practice_notary_staff');
        $this->addSql('DROP TABLE eb_notary_staff');
    }
}
