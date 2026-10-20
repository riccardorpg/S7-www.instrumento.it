<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Crea un utente ROLE_ADMIN o ROLE_NOTARY sul DB master.
 * (ADMIN e NOTARY non hanno registrazione pubblica: si inseriscono a mano.)
 */
#[AsCommand(name: 'app:create-user', description: 'Crea un utente ROLE_ADMIN o ROLE_NOTARY sul database master')]
class CreateUserCommand extends Command
{
    private const ALLOWED_ROLES = ['ROLE_ADMIN', 'ROLE_NOTARY'];

    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email (login)')
            ->addArgument('password', InputArgument::REQUIRED, 'Password in chiaro')
            ->addOption('role', 'r', InputOption::VALUE_REQUIRED, 'ROLE_ADMIN | ROLE_NOTARY', 'ROLE_ADMIN')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Nome')
            ->addOption('surname', null, InputOption::VALUE_REQUIRED, 'Cognome');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = mb_strtolower(trim((string) $input->getArgument('email')));
        $role = strtoupper((string) $input->getOption('role'));

        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            $io->error(sprintf('Ruolo non valido "%s". Ammessi: %s', $role, implode(', ', self::ALLOWED_ROLES)));

            return Command::INVALID;
        }

        $em = $this->registry->getManager();
        if ($em->getRepository(User::class)->findOneBy(['email' => $email]) !== null) {
            $io->error(sprintf('Esiste già un utente master con email "%s".', $email));

            return Command::FAILURE;
        }

        $user = new User();
        $user->setEmail($email)
            ->setRole($role)
            ->setName($input->getOption('name'))
            ->setSurname($input->getOption('surname'))
            ->setActive(true);
        $user->setPassword($this->hasher->hashPassword($user, (string) $input->getArgument('password')));

        $em->persist($user);
        $em->flush();

        $io->success(sprintf('Utente %s (%s) creato sul master.', $email, $role));

        return Command::SUCCESS;
    }
}
