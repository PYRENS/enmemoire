<?php

namespace App\Service\Payment;

use App\Entity\MemorialFormula;
use App\Entity\Payment;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class MobileMoneyService
{
    private const LABELS = [
        'airtel'       => 'Airtel Money',
        'mpesa'        => 'M-PESA',
        'orange_money' => 'Orange Money',
        'pawapay'      => 'PawaPay',
    ];

    public function __construct(
        #[Autowire('%env(PAWAPAY_API_KEY)%')]  private readonly string $pawaPayKey,
        #[Autowire('%env(PAWAPAY_BASE_URL)%')] private readonly string $pawaPayUrl,
        private readonly UrlGeneratorInterface $router,
    ) {}

    public function initiate(Payment $payment, MemorialFormula $formula, string $provider): PaymentIntent
    {
        $ref = 'EM-' . str_pad((string)$payment->getId(), 6, '0', STR_PAD_LEFT);

        return PaymentIntent::instructions($payment, [
            'provider'       => $provider,
            'provider_label' => self::LABELS[$provider] ?? $provider,
            'amount'         => $payment->getAmount(),
            'currency'       => $payment->getCurrency(),
            'reference'      => $ref,
            'instructions'   => match($provider) {
                'airtel' => [
                    "Envoyez {$payment->getAmount()} {$payment->getCurrency()} via Airtel Money",
                    "Numéro de destination : +243 XXX XXX XXX",
                    "Référence obligatoire : {$ref}",
                    "Envoyez la capture à support@enmemoire.com",
                ],
                'mpesa' => [
                    "Envoyez {$payment->getAmount()} {$payment->getCurrency()} via M-PESA",
                    "Numéro de destination : +243 XXX XXX XXX",
                    "Référence : {$ref}",
                    "Envoyez la confirmation SMS à support@enmemoire.com",
                ],
                'orange_money' => [
                    "Envoyez {$payment->getAmount()} {$payment->getCurrency()} via Orange Money",
                    "Numéro : +243 XXX XXX XXX",
                    "Référence : {$ref}",
                ],
                'pawapay' => [
                    "Paiement via PawaPay en cours de configuration",
                    "Référence : {$ref}",
                    "Contactez support@enmemoire.com",
                ],
                default => ["Contactez support@enmemoire.com avec la référence {$ref}"],
            },
            'confirm_url' => $this->router->generate('app_payment_mobile_confirm', [
                'paymentId' => $payment->getId(),
            ], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);
    }
}
