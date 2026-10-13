<?php

namespace App\Service;

use App\Bundle\DynamicConnection;
use App\Controller\Script\ScriptMigrationController;
use App\Entity\Master\Company;
use App\Entity\Slave\User as SlaveUser;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Gestione dello switch multi-tenant della connessione "slave".
 *
 * Il master è fisso; lo slave viene ri-puntato al database dell'agenzia (Company)
 * corrente. Il tenant è determinato dal CODICE agenzia (Company.code), che identifica
 * il database: la stessa email può esistere in agenzie diverse.
 */
class CompanyService
{
    public const SESSION_COMPANY_ID = 'companyId';
    public const SESSION_COMPANY_DB = 'companyDbName';

    /**
     * Cookie con il CODICE dell'agenzia, di durata pari al remember-me.
     * Serve quando la sessione è scaduta ma il cookie "resta collegato" è ancora valido:
     * senza tenant la connessione slave resterebbe sul database master e il caricamento
     * dell'utente agenzia fallirebbe. Contiene solo il codice, che è già pubblico
     * (compare nell'URL /accedi/{code}); non è una credenziale.
     */
    public const COOKIE_TENANT = 'eb_tenant';

    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly ParameterBagInterface $params,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Risolve la Company (agenzia) dal suo codice. Il codice identifica il database:
     * la stessa email può esistere in agenzie diverse, quindi è il codice a disambiguare.
     */
    public function getCompanyByCode(string $code): ?Company
    {
        return $this->registry->getManager('master')
            ->getRepository(Company::class)
            ->findOneBy(['code' => trim($code)]);
    }

    /**
     * Crea il database di una nuova agenzia con lo schema corrente delle entità Slave e
     * registra come già eseguite tutte le migration dello slave (CONVENZIONI 12): il database
     * nasce aggiornato, quindi la pagina /script/migration non deve proporgliele.
     * Lascia la connessione slave puntata sul nuovo database.
     */
    public function provisionDatabase(string $dbName): EntityManagerInterface
    {
        $this->registry->getConnection('master')->executeStatement(
            sprintf('CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $dbName)
        );

        $em = $this->pointSlaveToDbName($dbName);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        ScriptMigrationController::markAllSlaveExecuted($em->getConnection(), (string) $this->params->get('kernel.project_dir'));

        return $em;
    }

    /**
     * Punta la connessione slave al database dell'agenzia indicata e la memorizza in sessione.
     */
    public function switchToCompany(Company $company): EntityManagerInterface
    {
        $em = $this->pointSlaveToDbName((string) $company->getDbName());
        $this->storeInSession($company);

        return $em;
    }

    /**
     * Ri-punta fisicamente la connessione slave al database $dbName (idempotente).
     */
    public function pointSlaveToDbName(string $dbName): EntityManagerInterface
    {
        /** @var DynamicConnection $conn */
        $conn = $this->registry->getConnection('slave');
        $current = $conn instanceof DynamicConnection ? $conn->getCurrentDatabaseName() : ($conn->getParams()['dbname'] ?? null);

        if ($current !== $dbName && $conn instanceof DynamicConnection) {
            $conn->changeDatabase(
                (string) $this->params->get('slave_database_host'),
                (string) $this->params->get('slave_database_port'),
                (string) $this->params->get('slave_database_user'),
                (string) $this->params->get('slave_database_password'),
                $dbName,
            );
            $this->registry->getManager('slave')->clear();
        }

        return $this->registry->getManager('slave');
    }

    /**
     * Allinea la connessione slave allo stato di sessione (usato dal listener di request).
     */
    public function syncSlaveFromSession(): void
    {
        $dbName = $this->currentTenantDbName();
        if ($dbName !== null) {
            $this->pointSlaveToDbName($dbName);
        }
    }

    /**
     * Database del tenant corrente: prima la sessione, poi il cookie del remember-me.
     * Null se non c'è nessun contesto agenzia (utente master o visitatore anonimo).
     */
    public function currentTenantDbName(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return null;
        }

        if ($request->hasSession()) {
            $dbName = $request->getSession()->get(self::SESSION_COMPANY_DB);
            if (is_string($dbName) && $dbName !== '') {
                return $dbName;
            }
        }

        // Sessione scaduta ma "resta collegato" ancora valido: il tenant arriva dal cookie.
        $code = (string) $request->cookies->get(self::COOKIE_TENANT, '');
        if ($code === '') {
            return null;
        }

        $company = $this->getCompanyByCode($code);
        if ($company === null || !$company->isOperational()) {
            return null;
        }

        // Ripopola la sessione, così le richieste successive non ripassano dal cookie.
        $this->storeInSession($company);

        return (string) $company->getDbName();
    }

    /**
     * Indirizzo a cui scrivere al cliente (14.5 / 14.6 / 14.9). Il Company non ha un campo
     * e-mail: si usa il primo amministratore attivo dell'agenzia, altrimenti un utente
     * attivo qualsiasi. Null se il DB dell'agenzia non è raggiungibile o non ha utenti.
     *
     * Attenzione: ripunta la connessione slave sul DB del cliente.
     */
    public function getPrimaryContactEmail(Company $company): ?string
    {
        try {
            $slaveEm = $this->pointSlaveToDbName((string) $company->getDbName());
            $users = $slaveEm->getRepository(SlaveUser::class)->findBy(['active' => true], ['id' => 'ASC']);
        } catch (\Throwable) {
            return null;
        }

        foreach ($users as $user) {
            if ($user->isAdmin() && $user->getEmail()) {
                return $user->getEmail();
            }
        }

        foreach ($users as $user) {
            if ($user->getEmail()) {
                return $user->getEmail();
            }
        }

        return null;
    }

    /**
     * 7.1.8 Spazio occupato reale (MB) dal database dell'agenzia, letto da information_schema.
     */
    public function getStorageUsedMb(string $dbName): float
    {
        if ($dbName === '') {
            return 0.0;
        }

        $conn = $this->registry->getConnection('master');
        $bytes = (int) $conn->executeQuery(
            'SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = :db',
            ['db' => $dbName],
        )->fetchOne();

        return round($bytes / 1048576, 1);
    }

    public function getCurrentCompany(): ?Company
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || !$request->hasSession()) {
            return null;
        }

        $companyId = $request->getSession()->get(self::SESSION_COMPANY_ID);
        if ($companyId === null) {
            return null;
        }

        return $this->registry->getManager('master')->getRepository(Company::class)->find($companyId);
    }

    public function clearSession(): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || !$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        $session->remove(self::SESSION_COMPANY_ID);
        $session->remove(self::SESSION_COMPANY_DB);
    }

    private function storeInSession(Company $company): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || !$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        $session->set(self::SESSION_COMPANY_ID, $company->getId());
        $session->set(self::SESSION_COMPANY_DB, $company->getDbName());
    }
}
