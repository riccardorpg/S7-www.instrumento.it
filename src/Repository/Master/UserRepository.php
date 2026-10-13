<?php

namespace App\Repository\Master;

use App\Entity\Master\User;
use App\Repository\Trait\RepositoryTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    use RepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * 17.1 Elenco notai filtrato (filtri da FilterService::notaryFilter).
     * Il filtro "agenzia" arriva già risolto in $agencyEmails: le e-mail dei notai con una
     * pratica assegnata in quell'agenzia, lette dal suo database (NotaryAgencyAccessService).
     *
     * @param string[]|null $agencyEmails null = filtro agenzia non attivo
     */
    public function queryNotariesFiltered(array $filters, ?array $agencyEmails): Query
    {
        $qb = $this->createQueryBuilder('u')
            ->where('1=1')
            ->andWhere('u.role = :role');
        $params = ['role' => 'ROLE_NOTARY'];

        if ($filters['q'] !== '') {
            $qb->andWhere('u.name LIKE :q OR u.surname LIKE :q');
            $params['q'] = '%' . $filters['q'] . '%';
        }
        if ($filters['email'] !== '') {
            $qb->andWhere('u.email LIKE :email');
            $params['email'] = '%' . $filters['email'] . '%';
        }
        if ($agencyEmails !== null) {
            $qb->andWhere('u.email IN (:emails)');
            $params['emails'] = $agencyEmails !== [] ? $agencyEmails : [''];
        }

        foreach ($params as $key => $value) {
            $qb->setParameter($key, $value);
        }
        $this->applySort($qb, $filters, ['u.surname', 'u.email'], 'u.surname');

        return $qb->getQuery();
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($email))]);
    }

    /**
     * 12.2.9 Notai attivi, per cognome: sono i candidati assegnabili a una pratica.
     *
     * @return User[]
     */
    public function findActiveNotaries(): array
    {
        return $this->findBy(
            ['role' => 'ROLE_NOTARY', 'active' => true],
            ['surname' => 'ASC', 'name' => 'ASC'],
        );
    }
}
