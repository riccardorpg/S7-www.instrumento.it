<?php

namespace App\Controller\Agency;

use App\Controller\Agency\Trait\AgencyContextTrait;
use App\Controller\Agency\Trait\ColorValueFormsTrait;
use App\Entity\PracticeTag;
use App\Form\PracticeTagType;
use App\Repository\PracticeTagRepository;
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
 * 13.3 Tag delle pratiche (sottomenu "Pratiche"): catalogo dell'agenzia, nel suo DB.
 * Nuovo e modifica nei modali dell'elenco (CONVENZIONI 6, ?edit=ID), eliminazione con conferma.
 * Permesso: sezione "configurations" (lettura per vedere, rw per modificare).
 */
#[Route('/agenzia/pratiche/tag')]
#[IsGranted('ROLE_USER')]
#[IsGranted(new Expression("is_granted('view', 'configurations')"), message: 'Non hai accesso ai tag delle pratiche.')]
class AgencyPracticeTagController extends AbstractController
{
    use AgencyContextTrait;
    use ColorValueFormsTrait;

    private const PER_PAGE = 20;

    public function __construct(private readonly ManagerRegistry $registry)
    {
    }

    /** 13.3.1 Elenco dei tag, con i form di nuovo (13.3.2) e modifica (13.3.1.1) nei modali. */
    #[Route('/lista', name: 'agency_practice_tags', methods: ['GET', 'POST'])]
    public function agencyPracticeTags(Request $request, PaginatorInterface $paginator, FormFactoryInterface $formFactory): Response
    {
        // I form di nuovo e modifica scrivono: serve il permesso di modifica (rw), non basta la lettura.
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted('edit', 'configurations', 'Non hai il permesso di modificare i tag.');
        }

        /** @var PracticeTagRepository $tags */
        $tags = $this->em()->getRepository(PracticeTag::class);
        $forms = $this->colorValueForms($request, $formFactory, $tags, PracticeTagType::class, 'practice_tag', (new PracticeTag())->setCompany($this->company()), 'Tag', 'agency_practice_tags');
        if ($forms instanceof Response) {
            return $forms;
        }

        $filters = FilterService::colorValueFilter($request, 'agency_practice_tags');

        return $this->render('role/agency/practice/tag/list.html.twig', [
            'records' => $paginator->paginate($tags->queryAllFiltered($filters), FilterService::page($request, 'agency_practice_tags'), self::PER_PAGE),
            'filters' => $filters,
            'formNew' => $forms[0]->createView(),
            'formEdit' => $forms[1]->createView(),
        ]);
    }

    /** 13.3.1.2 Elimina. */
    #[Route('/{id}/elimina', name: 'agency_practice_tag_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression("is_granted('edit', 'configurations')"), message: 'Non hai il permesso di modificare i tag.')]
    public function agencyPracticeTagDelete(int $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('delete', (string) $request->request->get('_csrf_token'))) {
            return $this->backTo('agency_practice_tags');
        }

        $em = $this->em();
        $tag = $em->getRepository(PracticeTag::class)->find($id);
        if ($tag === null) {
            throw $this->createNotFoundException('Tag non trovato.');
        }

        $value = $tag->getValue();
        $em->remove($tag);
        $em->flush();
        $this->addFlash('notice_success', 'Tag "' . $value . '" eliminato.');

        return $this->backTo('agency_practice_tags');
    }
}
