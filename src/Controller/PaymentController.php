<?php

namespace App\Controller;

use App\Entity\MemorialPage;
use App\Entity\Payment;
use App\Repository\MemorialFormulaRepository;
use App\Service\MemorialPageService;
use App\Service\Payment\PaymentService;
use App\Service\Payment\StripeService;
use App\Service\Payment\PayPalService;
use App\Entity\MemorialFormula;
use App\Entity\MemorialTheme;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\MemorialEmailService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/payment')]
class PaymentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface  $em,
        private readonly PaymentService          $paymentService,
        private readonly MemorialPageService     $memorialService,
        private readonly MemorialEmailService    $memorialEmailService,
        private readonly StripeService           $stripeService,
        private readonly PayPalService           $paypalService,
        private readonly LoggerInterface        $logger,
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

        // PayPal : capturer l'ordre si nécessaire
        $paypalToken = $request->query->get('token');
        if ($paypalToken && $payment->getProvider() === 'paypal') {
            $meta = $payment->getMetadata() ?? [];
            $orderId = $meta['paypal_order_id'] ?? $paypalToken;
            $this->paypalService->captureOrder($orderId);
            $payment->setProviderTxId($orderId);
        }

        // Stripe : vérifier session
        $stripeSessionId = $request->query->get('session_id');
        if ($stripeSessionId) {
            $payment->setProviderTxId($stripeSessionId);
        }
        if ($payment->isCompleted()) {
            $meta = $payment->getMetadata() ?? [];
            if (!empty($meta['renewal']) && !empty($meta['memorial_slug'])) {
                $this->addFlash('success', '✅ Renouvellement confirmé ! Un email de confirmation vous a été envoyé.');
                return $this->redirectToRoute('app_dashboard_memorial', ['slug' => $meta['memorial_slug']]);
            }
            $page = $this->em->getRepository(MemorialPage::class)->findOneBy(['createdBy' => $this->getUser()]);
            if ($page) { return $this->redirectToRoute('app_dashboard_memorial', ['slug' => $page->getSlug()]); }
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

        // Renouvellement ?
        if (!empty($metadata['renewal']) && !empty($metadata['memorial_slug'])) {
            $page = $this->em->getRepository(MemorialPage::class)
                ->findOneBy(['slug' => $metadata['memorial_slug']]);

            if ($page) {
                $formula  = $this->em->getRepository(MemorialFormula::class)->find($metadata['formula_id']);
                $years    = $formula ? ($formula->getDurationYears() ?? 1) : 1;
                $months   = $years * 12;

                $base = ($page->getExpiresAt() && $page->getExpiresAt() > new \DateTime())
                    ? $page->getExpiresAt() : new \DateTime();
                $newExpiry = (clone $base)->modify("+{$months} months");

                $page->setExpiresAt($newExpiry)->setStatus(MemorialPage::STATUS_ACTIVE);
                if ($formula) $page->setFormula($formula);
                $this->em->flush();

                $this->addFlash('success', '✅ Page renouvelée jusqu\'au ' . $newExpiry->format('d/m/Y'));
                return $this->redirectToRoute('app_dashboard_memorial', ['slug' => $page->getSlug()]);
            }
        }

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

        // Email de confirmation
        try {
            $this->memorialEmailService->sendMemorialCreatedConfirmation($page, $payment, $this->getUser());
        } catch (\Exception $e) {
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

            // Renouvellement ?
            if (!empty($meta['renewal'])) {
                return $this->redirectToRoute('app_dashboard');
            }

            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('payment/mobile_confirm.html.twig', ['payment' => $payment]);
    }

    // =========================================================
    // WEBHOOK STRIPE (production)
    // =========================================================
    #[Route('/webhook/stripe', name: 'app_payment_webhook_stripe', methods: ['POST'])]
    public function webhookStripe(
        Request $request,
        #[Autowire('%env(STRIPE_WEBHOOK_SECRET)%')] string $webhookSecret = '',
    ): JsonResponse {
        $payload = $request->getContent();
        $sig     = $request->headers->get('Stripe-Signature', '');

        // Décoder l'événement
        $raw  = json_decode($payload, true);
        $type = $raw['type'] ?? '';

        // Vérifier la signature si configurée
        if ($webhookSecret && $sig) {
            $verified = $this->stripeService->verifyWebhook($payload, $sig, $webhookSecret);
            if (!$verified) {
                return $this->json(['error' => 'Invalid signature'], 400);
            }
        }

        $this->logger->info('[STRIPE] Webhook reçu: ' . $type);

        if ($type === 'checkout.session.completed') {
            // L'objet session Stripe
            $session   = $raw['data']['object'] ?? [];
            $metadata  = $session['metadata'] ?? [];
            $paymentId = $metadata['payment_id'] ?? null;
            $sessionId = $session['id'] ?? '';

            $this->logger->info('[STRIPE] payment_id: ' . ($paymentId ?? 'NULL') . ' | session: ' . $sessionId);

            if (!$paymentId) {
                return $this->json(['received' => true, 'warning' => 'no payment_id']);
            }

            $payment = $this->em->getRepository(Payment::class)->find($paymentId);
            if (!$payment) {
                return $this->json(['received' => true, 'warning' => 'payment not found']);
            }

            // Confirmer le paiement si pas encore fait
            if (!$payment->isCompleted()) {
                $payment->setProviderTxId($sessionId);
                $this->paymentService->confirmPayment($payment);
                $this->logger->info('[STRIPE] Paiement ' . $paymentId . ' confirmé');
            }

            // Traiter le renouvellement + email (même si déjà completed)
            $meta = $payment->getMetadata() ?? [];
            if (!empty($meta['renewal']) && !empty($meta['memorial_slug']) && empty($meta['email_sent'])) {
                $page = $this->em->getRepository(MemorialPage::class)
                    ->findOneBy(['slug' => $meta['memorial_slug']]);

                if ($page) {
                    // Mettre à jour la date d'expiration
                    $formula = $this->em->getRepository(\App\Entity\MemorialFormula::class)
                        ->find($meta['formula_id'] ?? 0);
                    $years   = $formula ? ($formula->getDurationYears() ?? 1) : 1;
                    $months  = $years * 12;
                    $base    = ($page->getExpiresAt() && $page->getExpiresAt() > new \DateTime())
                        ? $page->getExpiresAt() : new \DateTime();
                    $newExpiry = (clone $base)->modify("+{$months} months");

                    $page->setExpiresAt($newExpiry)->setStatus(MemorialPage::STATUS_ACTIVE);
                    if ($formula) $page->setFormula($formula);

                    // Marquer email envoyé
                    $meta['email_sent'] = true;
                    $payment->setMetadata($meta);
                    $this->em->flush();

                    $this->logger->info('[STRIPE] Page renouvelée jusqu au ' . $newExpiry->format('Y-m-d'));

                    // Envoyer email de confirmation
                    $owner = $payment->getUser();
                    if ($owner) {
                        try {
                            $this->memorialEmailService->sendRenewalConfirmation($page, $payment, $owner);
                            $this->logger->info('[STRIPE] Email envoyé à ' . $owner->getEmail());
                        } catch (\Exception $e) {
                            $this->logger->error('[STRIPE] Email FAILED: ' . $e->getMessage());
                        }
                    }
                }
            }
        }

        return $this->json(['received' => true]);
    }

    // =========================================================
    // WEBHOOK PAYPAL (production)
    // =========================================================
    #[Route('/webhook/paypal', name: 'app_payment_webhook_paypal', methods: ['POST'])]
    public function webhookPaypal(Request $request): JsonResponse
    {
        $data   = json_decode($request->getContent(), true);
        $event  = $data['event_type'] ?? '';
        $resource = $data['resource'] ?? [];

        if ($event === 'CHECKOUT.ORDER.APPROVED' || $event === 'PAYMENT.CAPTURE.COMPLETED') {
            $customId = $resource['purchase_units'][0]['custom_id']
                ?? $resource['custom_id']
                ?? null;

            if ($customId) {
                $payment = $this->em->getRepository(Payment::class)->find($customId);
                if ($payment && !$payment->isCompleted()) {
                    $payment->setProviderTxId($resource['id'] ?? '');
                    $this->paymentService->confirmPayment($payment);
                }
            }
        }

        return $this->json(['received' => true]);
    }
}
