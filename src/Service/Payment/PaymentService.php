<?php

namespace App\Service\Payment;

use App\Entity\MemorialFormula;
use App\Entity\Payment;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class PaymentService
{
    public const PROVIDERS = [
        'stripe'       => ['label' => 'Carte bancaire (Stripe)', 'icon' => 'bi-credit-card', 'zone' => 'international'],
        'paypal'       => ['label' => 'PayPal',                  'icon' => 'bi-paypal',       'zone' => 'international'],
        'airtel'       => ['label' => 'Airtel Money',            'icon' => 'bi-phone',        'zone' => 'rdc'],
        'mpesa'        => ['label' => 'M-PESA',                  'icon' => 'bi-phone',        'zone' => 'rdc'],
        'orange_money' => ['label' => 'Orange Money',            'icon' => 'bi-phone',        'zone' => 'rdc'],
        'pawapay'      => ['label' => 'PawaPay',                 'icon' => 'bi-phone',        'zone' => 'rdc'],
        'virement'     => ['label' => 'Virement bancaire',       'icon' => 'bi-bank',         'zone' => 'international'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StripeService          $stripe,
        private readonly PayPalService          $paypal,
        private readonly MobileMoneyService     $mobileMoney,
        private readonly VirementService        $virement,
    ) {}

    public function initiatePayment(
        User            $user,
        MemorialFormula $formula,
        string          $provider,
        string          $currency = 'USD',
        array           $metadata = [],
    ): PaymentIntent {
        $payment = new Payment();
        $payment->setUser($user)
                ->setType(Payment::TYPE_FORMULA)
                ->setAmount((string) $formula->getPrice())
                ->setCurrency($currency)
                ->setProvider($provider)
                ->setStatus(Payment::STATUS_PENDING)
                ->setMetadata(array_merge($metadata, [
                    'formula_slug' => $formula->getSlug(),
                    'formula_name' => $formula->getName(),
                ]));

        $this->em->persist($payment);
        $this->em->flush();

        return match($provider) {
            'stripe'                              => $this->stripe->createCheckoutSession($payment, $formula),
            'paypal'                              => $this->paypal->createOrder($payment, $formula),
            'airtel', 'mpesa', 'orange_money',
            'pawapay'                             => $this->mobileMoney->initiate($payment, $formula, $provider),
            'virement'                            => $this->virement->initiate($payment, $formula),
            default                               => throw new \InvalidArgumentException("Provider inconnu : $provider"),
        };
    }

    public function confirmPayment(Payment $payment): void
    {
        $payment->setStatus(Payment::STATUS_COMPLETED);
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

    public static function getProvidersByZone(): array
    {
        $zones = ['international' => [], 'rdc' => []];
        foreach (self::PROVIDERS as $key => $info) {
            $zones[$info['zone']][$key] = $info;
        }
        return $zones;
    }
}
