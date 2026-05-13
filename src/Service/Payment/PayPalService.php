<?php

namespace App\Service\Payment;

use App\Entity\MemorialFormula;
use App\Entity\Payment;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class PayPalService
{
    public function __construct(
        #[Autowire('%env(PAYPAL_CLIENT_ID)%')]     private readonly string $clientId,
        #[Autowire('%env(PAYPAL_CLIENT_SECRET)%')] private readonly string $clientSecret,
        #[Autowire('%env(PAYPAL_MODE)%')]          private readonly string $mode,
        private readonly UrlGeneratorInterface $router,
    ) {}

    public function createOrder(Payment $payment, MemorialFormula $formula): PaymentIntent
    {
        if (empty($this->clientId) || $this->mode !== 'live') {
            return PaymentIntent::redirect(
                $payment,
                $this->router->generate('app_payment_sandbox', [
                    'paymentId' => $payment->getId(),
                    'provider'  => 'paypal',
                ], UrlGeneratorInterface::ABSOLUTE_URL)
            );
        }

        // --- Production : PayPal Orders API v2 ---
        // $token = $this->getAccessToken();
        // $order = $this->createPayPalOrder($token, $payment, $formula);
        // return PaymentIntent::redirect($payment, $order['links']['approve']['href']);

        return PaymentIntent::redirect($payment, $this->router->generate('app_payment_sandbox', [
            'paymentId' => $payment->getId(), 'provider' => 'paypal',
        ], UrlGeneratorInterface::ABSOLUTE_URL));
    }
}
