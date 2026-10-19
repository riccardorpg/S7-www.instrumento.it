<?php

namespace App\Controller\Script;

use App\Entity\Master\Company;
use App\Service\CompanyService;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\SkipMigration;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Migration applicate da browser (CONVENZIONI 12), come il MigrationController di tillomeditalia
 * ma per i database master/slave: le agenzie sono le Company del master e lo slave si ripunta con
 * CompanyService. Sta sotto il firewall ^/script (http basic, ROLE_SCRIPT).
 *
 * Il master è un database solo; lo slave esiste in una copia per ogni agenzia, quindi una
 * migration dello slave non è "eseguita" o "pendente" in assoluto: lo è agenzia per agenzia.
 * Da qui le due sezioni della pagina e lo stato "eseguita su N di M". Si può eseguire una
 * migration (su tutte le agenzie a cui manca) o tutte le pendenti in una volta.
 *
 * Tre scelte da tenere a mente leggendo il codice:
 *
 * 1. Non si usa mai l'alias "latest". Con due namespace configurati sarebbe ambiguo e
 *    rischierebbe di applicare al master le migration dello slave: si esegue sempre una
 *    versione esplicita per volta, nell'ordine dato dal nome del file.
 * 2. La tabella di tracking viene creata qui con SQL diretto invece che dal comando
 *    sync-metadata-storage. Doctrine ricorda di averla già inizializzata, e in un ciclo che
 *    cambia database a ogni giro quel ricordo vale per il database sbagliato.
 * 3. Le migration non passano dai comandi doctrine:migrations:* ma si eseguono qui sulla
 *    connessione del database di turno (vedi execute()): i servizi di Doctrine Migrations
 *    restano legati alla prima connessione usata nella richiesta.
 */
