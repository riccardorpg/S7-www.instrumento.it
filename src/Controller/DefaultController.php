<?php

namespace App\Controller;

use App\Entity\Master\DemoRequest;
use App\Form\ContactType;
use App\Form\DemoRequestType;
use App\Form\Model\Contact;
use App\Repository\Master\DemoRequestRepository;
use App\Service\MailerService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Parte esterna (pubblica) del sito: homepage marketing, form contatti/demo, pagine legali.
 *
 * I due moduli (contatti e demo) sono form Symfony gestiti dalla homepage stessa: la validazione
 * sta nel validator (Model/Contact.yml, Master/DemoRequest.yml), poi notifica via e-mail (14.1 / 14.2)
 * e flash di conferma. TODO: verifica reCaptcha server-side.
 */
class DefaultController extends AbstractController
{
    /**
     * Homepage, con i due moduli della sezione Contatti (CONVENZIONI 6): si inviano a questa
     * stessa pagina, che li gestisce e torna a #contatti con il messaggio di conferma; se non sono
     * validi la pagina si ripresenta con il modulo aperto, i campi segnati e i dati già scritti.
     */
    #[Route('/', name: 'homepage', methods: ['GET', 'POST'])]
    public function home(Request $request, FormFactoryInterface $formFactory, DemoRequestRepository $demoRequests, MailerService $mailer): Response
    {
        // 14.1 Richiedi informazioni: non si salva, diventa una notifica interna.
        $formContact = $formFactory->createNamed('contact', ContactType::class, new Contact());
        $formContact->handleRequest($request);
        if ($formContact->isSubmitted() && $formContact->isValid()) {
            /** @var Contact $contact */
            $contact = $formContact->getData();
            // TODO: verifica reCaptcha server-side.
            $mailer->contactRequest($contact->getName(), $contact->getSurname(), $contact->getEmail(), (string) $contact->getMessage());
            $this->addFlash('notice_success', 'Grazie ' . $contact->getName() . '! Abbiamo ricevuto la tua richiesta e ti risponderemo al più presto.');

            return $this->redirect($this->generateUrl('homepage') . '#contatti', Response::HTTP_SEE_OTHER);
        }

        // Attiva la demo: la richiesta (lead) si evade in area admin (allarme 6.1.1).
        $formDemo = $formFactory->createNamed('demo_request', DemoRequestType::class, new DemoRequest());
        $formDemo->handleRequest($request);
        if ($formDemo->isSubmitted() && $formDemo->isValid()) {
            /** @var DemoRequest $demo */
            $demo = $formDemo->getData();
            // TODO: verifica reCaptcha server-side.
            $demoRequests->add($demo);
            // 14.2 Avvisa l'indirizzo interno: la richiesta è anche un allarme in scrivania.
            $mailer->demoRequest($demo);
            $this->addFlash('notice_success', 'Richiesta demo ricevuta! Ti invieremo a breve le istruzioni di accesso all\'indirizzo ' . $demo->getEmail() . '.');

            return $this->redirect($this->generateUrl('homepage') . '#contatti', Response::HTTP_SEE_OTHER);
        }

        // Modulo inviato con errori: la pagina lo riapre (come faceva l'ancora #contatti-contact /
        // #contatti-demo) e ci porta dentro il messaggio, oltre ai campi segnati.
        $openPanel = null;
        foreach (['contact' => $formContact, 'demo' => $formDemo] as $panel => $form) {
            if ($form->isSubmitted()) {
                $openPanel = $panel;
                $messages = array_map(static fn ($e) => $e->getMessage(), iterator_to_array($form->getErrors()));
                $this->addFlash('notice_danger', trim(implode(' ', $messages) . ' Controlla i campi evidenziati in rosso.'));
            }
        }

        // Sezione da raggiungere in scroll (impostata da homepage_with_section quando si
        // arriva da un'altra pagina). Consumata una sola volta.
        $section = null;
        if ($request->hasSession() && $request->getSession()->has('section')) {
            $section = (string) $request->getSession()->get('section');
            $request->getSession()->remove('section');
        }

        return $this->render('default/index.html.twig', [
            'section' => $section,
            'openPanel' => $openPanel,
            'formContact' => $formContact->createView(),
            'formDemo' => $formDemo->createView(),
        ]);
    }

    #[Route('/home/{section}', name: 'homepage_with_section', methods: ['GET'])]
    public function indexWithSection(Request $request, string $section): Response
    {
        // Salva la sezione in sessione e reindirizza alla homepage, che vi scorrerà.
        $request->getSession()->set('section', $section);

        return $this->redirectToRoute('homepage');
    }

    #[Route('/cookie-policy', name: 'legal_cookie', methods: ['GET'])]
    public function cookie(): Response
    {
        return $this->render('default/legal.html.twig', [
            'title' => 'Normativa cookie',
        ]);
    }

    #[Route('/privacy-policy', name: 'legal_privacy', methods: ['GET'])]
    public function privacy(): Response
    {
        return $this->render('default/legal.html.twig', [
            'title' => 'Normativa privacy',
        ]);
    }

    #[Route('/termini-e-condizioni', name: 'legal_terms', methods: ['GET'])]
    public function terms(): Response
    {
        return $this->render('default/legal.html.twig', [
            'title' => 'Termini e condizioni di utilizzo',
        ]);
    }
}
