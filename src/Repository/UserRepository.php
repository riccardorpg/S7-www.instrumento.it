<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\User;
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
     * Il filtro "agenzia" ($filters['agency'], id) tiene i notai con almeno una pratica assegnata in quell'agenzia.
     */
    public function queryNotariesFiltered(array $filters): Query
    {
        $qb = $this->createQueryBuilder('u')
            ->where('u.role = :role');
        $params = ['role' => User::ROLE_NOTARY];

        if ($filters['q'] !== '') {
            $qb->andWhere('u.name LIKE :q OR u.surname LIKE :q');
            $params['q'] = '%' . $filters['q'] . '%';
        }
        if ($filters['email'] !== '') {
            $qb->andWhere('u.email LIKE :email');
            $params['email'] = '%' . $filters['email'] . '%';
        }
        if ($filters['agency'] !== '') {
            $qb->andWhere('EXISTS (SELECT 1 FROM App\Entity\Practice fp WHERE fp.notary = u AND IDENTITY(fp.company) = :agency)');
            $params['agency'] = (int) $filters['agency'];
        }

        foreach ($params as $key => $value) {
            $qb->setParameter($key, $value);
        }
        $this->applySort($qb, $filters, ['u.surname', 'u.email'], 'u.surname');

        return $qb->getQuery();
    }

    /**
     * 10.1 Staff dell'agenzia filtrato (filtri da FilterService::staffFilter).
     *
     * @param string[] $roles ruoli ammessi (User::STAFF_ROLES): quelli fuori elenco si ignorano
     */
    public function queryStaffFiltered(Company $company, array $filters, array $roles): Query
    {
        $qb = $this->createQueryBuilder('u')
            ->where('u.company = :company')
            ->andWhere('u.role = :role');
        $params = ['company' => $company, 'role' => User::ROLE_USER];

        foreach (['name', 'surname', 'email'] as $field) {
            if ($filters[$field] !== '') {
                $qb->andWhere(sprintf('u.%s LIKE :%s', $field, $field));
                $params[$field] = '%' . $filters[$field] . '%';
            }
        }
        if ($filters['active'] !== '') {
            $qb->andWhere('u.active = :active');
            $params['active'] = $filters['active'] === '1';
        }
        $selected = array_values(array_intersect(array_filter(explode(',', $filters['role'])), $roles));
        if ($selected !== []) {
            $qb->andWhere('u.staffRole IN (:roles)');
            $params['roles'] = $selected;
        }

        foreach ($params as $key => $value) {
            $qb->setParameter($key, $value);
        }
        $this->applySort($qb, $filters, ['u.name', 'u.surname', 'u.email', 'u.staffRole'], 'u.surname');

        return $qb->getQuery();
    }

    /**
     * Staff di un'agenzia, per cognome (scheda cliente dell'amministratore, staff delle pratiche).
     *
     * @return User[]
     */
    public function findStaff(Company $company, ?bool $active = null): array
    {
        $criteria = ['company' => $company, 'role' => User::ROLE_USER];
        if ($active !== null) {
            $criteria['active'] = $active;
        }

        return $this->findBy($criteria, ['surname' => 'ASC', 'name' => 'ASC']);
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
            ['role' => User::ROLE_NOTARY, 'active' => true],
            ['surname' => 'ASC', 'name' => 'ASC'],
        );
    }
}