#[Route('/script/migration')]
class ScriptMigrationController extends AbstractController
{
    public const NAMESPACE_MASTER = 'DoctrineMigrations\\Master';
    public const NAMESPACE_SLAVE = 'DoctrineMigrations\\Slave';

    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly CompanyService $companyService,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'script_migration_list', methods: ['GET'])]
    public function scriptMigrationList(): Response
    {
        return new Response($this->page($this->masterStatus(), $this->slaveStatus()));
    }

    /**
     * Esegue una migration. Sul master una volta sola; sullo slave su ogni agenzia a cui
     * manca, saltando quelle che ce l'hanno già: la rotta resta ripetibile dopo un errore
     * a metà elenco.
     */
    #[Route('/esegui/{version}', name: 'script_migration_run', methods: ['GET'])]
    public function scriptMigrationRun(string $version): JsonResponse
    {
        return $this->apply($version, 'run');
    }

    /**
     * Marca una migration come già eseguita senza lanciarne l'SQL: serve quando lo schema è
     * stato allineato a mano e si vuole solo rimettere in pari il tracking.
     */
    #[Route('/marca/{version}', name: 'script_migration_mark', methods: ['GET'])]
    public function scriptMigrationMark(string $version): JsonResponse
    {
        return $this->apply($version, 'mark');
    }

    /** Esegue la prima migration pendente del target indicato: utile per capire dove si rompe. */
    #[Route('/prossima/{target}', name: 'script_migration_next', requirements: ['target' => 'master|slave'], methods: ['GET'])]
    public function scriptMigrationNext(string $target): JsonResponse
    {
        $status = $target === 'master' ? $this->masterStatus() : $this->slaveStatus();

        foreach ($status['versions'] as $row) {
            if ($row['pending'] > 0) {
                return $this->apply($row['version'], 'run');
            }
        }

        return new JsonResponse(['messaggio' => 'Nessuna migration pendente su ' . $target]);
    }

    /**
     * Esegue tutte le migration pendenti, in ordine: sul master, oppure su tutti i database delle
     * agenzie (ognuno fino alla prima che fallisce).
     */
    #[Route('/tutte/{target}', name: 'script_migration_all', requirements: ['target' => 'master|slave'], methods: ['GET'])]
    public function scriptMigrationAll(string $target): JsonResponse
    {
        return $this->applyAll($target);
    }

    /**
     * SQL che servirebbe ad allineare il database alle entità, in sola lettura: niente file
     * scritti, niente statement eseguiti. Sullo slave usa la prima agenzia raggiungibile.
     */
    #[Route('/diff/{target}', name: 'script_migration_diff', requirements: ['target' => 'master|slave'], methods: ['GET'])]
    public function scriptMigrationDiff(string $target): JsonResponse
    {
        try {
            if ($target === 'slave') {
                $reference = null;
                foreach ($this->companies() as $company) {
                    try {
                        $this->openSlave((string) $company->getDbName());
                        $reference = $company->getDbName();
                        break;
                    } catch (\Throwable) {
                        continue;
                    }
                }
                if ($reference === null) {
                    return new JsonResponse(['errore' => 'Nessun database di agenzia raggiungibile su cui calcolare il diff'], 404);
                }
                $em = $this->registry->getManager('slave');
            } else {
                $em = $this->registry->getManager('master');
                $reference = $this->registry->getConnection('master')->getParams()['dbname'] ?? 'master';
            }

            // La tabella di tracking non ha un'entità: il diff la proporrebbe sempre da cancellare.
            $statements = array_values(array_filter(
                (new \Doctrine\ORM\Tools\SchemaTool($em))->getUpdateSchemaSql($em->getMetadataFactory()->getAllMetadata()),
                static fn (string $sql) => !str_contains($sql, 'doctrine_migration_versions')
            ));
            if (!$statements) {
                return new JsonResponse([
                    'target' => $target,
                    'database di riferimento' => $reference,
                    'esito' => 'Nessuna differenza: lo schema è allineato alle entità',
                ]);
            }

            // Separati apposta: il diff propone di eliminare tutto ciò che non ha un'entità,
            // a partire dalla tabella di tracking. Copiarli in una migration senza guardarli cancella dati.
            $safe = [];
            $destructive = [];
            foreach ($statements as $sql) {
                if (preg_match('/\bDROP\b/i', $sql)) {
                    $destructive[] = $sql;
                } else {
                    $safe[] = $sql;
                }
            }

            return new JsonResponse([
                'target' => $target,
                'database di riferimento' => $reference,
                'da aggiungere o modificare' => $safe ?: 'nessuno',
                'ATTENZIONE distruttivi, da valutare a mano' => $destructive ?: 'nessuno',
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(['errore' => $e->getMessage(), 'dove' => $e->getFile() . ':' . $e->getLine()], 500);
        }
    }

    /**
     * Registra come eseguite tutte le migration dello slave sul database di un'agenzia appena
     * creata: nasce già con lo schema corrente (SchemaTool), quindi non deve ripassarle.
     */
    public static function markAllSlaveExecuted(Connection $conn, string $projectDir): void
    {
        self::createTrackingTable($conn);
        foreach (glob($projectDir . '/migrations/slave/Version*.php') ?: [] as $file) {
            $fqcn = self::NAMESPACE_SLAVE . '\\' . basename($file, '.php');
            $conn->executeStatement(
                'INSERT IGNORE INTO doctrine_migration_versions (version, executed_at, execution_time) VALUES (?, NOW(), 0)',
                [$fqcn]
            );
        }
    }

    // ------------------------------------------------------------------ esecuzione

    /** @param string $action 'run' oppure 'mark' */
    private function apply(string $version, string $action): JsonResponse
    {
        $fqcn = $this->fqcn($version);
        if ($fqcn === null) {
            return new JsonResponse(['errore' => 'Migration non trovata: ' . $version], 404);
        }

        // MASTER: un database solo, nessun ciclo.
        if (str_starts_with($fqcn, self::NAMESPACE_MASTER . '\\')) {
            $conn = $this->registry->getConnection('master');
            self::createTrackingTable($conn);

            if ($this->isExecuted($conn, $fqcn)) {
                return new JsonResponse(['migration' => $fqcn, 'target' => 'master', 'esito' => 'già eseguita, saltata']);
            }

            return new JsonResponse(['migration' => $fqcn, 'target' => 'master'] + $this->execute($conn, $fqcn, $action));
        }

        // SLAVE: una volta per agenzia, ognuna con il proprio tracking.
        $entries = [];
        foreach ($this->companies() as $company) {
            $dbName = (string) $company->getDbName();
            try {
                $conn = $this->openSlave($dbName);
                self::createTrackingTable($conn);

                if ($this->isExecuted($conn, $fqcn)) {
                    $entries[] = ['database' => $dbName, 'esito' => 'già eseguita, saltata'];
                    continue;
                }

                $entries[] = ['database' => $dbName] + $this->execute($conn, $fqcn, $action);
            } catch (\Throwable $e) {
                // Un database irraggiungibile non deve fermare le altre agenzie.
                $entries[] = ['database' => $dbName, 'esito' => 'errore', 'messaggio' => $e->getMessage()];
            }
        }
        $this->companyService->clearSession();

        return new JsonResponse(['migration' => $fqcn, 'target' => 'agenzie', 'database' => $entries]);
    }

    /**
     * Esegue tutte le migration pendenti del target, in ordine. Sullo slave lo fa agenzia per
     * agenzia: se una migration fallisce si ferma quell'agenzia (le successive dipendono da lei),
     * le altre proseguono.
     */
    private function applyAll(string $target): JsonResponse
    {
        $versions = $this->migrationFiles($target);
        $namespace = $target === 'master' ? self::NAMESPACE_MASTER : self::NAMESPACE_SLAVE;

        $databases = $target === 'master'
            ? ['master' => fn () => $this->registry->getConnection('master')]
            : array_combine(
                array_map(static fn (Company $c) => (string) $c->getDbName(), $this->companies()),
                array_map(fn (Company $c) => fn () => $this->openSlave((string) $c->getDbName()), $this->companies())
            );

        $result = [];
        foreach ($databases as $dbName => $open) {
            $done = [];
            try {
                $conn = $open();
                self::createTrackingTable($conn);
                $executed = $this->executed($conn);
                foreach ($versions as $version) {
                    if (isset($executed[$version])) {
                        continue;
                    }
                    $outcome = $this->execute($conn, $namespace . '\\' . $version, 'run');
                    $done[] = ['migration' => $version] + $outcome;
                    if ($outcome['esito'] !== 'ok') {
                        break;
                    }
                }
                $result[] = ['database' => $dbName, 'esito' => $done === [] ? 'nessuna pendente' : ($done[array_key_last($done)]['esito'] === 'ok' ? 'ok' : 'errore'), 'migration' => $done];
            } catch (\Throwable $e) {
                $result[] = ['database' => $dbName, 'esito' => 'errore', 'messaggio' => $e->getMessage(), 'migration' => $done];
            }
        }
        if ($target === 'slave') {
            $this->companyService->clearSession();
        }

        return new JsonResponse(['target' => $target === 'master' ? 'master' : 'agenzie', 'database' => $result]);
    }

    /**
     * Esegue (o marca) una migration sul database di questa connessione e la registra nel suo tracking.
     *
     * Non passa dal comando doctrine:migrations:execute: il suo DependencyFactory è un servizio
     * del container che, dopo il primo uso, resta legato alla prima connessione, per cui dalla
     * seconda agenzia della stessa richiesta il comando falliva. Qui la migration si istanzia
     * sulla connessione dell'agenzia di turno, come fa Doctrine (preUp, up, SQL raccolto, postUp).
     *
     * @return array{esito: string, sql?: int, ms?: int, messaggio?: string}
     */
    private function execute(Connection $conn, string $fqcn, string $action): array
    {
        if ($action === 'mark') {
            $this->track($conn, $fqcn, 0);

            return ['esito' => 'ok', 'messaggio' => 'marcata come eseguita, senza SQL'];
        }

        if (!class_exists($fqcn)) {
            return ['esito' => 'errore', 'messaggio' => 'Classe non trovata: ' . $fqcn];
        }

        $start = microtime(true);
        try {
            /** @var AbstractMigration $migration */
            $migration = new $fqcn($conn, $this->logger);
            $schema = $conn->createSchemaManager()->introspectSchema();
            $migration->preUp($schema);
            $migration->up($schema);
            $statements = $migration->getSql();
            foreach ($statements as $query) {
                $conn->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
            $migration->postUp($schema);
        } catch (SkipMigration $e) {
            // Come in Doctrine: una migration saltata (skipIf) risulta comunque eseguita.
            $this->track($conn, $fqcn, (int) round((microtime(true) - $start) * 1000));

            return ['esito' => 'ok', 'messaggio' => 'saltata: ' . $e->getMessage()];
        } catch (\Throwable $e) {
            return ['esito' => 'errore', 'messaggio' => $e->getMessage()];
        }

        $ms = (int) round((microtime(true) - $start) * 1000);
        $this->track($conn, $fqcn, $ms);

        return ['esito' => 'ok', 'sql' => count($statements), 'ms' => $ms];
    }

    /** Registra la migration come eseguita nel tracking di questo database. */
    private function track(Connection $conn, string $fqcn, int $ms): void
    {
        $conn->executeStatement(
            'INSERT IGNORE INTO doctrine_migration_versions (version, executed_at, execution_time) VALUES (?, NOW(), ?)',
            [$fqcn, $ms]
        );
    }

    // ------------------------------------------------------------------ stato

    /** @return array{versions: array, companies: int} */
    private function masterStatus(): array
    {
        $executed = $this->executed($this->registry->getConnection('master'));

        $versions = [];
        foreach ($this->migrationFiles('master') as $version) {
            $log = $executed[$version] ?? null;
            $versions[] = [
                'version' => $version,
                'description' => $this->description('master', $version),
                'executed' => $log ? 1 : 0,
                'pending' => $log ? 0 : 1,
                'when' => $log['executed_at'] ?? null,
                'missing' => [],
            ];
        }

        return ['versions' => $versions, 'companies' => 1];
    }

    /** @return array{versions: array, companies: int, unreachable: array<string, string>} */
    private function slaveStatus(): array
    {
        // Una lettura per agenzia, non una per agenzia per migration.
        $executedByDb = [];
        $errors = [];
        foreach ($this->companies() as $company) {
            $dbName = (string) $company->getDbName();
            try {
                $executedByDb[$dbName] = $this->executed($this->openSlave($dbName));
            } catch (\Throwable $e) {
                $errors[$dbName] = $e->getMessage();
            }
        }
        $this->companyService->clearSession();

        $reachable = count($executedByDb);
        $versions = [];
        foreach ($this->migrationFiles('slave') as $version) {
            $missing = [];
            $when = null;
            foreach ($executedByDb as $dbName => $executed) {
                if (isset($executed[$version])) {
                    $when ??= $executed[$version]['executed_at'];
                } else {
                    $missing[] = $dbName;
                }
            }

            $versions[] = [
                'version' => $version,
                'description' => $this->description('slave', $version),
                'executed' => $reachable - count($missing),
                'pending' => count($missing),
                'when' => $when,
                'missing' => $missing,
            ];
        }

        return ['versions' => $versions, 'companies' => $reachable, 'unreachable' => $errors];
    }

    /** @return array<string, array{version: string, executed_at: ?string, execution_time: ?int}> indicizzato per nome breve */
    private function executed(Connection $conn): array
    {
        try {
            $rows = $conn->fetchAllAssociative('SELECT version, executed_at, execution_time FROM doctrine_migration_versions');
        } catch (\Throwable) {
            // Tabella non ancora creata: nessuna migration risulta eseguita.
            return [];
        }

        $executed = [];
        foreach ($rows as $r) {
            $pos = strrpos($r['version'], '\\');
            $executed[$pos !== false ? substr($r['version'], $pos + 1) : $r['version']] = $r;
        }

        return $executed;
    }

    private function isExecuted(Connection $conn, string $fqcn): bool
    {
        $pos = strrpos($fqcn, '\\');

        return isset($this->executed($conn)[$pos !== false ? substr($fqcn, $pos + 1) : $fqcn]);
    }

    private static function createTrackingTable(Connection $conn): void
    {
        $conn->executeStatement('CREATE TABLE IF NOT EXISTS doctrine_migration_versions (
            version VARCHAR(191) NOT NULL,
            executed_at DATETIME DEFAULT NULL,
            execution_time INT DEFAULT NULL,
            PRIMARY KEY(version)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    // ------------------------------------------------------------------ utilità

    /** @return Company[] */
    private function companies(): array
    {
        return $this->registry->getManager('master')->getRepository(Company::class)->findAll();
    }

    /**
     * Punta la connessione slave sul database indicato e verifica subito che risponda.
     *
     * La verifica non è pleonastica: changeDatabase non apre nulla, e senza una query di
     * prova un database inesistente risulterebbe semplicemente "senza migration eseguite",
     * cioè verrebbe contato tra i pendenti invece che segnalato come irraggiungibile.
     */
    private function openSlave(string $dbName): Connection
    {
        $this->companyService->pointSlaveToDbName($dbName);
        $conn = $this->registry->getConnection('slave');
        $conn->executeQuery('SELECT 1');

        return $conn;
    }

    /** @return string[] nomi VersionXXXX, dal più vecchio al più recente */
    private function migrationFiles(string $target): array
    {
        $files = glob($this->folder($target) . '/Version*.php') ?: [];
        sort($files);

        return array_map(static fn ($p) => preg_replace('/\.php$/i', '', basename($p)), $files);
    }

    private function folder(string $target): string
    {
        return $this->getParameter('kernel.project_dir') . '/migrations/' . $target;
    }

    /** Dal nome breve al FQCN, cercando in quale dei due namespace sta il file. */
    private function fqcn(string $version): ?string
    {
        $version = basename(str_replace('\\', '/', $version));
        if (preg_match('/^Version\d{14}$/', $version) !== 1) {
            return null;
        }

        if (is_file($this->folder('master') . '/' . $version . '.php')) {
            return self::NAMESPACE_MASTER . '\\' . $version;
        }
        if (is_file($this->folder('slave') . '/' . $version . '.php')) {
            return self::NAMESPACE_SLAVE . '\\' . $version;
        }

        return null;
    }

    private function description(string $target, string $version): string
    {
        $content = @file_get_contents($this->folder($target) . '/' . $version . '.php');
        if ($content && preg_match('/function\s+getDescription\s*\(\s*\)\s*:\s*string\s*\{\s*return\s*([\'"])(.*?)\1\s*;/s', $content, $m)) {
            return $m[2];
        }

        return '';
    }

    private function dateFromName(string $version): string
    {
        if (preg_match('/(\d{14})$/', $version, $m)) {
            $dt = \DateTime::createFromFormat('YmdHis', $m[1]);
            if ($dt) {
                return $dt->format('d/m/Y H:i');
            }
        }

        return '';
    }

    // ------------------------------------------------------------------ pagina

    private function page(array $master, array $slave): string
    {
        $masterSection = $this->section('Master', 'master', $master);
        $slaveSection = $this->section('Agenzie', 'slave', $slave);

        $unreachable = '';
        if (!empty($slave['unreachable'])) {
            $list = '';
            foreach ($slave['unreachable'] as $db => $error) {
                $list .= '<li><code>' . htmlspecialchars((string) $db, ENT_QUOTES) . '</code>: ' . htmlspecialchars($error, ENT_QUOTES) . '</li>';
            }
            $unreachable = '<div class="warn"><strong>Database di agenzia non raggiungibili</strong> (esclusi dai conteggi):<ul>' . $list . '</ul></div>';
        }

        return <<<HTML
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Migration</title>
<style>
    body { font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 30px; color: #222; }
    h1 { font-size: 20px; margin-bottom: 4px; }
    h2 { font-size: 16px; margin: 28px 0 6px; }
    p.sub { color: #666; margin-top: 0; font-size: 14px; }
    table { border-collapse: collapse; width: 100%; max-width: 1150px; }
    th, td { text-align: left; padding: 8px 12px; border-bottom: 1px solid #e3e3e3; font-size: 14px; vertical-align: top; }
    th { background: #f5f5f5; }
    td.r, th.r { text-align: right; white-space: nowrap; }
    a.run { display: inline-block; background: #2e7d32; color: #fff; padding: 4px 10px; border-radius: 4px; text-decoration: none; font-size: 13px; }
    a.run.alt { background: #616161; }
    a.run.gray { background: #9e9e9e; }
    a.next { display: inline-block; background: #1d4ed8; color: #fff; padding: 6px 14px; border-radius: 4px; text-decoration: none; font-size: 14px; margin: 8px 8px 8px 0; }
    a.next.all { background: #2e7d32; }
    a.diff { display: inline-block; background: #ef6c00; color: #fff; padding: 6px 14px; border-radius: 4px; text-decoration: none; font-size: 14px; margin: 8px 0; }
    code { background: #f0f0f0; padding: 1px 5px; border-radius: 3px; }
    .muted { color: #888; font-size: 12px; }
    .ok { color: #2e7d32; font-weight: 600; }
    .pend { color: #b26a00; font-weight: 600; }
    .warn { background: #fff8e1; border: 1px solid #ffe082; padding: 10px 14px; border-radius: 4px; margin: 16px 0; max-width: 1150px; font-size: 13px; }
    .warn ul { margin: 6px 0 0; padding-left: 20px; }
    details summary { cursor: pointer; color: #666; font-size: 12px; }
</style>
</head>
<body>
    <h1>Migration</h1>
    <p class="sub">Il master è un database solo, lo slave uno per agenzia: una migration dello slave è eseguita agenzia per agenzia. &quot;Esegui&quot; salta i database che l'hanno già; &quot;Marca&quot; la registra come eseguita senza lanciarne l'SQL; &quot;Esegui tutte le pendenti&quot; le applica in ordine su ogni database (fermandosi, per quel database, alla prima che fallisce).</p>
    $unreachable
    $masterSection
    $slaveSection
</body>
</html>
HTML;
    }

    private function section(string $title, string $target, array $status): string
    {
        $rows = '';
        $pending = 0;

        foreach ($status['versions'] as $row) {
            $version = $row['version'];
            $versionHtml = htmlspecialchars($version, ENT_QUOTES);
            $descHtml = htmlspecialchars($row['description'], ENT_QUOTES);
            $date = $this->dateFromName($version);

            $runUrl = $this->generateUrl('script_migration_run', ['version' => $version]);
            $markUrl = $this->generateUrl('script_migration_mark', ['version' => $version]);

            if ($row['pending'] === 0) {
                $when = $row['when'] ? htmlspecialchars(date('d/m/Y H:i', strtotime((string) $row['when'])), ENT_QUOTES) : '';
                $statusCell = '<span class="ok">Eseguita</span>'
                    . ($target === 'slave' ? ' <span class="muted">su tutte le ' . $status['companies'] . '</span>' : '')
                    . ($when ? '<br><span class="muted">' . $when . '</span>' : '');
                $confirm = htmlspecialchars("$version risulta GIÀ eseguita ovunque. Ri-eseguirne l'SQL?", ENT_QUOTES);
                $actions = '<a class="run gray" href="' . $runUrl . '" target="_blank" rel="noopener" onclick="return confirm(\'' . $confirm . '\')">Ri-esegui</a>';
            } else {
                ++$pending;
                $statusCell = $target === 'slave'
                    ? '<span class="pend">' . $row['executed'] . ' di ' . $status['companies'] . '</span>'
                    : '<span class="pend">Pendente</span>';

                if ($target === 'slave' && $row['missing']) {
                    $list = htmlspecialchars(implode(', ', $row['missing']), ENT_QUOTES);
                    $statusCell .= '<details><summary>' . count($row['missing']) . ' mancanti</summary><span class="muted">' . $list . '</span></details>';
                }

                $confirmRun = htmlspecialchars("Eseguire $version sui database che non l'hanno?", ENT_QUOTES);
                $confirmMark = htmlspecialchars("Marcare $version come già eseguita SENZA lanciarne l'SQL?", ENT_QUOTES);
                $actions = '<a class="run" href="' . $runUrl . '" target="_blank" rel="noopener" onclick="return confirm(\'' . $confirmRun . '\')">Esegui</a> '
                    . '<a class="run alt" href="' . $markUrl . '" target="_blank" rel="noopener" onclick="return confirm(\'' . $confirmMark . '\')">Marca</a>';
            }

            $rows .= '<tr><td>' . $versionHtml . '</td><td class="r">' . $date . '</td><td>' . $descHtml . '</td><td>' . $statusCell . '</td><td>' . $actions . '</td></tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="5">Nessuna migration in <code>migrations/' . $target . '/</code></td></tr>';
        }

        $nextUrl = $this->generateUrl('script_migration_next', ['target' => $target]);
        $allUrl = $this->generateUrl('script_migration_all', ['target' => $target]);
        $diffUrl = $this->generateUrl('script_migration_diff', ['target' => $target]);
        $confirmNext = htmlspecialchars('Eseguire la prossima migration pendente?', ENT_QUOTES);
        $confirmAll = htmlspecialchars($target === 'slave'
            ? 'Eseguire TUTTE le migration pendenti su TUTTI i database delle agenzie?'
            : 'Eseguire TUTTE le migration pendenti sul master?', ENT_QUOTES);
        $count = count($status['versions']);
        $onHowMany = $target === 'slave' ? $status['companies'] . ' database di agenzia' : '1 database';

        return <<<HTML
    <h2>$title</h2>
    <p class="sub">$count migration, <b>$pending</b> con qualcosa da fare, su $onHowMany.</p>
    <a class="next" href="$nextUrl" target="_blank" rel="noopener" onclick="return confirm('$confirmNext')">&#9654; Esegui prossima pendente</a>
    <a class="next all" href="$allUrl" target="_blank" rel="noopener" onclick="return confirm('$confirmAll')">&#9654;&#9654; Esegui tutte le pendenti</a>
    <a class="diff" href="$diffUrl" target="_blank" rel="noopener">Diff entità &rarr; database</a>
    <table>
        <thead>
            <tr><th>Migration</th><th class="r">Data</th><th>Descrizione</th><th>Stato</th><th>Azione</th></tr>
        </thead>
        <tbody>
            $rows
        </tbody>
    </table>
HTML;
    }
}
