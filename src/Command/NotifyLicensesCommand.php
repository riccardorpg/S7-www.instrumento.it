<?php

namespace App\Command;

use App\Entity\Company;
use App\Service\CompanyService;
use App\Service\MailerService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * 14.5 / 14.6 Le due notifiche legate al tempo, da lanciare una volta al giorno:
 *  - licenza in scadenza entro --days giorni → avviso al cliente (una volta per periodo:
 *    la data dell'avviso resta sulla License e si azzera al cambio di scadenza);
 *  - demo scaduta → il cliente viene sospeso e riceve l'avviso di sospensione.
 *
 * Le licenze non-demo scadute non vengono sospese da qui: la scelta resta all'admin.
 */
#[AsCommand(
    name: 'app:notify-licenses',
    description: 'Avvisa i clienti con licenza in scadenza e sospende le demo scadute (14.5 / 14.6)'
)]
class NotifyLicensesCommand extends Command
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly CompanyService $companyService,
        private readonly MailerService $mailer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Giorni di preavviso per la scadenza', '15')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Mostra cosa farebbe senza scrivere né inviare');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = max(0, (int) $input->getOption('days'));
        $dryRun = (bool) $input->getOption('dry-run');

        $master = $this->registry->getManager();
        /** @var Company[] $companies */
        $companies = $master->getRepository(Company::class)->findBy(['active' => true]);

        $expiring = 0;
        $suspended = 0;
        $skipped = 0;

        foreach ($companies as $company) {
            if ($company->getLicense() === null) {
                continue;
            }

            $left = $company->getDaysToExpiry();
            $label = sprintf('%s (%s)', $company->getName(), $company->getCode());

            // --- 14.5 Demo scaduta: sospensione + avviso ---
            if ($left < 0 && $company->isDemo()) {
                $to = $this->companyService->getPrimaryContactEmail($company);
                if ($to === null) {
                    $io->warning($label . ': demo scaduta ma nessun utente attivo a cui scrivere. Sospensione non eseguita.');
                    ++$skipped;
                    continue;
                }

                if (!$dryRun) {
                    $company->setActive(false);
                    $company->getLicense()->setNoticeSentAt(new \DateTimeImmutable());
                    $master->flush();
                    if (!$this->mailer->demoSuspended($company, $to)) {
                        $io->warning($label . ': sospeso, ma invio a ' . $to . ' non riuscito.');
                    }
                }
                $io->text(sprintf('%s: demo scaduta da %d giorni → sospeso, avviso a %s%s', $label, abs($left), $to, $dryRun ? ' (dry-run)' : ''));
                ++$suspended;
                continue;
            }

            // --- 14.6 Licenza in scadenza: un avviso per periodo ---
            if ($left >= 0 && $left <= $days) {
                if ($company->getLicense()->getNoticeSentAt() !== null) {
                    ++$skipped;
                    continue; // già avvisato per questa scadenza
                }

                $to = $this->companyService->getPrimaryContactEmail($company);
                if ($to === null) {
                    $io->warning($label . ': licenza in scadenza ma nessun utente attivo a cui scrivere.');
                    ++$skipped;
                    continue;
                }

                if (!$dryRun) {
                    $company->getLicense()->setNoticeSentAt(new \DateTimeImmutable());
                    $master->flush();
                    if (!$this->mailer->licenseExpiring($company, $to, $left)) {
                        $io->warning($label . ': invio a ' . $to . ' non riuscito.');
                    }
                }
                $io->text(sprintf('%s: scade fra %d giorni → avviso a %s%s', $label, $left, $to, $dryRun ? ' (dry-run)' : ''));
                ++$expiring;
            }
        }

        $io->success(sprintf(
            '%d avvisi di scadenza, %d demo sospese, %d saltati.%s',
            $expiring,
            $suspended,
            $skipped,
            $dryRun ? ' (dry-run: nulla è stato scritto o inviato)' : ''
        ));

        return Command::SUCCESS;
    }
}
