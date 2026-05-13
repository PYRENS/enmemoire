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

    public function createCheckoutSession(Payment $payment, MemorialFormula $formula): PaymentIntent
    {
        if (empty($this->secretKey) || str_starts_with($this->secretKey, 'sk_test_YOUR')) {
            // Mode sandbox — pas de vraie clé configurée
            return PaymentIntent::redirect(
                $payment,
                $this->router->generate('app_payment_sandbox', [
                    'paymentId' => $payment->getId(),
                    'provider'  => 'stripe',
                ], UrlGeneratorInterface::ABSOLUTE_URL)
            );
        }

        // --- Production : appel API Stripe ---
        // \Stripe\Stripe::setApiKey($this->secretKey);
        // $session = \Stripe\Checkout\Session::create([
        //     'payment_method_types' => ['card'],
        //     'line_items'           => [[
        //         'price_data' => [
        //             'currency'     => strtolower($payment->getCurrency()),
        //             'unit_amount'  => (int)(floatval($payment->getAmount()) * 100),
        //             'product_data' => ['name' => $formula->getName()],
        //         ],
        //         'quantity' => 1,
        //     ]],
        //     'mode'       => 'payment',
        //     'success_url' => $this->router->generate('app_payment_success', ['paymentId' => $payment->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
        //     'cancel_url'  => $this->router->generate('app_payment_cancel',  ['paymentId' => $payment->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
        //     'metadata'    => ['payment_id' => $payment->getId()],
        // ]);
        // return PaymentIntent::redirect($payment, $session->url);

        return PaymentIntent::redirect($payment, $this->router->generate('app_payment_sandbox', [
            'paymentId' => $payment->getId(), 'provider' => 'stripe',
        ], UrlGeneratorInterface::ABSOLUTE_URL));
    }
}
