<?php

namespace App\Controller\Agency\Trait;

use App\Entity\PracticeMark;
use App\Entity\PracticeTag;
use App\Repository\PracticeMarkRepository;
use App\Repository\PracticeTagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gestione comune a contrassegni (13.2) e tag (13.3) delle pratiche, che hanno la stessa forma
 * (valore + colore): form di nuovo e modifica nei modali, sul DB dell'agenzia.
 * Da usare in un AbstractController con la proprietà $registry (ManagerRegistry).
 */
trait ColorValueFormsTrait
{
    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $this->registry->getManager();

        return $em;
    }

    /** Torna all'elenco su cui si stava lavorando. */
    private function backTo(string $route): RedirectResponse
    {
        return $this->redirectToRoute($route, [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Form di nuovo e modifica: salva e torna all'elenco (Response), oppure restituisce i due form
     * da mostrare nei modali. Il colore si salva in minuscolo.
     *
     * @param class-string $typeClass PracticeMarkType | PracticeTagType
     *
     * @return Response|array{0: FormInterface, 1: FormInterface}
     */
    private function colorValueForms(Request $request, FormFactoryInterface $formFactory, PracticeMarkRepository|PracticeTagRepository $repo, string $typeClass, string $formName, PracticeMark|PracticeTag $empty, string $label, string $route): Response|array
    {
        $formNew = $formFactory->createNamed($formName . '_new', $typeClass, clone $empty);
        $formNew->handleRequest($request);
        if ($formNew->isSubmitted() && $formNew->isValid()) {
            $entity = $formNew->getData();
            $entity->setColor(strtolower($entity->getColor()));
            $repo->add($entity);
            $this->addFlash('notice_success', $label . ' "' . $entity->getValue() . '" creato.');

            return $this->backTo($route);
        }

        $editId = $request->query->getInt('edit');
        $edited = $editId > 0 ? $repo->find($editId) : null;
        $formEdit = $formFactory->createNamed($formName . '_edit', $typeClass, $edited ?? clone $empty);
        if ($edited !== null) {
            $formEdit->handleRequest($request);
            if ($formEdit->isSubmitted()) {
                if ($formEdit->isValid()) {
                    $edited->setColor(strtolower($edited->getColor()));
                    $repo->add($edited);
                    $this->addFlash('notice_success', $label . ' aggiornato.');

                    return $this->backTo($route);
                }
                // L'elenco mostra i dati salvati, non quelli rifiutati (che restano nel modale).
                $this->em()->refresh($edited);
            }
        }

        return [$formNew, $formEdit];
    }
}
