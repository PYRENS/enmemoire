<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

class UserRegistrationService
{
    public function __construct(
        private readonly EntityManagerInterface      $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly OtpService                  $otpService,
    ) {}

    /**
     * Crée un compte User en statut pending, génère et envoie l'OTP
     * selon le canal choisi (email ou whatsapp).
     */
    public function register(
        string $firstName,
        string $lastName,
        string $email,
        string $plainPassword,
        ?string $phoneWhatsapp = null,
        string $otpChannel = 'email',
        string $locale = 'fr',
    ): User {
        $user = new User();
        $user->setFirstName(trim($firstName))
             ->setLastName(trim($lastName))
             ->setEmail(strtolower(trim($email)))
             ->setPhoneWhatsapp($phoneWhatsapp ?: null)
             ->setLocale($locale)
             ->setStatus(User::STATUS_PENDING)
             ->setRole(User::ROLE_USER);

        $hash = $this->hasher->hashPassword($user, $plainPassword);
        $user->setPasswordHash($hash);

        $this->em->persist($user);
        $this->em->flush();

        // Générer et envoyer l'OTP
        $code = $this->otpService->assignOtp($user);

        if ($otpChannel === 'whatsapp' && $phoneWhatsapp) {
            $this->otpService->sendByWhatsApp($user, $code);
        } else {
            $this->otpService->sendByEmail($user, $code);
        }

        return $user;
    }

    /**
     * Active le compte après vérification OTP réussie.
     */
    public function activateAccount(User $user, string $channel = 'email'): void
    {
        if ($channel === 'whatsapp') {
            $user->setPhoneVerified(true);
        } else {
            $user->setEmailVerified(true);
        }
        $user->setStatus(User::STATUS_ACTIVE);
        $this->em->flush();
    }

    /**
     * Renvoie un OTP (avec vérification du délai anti-spam de 60s).
     */
    public function resendOtp(User $user, string $channel = 'email'): bool
    {
        // Anti-spam : OTP doit être expiré ou avoir plus de 60s
        if ($user->getOtpExpiresAt()) {
            $minResendAt = (clone $user->getOtpExpiresAt())->modify('-' . (600 - 60) . ' seconds');
            if (new \DateTime() < $minResendAt) {
                return false; // Trop tôt
            }
        }

        $code = $this->otpService->assignOtp($user);

        if ($channel === 'whatsapp' && $user->getPhoneWhatsapp()) {
            $this->otpService->sendByWhatsApp($user, $code);
        } else {
            $this->otpService->sendByEmail($user, $code);
        }

        return true;
    }
}
