<?php

namespace App\Service\Payment;

use App\Entity\MemorialFormula;
use App\Entity\Payment;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class MobileMoneyService
{
    private const LABELS = [
        'airtel'       => 'Airtel Money',
        'mpesa'        => 'M-PESA',
        'orange_money' => 'Orange Money',
        'pawapay'      => 'Mobile Money (PawaPay)',
    ];

    public function __construct(
        #[Autowire('%env(PAWAPAY_API_KEY)%')]  private readonly string $pawaPayKey,
        #[Autowire('%env(PAWAPAY_BASE_URL)%')] private readonly string $pawaPayUrl,
        private readonly UrlGeneratorInterface  $router,
        private readonly PawaPayService         $pawaPayService,
        private readonly EntityManagerInterface $em,   // ← AJOUTÉ
    ) {}

    public function initiate(Payment $payment, MemorialFormula $formula, string $provider): PaymentIntent
    {
        $ref = 'EM-' . str_pad((string)$payment->getId(), 6, '0', STR_PAD_LEFT);

        if ($this->pawaPayService->isConfigured()) {
            return $this->initiatePawaPayPage($payment, $formula, $provider, $ref);
        }

        return $this->manualInstructions($payment, $provider, $ref);
    }

    private function initiatePawaPayPage(
        Payment $payment,
        MemorialFormula $formula,
        string $provider,
        string $ref
    ): PaymentIntent {
        // Convertir USD -> CDF (~2800 CDF/USD)
        $amountCdf = (int) round((float)$payment->getAmount() * 2800);

        $result = $this->pawaPayService->initiatePaymentPage(
            $ref,
            $amountCdf,
            '',
            'EnMemoire ' . str_replace('-', '', $ref)
        );

        if ($result['success'] && $result['redirectUrl']) {
            $meta = $payment->getMetadata() ?? [];
            $meta['pawapay_deposit_id'] = $result['depositId'];
            $meta['pawapay_ref']        = $ref;
            $meta['amount_cdf']         = $amountCdf;
            $payment->setMetadata($meta);
            $payment->setProviderTxId($result['depositId']);
            $this->em->flush(); // ← AJOUTÉ — persiste avant la redirection

            return PaymentIntent::redirect($payment, $result['redirectUrl']);
        }

        return $this->manualInstructions($payment, $provider, $ref);
    }

    private function manualInstructions(Payment $payment, string $provider, string $ref): PaymentIntent
    {
        $confirmUrl = $this->router->generate('app_payment_mobile_confirm', [
            'paymentId' => $payment->getId(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        return PaymentIntent::instructions($payment, [
            'provider'       => $provider,
            'provider_label' => self::LABELS[$provider] ?? $provider,
            'amount'         => $payment->getAmount(),
            'currency'       => $payment->getCurrency(),
            'reference'      => $ref,
            'instructions'   => match($provider) {
                'airtel' => [
                    "Composez *400# sur votre telephone Airtel",
                    "Selectionnez Envoyer de l'argent",
                    "Numero : +243 XXX XXX XXX",
                    "Montant : {$payment->getAmount()} {$payment->getCurrency()}",
                    "Reference : {$ref}",
                ],
                'mpesa' => [
                    "Composez *234# sur votre telephone Vodacom",
                    "Selectionnez M-PESA -> Envoyer de l'argent",
                    "Numero : +243 XXX XXX XXX",
                    "Montant : {$payment->getAmount()} {$payment->getCurrency()}",
                    "Reference : {$ref}",
                ],
                'orange_money' => [
                    "Composez #144# sur votre telephone Orange",
                    "Selectionnez Transfert d'argent",
                    "Numero : +243 XXX XXX XXX",
                    "Montant : {$payment->getAmount()} {$payment->getCurrency()}",
                    "Reference : {$ref}",
                ],
                default => [
                    "Paiement via Mobile Money",
                    "Reference : {$ref}",
                    "Contactez support@enmemoire.com",
                ],
            },
            'confirm_url' => $confirmUrl,
        ]);
    }
}