<?php

namespace App\Controller\Script;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\SkipMigration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Migration applicate da browser (CONVENZIONI 12), come il MigrationController di tillomeditalia.
 * Database unico, una sola cartella (migrations/, namespace DoctrineMigrations). Sta sotto il
 * firewall ^/script (http basic, ROLE_SCRIPT).
 *
 * Si esegue sempre una versione esplicita per volta, nell'ordine dato dal nome del file; la
 * migration si istanzia qui sulla connessione (preUp, up, SQL raccolto, postUp) e si registra
 * nella tabella di tracking di Doctrine, creata se manca.
 */
#[Route('/script/migration')]
class ScriptMigrationController extends AbstractController
{
    public const NAMESPACE = 'DoctrineMigrations';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'script_migration_list', methods: ['GET'])]
    public function scriptMigrationList(): Response
    {
        return new Response($this->page($this->status()));
    }

    /** Esegue una migration (saltata se risulta già eseguita). */
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

    /** Esegue la prima migration pendente: utile per capire dove si rompe. */
    #[Route('/prossima', name: 'script_migration_next', methods: ['GET'])]
    public function scriptMigrationNext(): JsonResponse
    {
        foreach ($this->status() as $row) {
            if (!$row['executed']) {
                return $this->apply($row['version'], 'run');
            }
        }

        return new JsonResponse(['messaggio' => 'Nessuna migration pendente']);
    }

    /** Esegue tutte le migration pendenti, in ordine, fermandosi alla prima che fallisce. */
    #[Route('/tutte', name: 'script_migration_all', methods: ['GET'])]
    public function scriptMigrationAll(): JsonResponse
    {
        $conn = $this->em->getConnection();
        self::createTrackingTable($conn);
        $executed = $this->executed($conn);

        $done = [];
        foreach ($this->migrationFiles() as $version) {
            if (isset($executed[$version])) {
                continue;
            }
            $outcome = $this->execute($conn, self::NAMESPACE . '\\' . $version, 'run');
            $done[] = ['migration' => $version] + $outcome;
            if ($outcome['esito'] !== 'ok') {
                break;
            }
        }

        return new JsonResponse([
            'esito' => $done === [] ? 'nessuna pendente' : ($done[array_key_last($done)]['esito'] === 'ok' ? 'ok' : 'errore'),
            'migration' => $done,
        ]);
    }

    /**
     * SQL che servirebbe ad allineare il database alle entità, in sola lettura: niente file
     * scritti, niente statement eseguiti.
     */
    #[Route('/diff', name: 'script_migration_diff', methods: ['GET'])]
    public function scriptMigrationDiff(): JsonResponse
    {
        try {
            // La tabella di tracking non ha un'entità: il diff la proporrebbe sempre da cancellare.
            $statements = array_values(array_filter(
                (new SchemaTool($this->em))->getUpdateSchemaSql($this->em->getMetadataFactory()->getAllMetadata()),
                static fn (string $sql) => !str_contains($sql, 'doctrine_migration_versions')
            ));
            if (!$statements) {
                return new JsonResponse(['esito' => 'Nessuna differenza: lo schema è allineato alle entità']);
            }

            // Separati apposta: il diff propone di eliminare tutto ciò che non ha un'entità.
            // Copiarli in una migration senza guardarli cancella dati.
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
                'da aggiungere o modificare' => $safe ?: 'nessuno',
                'ATTENZIONE distruttivi, da valutare a mano' => $destructive ?: 'nessuno',
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(['errore' => $e->getMessage(), 'dove' => $e->getFile() . ':' . $e->getLine()], 500);
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

        $conn = $this->em->getConnection();
        self::createTrackingTable($conn);

        if (isset($this->executed($conn)[$this->shortName($fqcn)])) {
            return new JsonResponse(['migration' => $fqcn, 'esito' => 'già eseguita, saltata']);
        }

        return new JsonResponse(['migration' => $fqcn] + $this->execute($conn, $fqcn, $action));
    }

    /**
     * Esegue (o marca) una migration e la registra nel tracking.
     *
     * @return array{esito: string, sql?: int, ms?: int, messaggio?: string}
     */
    private function execute(Connection $conn, string $fqcn, string $action): array
    {
        if ($action === 'mark') {
            $this->track($conn, $fqcn, 0);

            return ['esito' => 'ok', 'messaggio' => 'marcata come eseguita, senza SQL'];
        }

        // Le classi delle migration non sono nell'autoload di Composer (le carica il finder di
        // Doctrine Migrations): qui si include il file a mano.
        $file = $this->folder() . '/' . $this->shortName($fqcn) . '.php';
        if (!class_exists($fqcn, false) && is_file($file)) {
            require_once $file;
        }
        if (!class_exists($fqcn, false)) {
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

    private function track(Connection $conn, string $fqcn, int $ms): void
    {
        $conn->executeStatement(
            'INSERT IGNORE INTO doctrine_migration_versions (version, executed_at, execution_time) VALUES (?, NOW(), ?)',
            [$fqcn, $ms]
        );
    }

    // ------------------------------------------------------------------ stato

    /** @return list<array{version: string, description: string, executed: bool, when: ?string}> */
    private function status(): array
    {
        $executed = $this->executed($this->em->getConnection());

        $rows = [];
        foreach ($this->migrationFiles() as $version) {
            $log = $executed[$version] ?? null;
            $rows[] = [
                'version' => $version,
                'description' => $this->description($version),
                'executed' => $log !== null,
                'when' => $log['executed_at'] ?? null,
            ];
        }

        return $rows;
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
            $executed[$this->shortName($r['version'])] = $r;
        }

        return $executed;
    }

    private function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos !== false ? substr($fqcn, $pos + 1) : $fqcn;
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

    /** @return string[] nomi VersionXXXX, dal più vecchio al più recente */
    private function migrationFiles(): array
    {
        $files = glob($this->folder() . '/Version*.php') ?: [];
        sort($files);

        return array_map(static fn ($p) => preg_replace('/\.php$/i', '', basename($p)), $files);
    }

    private function folder(): string
    {
        return $this->getParameter('kernel.project_dir') . '/migrations';
    }

    private function fqcn(string $version): ?string
    {
        $version = basename(str_replace('\\', '/', $version));
        if (preg_match('/^Version\d{14}$/', $version) !== 1 || !is_file($this->folder() . '/' . $version . '.php')) {
            return null;
        }

        return self::NAMESPACE . '\\' . $version;
    }

    private function description(string $version): string
    {
        $content = @file_get_contents($this->folder() . '/' . $version . '.php');
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

    private function page(array $status): string
    {
        $rows = '';
        $pending = 0;

        foreach ($status as $row) {
            $version = $row['version'];
            $versionHtml = htmlspecialchars($version, ENT_QUOTES);
            $descHtml = htmlspecialchars($row['description'], ENT_QUOTES);
            $date = $this->dateFromName($version);

            $runUrl = $this->generateUrl('script_migration_run', ['version' => $version]);
            $markUrl = $this->generateUrl('script_migration_mark', ['version' => $version]);

            if ($row['executed']) {
                $when = $row['when'] ? htmlspecialchars(date('d/m/Y H:i', strtotime((string) $row['when'])), ENT_QUOTES) : '';
                $statusCell = '<span class="ok">Eseguita</span>' . ($when ? '<br><span class="muted">' . $when . '</span>' : '');
                $actions = '<span class="muted">-</span>';
            } else {
                ++$pending;
                $statusCell = '<span class="pend">Pendente</span>';
                $confirmRun = htmlspecialchars("Eseguire $version?", ENT_QUOTES);
                $confirmMark = htmlspecialchars("Marcare $version come già eseguita SENZA lanciarne l'SQL?", ENT_QUOTES);
                $actions = '<a class="run" href="' . $runUrl . '" target="_blank" rel="noopener" onclick="return confirm(\'' . $confirmRun . '\')">Esegui</a> '
                    . '<a class="run alt" href="' . $markUrl . '" target="_blank" rel="noopener" onclick="return confirm(\'' . $confirmMark . '\')">Marca</a>';
            }

            $rows .= '<tr><td>' . $versionHtml . '</td><td class="r">' . $date . '</td><td>' . $descHtml . '</td><td>' . $statusCell . '</td><td>' . $actions . '</td></tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="5">Nessuna migration in <code>migrations/</code></td></tr>';
        }

        $nextUrl = $this->generateUrl('script_migration_next');
        $allUrl = $this->generateUrl('script_migration_all');
        $diffUrl = $this->generateUrl('script_migration_diff');
        $confirmNext = htmlspecialchars('Eseguire la prossima migration pendente?', ENT_QUOTES);
        $confirmAll = htmlspecialchars('Eseguire TUTTE le migration pendenti?', ENT_QUOTES);
        $count = count($status);

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
    p.sub { color: #666; margin-top: 0; font-size: 14px; }
    table { border-collapse: collapse; width: 100%; max-width: 1150px; }
    th, td { text-align: left; padding: 8px 12px; border-bottom: 1px solid #e3e3e3; font-size: 14px; vertical-align: top; }
    th { background: #f5f5f5; }
    td.r, th.r { text-align: right; white-space: nowrap; }
    a.run { display: inline-block; background: #2e7d32; color: #fff; padding: 4px 10px; border-radius: 4px; text-decoration: none; font-size: 13px; }
    a.run.alt { background: #616161; }
    a.next { display: inline-block; background: #1d4ed8; color: #fff; padding: 6px 14px; border-radius: 4px; text-decoration: none; font-size: 14px; margin: 8px 8px 8px 0; }
    a.next.all { background: #2e7d32; }
    a.diff { display: inline-block; background: #ef6c00; color: #fff; padding: 6px 14px; border-radius: 4px; text-decoration: none; font-size: 14px; margin: 8px 0; }
    code { background: #f0f0f0; padding: 1px 5px; border-radius: 3px; }
    .muted { color: #888; font-size: 12px; }
    .ok { color: #2e7d32; font-weight: 600; }
    .pend { color: #b26a00; font-weight: 600; }
</style>
</head>
<body>
    <h1>Migration</h1>
    <p class="sub">$count migration, <b>$pending</b> pendenti. &quot;Esegui&quot; lancia l'SQL e la registra; &quot;Marca&quot; la registra come eseguita senza lanciarne l'SQL; &quot;Esegui tutte le pendenti&quot; le applica in ordine fermandosi alla prima che fallisce.</p>
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
</body>
</html>
HTML;
    }
}
