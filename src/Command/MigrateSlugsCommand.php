<?php

namespace App\Command;

use App\Entity\Master\Company;
use App\Entity\Slave\Practice;
use App\Entity\Slave\PracticeDocument;
use App\Service\CompanyService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Migrazione una-tantum: i valori enum salvati in italiano diventano slug inglesi.
 * Sul master i tipi cliente (eb_m_company.client_type, eb_m_demo_request.account_type),
 * su ogni DB agenzia gli stati (eb_s_practice.status, eb_s_practice_document.status).
 * Le etichette mostrate restano italiane (Company::getClientTypeLabel(),
 * Practice::STATUSES, PracticeDocument::STATUSES).
 *
 * È idempotente: i valori già in inglese non vengono toccati.
 */
#[AsCommand(
    name: 'app:migrate-slugs',
    description: 'Converte stati e tipi cliente da italiano a slug inglesi (una tantum)'
)]
class MigrateSlugsCommand extends Command
{
    /** @var array<string, string> vecchio tipo cliente => nuovo */
    private const TYPE_MAP = [
        'aziendale' => Company::TYPE_COMPANY,
        'professionista' => Company::TYPE_PROFESSIONAL,
    ];

    /** @var array<string, string> vecchio stato pratica => nuovo */
    private const PRACTICE_MAP = [
        'aperta' => Practice::STATUS_OPEN,
        'completata' => Practice::STATUS_COMPLETED,
        'archiviabile' => Practice::STATUS_COMPLETED, // non c'è più uno stato a parte: completata = archiviabile
        'archiviata' => Practice::STATUS_ARCHIVED,
    ];

    /** @var array<string, string> vecchio stato riga documentale => nuovo */
    private const DOCUMENT_MAP = [
        'da_caricare' => PracticeDocument::STATUS_TO_UPLOAD,
        'da_verificare' => PracticeDocument::STATUS_TO_VERIFY,
        'verificato' => PracticeDocument::STATUS_VERIFIED,
        'non_necessario' => PracticeDocument::STATUS_NOT_REQUIRED,
    ];

    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly CompanyService $companyService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Mostra cosa farebbe senza scrivere nulla');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $master = $this->registry->getManager('master');

        // --- Master: tipi cliente ---
        $io->section('Master (tipi cliente)');
        $errors = 0;
        try {
            $conn = $master->getConnection();
            $this->migrateColumn($conn, 'eb_m_company', 'client_type', self::TYPE_MAP, $io, $dryRun);
            $this->migrateColumn($conn, 'eb_m_demo_request', 'account_type', self::TYPE_MAP, $io, $dryRun);
        } catch (\Throwable $e) {
            ++$errors;
            $io->error('Errore sul master: ' . $e->getMessage());
        }

        // --- Agenzie: stati pratiche e righe documentali ---
        $companies = $master->getRepository(Company::class)->findAll();
        if ($companies === []) {
            $io->warning('Nessuna agenzia registrata: nessuno stato da convertire.');
        }

        foreach ($companies as $company) {
            $dbName = (string) $company->getDbName();
            $io->section(sprintf('%s (%s) → %s', $company->getName(), $company->getCode(), $dbName));

            try {
                $conn = $this->companyService->pointSlaveToDbName($dbName)->getConnection();
                $this->migrateColumn($conn, 'eb_s_practice', 'status', self::PRACTICE_MAP, $io, $dryRun);
                $this->migrateColumn($conn, 'eb_s_practice_document', 'status', self::DOCUMENT_MAP, $io, $dryRun);
            } catch (\Throwable $e) {
                ++$errors;
                $io->error(sprintf('Errore su %s: %s', $dbName, $e->getMessage()));
            }
        }

        $this->companyService->clearSession();

        if ($errors > 0) {
            $io->warning(sprintf('Completato con %d errori.', $errors));

            return Command::FAILURE;
        }

        $io->success($dryRun ? 'Dry-run completato.' : 'Conversione completata.');

        return Command::SUCCESS;
    }

    /**
     * Converte una colonna enum secondo la mappa vecchio => nuovo.
     *
     * @param array<string, string> $map
     */
    private function migrateColumn(Connection $conn, string $table, string $column, array $map, SymfonyStyle $io, bool $dryRun): void
    {
        foreach ($map as $old => $new) {
            $count = (int) $conn->fetchOne(
                sprintf('SELECT COUNT(*) FROM %s WHERE %s = ?', $table, $column),
                [$old]
            );
            if ($count === 0) {
                continue;
            }

            if (!$dryRun) {
                $conn->executeStatement(
                    sprintf('UPDATE %s SET %s = ? WHERE %s = ?', $table, $column, $column),
                    [$new, $old]
                );
            }
            $io->text(sprintf(
                '%s.%s: %d righe «%s» → «%s»%s',
                $table, $column, $count, $old, $new, $dryRun ? ' (dry-run)' : ''
            ));
        }

        // Valori fuori mappa (typo, valori scritti a mano). In dry-run i vecchi valori
        // sono ancora a posto, quindi non contano come sconosciuti.
        $known = array_merge(array_values($map), $dryRun ? array_keys($map) : []);
        $unknown = $conn->fetchFirstColumn(
            sprintf('SELECT DISTINCT %s FROM %s WHERE %s NOT IN (?)', $column, $table, $column),
            [$known],
            [ArrayParameterType::STRING]
        );
        if ($unknown !== []) {
            $io->warning(sprintf(
                '%s.%s: valori non riconosciuti, da sistemare a mano: %s',
                $table, $column, implode(', ', $unknown)
            ));
        }
    }
}
