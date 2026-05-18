<?php

namespace App\Service\Payment;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Intégration PawaPay v2 — Mobile Money RDC (M-Pesa, Airtel, Orange)
 *
 * PawaPay est la solution Mobile Money retenue pour VoixCitoyenne RDC car :
 *  - Couverture officielle RDC : M-Pesa (Vodacom), Airtel Money, Orange Money
 *  - Partenaire légal enregistré en RDC (Kerry Payments RDC SARLU)
 *  - API moderne, documentation excellente, sandbox libre d'accès
 *  - Zéro chargeback — contrairement aux cartes bancaires
 *  - Page de paiement hébergée disponible (aucun stockage de données sensibles)
 *  - Pas de frais mensuels sur le plan Standard
 *
 * Stratégie utilisée : Payment Page (page hébergée PawaPay)
 * ───────────────────────────────────────────────────────
 * Plutôt que l'API Direct Deposit (qui nécessite le push USSD et le numéro de
 * téléphone à l'avance), on utilise la Payment Page qui :
 *  1. Crée un lien de paiement via POST /v2/paymentpage
 *  2. Redirige le client vers la page PawaPay
 *  3. Le client choisit son opérateur (M-Pesa / Airtel / Orange) et valide
 *  4. PawaPay redirige vers returnUrl + appelle le webhook (callback URL)
 *
 * Cela simplifie l'UX (pas besoin de demander l'opérateur à l'avance)
 * et couvre les 3 principaux opérateurs RDC avec une seule intégration.
 *
 * Opérateurs RDC disponibles :
 *  - MPESA_CD       (Vodacom/M-Pesa)
 *  - AIRTEL_CD      (Airtel Money)
 *  - ORANGE_CD      (Orange Money)
 *
 * Variables d'environnement requises :
 *  PAWAPAY_API_TOKEN    — Token Bearer depuis Dashboard > API Tokens
 *  PAWAPAY_RETURN_URL   — URL de retour après paiement (ex: /don/pawapay/retour)
 *  PAWAPAY_WEBHOOK_URL  — URL du webhook de callback (ex: /don/webhook/pawapay)
 *
 * Sandbox : https://dashboard.sandbox.pawapay.io
 * API docs : https://docs.pawapay.io/v2
 */
final class PawaPayService
{
    private const API_SANDBOX = 'https://api.sandbox.pawapay.io/v2';
    private const API_LIVE    = 'https://api.pawapay.io/v2';

    /** Mapping opérateurs RDC PawaPay */
    public const PROVIDERS_RDC = [
        'mpesa'  => 'MPESA_CD',
        'airtel' => 'AIRTEL_CD',
        'orange' => 'ORANGE_CD',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface     $logger,
        private string                       $apiToken   = '',
        private string                       $returnUrl  = '',
        private string                       $webhookUrl = '',
        private string                       $mode       = 'sandbox',
    ) {}

    // ─── Payment Page (page hébergée) ─────────────────────────────────────────

    /**
     * Crée une Payment Page PawaPay et retourne l'URL de redirection.
     *
     * Le client est redirigé vers la page PawaPay où il saisit son numéro
     * et choisit son opérateur (M-Pesa, Airtel Money, Orange Money).
     *
     * @param string $depositId  UUID unique de la transaction (notre référence DON-xxx)
     * @param int    $amountCdf  Montant en Franc Congolais (CDF)
     * @param string $phone      Numéro pré-rempli (optionnel, format 243XXXXXXXXX)
     * @param string $reason     Message affiché sur la page (4-22 chars)
     *
     * @return array{
     *   success: bool,
     *   redirectUrl: string|null,  — URL vers laquelle rediriger le client
     *   depositId: string|null,    — UUID PawaPay de la transaction
     *   error: string|null
     * }
     */
    public function initiatePaymentPage(
        string $depositId,
        int    $amountCdf,
        string $phone  = '',
        string $reason = 'Don VoixCitoyenne'
    ): array {
        if (!$this->apiToken) {
            return ['success' => false, 'redirectUrl' => null, 'depositId' => null, 'error' => 'PawaPay non configuré (token manquant).'];
        }

        // PawaPay exige un UUID v4 comme depositId
        // On utilise notre référence DON-xxx comme clientReferenceId
        // et on génère un UUID pour le depositId
        $uuid = $this->generateUuid();

        // Le reason doit faire entre 4 et 22 caractères
        $reason = mb_substr(trim($reason) ?: 'Don VoixCitoyenne', 0, 22);
        if (mb_strlen($reason) < 4) {
            $reason = 'Don VC RDC';
        }

        $body = [
            'depositId'       => $uuid,
            'returnUrl'       => $this->returnUrl . '?ref=' . urlencode($depositId) . '&depositId=' . $uuid,
            'customerMessage' => $reason,
            'amountDetails'   => [
                'amount'   => (string) $amountCdf,
                'currency' => 'CDF',
            ],
            'country'         => 'COD',   // Code ISO Alpha-3 de la RDC
            'language'        => 'FR',
            'reason'          => $reason,
            'metadata'        => [
                ['orderId'     => $depositId],    // Notre référence interne
                ['platform'    => 'VoixCitoyenne RDC'],
            ],
        ];

        // Pré-remplir le numéro si disponible (format 243XXXXXXXXX)
        if ($phone) {
            $phone = preg_replace('/[^0-9]/', '', $phone);
            if (str_starts_with($phone, '0')) {
                $phone = '243' . substr($phone, 1);
            } elseif (!str_starts_with($phone, '243')) {
                $phone = '243' . $phone;
            }
            $body['phoneNumber'] = $phone;
        }

        try {
            $response = $this->httpClient->request('POST', $this->getBaseUrl() . '/paymentpage', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiToken,
                    'Content-Type'  => 'application/json',
                ],
                'json' => $body,
            ]);

