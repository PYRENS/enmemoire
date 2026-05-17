<?php

namespace App\Service\Payment;

use App\Entity\MemorialFormula;
use App\Entity\Payment;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PayPalService
{
    private const SANDBOX_URL = 'https://api-m.sandbox.paypal.com';
    private const LIVE_URL    = 'https://api-m.paypal.com';

    public function __construct(
        #[Autowire('%env(PAYPAL_CLIENT_ID)%')]     private readonly string $clientId,
        #[Autowire('%env(PAYPAL_CLIENT_SECRET)%')] private readonly string $clientSecret,
        #[Autowire('%env(PAYPAL_MODE)%')]           private readonly string $mode,
        private readonly UrlGeneratorInterface $router,
        private readonly HttpClientInterface   $httpClient,
    ) {}

    public function getClientId(): string { return $this->clientId; }
    public function getMode(): string     { return $this->mode; }

    public function isConfigured(): bool
    {
        return !empty($this->clientId)
            && !empty($this->clientSecret)
            && !str_starts_with($this->clientId, 'YOUR_');
    }

    public function createOrder(Payment $payment, MemorialFormula $formula): PaymentIntent
    {
        if (!$this->isConfigured()) {
            return $this->toSandbox($payment);
        }

        try {
            $baseUrl = $this->mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;

            // Obtenir token d'accès
            $tokenResp = $this->httpClient->request('POST', $baseUrl . '/v1/oauth2/token', [
                'auth_basic' => [$this->clientId, $this->clientSecret],
                'body'       => ['grant_type' => 'client_credentials'],
            ]);
            $token = $tokenResp->toArray()['access_token'];

            $successUrl = $this->router->generate('app_payment_success', [
                'paymentId' => $payment->getId(),
            ], UrlGeneratorInterface::ABSOLUTE_URL);

            $cancelUrl = $this->router->generate('app_payment_cancel', [
                'paymentId' => $payment->getId(),
            ], UrlGeneratorInterface::ABSOLUTE_URL);

            // Créer l'ordre PayPal
            $orderResp = $this->httpClient->request('POST', $baseUrl . '/v2/checkout/orders', [
                'auth_bearer' => $token,
                'json'        => [
                    'intent'         => 'CAPTURE',
                    'purchase_units' => [[
                        'amount'      => [
                            'currency_code' => $payment->getCurrency(),
                            'value'         => number_format((float)$payment->getAmount(), 2, '.', ''),
                        ],
                        'description' => 'EnMémoire — ' . $formula->getName(),
                        'custom_id'   => (string) $payment->getId(),
                    ]],
                    'application_context' => [
                        'return_url'  => $successUrl,
                        'cancel_url'  => $cancelUrl,
                        'brand_name'  => 'EnMémoire.com',
                        'locale'      => 'fr-FR',
                        'user_action' => 'PAY_NOW',
                    ],
                ],
            ]);

            $order = $orderResp->toArray();
            $orderId = $order['id'];

            // Stocker l'ID PayPal
            $meta = $payment->getMetadata() ?? [];
            $meta['paypal_order_id'] = $orderId;
            $payment->setMetadata($meta);
            $payment->setProviderTxId($orderId);

            // Trouver le lien d'approbation
            $approveUrl = '';
            foreach ($order['links'] ?? [] as $link) {
                if ($link['rel'] === 'approve') {
                    $approveUrl = $link['href'];
                    break;
                }
            }

            return PaymentIntent::redirect($payment, $approveUrl ?: $this->toSandbox($payment)->redirectUrl);

        } catch (\Exception $e) {
            error_log('[PayPal] Erreur: ' . $e->getMessage());
            return $this->toSandbox($payment);
        }
    }

    public function captureOrder(string $orderId): bool
    {
        try {
            $baseUrl = $this->mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;

            $tokenResp = $this->httpClient->request('POST', $baseUrl . '/v1/oauth2/token', [
                'auth_basic' => [$this->clientId, $this->clientSecret],
                'body'       => ['grant_type' => 'client_credentials'],
            ]);
            $token = $tokenResp->toArray()['access_token'];

            $resp = $this->httpClient->request('POST', $baseUrl . "/v2/checkout/orders/{$orderId}/capture", [
                'auth_bearer' => $token,
                'json'        => [],
            ]);

            $result = $resp->toArray();
            return $result['status'] === 'COMPLETED';

        } catch (\Exception $e) {
            error_log('[PayPal Capture] ' . $e->getMessage());
            return false;
        }
    }

    private function toSandbox(Payment $payment): PaymentIntent
    {
        return PaymentIntent::redirect($payment, $this->router->generate('app_payment_sandbox', [
            'paymentId' => $payment->getId(),
            'provider'  => 'paypal',
        ], UrlGeneratorInterface::ABSOLUTE_URL));
    }
}
