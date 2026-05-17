<?php

namespace App\Service\Payment;

use App\Entity\MemorialFormula;
use App\Entity\Payment;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class StripeService
{
    public function __construct(
        #[Autowire('%env(STRIPE_SECRET_KEY)%')] private readonly string $secretKey,
        #[Autowire('%env(STRIPE_PUBLIC_KEY)%')] private readonly string $publicKey,
        private readonly UrlGeneratorInterface $router,
    ) {}

    public function getPublicKey(): string { return $this->publicKey; }

    public function isConfigured(): bool
    {
        return !empty($this->secretKey)
            && !str_starts_with($this->secretKey, 'sk_test_YOUR')
            && str_starts_with($this->secretKey, 'sk_');
    }

    public function createCheckoutSession(Payment $payment, MemorialFormula $formula): PaymentIntent
    {
        if (!$this->isConfigured()) {
            return $this->toSandbox($payment);
        }

        try {
            \Stripe\Stripe::setApiKey($this->secretKey);

            $successUrl = $this->router->generate('app_payment_success', [
                'paymentId' => $payment->getId(),
            ], UrlGeneratorInterface::ABSOLUTE_URL) . '?session_id={CHECKOUT_SESSION_ID}';

            $cancelUrl = $this->router->generate('app_payment_cancel', [
                'paymentId' => $payment->getId(),
            ], UrlGeneratorInterface::ABSOLUTE_URL);

            $session = \Stripe\Checkout\Session::create([
                'payment_method_types' => ['card'],
                'line_items'           => [[
                    'price_data' => [
                        'currency'     => strtolower($payment->getCurrency()),
                        'unit_amount'  => (int)(floatval($payment->getAmount()) * 100),
                        'product_data' => [
                            'name'        => 'EnMémoire — ' . $formula->getName(),
                            'description' => 'Page mémorielle numérique · ' .
                                ($formula->getDurationYears() ? $formula->getDurationYears() . ' an(s)' : 'Perpétuel'),
                        ],
                    ],
                    'quantity' => 1,
                ]],
                'mode'           => 'payment',
                'success_url'    => $successUrl,
                'cancel_url'     => $cancelUrl,
                'customer_email' => $payment->getUser()?->getEmail(),
                'metadata'       => [
                    'payment_id'  => (string) $payment->getId(),
                    'formula'     => $formula->getSlug(),
                ],
                'locale' => 'fr',
            ]);

            // Stocker session ID
            $meta = $payment->getMetadata() ?? [];
            $meta['stripe_session_id'] = $session->id;
            $payment->setMetadata($meta);
            $payment->setProviderTxId($session->id);

            return PaymentIntent::redirect($payment, $session->url);

        } catch (\Exception $e) {
            error_log('[Stripe] Erreur: ' . $e->getMessage());
            return $this->toSandbox($payment);
        }
    }

    public function verifyWebhook(string $payload, string $sig, string $secret): ?array
    {
        try {
            \Stripe\Stripe::setApiKey($this->secretKey);
            $event = \Stripe\Webhook::constructEvent($payload, $sig, $secret);
            return ['type' => $event->type, 'data' => $event->data->object];
        } catch (\Exception $e) {
            error_log('[Stripe Webhook] ' . $e->getMessage());
            return null;
        }
    }

    private function toSandbox(Payment $payment): PaymentIntent
    {
        return PaymentIntent::redirect($payment, $this->router->generate('app_payment_sandbox', [
            'paymentId' => $payment->getId(),
            'provider'  => 'stripe',
        ], UrlGeneratorInterface::ABSOLUTE_URL));
    }
}
