<?php

namespace App\Command;

use App\Entity\Master\Company;
use App\Entity\Slave\User as AgencyUser;
use App\Service\CompanyService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Provisiona una nuova agenzia (tenant):
 *   1) crea il record Company sul master (code + dbName);
 *   2) crea fisicamente il database slave dell'agenzia e ne genera lo schema;
 *   3) crea il primo utente ROLE_AGENCY nel DB slave.
 *
 * L'email è univoca solo DENTRO il DB dell'agenzia: la stessa email può esistere in
 * agenzie diverse (il login la disambigua tramite il codice).
 */
#[AsCommand(name: 'app:create-agency', description: 'Crea una nuova agenzia: Company sul master, DB slave dedicato e primo utente ROLE_AGENCY')]
class CreateAgencyCommand extends Command
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly CompanyService $companyService,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ParameterBagInterface $params,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('code', InputArgument::REQUIRED, 'Codice agenzia univoco (es. AG001)')
            ->addArgument('name', InputArgument::REQUIRED, 'Ragione sociale')
            ->addArgument('email', InputArgument::REQUIRED, 'Email del primo utente agenzia')
            ->addArgument('password', InputArgument::REQUIRED, 'Password del primo utente agenzia')
            ->addOption('db-name', null, InputOption::VALUE_REQUIRED, 'Nome del DB slave (default: <db_prefix>ag_<code>)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $code = trim((string) $input->getArgument('code'));
        $name = trim((string) $input->getArgument('name'));
        $email = mb_strtolower(trim((string) $input->getArgument('email')));
        $password = (string) $input->getArgument('password');

        $prefix = (string) $this->params->get('db_prefix');
        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($code)) ?: 'agency';
        $dbName = (string) ($input->getOption('db-name') ?? $prefix . 'ag_' . $slug);

        $masterEm = $this->registry->getManager('master');

        // Guardie di unicità
        if ($masterEm->getRepository(Company::class)->findOneBy(['code' => $code]) !== null) {
            $io->error(sprintf('Esiste già una Company con codice "%s".', $code));

            return Command::FAILURE;
        }
        if ($masterEm->getRepository(Company::class)->findOneBy(['dbName' => $dbName]) !== null) {
            $io->error(sprintf('Il database "%s" è già assegnato a un\'altra agenzia.', $dbName));

            return Command::FAILURE;
        }

        // 1-2) Crea il database slave con lo schema corrente e le migration già registrate
        $io->section('Creazione database agenzia: ' . $dbName);
        $slaveEm = $this->companyService->provisionDatabase($dbName);

        // 3) Primo utente ROLE_AGENCY nel DB slave
        $agencyUser = new AgencyUser();
        $agencyUser->setEmail($email)->setActive(true);
        $agencyUser->setPassword($this->hasher->hashPassword($agencyUser, $password));
        $slaveEm->persist($agencyUser);
        $slaveEm->flush();

        // 4) Company sul master (registro agenzia: code + dbName)
        $company = new Company();
        $company->setCode($code)->setName($name)->setDbName($dbName)->setActive(true);

        $masterEm->persist($company);
        $masterEm->flush();

        $io->success(sprintf(
            "Agenzia \"%s\" (%s) creata.\n  DB slave: %s\n  Primo utente: %s",
            $name, $code, $dbName, $email
        ));

        return Command::SUCCESS;
    }
}
