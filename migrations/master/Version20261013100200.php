<?php

declare(strict_types=1);

namespace DoctrineMigrations\Master;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Dati di licenze e spazio, separati dallo schema così si possono controllare prima di
 * togliere le colonne vecchie:
 *
 * 1. cataloghi: tipi di licenza (demo 100 MB, base 1 GB) e pacchetti spazio
 *    (demo, base, business, pro, enterprise), inseriti solo se mancano;
 * 2. conversione dei clienti che non hanno ancora una licenza: dalla licenza vecchia
 *    (license_type, license_expires_at) nasce una License assegnata.
 *    "demo" resta demo, ogni altro tipo diventa base. La scadenza resta quella di prima
 *    (o un anno da oggi se non c'era). Per non ridurre lo spazio, ai clienti non demo con una
 *    quota vecchia più grande della licenza base si assegna il pacchetto più piccolo che la copre.
 *
 * Si può rilanciare: i cataloghi hanno la guardia NOT EXISTS e la conversione tocca solo i
 * clienti senza licenza.
 */
final class Version20261013100200 extends AbstractMigration
{
    /** Tipo licenza => spazio compreso (MB). Allineato a LicenseType e SpaceExtra. */
    private const LICENSE_TYPES = ['demo' => 100, 'base' => 1024];

    /** Pacchetto spazio => spazio aggiunto (MB). Allineato a LicenseType e SpaceExtra. */
    private const SPACE_EXTRAS = ['demo' => 100, 'base' => 1024, 'business' => 5120, 'pro' => 10240, 'enterprise' => 20480];

    public function getDescription(): string
    {
        return 'Licenze e pacchetti spazio: cataloghi e conversione delle licenze dei clienti esistenti';
    }

    public function up(Schema $schema): void
    {
        foreach (self::LICENSE_TYPES as $type => $mb) {
            $this->addSql(
                'INSERT INTO eb_m_license_type (type, space_included) SELECT :type, :mb FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM eb_m_license_type WHERE type = :type) x)',
                ['type' => $type, 'mb' => $mb]
            );
        }
        foreach (self::SPACE_EXTRAS as $type => $mb) {
            $this->addSql(
                'INSERT INTO eb_m_space_extra (type, space) SELECT :type, :mb FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM eb_m_space_extra WHERE type = :type) x)',
                ['type' => $type, 'mb' => $mb]
            );
        }

        // Senza le colonne vecchie non c'è niente da convertire (database nato dopo la modifica).
        if (!$this->columnExists('eb_m_company', 'license_type')) {
            return;
        }

        $companies = $this->connection->fetchAllAssociative(
            'SELECT id, license_type, license_expires_at, license_notice_sent_at, storage_quota_mb FROM eb_m_company WHERE license_id IS NULL'
        );
        foreach ($companies as $c) {
            $isDemo = $c['license_type'] === 'demo';
            $type = $isDemo ? 'demo' : 'base';

            // Una riga per cliente; LAST_INSERT_ID() vale per la stessa connessione,
            // e le istruzioni di una migration girano in ordine sulla stessa connessione.
            $this->addSql(
                'INSERT INTO eb_m_license (creation_date, expiration_date, notice_sent_at, type_id) SELECT CURDATE(), :expiration, :notice, id FROM eb_m_license_type WHERE type = :type',
                [
                    'expiration' => $c['license_expires_at'] ?? (new \DateTimeImmutable('today +1 year'))->format('Y-m-d'),
                    'notice' => $c['license_notice_sent_at'],
                    'type' => $type,
                ]
            );
            $this->addSql('UPDATE eb_m_company SET license_id = LAST_INSERT_ID() WHERE id = :id', ['id' => $c['id']]);

            $extra = $isDemo ? null : $this->extraFor((int) $c['storage_quota_mb'] - self::LICENSE_TYPES['base']);
            if ($extra !== null) {
                $this->addSql(
                    'UPDATE eb_m_company SET space_extra_id = (SELECT id FROM eb_m_space_extra WHERE type = :extra) WHERE id = :id AND space_extra_id IS NULL',
                    ['extra' => $extra, 'id' => $c['id']]
                );
            }
        }
    }

    /**
     * Non reversibile: le licenze create qui non si distinguono da quelle create poi a mano
     * dall'admin. Per tornare indietro sui dati c'è il ripristino dello snapshot.
     */
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('La conversione delle licenze non si annulla.');
    }

    /** Il pacchetto più piccolo che copre lo spazio mancante (il più grande se nessuno basta). */
    private function extraFor(int $missingMb): ?string
    {
        if ($missingMb <= 0) {
            return null;
        }
        $extras = self::SPACE_EXTRAS;
        asort($extras);
        foreach ($extras as $type => $mb) {
            if ($mb >= $missingMb) {
                return $type;
            }
        }

        return array_key_last($extras);
    }

    private function columnExists(string $table, string $column): bool
    {
        foreach ($this->connection->createSchemaManager()->listTableColumns($table) as $c) {
            if (strtolower($c->getName()) === $column) {
                return true;
            }
        }

        return false;
    }
}
