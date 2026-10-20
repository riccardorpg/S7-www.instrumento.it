<?php

namespace App\Service;

use App\Entity\DocumentType;
use App\Entity\Practice;
use App\Entity\PracticeDocument;
use Doctrine\ORM\EntityManagerInterface;

/**
 * 12.3.2.1 Costruisce le righe documentali di una pratica a partire dal catalogo
 * tipi di documento dell'agenzia (13.1).
 *
 * Regole:
 *  - entrano solo i tipi attivi (13.1.2);
 *  - i tipi marcati "mutuo" (13.1.1.3) solo se la pratica è con mutuo (12.2.4);
 *  - l'ordine è la priorità del catalogo (13.1.1.1), congelata sulla riga.
 * Le righe già presenti non vengono toccate: la sincronizzazione aggiunge soltanto,
 * così stato, allegati e mostra/nascondi impostati a mano restano dove sono.
 */
class PracticeDocumentSyncService
{
    /**
     * Allinea le righe della pratica al catalogo. Ritorna quante righe ha aggiunto.
     * Non fa flush: lo decide il chiamante.
     */
    public function sync(EntityManagerInterface $em, Practice $practice): int
    {
        $added = 0;
        foreach ($this->missingFor($em, $practice) as $type) {
            $practiceDocument = (new PracticeDocument())
                ->setDocumentType($type)
                ->setLabel($type->getValue())
                ->setPriority($type->getPriority())
                ->setStatus(PracticeDocument::STATUS_TO_UPLOAD);

            $practice->addPracticeDocument($practiceDocument);
            $em->persist($practiceDocument);
            ++$added;
        }

        return $added;
    }

    /**
     * 12.3.2.1 Tipi previsti che non hanno ancora una riga sulla pratica: sono quelli
     * aggiunti al catalogo (o riattivati) dopo l'apertura, oppure entrati con il
     * passaggio a "con mutuo".
     *
     * @return DocumentType[]
     */
    public function missingFor(EntityManagerInterface $em, Practice $practice): array
    {
        $existing = [];
        foreach ($practice->getPracticeDocuments() as $practiceDocument) {
            $typeId = $practiceDocument->getDocumentType()?->getId();
            if ($typeId !== null) {
                $existing[(int) $typeId] = true;
            }
        }

        return array_values(array_filter(
            $this->catalogFor($em, $practice),
            static fn (DocumentType $type) => !isset($existing[(int) $type->getId()]),
        ));
    }

    /**
     * Tipi di documento previsti per la pratica: quelli della sua agenzia.
     *
     * @return DocumentType[]
     */
    public function catalogFor(EntityManagerInterface $em, Practice $practice): array
    {
        $qb = $em->getRepository(DocumentType::class)->createQueryBuilder('t')
            ->andWhere('t.company = :company')->setParameter('company', $practice->getCompany())
            ->andWhere('t.active = true')
            ->orderBy('t.priority', 'ASC')
            ->addOrderBy('t.id', 'ASC');

        if (!$practice->isMortgage()) {
            $qb->andWhere('t.mortgage = false');
        }

        return $qb->getQuery()->getResult();
    }
}
