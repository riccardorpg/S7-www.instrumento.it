<?php

namespace App\Repository\Trait;

use Doctrine\ORM\QueryBuilder;

/**
 * Metodi comuni ai repository (CONVENZIONI 5): ciclo di vita base e ordinamento delle liste.
 * Va usato dentro un ServiceEntityRepository.
 */
trait RepositoryTrait
{
    public function add(object $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(object $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Ordinamento in coda al DQL: quello scelto dall'utente (filters sortField / sortOrder) se il
     * campo è fra gli ammessi, altrimenti quello di default della lista.
     *
     * @param string[] $allowed campi DQL ordinabili (es. 'c.name')
     */
    private function applySort(QueryBuilder $qb, array $filters, array $allowed, string $defaultField, string $defaultOrder = 'asc'): void
    {
        $field = (string) ($filters['sortField'] ?? '');
        if (in_array($field, $allowed, true)) {
            $qb->orderBy($field, ($filters['sortOrder'] ?? '') === 'desc' ? 'DESC' : 'ASC');
        } else {
            $qb->orderBy($defaultField, $defaultOrder === 'desc' ? 'DESC' : 'ASC');
        }
    }
}
