<?php

namespace App\Controller;

use App\Entity\MemorialPage;
use App\Entity\Payment;
use App\Repository\MemorialFormulaRepository;
use App\Service\MemorialPageService;
use App\Service\Payment\GadgetPaymentService;
use App\Service\Payment\PaymentService;
use App\Service\Payment\StripeService;
use App\Service\Payment\PayPalService;
use App\Service\Payment\PawaPayService;
use App\Service\Payment\MobileMoneyService;
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
        private readonly LoggerInterface         $logger,
        private readonly PawaPayService          $pawaPayService,
        private readonly MobileMoneyService      $mobileMoneyService,
        private readonly GadgetPaymentService    $gadgetPaymentService,  // ← AJOUT
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

        // ── PayPal : capturer l'ordre + renouvellement + email ──────────────
        $paypalToken = $request->query->get('token');
        if ($paypalToken && $payment->getProvider() === 'paypal') {
            $meta    = $payment->getMetadata() ?? [];
            $orderId = $meta['paypal_order_id'] ?? $paypalToken;

            $captured = $this->paypalService->captureOrder($orderId);
            $payment->setProviderTxId($orderId);

            if ($captured && !$payment->isCompleted()) {
                $this->paymentService->confirmPayment($payment);
                $this->logger->info('[PAYPAL] Paiement ' . $paymentId . ' capturé et confirmé');

                // Renouvellement + email
                $this->processRenewal($payment);

                $meta2 = $payment->getMetadata() ?? [];
                $this->addFlash('success', '✅ Paiement PayPal confirmé ! Un email vous a été envoyé.');

                if (!empty($meta2['memorial_slug'])) {
                    return $this->redirectToRoute('app_dashboard_memorial', [
                        'slug' => $meta2['memorial_slug'],
                    ]);
                }
            }
        }

        // ── Stripe : vérifier session ────────────────────────────────────────
        $stripeSessionId = $request->query->get('session_id');
        if ($stripeSessionId) {
            $payment->setProviderTxId($stripeSessionId);
        }

        // Déjà completed (ex: webhook Stripe arrivé avant le retour)
        if ($payment->isCompleted()) {
            $meta = $payment->getMetadata() ?? [];
            if (!empty($meta['renewal']) && !empty($meta['memorial_slug'])) {
                $this->addFlash('success', '✅ Renouvellement confirmé ! Un email de confirmation vous a été envoyé.');
                return $this->redirectToRoute('app_dashboard_memorial', ['slug' => $meta['memorial_slug']]);
            }
            $page = $this->em->getRepository(MemorialPage::class)->findOneBy(['createdBy' => $this->getUser()]);
            if ($page) {
                return $this->redirectToRoute('app_dashboard_memorial', ['slug' => $page->getSlug()]);
            }
        }

        // Confirmer le paiement (Stripe retour sans webhook, sandbox, etc.)
        $this->paymentService->confirmPayment($payment);

        // Récupérer les données de session
        $session  = $request->getSession();
        $step1    = $session->get('memorial_create_step1', []);
        $step2    = $session->get('memorial_create_step2', []);
        $metadata = $payment->getMetadata() ?? [];
        $s1       = $step1 ?: ($metadata['step1'] ?? []);
        $s2       = $step2 ?: ($metadata['step2'] ?? []);

        // Renouvellement (fallback si webhook pas encore arrivé)
        if (!empty($metadata['renewal']) && !empty($metadata['memorial_slug'])) {
            $page = $this->em->getRepository(MemorialPage::class)
                ->findOneBy(['slug' => $metadata['memorial_slug']]);

            if ($page) {
                $formula   = $this->em->getRepository(MemorialFormula::class)->find($metadata['formula_id']);
                $years     = $formula ? ($formula->getDurationYears() ?? 1) : 1;
                $months    = $years * 12;
                $base      = ($page->getExpiresAt() && $page->getExpiresAt() > new \DateTime())
                    ? $page->getExpiresAt() : new \DateTime();
                $newExpiry = (clone $base)->modify("+{$months} months");

                $page->setExpiresAt($newExpiry)->setStatus(MemorialPage::STATUS_ACTIVE);
                if ($formula) $page->setFormula($formula);

                // Email si pas encore envoyé
                if (empty($metadata['email_sent'])) {
                    $metadata['email_sent'] = true;
                    $payment->setMetadata($metadata);
                    $this->em->flush();

                    try {
                        $this->memorialEmailService->sendRenewalConfirmation($page, $payment, $payment->getUser());
                    } catch (\Exception $e) {
                        $this->logger->error('[SUCCESS] Email renouvellement échoué: ' . $e->getMessage());
                    }
                } else {
                    $this->em->flush();
                }

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

        // Email de confirmation création
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
            $this->paymentService->failPayment($payment, "Annulé par l'utilisateur");
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

            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('payment/mobile_confirm.html.twig', ['payment' => $payment]);
    }

    // =========================================================
    // WEBHOOK STRIPE (production)
    // ── Patché pour gérer TYPE_GADGET vs TYPE_FORMULA ────────
    // =========================================================
    #[Route('/webhook/stripe', name: 'app_payment_webhook_stripe', methods: ['POST'])]
    public function webhookStripe(
        Request $request,
        #[Autowire('%env(STRIPE_WEBHOOK_SECRET)%')] string $webhookSecret = '',
    ): JsonResponse {
        $payload = $request->getContent();
        $sig     = $request->headers->get('Stripe-Signature', '');

        $raw  = json_decode($payload, true);
        $type = $raw['type'] ?? '';

        if ($webhookSecret && $sig) {
            $verified = $this->stripeService->verifyWebhook($payload, $sig, $webhookSecret);
            if (!$verified) {
                return $this->json(['error' => 'Invalid signature'], 400);
            }
        }

        $this->logger->info('[STRIPE] Webhook reçu: ' . $type);

        if ($type === 'checkout.session.completed') {
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

            if (!$payment->isCompleted()) {
                $payment->setProviderTxId($sessionId);

                // ── Aiguillage selon le type de paiement ────────────────────
                if ($payment->getType() === Payment::TYPE_GADGET) {
                    // Gadget : créditer le portefeuille
                    $this->gadgetPaymentService->confirmAndCreditWallet($payment);
                    $this->logger->info('[STRIPE] Gadget paiement ' . $paymentId . ' confirmé — portefeuille crédité');
                } else {
                    // Formule mémorielle : logique existante
                    $this->paymentService->confirmPayment($payment);
                    $this->logger->info('[STRIPE] Paiement formule ' . $paymentId . ' confirmé');

                    // Renouvellement si applicable
                    $meta = $payment->getMetadata() ?? [];
                    if (!empty($meta['renewal']) && !empty($meta['memorial_slug']) && empty($meta['email_sent'])) {
                        $page = $this->em->getRepository(MemorialPage::class)
                            ->findOneBy(['slug' => $meta['memorial_slug']]);

                        if ($page) {
                            $formula   = $this->em->getRepository(MemorialFormula::class)->find($meta['formula_id'] ?? 0);
                            $years     = $formula ? ($formula->getDurationYears() ?? 1) : 1;
                            $months    = $years * 12;
                            $base      = ($page->getExpiresAt() && $page->getExpiresAt() > new \DateTime())
                                ? $page->getExpiresAt() : new \DateTime();
                            $newExpiry = (clone $base)->modify("+{$months} months");

                            $page->setExpiresAt($newExpiry)->setStatus(MemorialPage::STATUS_ACTIVE);
                            if ($formula) $page->setFormula($formula);

                            $meta['email_sent'] = true;
                            $payment->setMetadata($meta);
                            $this->em->flush();

                            $this->logger->info('[STRIPE] Page renouvelée jusqu au ' . $newExpiry->format('Y-m-d'));

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
            }
        }

        return $this->json(['received' => true]);
    }

    // =========================================================
    // PAWAPAY — Retour utilisateur après paiement
    // ── Point d'entrée unique pour formules ET gadgets ────────
    // Refs possibles :
    //   EM-000042  → formule mémorielle (Payment::TYPE_FORMULA)
    //   EMG-000042 → gadget (Payment::TYPE_GADGET)
    // =========================================================
    #[Route('/pawapay/retour', name: 'app_payment_pawapay_return', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function pawaPayReturn(Request $request): Response
    {
        $ref       = $request->query->get('ref', '');       // ex: "EMG-000042" ou "EM-000042"
        $depositId = $request->query->get('depositId', ''); // UUID PawaPay
        $cancelled = $request->query->get('cancel', false);

        // ── Extraire le paymentId depuis la ref ──────────────────────────────
        // EM-000042  → garder uniquement les chiffres → 42
        // EMG-000042 → garder uniquement les chiffres → 42
        $paymentId = (int) preg_replace('/[^0-9]/', '', $ref);

        $payment = $this->em->getRepository(Payment::class)->find($paymentId);

        if (!$payment || $payment->getUser() !== $this->getUser()) {
            $this->logger->warning('[PAWAPAY] Retour : payment introuvable', [
                'ref'       => $ref,
                'paymentId' => $paymentId,
            ]);
            throw $this->createNotFoundException();
        }

        $isGadget = $payment->getType() === Payment::TYPE_GADGET;

        // ── Annulation ───────────────────────────────────────────────────────
        if ($cancelled) {
            if ($isGadget) {
                $this->gadgetPaymentService->failPayment($payment, "PawaPay: annulé par l'utilisateur");
                $this->addFlash('warning', 'Paiement annulé.');
                return $this->redirectToRoute('app_gadget_shop');
            }
            $this->paymentService->failPayment($payment, "PawaPay: annulé par l'utilisateur");
            $this->addFlash('warning', 'Paiement annulé. Vous pouvez réessayer.');
            return $this->redirectToRoute('app_renewal', [
                'slug' => $payment->getMetadata()['memorial_slug'] ?? '',
            ]);
        }

        // ── Sauvegarder le depositId PawaPay depuis l'URL si absent en base ─
        $meta = $payment->getMetadata() ?? [];
        if ($depositId && empty($meta['pawapay_deposit_id'])) {
            $meta['pawapay_deposit_id'] = $depositId;
            $payment->setMetadata($meta);
            $this->em->flush();
            $this->logger->info('[PAWAPAY] depositId sauvegardé depuis URL retour', [
                'ref'       => $ref,
                'paymentId' => $paymentId,
                'depositId' => $depositId,
                'type'      => $isGadget ? 'gadget' : 'formula',
            ]);
        }

        // ── Déjà complété (webhook arrivé avant le retour navigateur) ────────
        if ($payment->isCompleted()) {
            if ($isGadget) {
                $this->addFlash('success', '✅ Paiement Mobile Money confirmé ! Gadgets crédités.');
                return $this->redirectToRoute('app_gadget_wallet');
            }
            $this->addFlash('success', '✅ Renouvellement confirmé ! Un email vous a été envoyé.');
            return $this->redirectToRoute('app_dashboard_memorial', [
                'slug' => $meta['memorial_slug'] ?? '',
            ]);
        }

        // ── Vérifier le statut PawaPay ────────────────────────────────────────
        $meta      = $payment->getMetadata() ?? [];
        $depositId = $meta['pawapay_deposit_id'] ?? '';

        if ($depositId) {
            $result = $this->pawaPayService->checkDepositStatus($depositId);
            $status = $result['status'] ?? '';

            if ($status === 'COMPLETED') {
                $payment->setProviderTxId($depositId);

                if ($isGadget) {
                    // Gadget → créditer le portefeuille
                    $this->gadgetPaymentService->confirmAndCreditWallet($payment);
                    $this->addFlash('success', '✅ Paiement Mobile Money confirmé ! Gadgets crédités.');
                    return $this->redirectToRoute('app_gadget_wallet');
                }

                // Formule → logique existante
                $this->paymentService->confirmPayment($payment);
                $this->processRenewal($payment);
                $this->addFlash('success', '✅ Paiement Mobile Money confirmé !');
                return $this->redirectToRoute('app_dashboard_memorial', [
                    'slug' => $meta['memorial_slug'] ?? '',
                ]);
            }

            if (in_array($status, ['FAILED', 'CANCELLED', 'REJECTED', 'TIMED_OUT'], true)) {
                if ($isGadget) {
                    $this->gadgetPaymentService->failPayment($payment, 'PawaPay: ' . $status);
                    $this->addFlash('error', 'Le paiement Mobile Money a échoué. Réessayez.');
                    return $this->redirectToRoute('app_gadget_shop');
                }
                $this->paymentService->failPayment($payment, 'PawaPay: ' . $status);
                $this->addFlash('error', 'Le paiement Mobile Money a échoué. Réessayez.');
                return $this->redirectToRoute('app_renewal', [
                    'slug' => $meta['memorial_slug'] ?? '',
                ]);
            }
        }

        // ── En attente → page de polling ─────────────────────────────────────
        // checkUrl adapté selon le type (gadget ou formule)
        $checkUrl = $isGadget
            ? $this->generateUrl('app_gadget_payment_pawapay_check', ['paymentId' => $paymentId])
            : $this->generateUrl('app_payment_pawapay_check', ['paymentId' => $paymentId]);

        return $this->render('payment/pawapay_pending.html.twig', [
            'payment'   => $payment,
            'depositId' => $depositId,
            'checkUrl'  => $checkUrl,
        ]);
    }

    // =========================================================
    // PAWAPAY — Vérification statut (polling AJAX)
    // =========================================================
    #[Route('/pawapay/check/{paymentId}', name: 'app_payment_pawapay_check', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function pawaPayCheck(int $paymentId): JsonResponse
    {
        $payment = $this->em->getRepository(Payment::class)->find($paymentId);
        if (!$payment || $payment->getUser() !== $this->getUser()) {
            return $this->json(['error' => 'Not found'], 404);
        }

        $meta      = $payment->getMetadata() ?? [];
        $depositId = $meta['pawapay_deposit_id'] ?? '';
        $result    = $this->pawaPayService->checkDepositStatus($depositId);
        $status    = $result['status'] ?? 'PENDING';

        if ($status === 'COMPLETED' && !$payment->isCompleted()) {
            $payment->setProviderTxId($depositId);
            $this->paymentService->confirmPayment($payment);
            $this->processRenewal($payment);
        }

        return $this->json([
            'status'      => $status,
            'redirectUrl' => $status === 'COMPLETED'
                ? $this->generateUrl('app_dashboard_memorial', ['slug' => $meta['memorial_slug'] ?? ''])
                : null,
        ]);
    }

    // =========================================================
    // WEBHOOK PAWAPAY
    // =========================================================
    #[Route('/webhook/pawapay', name: 'app_payment_webhook_pawapay', methods: ['POST'])]
    public function webhookPawaPay(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true) ?? [];

        if (!$this->pawaPayService->verifyWebhook($payload)) {
            return new Response('Bad Request', 400);
        }

        $depositId   = $payload['depositId'] ?? null;
        $status      = $payload['status'] ?? '';
        $internalRef = null;

        foreach ($payload['metadata'] ?? [] as $meta) {
            if (isset($meta['orderId'])) { $internalRef = $meta['orderId']; break; }
        }

        // Retrouver le paiement par depositId (providerTxId)
        $payment = $depositId
            ? $this->em->getRepository(Payment::class)->findOneBy(['providerTxId' => $depositId])
            : null;

        // Fallback : chercher par pawapay_ref dans metadata
        if (!$payment && $internalRef) {
            $payments = $this->em->getRepository(Payment::class)->findAll();
            foreach ($payments as $p) {
                if (($p->getMetadata()['pawapay_ref'] ?? '') === $internalRef) {
                    $payment = $p;
                    break;
                }
            }
        }

        if (!$payment) {
            return new Response('OK', 200);
        }

        if ($status === 'COMPLETED' && !$payment->isCompleted()) {
            $payment->setProviderTxId($depositId);

            // Aiguillage gadget vs formule
            if ($payment->getType() === Payment::TYPE_GADGET) {
                $this->gadgetPaymentService->confirmAndCreditWallet($payment);
            } else {
                $this->paymentService->confirmPayment($payment);
                $this->processRenewal($payment);
            }
        } elseif ($status === 'FAILED') {
            $this->paymentService->failPayment($payment, 'PawaPay webhook: FAILED');
        }

        return new Response('OK', 200);
    }

    // =========================================================
    // HELPER — Traitement renouvellement + email
    // =========================================================
    private function processRenewal(Payment $payment): void
    {
        $meta = $payment->getMetadata() ?? [];
        if (empty($meta['renewal']) || empty($meta['memorial_slug']) || !empty($meta['email_sent'])) {
            return;
        }

        $page = $this->em->getRepository(MemorialPage::class)->findOneBy(['slug' => $meta['memorial_slug']]);
        if (!$page) return;

        $formula   = $this->em->getRepository(MemorialFormula::class)->find($meta['formula_id'] ?? 0);
        $years     = $formula ? ($formula->getDurationYears() ?? 1) : 1;
        $base      = ($page->getExpiresAt() && $page->getExpiresAt() > new \DateTime())
            ? $page->getExpiresAt() : new \DateTime();
        $newExpiry = (clone $base)->modify('+' . ($years * 12) . ' months');

        $page->setExpiresAt($newExpiry)->setStatus(MemorialPage::STATUS_ACTIVE);
        if ($formula) $page->setFormula($formula);

        $meta['email_sent'] = true;
        $payment->setMetadata($meta);
        $this->em->flush();

        try {
            $this->memorialEmailService->sendRenewalConfirmation($page, $payment, $payment->getUser());
            $this->logger->info('[RENEWAL] Email envoyé pour ' . $meta['memorial_slug']);
        } catch (\Exception $e) {
            $this->logger->error('[RENEWAL] Email échoué: ' . $e->getMessage());
        }
    }

    // =========================================================
    // WEBHOOK PAYPAL (production)
    // =========================================================
    #[Route('/webhook/paypal', name: 'app_payment_webhook_paypal', methods: ['POST'])]
    public function webhookPaypal(Request $request): JsonResponse
    {
        $data     = json_decode($request->getContent(), true);
        $event    = $data['event_type'] ?? '';
        $resource = $data['resource'] ?? [];

        if ($event === 'CHECKOUT.ORDER.APPROVED' || $event === 'PAYMENT.CAPTURE.COMPLETED') {
            $customId = $resource['purchase_units'][0]['custom_id']
                ?? $resource['custom_id']
                ?? null;

            if ($customId) {
                $payment = $this->em->getRepository(Payment::class)->find($customId);
                if ($payment && !$payment->isCompleted()) {
                    $payment->setProviderTxId($resource['id'] ?? '');

                    // Aiguillage gadget vs formule
                    if ($payment->getType() === Payment::TYPE_GADGET) {
                        $this->gadgetPaymentService->confirmAndCreditWallet($payment);
                    } else {
                        $this->paymentService->confirmPayment($payment);
                        $this->processRenewal($payment);
                    }
                }
            }
        }

        return $this->json(['received' => true]);
    }
}
