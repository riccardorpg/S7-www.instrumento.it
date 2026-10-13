<?php

namespace App\Command;

use App\Entity\Slave\Customer;
use App\Entity\Slave\Document;
use App\Entity\Slave\Practice;
use App\Entity\Slave\PracticeDocument;
use App\Service\CompanyService;
use App\Service\PracticeDocumentSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Popola il DB di un'agenzia con pratiche demo (finché l'inserimento lato agenzia
 * non è disponibile). Le pratiche servono a testare l'area notaio.
 *
 *   php bin/console app:seed-practices AG001 --notary=notaio@instrumento.it
 */
#[AsCommand(name: 'app:seed-practices', description: 'Crea pratiche demo nel DB di un\'agenzia (per testare l\'area notaio)')]
class SeedPracticesCommand extends Command
{
    public function __construct(
        private readonly CompanyService $companyService,
        private readonly ParameterBagInterface $params,
        private readonly PracticeDocumentSync $documentSync,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('code', InputArgument::REQUIRED, 'Codice agenzia (Company.code)')
            ->addOption('notary', null, InputOption::VALUE_REQUIRED, 'Email notaio con accesso (default: tutte le pratiche accessibili)')
            ->addOption('count', null, InputOption::VALUE_REQUIRED, 'Numero di pratiche da creare', '4');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $code = trim((string) $input->getArgument('code'));
        $notaryEmail = $input->getOption('notary') ? mb_strtolower(trim((string) $input->getOption('notary'))) : null;
        $count = max(1, (int) $input->getOption('count'));

        $company = $this->companyService->getCompanyByCode($code);
        if ($company === null) {
            $io->error(sprintf('Nessuna agenzia con codice "%s".', $code));

            return Command::FAILURE;
        }

        $em = $this->companyService->pointSlaveToDbName((string) $company->getDbName());
        $baseDir = $this->uploadsBaseDir((string) $company->getDbName());

        // [nome, cognome/ragione sociale, CF o P.IVA, email, telefono, indirizzo, città, CAP]
        $buyers = [
            ['Mario', 'Rossi', 'RSSMRA80A01H501U', 'mario.rossi@example.com', '3401112223', 'Via Roma 1', 'Milano', '20121'],
            ['', 'Bianchi Srl', '01234567890', 'info@bianchisrl.example', '025556677', 'Corso Italia 22', 'Milano', '20122'],
            ['Anna', 'Verdi', 'VRDNNA85M41F205X', 'anna.verdi@example.com', '3487778889', 'Via Dante 9', 'Torino', '10122'],
            ['Luca', 'Neri', 'NRELCU78T10L219K', 'luca.neri@example.com', '3391234567', 'Via Po 4', 'Torino', '10124'],
        ];
        $sellers = [
            ['', 'Immobiliare Sole Spa', '09876543210', 'vendite@sole.example', '0287654321', 'Viale Europa 100', 'Milano', '20126'],
            ['Giuseppe', 'Gialli', 'GLLGPP60E05H501Z', 'g.gialli@example.com', '3405550001', 'Via Verdi 3', 'Monza', '20900'],
            ['', 'Costruzioni Alfa Srl', '05555512345', 'alfa@costruzioni.example', '0311122334', 'Via Milano 50', 'Como', '22100'],
            ['Paola', 'Ferrari', 'FRRPLA72C48A794T', 'paola.ferrari@example.com', '3399998887', 'Via Torino 12', 'Bergamo', '24121'],
        ];

        $created = 0;
        for ($i = 0; $i < $count; ++$i) {
            $practice = new Practice();
            $practice->setNumber(sprintf('P-%04d/%d', $i + 1, 2026))
                ->setMortgage($i % 3 === 0)
                ->setSubject('Immobile sito in Via Esempio ' . ($i + 1) . ' — foglio ' . (10 + $i) . ', particella ' . (100 + $i))
                ->setAddress('Via Esempio ' . ($i + 1) . ', Milano')
                ->setStatus(Practice::STATUS_OPEN)
                ->setNotaryEmail($notaryEmail);

            // Le parti sono clienti dell'agenzia (11): riusa l'anagrafica se il CF c'è già.
            $practice->setBuyer($this->resolveCustomer($em, $buyers[$i % count($buyers)]));
            $practice->setSeller($this->resolveCustomer($em, $sellers[$i % count($sellers)]));

            $em->persist($practice);
            $em->flush(); // serve l'id per il percorso file

            // 12.3.2.1 Righe documentali dal catalogo dell'agenzia (13.1), filtrate per mutuo.
            $this->documentSync->sync($em, $practice);
            $em->flush();

            // Allegati demo: su una riga sì e una no, così si vedono entrambi gli stati.
            $rowIndex = 0;
            foreach ($practice->getPracticeDocuments() as $row) {
                if ($rowIndex++ % 2 === 1) {
                    continue; // riga lasciata "da caricare"
                }

                $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', strtolower($row->getLabel())) . '.txt';
                $rel = $practice->getId() . '/' . uniqid('', false) . '_' . $safe;
                $abs = $baseDir . '/' . $rel;
                @mkdir(dirname($abs), 0775, true);
                file_put_contents($abs, "Documento demo: {$row->getLabel()}\nPratica: {$practice->getNumber()}\n");

                $doc = (new Document())
                    ->setName($row->getLabel())
                    ->setAgentNote('Caricato dall\'agente in fase di apertura pratica.')
                    ->setOriginalFilename($safe)
                    ->setStoragePath($rel)
                    ->setMimeType('text/plain')
                    ->setSizeBytes(filesize($abs) ?: null);

                $row->addDocument($doc);
                $row->setStatus($rowIndex % 3 === 0 ? PracticeDocument::STATUS_VERIFIED : PracticeDocument::STATUS_TO_VERIFY);
                $em->persist($doc);
            }
            $em->flush();
            ++$created;
        }

        $io->success(sprintf('Create %d pratiche demo nel DB "%s" (agenzia %s).', $created, $company->getDbName(), $code));

        return Command::SUCCESS;
    }

    /**
     * Cliente demo: se il codice fiscale è già in anagrafica lo riusa, altrimenti lo crea.
     *
     * @param array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string, 7: string} $data
     */
    private function resolveCustomer(\Doctrine\ORM\EntityManagerInterface $em, array $data): Customer
    {
        [$name, $surname, $fiscalCode, $email, $phone, $address, $city, $zip] = $data;

        /** @var \App\Repository\Slave\CustomerRepository $repo */
        $repo = $em->getRepository(Customer::class);
        $existing = $repo->findOneByFiscalCode($fiscalCode);
        if ($existing !== null) {
            return $existing;
        }

        $customer = (new Customer())
            ->setName($name)
            ->setSurname($surname)
            ->setFiscalCode($fiscalCode)
            ->setEmail($email)
            ->setPhone($phone)
            ->setAddress($address)
            ->setCity($city)
            ->setZip($zip);

        // Le aziende demo hanno una P. IVA di 11 cifre al posto del codice fiscale.
        if (preg_match('/^\d{11}$/', $fiscalCode) === 1) {
            $customer->setVatNumber($fiscalCode);
        }

        $em->persist($customer);
        $em->flush();

        return $customer;
    }

    private function uploadsBaseDir(string $dbName): string
    {
        return rtrim((string) $this->params->get('kernel.project_dir'), '/\\') . '/var/uploads/practices/' . $dbName;
    }
}
