<?php

namespace App\Service;

use App\Entity\Master\Company;
use App\Entity\Master\User;
use Doctrine\Persistence\ManagerRegistry;

/**
 * 17.1 Quali agenzie vede un notaio: non c'è più un abbinamento deciso in
 * amministrazione, l'accesso lo dà l'agenzia assegnandogli una pratica. Un'agenzia
 * compare quindi solo se nel suo database c'è almeno una pratica con quel notaio.
 *
 * Le pratiche stanno negli slave (uno per agenzia), ma qui non si ri-punta la
 * connessione slave — si romperebbe il contesto della richiesta in corso: si legge
 * dalla connessione master qualificando il nome del database, come per lo spazio
 * occupato (7.1.8).
 */
class NotaryAgencyAccessService
{
    /** @var array<string, string[]> Cache per richiesta: e-mail assegnate per database. */
    private array $cache = [];

    public function __construct(private readonly ManagerRegistry $registry)
    {
    }

    /**
     * Agenzie attive con almeno una pratica assegnata al notaio, per nome.
     *
     * @return Company[]
     */
    public function companiesFor(User $notary): array
    {
        $email = $this->email($notary);
        if ($email === null) {
            return [];
        }

        return array_values(array_filter(
            $this->activeCompanies(),
            fn (Company $company) => in_array($email, $this->assignedEmails($company), true)
        ));
    }

    /** Il notaio ha una pratica assegnata in questa agenzia (e l'agenzia è attiva, con licenza valida)? */
    public function hasAccess(User $notary, Company $company): bool
    {
        $email = $this->email($notary);

        return $email !== null
            && $company->isOperational()
            && in_array($email, $this->assignedEmails($company), true);
    }

    /**
     * E-mail dei notai con almeno una pratica assegnata in questa agenzia.
     *
     * @return string[]
     */
    public function assignedEmails(Company $company): array
    {
        $db = (string) $company->getDbName();
        // Il nome arriva dal master, ma finisce in una query non parametrizzabile:
        // fuori dall'alfabeto ammesso non si interroga.
        if (preg_match('/^[A-Za-z0-9_]+$/', $db) !== 1) {
            return [];
        }

        if (!array_key_exists($db, $this->cache)) {
            $this->cache[$db] = $this->readAssignedEmails($db);
        }

        return $this->cache[$db];
    }

    /**
     * Agenzie di ciascun notaio, indicizzate per e-mail: una sola lettura per
     * agenzia, così un elenco di notai non fa una query per riga.
     *
     * @return array<string, Company[]>
     */
    public function companiesByNotaryEmail(): array
    {
        $map = [];
        foreach ($this->activeCompanies() as $company) {
            foreach ($this->assignedEmails($company) as $email) {
                $map[$email][] = $company;
            }
        }

        return $map;
    }

    /** @return Company[] */
    private function activeCompanies(): array
    {
        // Attive e con licenza valida (Company::isOperational), come per l'accesso dello staff.
        return array_values(array_filter(
            $this->registry->getManager('master')->getRepository(Company::class)->findBy(['active' => true], ['name' => 'ASC']),
            static fn (Company $company) => $company->isOperational()
        ));
    }

    /** @return string[] */
    private function readAssignedEmails(string $db): array
    {
        try {
            $rows = $this->registry->getConnection('master')->executeQuery(sprintf(
                'SELECT DISTINCT notary_email FROM `%s`.`eb_s_practice` WHERE notary_email IS NOT NULL',
                $db,
            ))->fetchFirstColumn();
        } catch (\Throwable) {
            // Database dell'agenzia non ancora creato o senza la tabella: nessun accesso.
            return [];
        }

        return array_values(array_unique(array_map(
            static fn ($email) => mb_strtolower(trim((string) $email)),
            $rows,
        )));
    }

    private function email(User $notary): ?string
    {
        $email = mb_strtolower(trim((string) $notary->getEmail()));

        return $email !== '' ? $email : null;
    }
}
