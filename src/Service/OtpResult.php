<?php

namespace App\Service;

/**
 * ✅ FIX : OtpResult était déclaré dans le même fichier qu'OtpService.
 * PHP PSR-4 exige un fichier par classe. Ce fichier est désormais
 * src/Service/OtpResult.php (autoloadé automatiquement par Composer).
 *
 * Value Object immuable représentant le résultat d'une vérification OTP.
 */
final class OtpResult
{
    private function __construct(
        public readonly bool   $ok,
        public readonly string $reason    = '',
        public readonly int    $remaining = 0,
    ) {}

    // --- Constructeurs nommés ---

    public static function success(): self
    {
        return new self(true);
    }

    public static function expired(): self
    {
        return new self(false, 'expired');
    }

    /**
     * @param int $remaining Nombre de tentatives restantes
     */
    public static function invalid(int $remaining): self
    {
        return new self(false, 'invalid', $remaining);
    }

    /**
     * @param int $remaining Nombre de minutes de lockout
     */
    public static function tooManyAttempts(int $remaining): self
    {
        return new self(false, 'locked', $remaining);
    }

    public static function noCode(): self
    {
        return new self(false, 'no_code');
    }

    // --- Accesseurs sémantiques ---

    public function isExpired(): bool { return $this->reason === 'expired'; }
    public function isLocked(): bool  { return $this->reason === 'locked'; }
    public function isInvalid(): bool { return $this->reason === 'invalid'; }
    public function isNoCode(): bool  { return $this->reason === 'no_code'; }
}
