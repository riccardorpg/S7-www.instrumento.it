<?php

namespace App\EventListener;

use App\Doctrine\Filter\CompanyFilter;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Accende il filtro Doctrine "company" quando l'utente è dello staff di un'agenzia.
 * Gira dopo il firewall (priority 8), quindi l'utente è già caricato; il caricamento
 * dell'utente stesso avviene a filtro spento, ma è per e-mail e l'e-mail è unica.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 5)]
final class CompanyFilterListener
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || !$user->isAgencyUser()) {
            return;
        }

        $company = $user->getCompany();
        // Staff senza agenzia: non deve vedere niente (id 0 non esiste).
        $this->em->getFilters()
            ->enable(CompanyFilter::NAME)
            ->setParameter(CompanyFilter::PARAMETER, $company?->getId() ?? 0, 'integer');
    }
}
