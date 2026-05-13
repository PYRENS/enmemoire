<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ✅ FIX : OtpResult a été extrait dans src/Service/OtpResult.php.
 * Ce fichier ne contient plus que la classe OtpService.
 */
class OtpService
{
    private const OTP_LENGTH   = 6;
    private const OTP_TTL      = 600;  // 10 minutes en secondes
    private const MAX_ATTEMPTS = 3;
    private const LOCKOUT_MIN  = 15;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface        $mailer,
        #[Autowire('%env(MAILER_FROM_ADDRESS)%')] private readonly string $fromAddress,
        #[Autowire('%env(MAILER_FROM_NAME)%')]    private readonly string $fromName,
        #[Autowire('%env(APP_NAME)%')]            private readonly string $appName,
    ) {}

    // --------------------------------------------------
    // Génération d'un OTP numérique à 6 chiffres
    // --------------------------------------------------
    public function generateOtp(): string
    {
        return str_pad((string) random_int(0, 999999), self::OTP_LENGTH, '0', STR_PAD_LEFT);
    }

    // --------------------------------------------------
    // Assigner un OTP à un user et persister
    // --------------------------------------------------
    public function assignOtp(User $user): string
    {
        $code = $this->generateOtp();

        $user->setOtpCode($code)
             ->setOtpExpiresAt(new \DateTime('+' . self::OTP_TTL . ' seconds'))
             ->setOtpAttempts(0);

        $this->em->flush();

        return $code;
    }

    // --------------------------------------------------
    // Vérifier l'OTP soumis par l'utilisateur
    // --------------------------------------------------
    public function verifyOtp(User $user, string $submittedCode): OtpResult
    {
        // Trop de tentatives → lockout
        if ($user->getOtpAttempts() >= self::MAX_ATTEMPTS) {
            return OtpResult::tooManyAttempts(self::LOCKOUT_MIN);
        }

        // Aucun code en attente
        if (!$user->getOtpCode()) {
            return OtpResult::noCode();
        }

        // Code expiré
        if ($user->getOtpExpiresAt() < new \DateTime()) {
            $user->resetOtp();
            $this->em->flush();
            return OtpResult::expired();
        }

        // Code incorrect
        if (!hash_equals($user->getOtpCode(), $submittedCode)) {
            $user->incrementOtpAttempts();
            $this->em->flush();
            $remaining = self::MAX_ATTEMPTS - $user->getOtpAttempts();
            return OtpResult::invalid($remaining);
        }

        // ✅ Code correct
        $user->resetOtp();
        $this->em->flush();
        return OtpResult::success();
    }

    // --------------------------------------------------
    // Envoi OTP par email
    // --------------------------------------------------
    public function sendByEmail(User $user, string $code): void
    {
        $html = $this->buildEmailHtml($user, $code);

        $email = (new Email())
            ->from(sprintf('%s <%s>', $this->fromName, $this->fromAddress))
            ->to($user->getEmail())
            ->subject("Votre code de vérification — {$this->appName}")
            ->html($html)
            ->text("Votre code {$this->appName} est : {$code}\nValable 10 minutes. Ne le partagez pas.");

        $this->mailer->send($email);
    }

    // --------------------------------------------------
    // Envoi OTP par WhatsApp via Twilio (stub prod)
    // --------------------------------------------------
    public function sendByWhatsApp(User $user, string $code): bool
    {
        if (!$user->getPhoneWhatsapp()) {
            return false;
        }

        // --- Production : décommenter et configurer Twilio ---
        // $twilio = new \Twilio\Rest\Client($this->twilioSid, $this->twilioToken);
        // $twilio->messages->create('whatsapp:' . $user->getPhoneWhatsapp(), [
        //     'from' => 'whatsapp:' . $this->twilioFrom,
        //     'body' => "Votre code {$this->appName} : {$code}\nValable 10 minutes.",
        // ]);

        // Dev : log uniquement
        error_log("[OTP-WhatsApp] Code {$code} pour " . $user->getPhoneWhatsapp());
        return true;
    }

    // --------------------------------------------------
    // Template HTML de l'email OTP
    // --------------------------------------------------
    private function buildEmailHtml(User $user, string $code): string
    {
        $digits = implode('', array_map(
            fn(string $c) => "<span style='display:inline-block;width:44px;height:56px;line-height:56px;"
                . "text-align:center;background:#F4F6F9;border:2px solid #E0E0E0;border-radius:10px;"
                . "font-size:28px;font-weight:700;color:#1B2B4B;margin:0 3px;font-family:monospace;'>"
                . htmlspecialchars($c) . "</span>",
            str_split($code)
        ));

        $year      = date('Y');
        $firstName = htmlspecialchars($user->getFirstName());
        $appName   = htmlspecialchars($this->appName);

        return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#F4F6F9;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#F4F6F9;padding:40px 20px;">
  <tr><td align="center">
    <table width="520" cellpadding="0" cellspacing="0" style="background:white;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,.08);overflow:hidden;max-width:520px;width:100%;">
      <tr><td style="background:#1B2B4B;padding:28px 32px;text-align:center;">
        <h1 style="color:white;font-weight:300;font-size:26px;margin:0;">
          🕯️ <span style="color:#C9A84C;">En</span>Mémoire<span style="color:#C9A84C;">.com</span>
        </h1>
      </td></tr>
      <tr><td style="padding:36px 40px;text-align:center;">
        <h2 style="color:#1B2B4B;font-size:20px;margin:0 0 10px;">Bonjour, {$firstName} 👋</h2>
        <p style="color:#6B7280;font-size:15px;margin:0 0 28px;line-height:1.6;">
          Voici votre code de vérification <strong>{$appName}</strong>.<br>
          Il est valable <strong>10 minutes</strong>.
        </p>
        <div style="margin:0 auto 28px;">{$digits}</div>
        <p style="color:#9CA3AF;font-size:13px;margin:0;">
          Si vous n'avez pas demandé ce code, ignorez cet email.<br>
          Ne partagez jamais votre code.
        </p>
      </td></tr>
      <tr><td style="background:#F9FAFB;border-top:1px solid #E5E7EB;padding:18px 32px;text-align:center;">
        <p style="color:#9CA3AF;font-size:12px;margin:0;">© {$year} {$appName} — Tous droits réservés</p>
      </td></tr>
    </table>
  </td></tr>
</table>
</body></html>
HTML;
    }
}
