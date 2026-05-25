<?php

namespace App\Controller;

use App\Entity\MemorialFormula;
use App\Repository\MemorialPageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    public function __construct(
        private readonly MemorialPageRepository $memorialRepo,
        private readonly EntityManagerInterface $em,   // ✅ injecté proprement, pas getDoctrine()
    ) {}

    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        return $this->render('home/index.html.twig', [
            'recentMemorials' => $this->memorialRepo->findRecentPublic(12),
        ]);
    }

    #[Route('/comment-ca-marche', name: 'app_how_it_works')]
    public function howItWorks(): Response
    {
        return $this->render('home/how_it_works.html.twig');
    }

    #[Route('/formules', name: 'app_pricing')]
    public function pricing(): Response
    {
        $formulas = $this->em->getRepository(MemorialFormula::class)
            ->findBy(['isActive' => true], ['sortOrder' => 'ASC']);

        return $this->render('home/pricing.html.twig', ['formulas' => $formulas]);
    }

    #[Route('/faq', name: 'app_faq')]
    public function faq(): Response
    {
        return $this->render('home/faq.html.twig');
    }

    #[Route('/contact', name: 'app_contact')]
    public function contact(): Response
    {
        return $this->render('home/contact.html.twig');
    }

    #[Route('/support', name: 'app_support')]
    public function support(): Response
    {
        return $this->render('home/contact.html.twig');
    }

    #[Route('/mentions-legales', name: 'app_legal_terms')]
    public function legalTerms(): Response
    {
        return $this->render('home/legal.html.twig', ['section' => 'terms']);
    }

    #[Route('/confidentialite', name: 'app_privacy')]
    public function privacy(): Response
    {
        return $this->render('home/legal.html.twig', ['section' => 'privacy']);
    }

    #[Route('/cookies', name: 'app_cookies')]
    public function cookies(): Response
    {
        return $this->render('home/legal.html.twig', ['section' => 'cookies']);
    }

    #[Route('/newsletter/subscribe', name: 'app_newsletter_subscribe', methods: ['POST'])]
    public function newsletterSubscribe(Request $request): Response
    {
        $this->addFlash('success', 'Merci pour votre inscription à notre newsletter !');
        return $this->redirect($request->headers->get('referer', '/'));
    }

    #[Route('/avis-de-deces', name: 'app_obituaries')]
    public function obituaries(Request $request): Response
    {
        $page    = max(1, (int) $request->query->get('page', 1));
        $perPage = 20;
        $all     = $this->memorialRepo->findRecentPublic(500);
        $total   = count($all);

        return $this->render('home/obituaries.html.twig', [
            'memorials'  => array_slice($all, ($page - 1) * $perPage, $perPage),
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $perPage,
            'totalPages' => (int) ceil($total / $perPage),
        ]);
    }

    // --- Pages stub ---

    #[Route('/dashboard/invitation/{id}/accept', name: 'app_invitation_accept', methods: ['GET', 'POST'])]
    public function acceptInvitation(int $id): Response
    {
        return $this->render('home/coming_soon.html.twig', ['feature' => 'Acceptation d\'invitation']);
    }

    #[Route('/notifications', name: 'app_notifications')]
    public function notifications(): Response
    {
        return $this->render('home/coming_soon.html.twig', ['feature' => 'Notifications']);
    }

    #[Route('/profile', name: 'app_profile')]
    public function profile(): Response
    {
        return $this->render('home/coming_soon.html.twig', ['feature' => 'Mon profil']);
    }


    #[Route('/memorial/create', name: 'app_memorial_create')]
    public function createMemorial(): Response
    {
        return $this->render('home/coming_soon.html.twig', ['feature' => 'Création d\'une page mémorielle']);
    }
}
