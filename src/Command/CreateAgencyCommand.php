<?php

namespace App\Command;

use App\Entity\Company;
use App\Entity\User;
use App\Repository\CompanyRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Nuova agenzia: il record Company e il suo primo utente (ROLE_USER, amministratore dello staff).
 * L'e-mail è unica su tutta la piattaforma: un account = un'e-mail.
 */
#[AsCommand(name: 'app:create-agency', description: 'Crea una nuova agenzia con il primo utente amministratore')]
class CreateAgencyCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CompanyRepository $companies,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('code', InputArgument::REQUIRED, 'Codice agenzia univoco (es. AG001)')
            ->addArgument('name', InputArgument::REQUIRED, 'Ragione sociale')
            ->addArgument('email', InputArgument::REQUIRED, 'Email del primo utente agenzia')
            ->addArgument('password', InputArgument::REQUIRED, 'Password del primo utente agenzia');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $code = mb_strtoupper(trim((string) $input->getArgument('code')));
        $name = trim((string) $input->getArgument('name'));
        $email = mb_strtolower(trim((string) $input->getArgument('email')));
        $password = (string) $input->getArgument('password');

        if ($this->companies->findOneByCode($code) !== null) {
            $io->error(sprintf('Esiste già un\'agenzia con codice "%s".', $code));

            return Command::FAILURE;
        }
        if ($this->users->findOneByEmail($email) !== null) {
            $io->error(sprintf('L\'e-mail "%s" è già usata da un altro utente.', $email));

            return Command::FAILURE;
        }

        $company = (new Company())->setCode($code)->setName($name)->setActive(true);
        $this->em->persist($company);

        $user = (new User())->setRole(User::ROLE_USER)->setCompany($company)->setEmail($email)->setActive(true);
        $user->setStaffRole(User::STAFF_ADMIN);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf("Agenzia \"%s\" (%s) creata.\n  Primo utente: %s", $name, $code, $email));

        return Command::SUCCESS;
    }
}
