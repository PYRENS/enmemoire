<?php

namespace App\Controller;

use App\Entity\MemorialPage;
use App\Entity\Payment;
use App\Repository\MemorialFormulaRepository;
use App\Repository\MemorialThemeRepository;
use App\Service\MemorialPageService;
use App\Service\Payment\PaymentService;
use App\Entity\MemorialFormula;
use App\Entity\MemorialTheme;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\MemorialEmailService;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/payment')]
class PaymentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface  $em,
        private readonly PaymentService          $paymentService,
        private readonly MemorialPageService     $memorialService,
        private readonly MemorialEmailService    $memorialEmailService,
    ) {}

    // =========================================================
    // SANDBOX — simulation de paiement en développement
    // =========================================================
    #[Route('/sandbox/{paymentId}/{provider}', name: 'app_payment_sandbox')]
    #[IsGranted('ROLE_USER')]
    public function sandbox(int $paymentId, string $provider, Request $request): Response
    {
        $payment = $this->em->getRepository(Payment::class)->find($paymentId);

        if (!$payment || $payment->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            $action = $request->request->get('action');

            if ($action === 'success') {
                return $this->redirectToRoute('app_payment_success', ['paymentId' => $paymentId]);
            } else {
                return $this->redirectToRoute('app_payment_cancel', ['paymentId' => $paymentId]);
            }
        }

        return $this->render('payment/sandbox.html.twig', [
            'payment'  => $payment,
            'provider' => $provider,
            'label'    => PaymentService::PROVIDERS[$provider]['label'] ?? $provider,
        ]);
    }

    // =========================================================
    // SUCCÈS — paiement validé → créer la page mémorielle
    // =========================================================
    #[Route('/success/{paymentId}', name: 'app_payment_success')]
    #[IsGranted('ROLE_USER')]
    public function success(int $paymentId, Request $request): Response
    {
        $payment = $this->em->getRepository(Payment::class)->find($paymentId);

        if (!$payment || $payment->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        // Éviter le double traitement
        if ($payment->isCompleted()) {
            // Chercher la page déjà créée
            $page = $this->em->getRepository(MemorialPage::class)
                ->findOneBy(['createdBy' => $this->getUser()]);

            if ($page) {
                return $this->redirectToRoute('app_dashboard_memorial', ['slug' => $page->getSlug()]);
            }
        }

        // Confirmer le paiement
        $this->paymentService->confirmPayment($payment);

        // Récupérer les données de session
        $session  = $request->getSession();
        $step1    = $session->get('memorial_create_step1', []);
        $step2    = $session->get('memorial_create_step2', []);
        $metadata = $payment->getMetadata() ?? [];
        $s1       = $step1 ?: ($metadata['step1'] ?? []);
        $s2       = $step2 ?: ($metadata['step2'] ?? []);

        if (empty($s1) || empty($s2)) {
            $this->addFlash('success', 'Paiement confirmé ! Créez maintenant votre page mémorielle.');
            return $this->redirectToRoute('app_dashboard');
        }

        // Créer la page mémorielle
        $formula = $this->em->getRepository(MemorialFormula::class)->find($s2['formula_id']);
        $theme   = $this->em->getRepository(MemorialTheme::class)->find($s2['theme_id'] ?? 1);

        $page = new MemorialPage();
        $page->setDeceasedFirstName($s1['first_name'])
             ->setDeceasedLastName($s1['last_name'])
             ->setDeceasedNickname($s1['nickname'] ?: null)
             ->setDeceasedBirthDate(new \DateTime($s1['birth_date']))
             ->setDeceasedDeathDate(new \DateTime($s1['death_date']))
             ->setDeceasedBirthPlace($s1['birth_place'] ?: null)
             ->setDeceasedDeathPlace($s1['death_place'])
             ->setDeceasedProfession($s1['profession'] ?: null)
             ->setDeceasedQuote($s1['quote'] ?: null)
             ->setObituaryText($s1['obituary'] ?: null)
             ->setVisibility($s1['visibility'] ?? 'public')
             ->setFormula($formula)
             ->setTheme($theme ?? $this->em->getRepository(MemorialTheme::class)->findOneBy(['sortOrder' => 1]));

        $this->memorialService->createMemorialPage($page, $this->getUser());

        // Lier le paiement à la page
        $meta = $payment->getMetadata() ?? [];
        $meta['memorial_page_id'] = $page->getId();
        $payment->setMetadata($meta);
        $this->em->flush();

        // Envoyer l'email de confirmation + facture au modérateur
        try {
            $this->memorialEmailService->sendMemorialCreatedConfirmation(
                $page,
                $payment,
                $this->getUser(),
            );
        } catch (\Exception $e) {
            // Non bloquant — log uniquement
            error_log('[EnMémoire] Email confirmation failed: ' . $e->getMessage());
        }

        // Nettoyer la session
        $session->remove('memorial_create_step1');
        $session->remove('memorial_create_step2');
        $session->remove('pending_payment_id');

        $this->addFlash('success', '🎉 Page mémorielle créée ! Un email de confirmation vous a été envoyé.');
        return $this->redirectToRoute('app_dashboard_memorial', ['slug' => $page->getSlug()]);
    }

    // =========================================================
    // ANNULATION
    // =========================================================
    #[Route('/cancel/{paymentId}', name: 'app_payment_cancel')]
    #[IsGranted('ROLE_USER')]
    public function cancel(int $paymentId): Response
    {
        $payment = $this->em->getRepository(Payment::class)->find($paymentId);

        if ($payment && $payment->getUser() === $this->getUser()) {
            $this->paymentService->failPayment($payment, 'Annulé par l\'utilisateur');
        }

        $this->addFlash('warning', 'Paiement annulé. Vous pouvez réessayer à tout moment.');
        return $this->redirectToRoute('app_memorial_create_step3');
    }

    // =========================================================
    // CONFIRMATION MOBILE MONEY (upload preuve)
    // =========================================================
    #[Route('/mobile/confirm/{paymentId}', name: 'app_payment_mobile_confirm', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function mobileConfirm(int $paymentId, Request $request): Response
    {
        $payment = $this->em->getRepository(Payment::class)->find($paymentId);

        if (!$payment || $payment->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            // L'admin devra valider manuellement
            $meta = $payment->getMetadata() ?? [];
            $meta['mobile_confirmation'] = [
                'submitted_at' => (new \DateTime())->format('Y-m-d H:i:s'),
                'phone'        => $request->request->get('phone', ''),
                'tx_id'        => $request->request->get('tx_id', ''),
                'note'         => $request->request->get('note', ''),
            ];
            $payment->setMetadata($meta);
            $payment->setStatus('pending_manual_review');
            $this->em->flush();

            $this->addFlash('success', 'Confirmation reçue ! Votre paiement sera validé sous 24h par notre équipe.');
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('payment/mobile_confirm.html.twig', ['payment' => $payment]);
    }

    // =========================================================
    // WEBHOOK STRIPE
    // =========================================================
    #[Route('/webhook/stripe', name: 'app_payment_webhook_stripe', methods: ['POST'])]
    public function webhookStripe(Request $request): JsonResponse
    {
        // En production : vérifier la signature Stripe
        // $sig = $request->headers->get('Stripe-Signature');
        // $event = \Stripe\Webhook::constructEvent($request->getContent(), $sig, $webhookSecret);

        $payload = json_decode($request->getContent(), true);
        $type    = $payload['type'] ?? '';

        if ($type === 'checkout.session.completed') {
            $paymentId = $payload['data']['object']['metadata']['payment_id'] ?? null;
            if ($paymentId) {
                $payment = $this->em->getRepository(Payment::class)->find($paymentId);
                if ($payment) {
                    $this->paymentService->confirmPayment($payment);
                }
            }
        }

        return $this->json(['received' => true]);
    }
}
