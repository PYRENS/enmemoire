<?php

namespace App\Controller;

use App\Entity\MemorialFormula;
use App\Entity\MemorialPage;
use App\Entity\Payment;
use App\Repository\MemorialFormulaRepository;
use App\Repository\MemorialPageRepository;
use App\Service\NotificationService;
use App\Service\Payment\PaymentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/memorial/{slug}/renouveler')]
#[IsGranted('ROLE_USER')]
class RenewalController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface    $em,
        private readonly MemorialPageRepository    $memorialRepo,
        private readonly MemorialFormulaRepository $formulaRepo,
        private readonly NotificationService       $notifService,
        private readonly PaymentService            $paymentService,
        private readonly UrlGeneratorInterface     $router,
    ) {}

    #[Route('', name: 'app_renewal', methods: ['GET'])]
    public function index(string $slug): Response
    {
        $page     = $this->getPageOrDeny($slug);
        $formulas = $this->formulaRepo->findBy(['isActive' => true], ['sortOrder' => 'ASC', 'price' => 'ASC']);

        $daysLeft = null;
        if ($page->getExpiresAt()) {
            $diff     = (new \DateTime())->diff($page->getExpiresAt());
            $daysLeft = (int) $diff->days * ($page->getExpiresAt() >= new \DateTime() ? 1 : -1);
        }

        return $this->render('renewal/index.html.twig', [
            'page'           => $page,
            'formulas'       => $formulas,
            'daysLeft'       => $daysLeft,
            'currentFormula' => $page->getFormula(),
            'providers'      => PaymentService::PROVIDERS,
            'providersByZone'=> PaymentService::getProvidersByZone(),
        ]);
    }

    #[Route('/payer', name: 'app_renewal_pay', methods: ['POST'])]
    public function pay(string $slug, Request $request): Response
    {
        $page = $this->getPageOrDeny($slug);

        if (!$this->isCsrfTokenValid('renewal_' . $page->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token invalide.');
            return $this->redirectToRoute('app_renewal', ['slug' => $slug]);
        }

        $formulaId = (int) $request->request->get('formula_id');
        $provider  = $request->request->get('provider', 'stripe');

        $formula = $this->formulaRepo->find($formulaId);
        if (!$formula) {
            $this->addFlash('error', 'Formule introuvable.');
            return $this->redirectToRoute('app_renewal', ['slug' => $slug]);
        }

        $intent = $this->paymentService->initiatePayment(
            $this->getUser(),
            $formula,
            $provider,
            'USD',
            [
                'renewal'        => true,
                'memorial_slug'  => $slug,
                'formula_id'     => $formula->getId(),
                'formula_name'   => $formula->getName(),
                'duration_years' => $formula->getDurationYears(),
            ]
        );

        if ($intent->action === 'redirect') {
            return $this->redirect($intent->redirectUrl);
        }

        return $this->render('payment/instructions.html.twig', [
            'payment'      => $intent->payment,
            'instructions' => $intent->instructions,
            'slug'         => $slug,
        ]);
    }

    #[Route('/succes/{paymentId}', name: 'app_renewal_success', methods: ['GET'])]
    public function success(string $slug, int $paymentId): Response
    {
        $page    = $this->getPageOrDeny($slug);
        $payment = $this->em->getRepository(Payment::class)->find($paymentId);
        if (!$payment) throw $this->createNotFoundException();

        return $this->render('renewal/success.html.twig', [
            'page'    => $page,
            'payment' => $payment,
        ]);
    }

    private function getPageOrDeny(string $slug): MemorialPage
    {
        $page = $this->memorialRepo->findBySlug($slug);
        if (!$page) throw $this->createNotFoundException();
        if ($page->getCreatedBy()->getId() !== $this->getUser()->getId()) {
            throw $this->createAccessDeniedException();
        }
        return $page;
    }
}
