<?php

namespace App\Doctrine\Filter;

use App\Entity\CompanyOwnedInterface;
use App\Entity\User;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Isolamento dei dati per agenzia. Acceso da {@see \App\EventListener\CompanyFilterListener}
 * per gli utenti dello staff (ROLE_USER): ogni query su un'entity CompanyOwnedInterface,
 * comprese find() e i join, vede solo le righe della sua agenzia.
 *
 * Sugli utenti nasconde lo staff delle altre agenzie, ma lascia visibili amministratori
 * e notai (company_id NULL): il notaio di una pratica si deve poter caricare.
 *
 * Le entity figlie della pratica (righe documentali, documenti, avvisi) non hanno la
 * colonna: le proteggono i controller, risalendo alla pratica.
 */
class CompanyFilter extends SQLFilter
{
    public const NAME = 'company';
    public const PARAMETER = 'company_id';

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        $class = $targetEntity->getReflectionClass();

        if ($class->implementsInterface(CompanyOwnedInterface::class)) {
            return sprintf('%s.company_id = %s', $targetTableAlias, $this->getParameter(self::PARAMETER));
        }

        if ($class->getName() === User::class) {
            return sprintf('(%1$s.company_id = %2$s OR %1$s.company_id IS NULL)', $targetTableAlias, $this->getParameter(self::PARAMETER));
        }

        return '';
    }
}
