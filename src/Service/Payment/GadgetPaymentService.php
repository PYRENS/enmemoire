<?php

namespace App\Service\Payment;

use App\Entity\GadgetCatalog;
use App\Entity\GadgetPurchase;
use App\Entity\Payment;
use App\Entity\User;
use App\Entity\UserGadgetWallet;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * GadgetPaymentService
 *
 * Gère le cycle complet de paiement pour l'achat de gadgets numériques :
 *   - Création du Payment en BDD
 *   - Initiation Stripe Checkout / PayPal Orders / PawaPay Payment Page
 *   - Confirmation : crédit du portefeuille + enregistrement GadgetPurchase
 *   - Sandbox (développement sans clés API)
 *
 * PawaPay : utilise PawaPayService::initiatePaymentPage() — même stratégie
 * que MobileMoneyService (page hébergée /v2/paymentpage, endpoint correct).
 */
class GadgetPaymentService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface  $router,
        private readonly HttpClientInterface    $httpClient,
        private readonly LoggerInterface        $logger,
        private readonly PawaPayService         $pawaPayService,   // ← via PawaPayService, pas d'appel direct
        #[Autowire('%env(STRIPE_SECRET_KEY)%')]    private readonly string $stripeSecretKey,
        #[Autowire('%env(STRIPE_PUBLIC_KEY)%')]    private readonly string $stripePublicKey,
        #[Autowire('%env(PAYPAL_CLIENT_ID)%')]     private readonly string $paypalClientId,
        #[Autowire('%env(PAYPAL_CLIENT_SECRET)%')] private readonly string $paypalClientSecret,
        #[Autowire('%env(PAYPAL_MODE)%')]           private readonly string $paypalMode,
    ) {}

    // =========================================================
    // INITIATION — crée le Payment + redirige vers le provider
    // =========================================================

    public function initiate(
        User          $user,
        GadgetCatalog $gadget,
        int           $quantity,
        string        $provider,
        string        $currency = 'USD',
    ): array {
        $totalAmount = bcmul((string) $gadget->getPrice(), (string) $quantity, 2);

        $payment = new Payment();
        $payment->setUser($user)
                ->setType(Payment::TYPE_GADGET)
                ->setReferenceId($gadget->getId())
                ->setAmount($totalAmount)
                ->setCurrency($currency)
                ->setProvider($provider)
                ->setStatus(Payment::STATUS_PENDING)
                ->setMetadata([
                    'gadget_id'   => $gadget->getId(),
                    'gadget_name' => $gadget->getName(),
                    'gadget_slug' => $gadget->getSlug(),
                    'quantity'    => $quantity,
                    'unit_price'  => (string) $gadget->getPrice(),
                ]);

        $this->em->persist($payment);
        $this->em->flush();

        return match ($provider) {
            'stripe'                                      => $this->initiateStripe($payment, $gadget, $quantity),
            'paypal'                                      => $this->initiatePayPal($payment, $gadget, $quantity),
            'airtel', 'mpesa', 'orange_money', 'pawapay' => $this->initiateMobileMoney($payment, $gadget, $provider),
            default => throw new \InvalidArgumentException("Provider inconnu : $provider"),
        };
    }

    // =========================================================
    // STRIPE
    // =========================================================

    private function isStripeConfigured(): bool
    {
        return !empty($this->stripeSecretKey)
            && str_starts_with($this->stripeSecretKey, 'sk_')
            && !str_starts_with($this->stripeSecretKey, 'sk_test_YOUR');
    }

    private function initiateStripe(Payment $payment, GadgetCatalog $gadget, int $quantity): array
    {
        if (!$this->isStripeConfigured()) {
            return ['redirect' => $this->sandboxUrl($payment, 'stripe')];
        }

        try {
            \Stripe\Stripe::setApiKey($this->stripeSecretKey);

            $successUrl = $this->router->generate('app_gadget_payment_success', [
                'paymentId' => $payment->getId(),
            ], UrlGeneratorInterface::ABSOLUTE_URL) . '?session_id={CHECKOUT_SESSION_ID}';

            $cancelUrl = $this->router->generate('app_gadget_payment_cancel', [
                'paymentId' => $payment->getId(),
            ], UrlGeneratorInterface::ABSOLUTE_URL);

            $session = \Stripe\Checkout\Session::create([
                'payment_method_types' => ['card'],
                'line_items'           => [[
                    'price_data' => [
                        'currency'     => strtolower($payment->getCurrency()),
                        'unit_amount'  => (int) (floatval($gadget->getPrice()) * 100),
                        'product_data' => [
                            'name'        => 'EnMémoire — ' . $gadget->getName(),
                            'description' => $gadget->getDescription() ?? 'Gadget numérique mémoriel',
                            'images'      => $gadget->getImageUrl() ? [$gadget->getImageUrl()] : [],
                        ],
                    ],
                    'quantity' => $quantity,
                ]],
                'mode'           => 'payment',
                'success_url'    => $successUrl,
                'cancel_url'     => $cancelUrl,
                'customer_email' => $payment->getUser()?->getEmail(),
                'metadata'       => [
                    'payment_id' => (string) $payment->getId(),
                    'gadget_id'  => (string) $gadget->getId(),
                    'quantity'   => (string) $quantity,
                    'type'       => 'gadget',
                ],
                'locale' => 'fr',
            ]);

            $meta = $payment->getMetadata() ?? [];
            $meta['stripe_session_id'] = $session->id;
            $payment->setMetadata($meta);
            $payment->setProviderTxId($session->id);
            $this->em->flush();

            return ['redirect' => $session->url];

        } catch (\Exception $e) {
            $this->logger->error('[GADGET][STRIPE] ' . $e->getMessage());
            return ['redirect' => $this->sandboxUrl($payment, 'stripe')];
        }
    }

    // =========================================================
    // PAYPAL
    // =========================================================

    private function isPayPalConfigured(): bool
    {
        return !empty($this->paypalClientId)
            && !empty($this->paypalClientSecret)
            && !str_starts_with($this->paypalClientId, 'YOUR_');
    }

    private function initiatePayPal(Payment $payment, GadgetCatalog $gadget, int $quantity): array
    {
        if (!$this->isPayPalConfigured()) {
            return ['redirect' => $this->sandboxUrl($payment, 'paypal')];
        }

        try {
            $baseUrl = $this->paypalMode === 'live'
                ? 'https://api-m.paypal.com'
                : 'https://api-m.sandbox.paypal.com';

            $tokenResp = $this->httpClient->request('POST', $baseUrl . '/v1/oauth2/token', [
                'auth_basic' => [$this->paypalClientId, $this->paypalClientSecret],
                'body'       => ['grant_type' => 'client_credentials'],
            ]);
            $token = $tokenResp->toArray()['access_token'];

            $successUrl = $this->router->generate('app_gadget_payment_success', [
                'paymentId' => $payment->getId(),
            ], UrlGeneratorInterface::ABSOLUTE_URL);

            $cancelUrl = $this->router->generate('app_gadget_payment_cancel', [
                'paymentId' => $payment->getId(),
            ], UrlGeneratorInterface::ABSOLUTE_URL);

            $orderResp = $this->httpClient->request('POST', $baseUrl . '/v2/checkout/orders', [
                'auth_bearer' => $token,
                'json'        => [
                    'intent'         => 'CAPTURE',
                    'purchase_units' => [[
                        'amount'      => [
                            'currency_code' => $payment->getCurrency(),
                            'value'         => number_format((float) $payment->getAmount(), 2, '.', ''),
                        ],
                        'description' => 'EnMémoire — ' . $gadget->getName() . ' ×' . $quantity,
                        'custom_id'   => (string) $payment->getId(),
                    ]],
                    'application_context' => [
                        'return_url'          => $successUrl,
                        'cancel_url'          => $cancelUrl,
                        'brand_name'          => 'EnMémoire.com',
                        'user_action'         => 'PAY_NOW',
                        'shipping_preference' => 'NO_SHIPPING',
                    ],
                ],
            ]);

            $order      = $orderResp->toArray();
            $orderId    = $order['id'];
            $approveUrl = '';

            foreach ($order['links'] as $link) {
                if ($link['rel'] === 'approve') {
                    $approveUrl = $link['href'];
                    break;
                }
            }

            $meta = $payment->getMetadata() ?? [];
            $meta['paypal_order_id'] = $orderId;
            $payment->setMetadata($meta);
            $payment->setProviderTxId($orderId);
            $this->em->flush();

            return ['redirect' => $approveUrl];

        } catch (\Exception $e) {
            $this->logger->error('[GADGET][PAYPAL] ' . $e->getMessage());
            return ['redirect' => $this->sandboxUrl($payment, 'paypal')];
        }
    }

    public function capturePayPalOrder(string $orderId): bool
    {
        if (!$this->isPayPalConfigured()) {
            return false;
        }
        try {
            $baseUrl = $this->paypalMode === 'live'
                ? 'https://api-m.paypal.com'
                : 'https://api-m.sandbox.paypal.com';

            $tokenResp = $this->httpClient->request('POST', $baseUrl . '/v1/oauth2/token', [
                'auth_basic' => [$this->paypalClientId, $this->paypalClientSecret],
                'body'       => ['grant_type' => 'client_credentials'],
            ]);
            $token = $tokenResp->toArray()['access_token'];

            $resp = $this->httpClient->request(
                'POST',
                $baseUrl . '/v2/checkout/orders/' . $orderId . '/capture',
                ['auth_bearer' => $token, 'json' => []]
            );
            return ($resp->toArray(false)['status'] ?? '') === 'COMPLETED';

        } catch (\Exception $e) {
            $this->logger->error('[GADGET][PAYPAL] Capture failed: ' . $e->getMessage());
            return false;
        }
    }

    // =========================================================
    // MOBILE MONEY — via PawaPayService::initiatePaymentPage()
    // Même stratégie que MobileMoneyService : page hébergée /v2/paymentpage
    // =========================================================

    private function initiateMobileMoney(Payment $payment, GadgetCatalog $gadget, string $provider): array
    {
        // Référence lisible ex: EMG-000042
        $ref = 'EMG-' . str_pad((string) $payment->getId(), 6, '0', STR_PAD_LEFT);

        // Label pour l'affichage sur la page PawaPay (4-22 chars)
        $reason = mb_substr('EnMem ' . $gadget->getName(), 0, 22);

        if ($this->pawaPayService->isConfigured()) {
            // Convertir USD → CDF (~2800 CDF/USD)
            $amountCdf = (int) round((float) $payment->getAmount() * 2800);

            // returnUrl : PawaPay redirige ici après paiement
            // On passe à PawaPayService notre ref interne ($ref = "EMG-000042")
            // PawaPayService construit : returnUrl?ref=EMG-000042&depositId=<uuid_pawapay>
            // On override la returnUrl via le 2e paramètre de initiatePaymentPage
            // puisque PawaPayService utilise $this->returnUrl (injecté en config)
            // → On utilise directement initiatePaymentPage() avec $ref comme depositId
            //   PawaPayService génère un UUID interne et l'ajoute à returnUrl automatiquement

            $result = $this->pawaPayService->initiatePaymentPage(
                $ref,       // Notre référence interne (clientReferenceId / metadata.orderId)
                $amountCdf, // Montant en CDF
                '',         // Téléphone (vide → le client le saisit sur la page PawaPay)
                $reason     // Message affiché (4-22 chars)
            );

            if ($result['success'] && $result['redirectUrl']) {
                // Sauvegarder le depositId PawaPay (UUID généré par PawaPayService)
                $meta = $payment->getMetadata() ?? [];
                $meta['pawapay_deposit_id'] = $result['depositId']; // UUID PawaPay
                $meta['pawapay_ref']        = $ref;                  // Notre ref interne EMG-xxx
                $meta['amount_cdf']         = $amountCdf;
                $payment->setMetadata($meta);
                $payment->setProviderTxId($result['depositId']);
                $this->em->flush();

                $this->logger->info('[GADGET][PAWAPAY] Payment Page créée', [
                    'paymentId' => $payment->getId(),
                    'ref'       => $ref,
                    'depositId' => $result['depositId'],
                    'amountCdf' => $amountCdf,
                ]);

                return ['redirect' => $result['redirectUrl']];
            }

            $this->logger->warning('[GADGET][PAWAPAY] Fallback manuel', [
                'ref'   => $ref,
                'error' => $result['error'] ?? 'inconnu',
            ]);
        }

        // Fallback : instructions manuelles si PawaPay non configuré ou erreur
        return $this->mobileMoneyInstructions($payment, $provider, $ref);
    }

    private function mobileMoneyInstructions(Payment $payment, string $provider, string $ref): array
    {
        $labels = [
            'airtel'       => 'Airtel Money',
            'mpesa'        => 'M-PESA',
            'orange_money' => 'Orange Money',
            'pawapay'      => 'Mobile Money',
        ];

        return [
            'instructions' => [
                'provider'       => $provider,
                'provider_label' => $labels[$provider] ?? 'Mobile Money',
                'amount'         => $payment->getAmount(),
                'currency'       => $payment->getCurrency(),
                'reference'      => $ref,
                'confirm_url'    => $this->router->generate('app_gadget_payment_mobile_confirm', [
                    'paymentId' => $payment->getId(),
                ], UrlGeneratorInterface::ABSOLUTE_URL),
                'steps' => [
                    "Ouvrez votre application {$labels[$provider]}",
                    "Envoyez {$payment->getAmount()} {$payment->getCurrency()} au numéro EnMémoire",
                    "Indiquez la référence : {$ref}",
                    "Cliquez sur « J'ai effectué le paiement » ci-dessous",
                ],
            ],
        ];
    }

    /**
     * Vérifie le statut PawaPay d'un depositId via PawaPayService.
     */
    public function checkPawaPayStatus(string $depositId): string
    {
        if (empty($depositId)) {
            return 'UNKNOWN';
        }
        $result = $this->pawaPayService->checkDepositStatus($depositId);
        return $result['status'] ?? 'UNKNOWN';
    }

    // =========================================================
    // CONFIRMATION — crédite le portefeuille (idempotent)
    // =========================================================

    public function confirmAndCreditWallet(Payment $payment): void
    {
        if ($payment->isCompleted()) {
            return;
        }

        $payment->setStatus(Payment::STATUS_COMPLETED);

        $meta     = $payment->getMetadata() ?? [];
        $gadgetId = $meta['gadget_id'] ?? null;
        $quantity = (int) ($meta['quantity'] ?? 1);

        if ($gadgetId) {
            $gadget = $this->em->getRepository(GadgetCatalog::class)->find($gadgetId);
            $user   = $payment->getUser();

            if ($gadget && $user) {
                // Enregistrer l'achat
                $purchase = new GadgetPurchase();
                $purchase->setUser($user)
                         ->setGadget($gadget)
                         ->setQuantity($quantity)
                         ->setUnitPrice($gadget->getPrice())
                         ->setTotalPrice($payment->getAmount())
                         ->setCurrency($payment->getCurrency());
                $this->em->persist($purchase);

                // Créditer le portefeuille
                $walletItem = $this->em->getRepository(UserGadgetWallet::class)
                    ->findOneBy(['user' => $user, 'gadget' => $gadget]);

                if (!$walletItem) {
                    $walletItem = new UserGadgetWallet();
                    $walletItem->setUser($user)->setGadget($gadget)->setQuantity(0);
                    $this->em->persist($walletItem);
                }
                $walletItem->setQuantity($walletItem->getQuantity() + $quantity);

                $this->logger->info('[GADGET] Portefeuille crédité', [
                    'user'     => $user->getId(),
                    'gadget'   => $gadget->getId(),
                    'quantity' => $quantity,
                ]);
            }
        }

        $this->em->flush();
    }

    public function failPayment(Payment $payment, string $reason = ''): void
    {
        $payment->setStatus(Payment::STATUS_FAILED);
        $meta = $payment->getMetadata() ?? [];
        $meta['failure_reason'] = $reason;
        $payment->setMetadata($meta);
        $this->em->flush();
    }

    // =========================================================
    // SANDBOX
    // =========================================================

    private function sandboxUrl(Payment $payment, string $provider): string
    {
        return $this->router->generate('app_gadget_payment_sandbox', [
            'paymentId' => $payment->getId(),
            'provider'  => $provider,
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function getStripePublicKey(): string { return $this->stripePublicKey; }
    public function getPayPalClientId(): string  { return $this->paypalClientId; }
    public function getPayPalMode(): string      { return $this->paypalMode; }
}
