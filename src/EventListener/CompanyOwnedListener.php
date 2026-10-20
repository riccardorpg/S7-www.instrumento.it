<?php

namespace App\EventListener;

use App\Entity\CompanyOwnedInterface;
use App\Service\CompanyService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Rete di sicurezza sull'isolamento dei dati: un'entity di un'agenzia salvata da un utente
 * dello staff prende la sua agenzia se non ne ha una, e non può finire in un'altra.
 * I controller assegnano comunque l'agenzia alla creazione (la serve la validazione di unicità).
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
final class CompanyOwnedListener
{
    public function __construct(private readonly CompanyService $companyService)
    {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof CompanyOwnedInterface) {
            return;
        }

        $current = $this->companyService->getCurrentCompany();
        if ($current === null) {
            return;
        }

        if ($entity->getCompany() === null) {
            $entity->setCompany($current);
        }

        $this->guard($entity, $current->getId());
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        $current = $this->companyService->getCurrentCompany();
        if ($entity instanceof CompanyOwnedInterface && $current !== null) {
            $this->guard($entity, $current->getId());
        }
    }

    private function guard(CompanyOwnedInterface $entity, ?int $companyId): void
    {
        if ($entity->getCompany()?->getId() !== null && $entity->getCompany()->getId() !== $companyId) {
            throw new \LogicException(sprintf('%s di un\'altra agenzia: salvataggio negato.', $entity::class));
        }
    }
}
