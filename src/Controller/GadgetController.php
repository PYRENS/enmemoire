<?php

namespace App\Controller;

use App\Entity\GadgetCatalog;
use App\Entity\GadgetInteraction;
use App\Entity\GadgetPurchase;
use App\Entity\MemorialPage;
use App\Entity\Payment;
use App\Entity\UserGadgetWallet;
use App\Service\Payment\GadgetPaymentService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class GadgetController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GadgetPaymentService   $gadgetPaymentService,
        private readonly LoggerInterface        $logger,
    ) {}

    // =========================================================
    // BOUTIQUE
    // =========================================================
    #[Route('/boutique', name: 'app_gadget_shop')]
    public function shop(): Response
    {
        $gadgets = $this->em->getRepository(GadgetCatalog::class)
            ->findBy(['status' => GadgetCatalog::STATUS_ACTIVE], ['type' => 'ASC', 'price' => 'ASC']);

        $wallet = [];
        if ($this->getUser()) {
            $walletItems = $this->em->getRepository(UserGadgetWallet::class)
                ->findBy(['user' => $this->getUser()]);
            foreach ($walletItems as $item) {
                $wallet[$item->getGadget()->getId()] = $item->getQuantity();
            }
        }

        $byType = [];
        foreach ($gadgets as $gadget) {
            $byType[$gadget->getType()][] = $gadget;
        }

        return $this->render('gadget/shop.html.twig', [
            'byType' => $byType,
            'wallet' => $wallet,
        ]);
    }

    // =========================================================
    // PORTEFEUILLE
    // =========================================================
    #[Route('/boutique/portefeuille', name: 'app_gadget_wallet')]
    #[IsGranted('ROLE_USER')]
    public function wallet(): Response
    {
        $walletItems = $this->em->getRepository(UserGadgetWallet::class)
            ->findBy(['user' => $this->getUser()], []);

        $purchases = $this->em->getRepository(GadgetPurchase::class)
            ->findBy(['user' => $this->getUser()], ['createdAt' => 'DESC'], 20);

        return $this->render('gadget/wallet.html.twig', [
            'walletItems' => $walletItems,
            'purchases'   => $purchases,
        ]);
    }

    // =========================================================
    // PAGE CHECKOUT — affiche le choix du provider de paiement
    // ⚠️ AVANT /boutique/acheter/{id} pour éviter les conflits de routing
    // =========================================================
    #[Route('/boutique/acheter-page/{id}', name: 'app_gadget_checkout_page')]
    #[IsGranted('ROLE_USER')]
    public function checkoutPage(int $id): Response
    {
        $gadget = $this->em->getRepository(GadgetCatalog::class)->find($id);
        if (!$gadget || !$gadget->isActive()) {
            throw $this->createNotFoundException('Gadget introuvable.');
        }

        $mobileProviders = [
            'pawapay'      => ['label' => 'PawaPay / M-Pesa'],
            'airtel'       => ['label' => 'Airtel Money'],
            'orange_money' => ['label' => 'Orange Money'],
        ];

        return $this->render('gadget/checkout.html.twig', [
            'gadget'          => $gadget,
            'mobileproviders' => $mobileProviders,
        ]);
    }

    // =========================================================
    // CHECKOUT — initie le paiement réel (POST depuis checkout.html.twig)
    // ⚠️ AVANT /boutique/acheter/{id}
    // =========================================================
    #[Route('/boutique/payer/{id}', name: 'app_gadget_checkout', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function checkout(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('gadget_checkout_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token invalide. Veuillez réessayer.');
            return $this->redirectToRoute('app_gadget_shop');
        }

        $gadget = $this->em->getRepository(GadgetCatalog::class)->find($id);
        if (!$gadget || !$gadget->isActive()) {
            throw $this->createNotFoundException('Gadget introuvable.');
        }

        $quantity = max(1, min(50, (int) $request->request->get('quantity', 1)));
        $provider = $request->request->get('provider', 'stripe');
        $currency = $request->request->get('currency', 'USD');

        $validProviders = ['stripe', 'paypal', 'airtel', 'mpesa', 'orange_money', 'pawapay'];
        if (!in_array($provider, $validProviders, true)) {
            $this->addFlash('error', 'Moyen de paiement invalide.');
            return $this->redirectToRoute('app_gadget_shop');
        }

        try {
            $result = $this->gadgetPaymentService->initiate(
                $this->getUser(),
                $gadget,
                $quantity,
                $provider,
                $currency,
            );

            // Redirection directe (Stripe, PayPal, PawaPay page)
            if (!empty($result['redirect'])) {
                return $this->redirect($result['redirect']);
            }

            // Instructions Mobile Money (PawaPay fallback manuel)
            if (!empty($result['instructions'])) {
                return $this->render('gadget/payment_instructions.html.twig', [
                    'gadget'       => $gadget,
                    'quantity'     => $quantity,
                    'instructions' => $result['instructions'],
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('[GADGET][CHECKOUT] ' . $e->getMessage());
            $this->addFlash('error', 'Une erreur est survenue. Veuillez réessayer.');
        }

        return $this->redirectToRoute('app_gadget_shop');
    }

    // =========================================================
    // SUCCÈS — après retour Stripe / PayPal / PawaPay
    // =========================================================
    #[Route('/boutique/paiement/succes/{paymentId}', name: 'app_gadget_payment_success')]
    #[IsGranted('ROLE_USER')]
    public function paymentSuccess(int $paymentId, Request $request): Response
    {
        $payment = $this->em->getRepository(Payment::class)->find($paymentId);

        if (!$payment || $payment->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        // ── PayPal : capturer l'ordre ────────────────────────
        $paypalToken = $request->query->get('token');
        if ($paypalToken && $payment->getProvider() === 'paypal') {
            $meta    = $payment->getMetadata() ?? [];
            $orderId = $meta['paypal_order_id'] ?? $paypalToken;

            $captured = $this->gadgetPaymentService->capturePayPalOrder($orderId);
            $payment->setProviderTxId($orderId);

            if ($captured) {
                $this->gadgetPaymentService->confirmAndCreditWallet($payment);
                $this->logger->info('[GADGET][PAYPAL] Paiement ' . $paymentId . ' capturé');
            }
        }

        // ── Stripe : session_id dans l'URL ───────────────────
        $stripeSessionId = $request->query->get('session_id');
        if ($stripeSessionId) {
            $payment->setProviderTxId($stripeSessionId);
            $this->em->flush();
        }

        // ── Confirmer si pas encore fait (webhook peut arriver avant) ──
        if (!$payment->isCompleted()) {
            $this->gadgetPaymentService->confirmAndCreditWallet($payment);
        }

        $meta     = $payment->getMetadata() ?? [];
        $gadget   = $this->em->getRepository(GadgetCatalog::class)->find($meta['gadget_id'] ?? 0);
        $quantity = (int) ($meta['quantity'] ?? 1);

        $this->addFlash('success', sprintf(
            '✅ Paiement confirmé ! %d × %s ajouté%s à votre portefeuille.',
            $quantity,
            $gadget?->getName() ?? 'gadget',
            $quantity > 1 ? 's' : ''
        ));

        return $this->redirectToRoute('app_gadget_wallet');
    }

    // =========================================================
    // ANNULATION
    // =========================================================
    #[Route('/boutique/paiement/annule/{paymentId}', name: 'app_gadget_payment_cancel')]
    #[IsGranted('ROLE_USER')]
    public function paymentCancel(int $paymentId): Response
    {
        $payment = $this->em->getRepository(Payment::class)->find($paymentId);

        if ($payment && $payment->getUser() === $this->getUser() && !$payment->isCompleted()) {
            $this->gadgetPaymentService->failPayment($payment, "Annulé par l'utilisateur");
        }

        $this->addFlash('warning', 'Paiement annulé. Vous pouvez réessayer depuis la boutique.');
        return $this->redirectToRoute('app_gadget_shop');
    }

    // =========================================================
    // SANDBOX (développement sans clés API)
    // =========================================================
    #[Route('/boutique/paiement/sandbox/{paymentId}/{provider}', name: 'app_gadget_payment_sandbox')]
    #[IsGranted('ROLE_USER')]
    public function paymentSandbox(int $paymentId, string $provider, Request $request): Response
    {
        $payment = $this->em->getRepository(Payment::class)->find($paymentId);

        if (!$payment || $payment->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            $action = $request->request->get('action');
            if ($action === 'success') {
                return $this->redirectToRoute('app_gadget_payment_success', ['paymentId' => $paymentId]);
            }
            return $this->redirectToRoute('app_gadget_payment_cancel', ['paymentId' => $paymentId]);
        }

        $meta   = $payment->getMetadata() ?? [];
        $gadget = $this->em->getRepository(GadgetCatalog::class)->find($meta['gadget_id'] ?? 0);

        return $this->render('gadget/payment_sandbox.html.twig', [
            'payment'  => $payment,
            'gadget'   => $gadget,
            'provider' => $provider,
        ]);
    }

    // =========================================================
    // CONFIRMATION MOBILE (fallback manuel PawaPay)
    // =========================================================
    #[Route('/boutique/paiement/mobile-confirmer/{paymentId}', name: 'app_gadget_payment_mobile_confirm', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function paymentMobileConfirm(int $paymentId, Request $request): Response
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
            ];
            $payment->setMetadata($meta);
            $payment->setStatus('pending_manual_review');
            $this->em->flush();

            $this->addFlash('success', 'Confirmation reçue ! Votre paiement sera validé sous 24h. Les gadgets seront crédités dès validation.');
            return $this->redirectToRoute('app_gadget_wallet');
        }

        $meta   = $payment->getMetadata() ?? [];
        $gadget = $this->em->getRepository(GadgetCatalog::class)->find($meta['gadget_id'] ?? 0);

        return $this->render('gadget/payment_mobile_confirm.html.twig', [
            'payment' => $payment,
            'gadget'  => $gadget,
        ]);
    }

    // =========================================================
    // VÉRIFICATION STATUT PAWAPAY (polling Ajax)
    // =========================================================
    #[Route('/boutique/paiement/pawapay-check/{paymentId}', name: 'app_gadget_payment_pawapay_check', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function pawaPayCheck(int $paymentId): JsonResponse
    {
        $payment = $this->em->getRepository(Payment::class)->find($paymentId);

        if (!$payment || $payment->getUser() !== $this->getUser()) {
            return $this->json(['error' => 'Not found'], 404);
        }

        $meta      = $payment->getMetadata() ?? [];
        $depositId = $meta['pawapay_deposit_id'] ?? '';
        $status    = $this->gadgetPaymentService->checkPawaPayStatus($depositId);

        if ($status === 'COMPLETED' && !$payment->isCompleted()) {
            $payment->setProviderTxId($depositId);
            $this->gadgetPaymentService->confirmAndCreditWallet($payment);
        }

        return $this->json([
            'status'      => $status,
            'redirectUrl' => $status === 'COMPLETED'
                ? $this->generateUrl('app_gadget_wallet')
                : null,
        ]);
    }

    // =========================================================
    // ACHAT DIRECT sans paiement (gadgets gratuits / admin)
    // =========================================================
    #[Route('/boutique/acheter/{id}', name: 'app_gadget_buy', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function buy(int $id, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('gadget_buy_' . $id, $request->request->get('_token'))) {
            return $this->json(['error' => 'Token invalide'], 403);
        }

        $gadget = $this->em->getRepository(GadgetCatalog::class)->find($id);
        if (!$gadget || !$gadget->isActive()) {
            return $this->json(['error' => 'Gadget introuvable'], 404);
        }

        // Sécurité : cette route ne fonctionne que pour les gadgets gratuits (price == 0)
        // Les gadgets payants passent par /boutique/payer/{id}
        if ((float) $gadget->getPrice() > 0) {
            return $this->json(['error' => 'Ce gadget nécessite un paiement. Utilisez la boutique.'], 403);
        }

        $quantity = max(1, (int) $request->request->get('quantity', 1));
        $user     = $this->getUser();

        $purchase = new GadgetPurchase();
        $purchase->setUser($user)
                 ->setGadget($gadget)
                 ->setQuantity($quantity)
                 ->setUnitPrice($gadget->getPrice())
                 ->setTotalPrice(bcmul($gadget->getPrice(), (string) $quantity, 2))
                 ->setCurrency($gadget->getCurrency());
        $this->em->persist($purchase);

        $walletItem = $this->em->getRepository(UserGadgetWallet::class)
            ->findOneBy(['user' => $user, 'gadget' => $gadget]);

        if (!$walletItem) {
            $walletItem = new UserGadgetWallet();
            $walletItem->setUser($user)->setGadget($gadget)->setQuantity(0);
            $this->em->persist($walletItem);
        }
        $walletItem->setQuantity($walletItem->getQuantity() + $quantity);

        $this->em->flush();

        return $this->json([
            'success'     => true,
            'newQuantity' => $walletItem->getQuantity(),
            'gadgetName'  => $gadget->getName(),
        ]);
    }

    // =========================================================
    // API — Gadgets du portefeuille (pour dépôt sur page)
    // =========================================================
    #[Route('/api/gadgets/wallet', name: 'app_api_gadget_wallet', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function apiWallet(): JsonResponse
    {
        $items = $this->em->getRepository(UserGadgetWallet::class)
            ->findBy(['user' => $this->getUser()]);

        $data = array_map(fn($i) => [
            'id'           => $i->getGadget()->getId(),
            'name'         => $i->getGadget()->getName(),
            'type'         => $i->getGadget()->getType(),
            'imageUrl'     => $i->getGadget()->getImageUrl(),
            'animationUrl' => $i->getGadget()->getAnimationUrl(),
            'allowsText'   => $i->getGadget()->isAllowsCustomText(),
            'maxTextLen'   => $i->getGadget()->getMaxTextLength(),
            'quantity'     => $i->getQuantity(),
        ], array_filter($items, fn($i) => $i->getQuantity() > 0));

        return $this->json(array_values($data));
    }

    // =========================================================
    // DÉPÔT sur page mémorielle
    // =========================================================
    #[Route('/boutique/deposer/{slug}', name: 'app_gadget_deposit', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function deposit(string $slug, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('gadget_deposit', $request->request->get('_token'))) {
            return $this->json(['error' => 'Token invalide'], 403);
        }

        $page = $this->em->getRepository(MemorialPage::class)->findBySlug($slug);
        if (!$page) return $this->json(['error' => 'Page introuvable'], 404);

        $gadgetId = (int) $request->request->get('gadget_id');
        $gadget   = $this->em->getRepository(GadgetCatalog::class)->find($gadgetId);
        if (!$gadget || !$gadget->isActive()) {
            return $this->json(['error' => 'Gadget introuvable'], 404);
        }

        $user = $this->getUser();

        $walletItem = $this->em->getRepository(UserGadgetWallet::class)
            ->findOneBy(['user' => $user, 'gadget' => $gadget]);

        if (!$walletItem || $walletItem->getQuantity() < 1) {
            return $this->json(['error' => "Vous n'avez pas ce gadget dans votre portefeuille."], 400);
        }

        $walletItem->setQuantity($walletItem->getQuantity() - 1);

        $interaction = new GadgetInteraction();
        $interaction->setMemorial($page)
                    ->setUser($user)
                    ->setGadget($gadget)
                    ->setAction($gadget->getType())
                    ->setCustomText($request->request->get('custom_text') ?: null);

        $this->em->persist($interaction);
        $this->em->flush();

        $counts = $this->getInteractionCounts($page->getId());

        return $this->json([
            'success'           => true,
            'gadgetName'        => $gadget->getName(),
            'gadgetType'        => $gadget->getType(),
            'gadgetEmoji'       => $this->typeEmoji($gadget->getType()),
            'customText'        => $interaction->getCustomText(),
            'userName'          => $user->getFullName(),
            'remainingInWallet' => $walletItem->getQuantity(),
            'counts'            => $counts,
        ]);
    }

    // =========================================================
    // API — Interactions sur une page (gadgets déposés)
    // =========================================================
    #[Route('/api/gadgets/interactions/{slug}', name: 'app_api_gadget_interactions', methods: ['GET'])]
    public function interactions(string $slug): JsonResponse
    {
        $page = $this->em->getRepository(MemorialPage::class)->findBySlug($slug);
        if (!$page) return $this->json([]);

        $interactions = $this->em->getRepository(GadgetInteraction::class)
            ->findBy(['memorial' => $page], ['createdAt' => 'DESC'], 50);

        $data = array_map(fn($i) => [
            'id'         => $i->getId(),
            'type'       => $i->getGadget()->getType(),
            'name'       => $i->getGadget()->getName(),
            'emoji'      => $this->typeEmoji($i->getGadget()->getType()),
            'customText' => $i->getCustomText(),
            'userName'   => $i->getUser()->getFullName(),
            'createdAt'  => $i->getCreatedAt()->format('d/m/Y à H:i'),
        ], $interactions);

        return $this->json([
            'interactions' => $data,
            'counts'       => $this->getInteractionCounts($page->getId()),
        ]);
    }

    // =========================================================
    // HELPERS privés
    // =========================================================
    private function getInteractionCounts(int $pageId): array
    {
        $rows = $this->em->getConnection()->executeQuery(
            "SELECT g.type, COUNT(*) as cnt
             FROM gadget_interactions gi
             JOIN gadget_catalog g ON g.id = gi.gadget_id
             WHERE gi.memorial_id = ?
             GROUP BY g.type",
            [$pageId]
        )->fetchAllAssociative();

        $counts = ['flower' => 0, 'candle' => 0, 'dove' => 0, 'other' => 0];
        foreach ($rows as $row) {
            $counts[$row['type']] = (int) $row['cnt'];
        }
        return $counts;
    }

    private function typeEmoji(string $type): string
    {
        return match ($type) {
            'flower' => '🌸', 'candle' => '🕯️', 'dove' => '🕊️', default => '✨'
        };
    }
}
