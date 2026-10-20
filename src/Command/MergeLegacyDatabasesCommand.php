<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Porta nel database unico i dati della vecchia architettura master/slave: il master
 * (eb_m_*) e il database di ogni agenzia (eb_s_*, Company.db_name). Una tantum.
 *
 * - I database di origine stanno sullo stesso server e si leggono soltanto (nomi qualificati).
 * - Il database unico dev'essere vuoto (schema creato dalla migration iniziale).
 * - Un account = un'e-mail: se la stessa e-mail c'è più volte (fra master e agenzie, o in due
 *   agenzie) il comando si ferma prima di scrivere e le elenca.
 * - Le tabelle del master tengono i loro id; quelle delle agenzie prendono id nuovi e la FK
 *   company_id. Lo staff diventa ROLE_USER della sua agenzia.
 * - Il notaio della pratica (prima un'e-mail) diventa la FK verso l'utente ROLE_NOTARY con
 *   quell'e-mail; le pratiche senza corrispondenza restano senza notaio e vengono elencate.
 * - I file si copiano (gli originali restano dove sono) nelle cartelle per id dell'agenzia,
 *   con il percorso rifatto sul nuovo id della pratica.
 */
#[AsCommand(name: 'app:merge-legacy-databases', description: 'Porta i dati master/slave nel database unico (una tantum)')]
class MergeLegacyDatabasesCommand extends Command
{
    /** Tabelle del master copiate così come sono (stessi id), in ordine di dipendenza. */
    private const MASTER_TABLES = [
        'region', 'province', 'city', 'zip', 'join_table_city_zip',
        'license_type', 'space_extra', 'license', 'company', 'demo_request', 'external_account',
    ];

    private SymfonyStyle $io;

    /** @var array<string, string[]> colonne per tabella (cache) */
    private array $columns = [];

    public function __construct(
        private readonly Connection $conn,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Database master di origine', 'instrumento_master_db')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Solo i controlli e il riepilogo, nessuna scrittura');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $source = (string) $input->getOption('source');
        $dryRun = (bool) $input->getOption('dry-run');

        if (!$this->isSafeName($source) || !$this->databaseExists($source)) {
            $this->io->error(sprintf('Database di origine "%s" non trovato.', $source));

            return Command::FAILURE;
        }
        if ((int) $this->conn->fetchOne('SELECT COUNT(*) FROM eb_company') > 0 || (int) $this->conn->fetchOne('SELECT COUNT(*) FROM eb_user') > 0) {
            $this->io->error('Il database unico non è vuoto (eb_company / eb_user): il comando va lanciato una volta sola, su uno schema appena creato.');

            return Command::FAILURE;
        }

        // ---- Agenzie e loro database
        $agencies = [];
        foreach ($this->conn->fetchAllAssociative(sprintf('SELECT id, code, name, db_name, logo FROM `%s`.eb_m_company ORDER BY id', $source)) as $row) {
            $db = (string) $row['db_name'];
            if (!$this->isSafeName($db) || !$this->databaseExists($db)) {
                $this->io->warning(sprintf('Agenzia %s: database "%s" non trovato, solo il record del cliente.', $row['code'], $db));
                $db = null;
            }
            $agencies[] = ['id' => (int) $row['id'], 'code' => $row['code'], 'name' => $row['name'], 'db' => $db, 'logo' => $row['logo']];
        }

        // ---- Un account = un'e-mail: controllo prima di scrivere
        $owners = [];
        foreach ($this->conn->fetchAllAssociative(sprintf('SELECT email, role FROM `%s`.eb_m_user', $source)) as $u) {
            $owners[mb_strtolower(trim((string) $u['email']))][] = 'master (' . $u['role'] . ')';
        }
        foreach ($agencies as $a) {
            if ($a['db'] === null) {
                continue;
            }
            foreach ($this->conn->fetchFirstColumn(sprintf('SELECT email FROM `%s`.eb_s_user', $a['db'])) as $email) {
                $owners[mb_strtolower(trim((string) $email))][] = 'agenzia ' . $a['code'];
            }
        }
        $duplicates = array_filter($owners, static fn (array $where) => count($where) > 1);
        if ($duplicates !== []) {
            $this->io->error('E-mail usate da più utenti: risolvile nei database di origine e rilancia.');
            $this->io->table(['E-mail', 'Dove'], array_map(
                static fn (string $email, array $where) => [$email, implode(', ', $where)],
                array_keys($duplicates),
                $duplicates,
            ));

            return Command::FAILURE;
        }

        $this->io->text(sprintf('%d agenzie, %d utenti in tutto: nessuna e-mail doppia.', count($agencies), count($owners)));
        if ($dryRun) {
            $this->io->success('Dry-run: controlli superati, nessuna scrittura.');

            return Command::SUCCESS;
        }

        $files = [];
        $unmatched = [];
        $this->conn->beginTransaction();
        try {
            // ---- Master: stessi id
            foreach (self::MASTER_TABLES as $table) {
                $n = $this->copyTable($source, 'eb_m_' . $table, 'eb_' . $table);
                $this->io->text(sprintf('eb_%s: %d righe', $table, $n));
            }
            $n = $this->copyTable($source, 'eb_m_user', 'eb_user');
            $this->io->text(sprintf('eb_user (amministratori e notai): %d righe', $n));

            // Notai per e-mail (minuscola): diventano la FK della pratica.
            $notaries = [];
            foreach ($this->conn->fetchAllAssociative("SELECT id, email FROM eb_user WHERE role = 'ROLE_NOTARY'") as $u) {
                $notaries[mb_strtolower(trim((string) $u['email']))] = (int) $u['id'];
            }

            // Catalogo permessi: uno solo, quello del comando di allineamento.
            $permissionIds = [];
            foreach (SyncPermissionsCommand::CATALOG as $slug => $meta) {
                $this->conn->insert('eb_permission', ['slug' => $slug, 'value' => $meta['label'], 'priority' => $meta['priority']]);
                $permissionIds[$slug] = (int) $this->conn->lastInsertId();
            }

            foreach ($agencies as $a) {
                if ($a['db'] === null) {
                    continue;
                }
                $this->io->section(sprintf('%s (%s) ← %s', $a['name'], $a['code'], $a['db']));
                $files = array_merge($files, $this->mergeAgency($a, $permissionIds, $notaries, $unmatched));
            }

            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            $this->io->error('Interrotto, nessun dato scritto: ' . $e->getMessage());

            return Command::FAILURE;
        }

        // ---- File: copiati dopo il commit (gli originali restano)
        $fs = new Filesystem();
        $copied = $missing = 0;
        foreach ($files as [$from, $to]) {
            if (is_file($from)) {
                $fs->copy($from, $to, true);
                ++$copied;
            } else {
                ++$missing;
            }
        }
        $this->io->text(sprintf('File copiati: %d%s.', $copied, $missing ? sprintf(' (%d non trovati sul disco)', $missing) : ''));

        if ($unmatched !== []) {
            $this->io->warning('Pratiche rimaste senza notaio (nessun utente ROLE_NOTARY con quell\'e-mail):');
            $this->io->table(['Agenzia', 'Pratica', 'E-mail del notaio'], $unmatched);
        }

        $this->io->success('Dati portati nel database unico.');

        return Command::SUCCESS;
    }

    /**
     * Copia i dati di un'agenzia, con id nuovi e company_id.
     *
     * @param array{id: int, code: string, name: string, db: string, logo: ?string} $a
     * @param array<string, int> $permissionIds slug => id nuovo
     * @param array<string, int> $notaries e-mail => id del notaio
     * @param list<array{0: string, 1: string, 2: string}> $unmatched
     *
     * @return list<array{0: string, 1: string}> file da copiare [origine, destinazione]
     */
    private function mergeAgency(array $a, array $permissionIds, array $notaries, array &$unmatched): array
    {
        $db = $a['db'];
        $companyId = $a['id'];
        $read = fn (string $table) => $this->conn->fetchAllAssociative(sprintf('SELECT * FROM `%s`.%s ORDER BY 1', $db, $table));

        // Staff → utenti ROLE_USER dell'agenzia.
        $users = $this->insertMapped($read('eb_s_user'), 'eb_user', ['company_id' => $companyId, 'role' => 'ROLE_USER']);
        $this->io->text(sprintf('staff: %d', count($users)));

        $oldPermissions = [];
        foreach ($read('eb_s_permission') as $p) {
            $oldPermissions[(int) $p['id']] = $p['slug'];
        }
        $n = 0;
        foreach ($read('eb_s_user_permission') as $up) {
            $slug = $oldPermissions[(int) $up['permission_id']] ?? null;
            if ($slug === null || !isset($permissionIds[$slug], $users[(int) $up['user_id']])) {
                continue;
            }
            $this->conn->insert('eb_user_permission', ['user_id' => $users[(int) $up['user_id']], 'permission_id' => $permissionIds[$slug], 'value' => $up['value']]);
            ++$n;
        }
        $this->io->text(sprintf('permessi: %d', $n));

        $customers = $this->insertMapped($read('eb_s_customer'), 'eb_customer', ['company_id' => $companyId]);
        $types = $this->insertMapped($read('eb_s_document_type'), 'eb_document_type', ['company_id' => $companyId]);
        $marks = $this->insertMapped($read('eb_s_practice_mark'), 'eb_practice_mark', ['company_id' => $companyId]);
        $tags = $this->insertMapped($read('eb_s_practice_tag'), 'eb_practice_tag', ['company_id' => $companyId]);
        $this->io->text(sprintf('clienti: %d, tipi documento: %d, contrassegni: %d, tag: %d', count($customers), count($types), count($marks), count($tags)));

        // Pratiche: parti, contrassegno e notaio rimappati.
        $practices = [];
        foreach ($read('eb_s_practice') as $row) {
            $email = mb_strtolower(trim((string) $row['notary_email']));
            $notaryId = $email !== '' ? ($notaries[$email] ?? null) : null;
            if ($email !== '' && $notaryId === null) {
                $unmatched[] = [$a['code'], (string) $row['number'], $email];
            }
            $oldId = (int) $row['id'];
            $row['buyer_customer_id'] = $this->remap($customers, $row['buyer_customer_id']);
            $row['seller_customer_id'] = $this->remap($customers, $row['seller_customer_id']);
            $row['mark_id'] = $this->remap($marks, $row['mark_id']);
            $practices[$oldId] = $this->insertRow('eb_practice', $row, ['company_id' => $companyId, 'notary_id' => $notaryId]);
        }
        $this->io->text(sprintf('pratiche: %d', count($practices)));

        foreach ($read('eb_s_practice_tag_map') as $row) {
            if (isset($practices[(int) $row['practice_id']], $tags[(int) $row['practice_tag_id']])) {
                $this->conn->insert('eb_practice_tag_map', ['practice_id' => $practices[(int) $row['practice_id']], 'practice_tag_id' => $tags[(int) $row['practice_tag_id']]]);
            }
        }
        foreach ($read('eb_s_practice_staff') as $row) {
            if (isset($practices[(int) $row['practice_id']], $users[(int) $row['user_id']])) {
                $this->conn->insert('eb_practice_staff', ['practice_id' => $practices[(int) $row['practice_id']], 'user_id' => $users[(int) $row['user_id']]]);
            }
        }

        $rows = [];
        foreach ($read('eb_s_practice_document') as $row) {
            $oldId = (int) $row['id'];
            $row['practice_id'] = $this->remap($practices, $row['practice_id']);
            $row['document_type_id'] = $this->remap($types, $row['document_type_id']);
            $rows[$oldId] = $this->insertRow('eb_practice_document', $row);
        }

        // Allegati: percorso "{idPratica}/file" rifatto sul nuovo id, file da copiare.
        $files = [];
        $oldBase = $this->projectDir . '/var/uploads/practices/' . $db;
        $newBase = $this->projectDir . '/var/uploads/practices/' . $companyId;
        $documents = 0;
        foreach ($read('eb_s_document') as $row) {
            $row['practice_document_id'] = $this->remap($rows, $row['practice_document_id']);
            $path = (string) $row['storage_path'];
            if ($path !== '' && preg_match('#^(\d+)/(.+)$#', str_replace('\\', '/', $path), $m) === 1 && isset($practices[(int) $m[1]])) {
                $row['storage_path'] = $practices[(int) $m[1]] . '/' . $m[2];
                $files[] = [$oldBase . '/' . $path, $newBase . '/' . $row['storage_path']];
            }
            $this->insertRow('eb_document', $row);
            ++$documents;
        }

        $alerts = 0;
        foreach ($read('eb_s_practice_alert') as $row) {
            $row['practice_id'] = $this->remap($practices, $row['practice_id']);
            $this->insertRow('eb_practice_alert', $row);
            ++$alerts;
        }
        $this->io->text(sprintf('righe documentali: %d, allegati: %d, avvisi: %d', count($rows), $documents, $alerts));

        // Logo dell'agenzia.
        if ($a['logo']) {
            $files[] = [
                $this->projectDir . '/var/uploads/logos/' . $db . '/' . $a['logo'],
                $this->projectDir . '/var/uploads/logos/' . $companyId . '/' . $a['logo'],
            ];
        }

        return $files;
    }

    // ------------------------------------------------------------------ supporto

    /** Copia una tabella del master (stessi id), sulle colonne che esistono in entrambe. */
    private function copyTable(string $source, string $from, string $to): int
    {
        $columns = array_values(array_intersect($this->targetColumns($to), $this->sourceColumns($source, $from)));
        $list = implode(', ', array_map(static fn ($c) => '`' . $c . '`', $columns));

        return (int) $this->conn->executeStatement(sprintf('INSERT INTO %s (%s) SELECT %s FROM `%s`.%s', $to, $list, $list, $source, $from));
    }

    /**
     * Inserisce le righe con id nuovi.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $override
     *
     * @return array<int, int> id vecchio => id nuovo
     */
    private function insertMapped(array $rows, string $table, array $override = []): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = $this->insertRow($table, $row, $override);
        }

        return $map;
    }

    /** @param array<string, mixed> $row riga di origine; id e colonne non presenti nel target si scartano */
    private function insertRow(string $table, array $row, array $override = []): int
    {
        $data = array_intersect_key($override + $row, array_flip($this->targetColumns($table)));
        unset($data['id']);
        $this->conn->insert($table, array_combine(array_map(static fn ($c) => '`' . $c . '`', array_keys($data)), $data));

        return (int) $this->conn->lastInsertId();
    }

    /** @param array<int, int> $map */
    private function remap(array $map, mixed $oldId): ?int
    {
        if ($oldId === null || $oldId === '') {
            return null;
        }

        return $map[(int) $oldId] ?? throw new \RuntimeException(sprintf('Riferimento %s senza corrispondenza.', $oldId));
    }

    /** @return string[] */
    private function targetColumns(string $table): array
    {
        return $this->columns['.' . $table] ??= $this->conn->fetchFirstColumn(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        );
    }

    /** @return string[] */
    private function sourceColumns(string $db, string $table): array
    {
        return $this->columns[$db . '.' . $table] ??= $this->conn->fetchFirstColumn(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$db, $table],
        );
    }

    private function databaseExists(string $db): bool
    {
        return $this->conn->fetchOne('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$db]) !== false;
    }

    /** I nomi dei database finiscono in query non parametrizzabili: solo lettere, cifre e underscore. */
    private function isSafeName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $name) === 1;
    }
}
