<?php

namespace App\Controller;

use App\Entity\MemorialFormula;
use App\Entity\MemorialPage;
use App\Entity\MemorialTheme;
use App\Service\MemorialPageService;
use App\Service\Payment\PaymentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/creer-un-memorial')]
#[IsGranted('ROLE_USER')]
class MemorialCreateController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MemorialPageService    $memorialService,
        private readonly PaymentService         $paymentService,
        private readonly SluggerInterface       $slugger,
    ) {}

    // =========================================================
    // ÉTAPE 1 — Informations du défunt
    // =========================================================
    #[Route('', name: 'app_memorial_create', methods: ['GET', 'POST'])]
    public function step1(Request $request): Response
    {
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('memorial_create_step1', $request->request->get('_token'))) {
                $error = 'Token de sécurité invalide.';
            } else {
                $data = [
                    'first_name'   => trim($request->request->get('first_name', '')),
                    'last_name'    => trim($request->request->get('last_name', '')),
                    'nickname'     => trim($request->request->get('nickname', '')),
                    'birth_date'   => $request->request->get('birth_date', ''),
                    'death_date'   => $request->request->get('death_date', ''),
                    'birth_place'  => trim($request->request->get('birth_place', '')),
                    'death_place'  => trim($request->request->get('death_place', '')),
                    'profession'   => trim($request->request->get('profession', '')),
                    'quote'        => trim($request->request->get('quote', '')),
                    'obituary'     => trim($request->request->get('obituary', '')),
                    'visibility'   => $request->request->get('visibility', 'public'),
                ];

                // Validations
                if (empty($data['first_name']) || empty($data['last_name'])) {
                    $error = 'Le prénom et le nom du défunt sont obligatoires.';
                } elseif (empty($data['birth_date']) || empty($data['death_date'])) {
                    $error = 'Les dates de naissance et de décès sont obligatoires.';
                } elseif (empty($data['death_place'])) {
                    $error = 'Le lieu de décès est obligatoire.';
                } elseif (new \DateTime($data['death_date']) < new \DateTime($data['birth_date'])) {
                    $error = 'La date de décès doit être postérieure à la date de naissance.';
                } else {
                    // Sauvegarder en session et passer à l'étape 2
                    $request->getSession()->set('memorial_create_step1', $data);
                    return $this->redirectToRoute('app_memorial_create_step2');
                }
            }
        }

        return $this->render('memorial/create/step1.html.twig', [
            'error' => $error,
            'form'  => $request->request->all(),
        ]);
    }

    // =========================================================
    // ÉTAPE 2 — Choix de la formule et du thème
    // =========================================================
    #[Route('/formule', name: 'app_memorial_create_step2', methods: ['GET', 'POST'])]
    public function step2(Request $request): Response
    {
        // Vérifier que l'étape 1 est complète
        if (!$request->getSession()->has('memorial_create_step1')) {
            return $this->redirectToRoute('app_memorial_create');
        }

        $formulas = $this->em->getRepository(MemorialFormula::class)
            ->findBy(['isActive' => true], ['sortOrder' => 'ASC']);

        $themes = $this->em->getRepository(MemorialTheme::class)
            ->findBy(['isActive' => true, 'type' => 'free'], ['sortOrder' => 'ASC']);

        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('memorial_create_step2', $request->request->get('_token'))) {
                $error = 'Token invalide.';
            } else {
                $formulaId = (int) $request->request->get('formula_id');
                $themeId   = (int) $request->request->get('theme_id', 1);

                $formula = $this->em->getRepository(MemorialFormula::class)->find($formulaId);
                $theme   = $this->em->getRepository(MemorialTheme::class)->find($themeId);

                if (!$formula) {
                    $error = 'Veuillez choisir une formule.';
                } else {
                    $request->getSession()->set('memorial_create_step2', [
                        'formula_id' => $formulaId,
                        'theme_id'   => $themeId,
                    ]);
                    return $this->redirectToRoute('app_memorial_create_step3');
                }
            }
        }

        return $this->render('memorial/create/step2.html.twig', [
            'formulas'   => $formulas,
            'themes'     => $themes,
            'step1_data' => $request->getSession()->get('memorial_create_step1'),
            'error'      => $error,
        ]);
    }

    // =========================================================
    // ÉTAPE 3 — Paiement
    // =========================================================
    #[Route('/paiement', name: 'app_memorial_create_step3', methods: ['GET', 'POST'])]
    public function step3(Request $request): Response
    {
        $session = $request->getSession();

        if (!$session->has('memorial_create_step1') || !$session->has('memorial_create_step2')) {
            return $this->redirectToRoute('app_memorial_create');
        }

        $step2   = $session->get('memorial_create_step2');
        $formula = $this->em->getRepository(MemorialFormula::class)->find($step2['formula_id']);

        if (!$formula) {
            return $this->redirectToRoute('app_memorial_create_step2');
        }

        $error   = null;
        $providersByZone = PaymentService::getProvidersByZone();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('memorial_create_step3', $request->request->get('_token'))) {
                $error = 'Token invalide.';
            } else {
                $provider = $request->request->get('provider');
                $currency = $request->request->get('currency', 'USD');

                if (!array_key_exists($provider, PaymentService::PROVIDERS)) {
                    $error = 'Veuillez choisir un moyen de paiement.';
                } else {
                    try {
                        $intent = $this->paymentService->initiatePayment(
                            $this->getUser(),
                            $formula,
                            $provider,
                            $currency,
                            ['step1' => $session->get('memorial_create_step1'), 'step2' => $step2]
                        );

                        // Sauvegarder l'ID du paiement en session
                        $session->set('pending_payment_id', $intent->payment->getId());

                        if ($intent->action === 'redirect') {
                            return $this->redirect($intent->redirectUrl);
                        }

                        // Mobile money ou virement → afficher les instructions
                        return $this->render('payment/instructions.html.twig', [
                            'payment'      => $intent->payment,
                            'formula'      => $formula,
                            'instructions' => $intent->instructions,
                        ]);

                    } catch (\Exception $e) {
                        $error = 'Erreur lors de l\'initiation du paiement : ' . $e->getMessage();
                    }
                }
            }
        }

        return $this->render('memorial/create/step3.html.twig', [
            'formula'         => $formula,
            'step1_data'      => $session->get('memorial_create_step1'),
            'providersByZone' => $providersByZone,
            'error'           => $error,
        ]);
    }

    // =========================================================
    // RETOUR ARRIÈRE
    // =========================================================
    #[Route('/retour/{step}', name: 'app_memorial_create_back', methods: ['GET'], requirements: ['step' => '\d+'])]
    public function back(int $step, Request $request): Response
    {
        return match($step) {
            1 => $this->redirectToRoute('app_memorial_create'),
            2 => $this->redirectToRoute('app_memorial_create_step2'),
            default => $this->redirectToRoute('app_memorial_create'),
        };
    }
}
