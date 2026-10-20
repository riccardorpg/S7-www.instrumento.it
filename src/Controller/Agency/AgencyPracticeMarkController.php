<?php

namespace App\Controller\Agency;

use App\Controller\Agency\Trait\AgencyContextTrait;
use App\Controller\Agency\Trait\ColorValueFormsTrait;
use App\Entity\PracticeMark;
use App\Form\PracticeMarkType;
use App\Repository\PracticeMarkRepository;
use App\Service\FilterService;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * 13.2 Contrassegni delle pratiche (sottomenu "Pratiche"): catalogo dell'agenzia, nel suo DB.
 * Nuovo e modifica nei modali dell'elenco (CONVENZIONI 6, ?edit=ID), eliminazione con conferma.
 * Permesso: sezione "configurations" (lettura per vedere, rw per modificare).
 */
#[Route('/agenzia/pratiche/contrassegni')]
#[IsGranted('ROLE_USER')]
#[IsGranted(new Expression("is_granted('view', 'configurations')"), message: 'Non hai accesso ai contrassegni delle pratiche.')]
class AgencyPracticeMarkController extends AbstractController
{
    use AgencyContextTrait;
    use ColorValueFormsTrait;

    private const PER_PAGE = 20;

    public function __construct(private readonly ManagerRegistry $registry)
    {
    }

    /** 13.2.1 Elenco dei contrassegni, con i form di nuovo (13.2.2) e modifica (13.2.1.1) nei modali. */
    #[Route('/lista', name: 'agency_practice_marks', methods: ['GET', 'POST'])]
    public function agencyPracticeMarks(Request $request, PaginatorInterface $paginator, FormFactoryInterface $formFactory): Response
    {
        // I form di nuovo e modifica scrivono: serve il permesso di modifica (rw), non basta la lettura.
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted('edit', 'configurations', 'Non hai il permesso di modificare i contrassegni.');
        }

        /** @var PracticeMarkRepository $marks */
        $marks = $this->em()->getRepository(PracticeMark::class);
        $forms = $this->colorValueForms($request, $formFactory, $marks, PracticeMarkType::class, 'practice_mark', (new PracticeMark())->setCompany($this->company()), 'Contrassegno', 'agency_practice_marks');
        if ($forms instanceof Response) {
            return $forms;
        }

        $filters = FilterService::colorValueFilter($request, 'agency_practice_marks');

        return $this->render('role/agency/practice/mark/list.html.twig', [
            'records' => $paginator->paginate($marks->queryAllFiltered($filters), FilterService::page($request, 'agency_practice_marks'), self::PER_PAGE),
            'filters' => $filters,
            'formNew' => $forms[0]->createView(),
            'formEdit' => $forms[1]->createView(),
        ]);
    }

    /** 13.2.1.2 Elimina. */
    #[Route('/{id}/elimina', name: 'agency_practice_mark_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression("is_granted('edit', 'configurations')"), message: 'Non hai il permesso di modificare i contrassegni.')]
    public function agencyPracticeMarkDelete(int $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('delete', (string) $request->request->get('_csrf_token'))) {
            return $this->backTo('agency_practice_marks');
        }

        $em = $this->em();
        $mark = $em->getRepository(PracticeMark::class)->find($id);
        if ($mark === null) {
            throw $this->createNotFoundException('Contrassegno non trovato.');
        }

        $value = $mark->getValue();
        $em->remove($mark);
        $em->flush();
        $this->addFlash('notice_success', 'Contrassegno "' . $value . '" eliminato.');

        return $this->backTo('agency_practice_marks');
    }
}
