<?php

namespace App\Service\Payment;

use App\Entity\MemorialFormula;
use App\Entity\Payment;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class VirementService
{
    public function __construct(
        private readonly UrlGeneratorInterface $router,
    ) {}

    public function initiate(Payment $payment, MemorialFormula $formula): PaymentIntent
    {
        $ref = 'EM-' . str_pad((string)$payment->getId(), 6, '0', STR_PAD_LEFT);

        return PaymentIntent::instructions($payment, [
            'provider'       => 'virement',
            'provider_label' => 'Virement bancaire',
            'amount'         => $payment->getAmount(),
            'currency'       => $payment->getCurrency(),
            'reference'      => $ref,
            'instructions'   => [
                "Effectuez un virement de {$payment->getAmount()} {$payment->getCurrency()}",
                "IBAN : FR76 XXXX XXXX XXXX XXXX XXXX XXX",
                "BIC  : XXXXXXXX",
                "Bénéficiaire : EnMémoire SAS",
                "Référence obligatoire : {$ref}",
                "Délai de traitement : 2 à 5 jours ouvrés",
            ],
            'bank_details' => [
                'iban'         => 'FR76 XXXX XXXX XXXX XXXX XXXX XXX',
                'bic'          => 'XXXXXXXX',
                'beneficiaire' => 'EnMémoire SAS',
                'banque'       => 'Banque Exemple',
            ],
            'confirm_url' => $this->router->generate('app_payment_mobile_confirm', [
                'paymentId' => $payment->getId(),
            ], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);
    }
}