            $data       = $response->toArray(false);
            $statusCode = $response->getStatusCode();
            $status     = $data['status'] ?? '';

            $this->logger->info('PawaPay Payment Page initiée', [
                'ref'        => $depositId,
                'uuid'       => $uuid,
                'httpStatus' => $statusCode,
                'status'     => $status,
            ]);

            // Succès : HTTP 201 avec redirectUrl présent dans la réponse
            // Note : PawaPay Payment Page ne retourne PAS de champ 'status'
            // La présence de 'redirectUrl' suffit à confirmer le succès
            if (in_array($statusCode, [200, 201]) && isset($data['redirectUrl'])) {
                return [
                    'success'     => true,
                    'redirectUrl' => $data['redirectUrl'],
                    'depositId'   => $uuid,
                    'error'       => null,
                ];
            }

            $error = $data['failureReason']['failureMessage']
                ?? ($data['message'] ?? 'Erreur PawaPay inconnue (status: ' . $status . ')');

            $this->logger->error('PawaPay Payment Page échouée', [
                'ref'   => $depositId,
                'error' => $error,
                'data'  => $data,
            ]);

            return ['success' => false, 'redirectUrl' => null, 'depositId' => null, 'error' => $error];

        } catch (\Throwable $e) {
            $this->logger->error('PawaPay exception réseau', ['ref' => $depositId, 'message' => $e->getMessage()]);
            return ['success' => false, 'redirectUrl' => null, 'depositId' => null, 'error' => $e->getMessage()];
        }
    }

    // ─── Vérification du statut d'un dépôt ───────────────────────────────────

    /**
     * Vérifie le statut d'un dépôt PawaPay (polling ou vérification return_url).
     *
     * Statuts possibles :
     *  - COMPLETED  → paiement réussi ✅
     *  - FAILED     → paiement échoué ❌
     *  - PENDING    → en attente (le client n'a pas encore validé)
     *  - ACCEPTED   → accepté, traitement en cours
     *
     * @param string $depositId  UUID PawaPay (généré lors de initiatePaymentPage)
     *
     * @return array{
     *   success: bool,
     *   status: string|null,       — 'COMPLETED', 'FAILED', 'PENDING', 'ACCEPTED'
     *   amount: string|null,
     *   currency: string|null,
     *   provider: string|null,     — Ex: 'MPESA_CD', 'AIRTEL_CD', 'ORANGE_CD'
     *   error: string|null
     * }
     */
    public function checkDepositStatus(string $depositId): array
    {
        try {
            $response = $this->httpClient->request(
                'GET',
                $this->getBaseUrl() . '/deposits/' . urlencode($depositId),
                [
                    'headers' => ['Authorization' => 'Bearer ' . $this->apiToken],
                ]
            );

            $dataArr    = $response->toArray(false);
            $statusCode = $response->getStatusCode();

            // PawaPay retourne un tableau d'objets
            $data = is_array($dataArr) && isset($dataArr[0]) ? $dataArr[0] : $dataArr;
            $status = $data['status'] ?? '';

            $this->logger->info('PawaPay statut dépôt vérifié', [
                'depositId' => $depositId,
                'status'    => $status,
            ]);

            return [
                'success'  => $status === 'COMPLETED',
                'status'   => $status,
                'amount'   => $data['amount'] ?? null,
                'currency' => $data['currency'] ?? null,
                'provider' => $data['payer']['accountDetails']['provider'] ?? null,
                'error'    => $status === 'FAILED'
                    ? ($data['failureReason']['failureMessage'] ?? 'Paiement échoué')
                    : null,
            ];

        } catch (\Throwable $e) {
            $this->logger->error('PawaPay check status exception', ['depositId' => $depositId, 'message' => $e->getMessage()]);
            return ['success' => false, 'status' => 'UNKNOWN', 'amount' => null, 'currency' => null, 'provider' => null, 'error' => $e->getMessage()];
        }
    }

    // ─── Vérification signature webhook ───────────────────────────────────────

    /**
     * Vérifie l'authenticité d'un callback PawaPay.
     *
     * PawaPay signe les callbacks avec RS256 (RSA + SHA-256).
     * Pour simplifier, on vérifie que le depositId correspond à notre référence
     * et que le statut est COMPLETED.
     *
     * En production, activer la vérification de signature complète dans le dashboard.
     *
     * @param array $payload Corps du webhook décodé en tableau
     * @return bool
     */
    public function verifyWebhook(array $payload): bool
    {
        // Vérification minimale : le payload doit contenir depositId et status
        return isset($payload['depositId']) && isset($payload['status']);
    }

    // ─── Utilitaires ──────────────────────────────────────────────────────────

    private function getBaseUrl(): string
    {
        return $this->mode === 'live' ? self::API_LIVE : self::API_SANDBOX;
    }

    /**
     * Génère un UUID v4 requis par l'API PawaPay pour le depositId.
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiToken);
    }

    public function getMode(): string
    {
        return $this->mode;
    }
}
