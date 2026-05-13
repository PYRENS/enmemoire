<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\OtpService;
use App\Service\UserRegistrationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class AuthController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface      $em,
        private readonly OtpService                  $otpService,
        private readonly UserRegistrationService     $registrationService,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly MailerInterface             $mailer,
        #[Autowire('%env(MAILER_FROM_ADDRESS)%')] private readonly string $fromAddress,
        #[Autowire('%env(APP_NAME)%')]            private readonly string $appName,
    ) {}

    // =========================================================
    // LOGIN
    // =========================================================
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('auth/login.html.twig', [
            'last_username' => $authUtils->getLastUsername(),
            'error'         => $authUtils->getLastAuthenticationError(),
        ]);
    }

    // =========================================================
    // REGISTER — Étape 1 : formulaire
    // =========================================================
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('register', $request->request->get('_token'))) {
                $error = 'Token de sécurité invalide. Rechargez la page.';
            } else {
                $email    = strtolower(trim($request->request->get('email', '')));
                $password = $request->request->get('password', '');
                $confirm  = $request->request->get('password_confirm', '');
                $firstName= trim($request->request->get('first_name', ''));
                $lastName = trim($request->request->get('last_name', ''));
                $phone    = trim($request->request->get('phone_whatsapp', ''));
                $channel  = $request->request->get('otp_channel', 'email');
                $locale   = $request->request->get('locale', 'fr');
                $cgu      = $request->request->get('cgu');

                // Validations
                if (!$cgu) {
                    $error = 'Vous devez accepter les Conditions Générales d\'Utilisation.';
                } elseif (strlen($firstName) < 2 || strlen($lastName) < 2) {
                    $error = 'Prénom et nom doivent contenir au moins 2 caractères.';
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Adresse email invalide.';
                } elseif ($this->em->getRepository(User::class)->findOneBy(['email' => $email])) {
                    $error = 'Cette adresse email est déjà utilisée. <a href="' . $this->generateUrl('app_login') . '">Connectez-vous</a> ou utilisez une autre adresse.';
                } elseif (strlen($password) < 8) {
                    $error = 'Le mot de passe doit contenir au moins 8 caractères.';
                } elseif ($password !== $confirm) {
                    $error = 'Les mots de passe ne correspondent pas.';
                } elseif ($channel === 'whatsapp' && empty($phone)) {
                    $error = 'Numéro WhatsApp requis pour la vérification par WhatsApp.';
                } else {
                    try {
                        $user = $this->registrationService->register(
                            $firstName, $lastName, $email,
                            $password, $phone ?: null, $channel, $locale
                        );

                        $request->getSession()->set('pending_user_id', $user->getId());
                        $request->getSession()->set('otp_channel', $channel);

                        return $this->redirectToRoute('app_verify_otp');

                    } catch (\Exception $e) {
                        // En développement : afficher le message exact
                        // En production : remplacer par un message générique
                        $error = $e->getMessage();
                        error_log('[EnMémoire Register Error] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                    }
                }
            }
        }

        return $this->render('auth/register.html.twig', [
            'error' => $error,
            'form'  => $request->request->all(),
        ]);
    }

    // =========================================================
    // OTP — Vérification du code
    // =========================================================
    #[Route('/verify', name: 'app_verify_otp', methods: ['GET', 'POST'])]
    public function verifyOtp(Request $request): Response
    {
        $userId  = $request->getSession()->get('pending_user_id');
        $channel = $request->getSession()->get('otp_channel', 'email');

        if (!$userId) {
            return $this->redirectToRoute('app_register');
        }

        /** @var ?User $user */
        $user  = $this->em->getRepository(User::class)->find($userId);
        $error = null;

        if (!$user || $user->getStatus() === User::STATUS_ACTIVE) {
            $request->getSession()->remove('pending_user_id');
            return $this->redirectToRoute('app_login');
        }

        if ($request->isMethod('POST')) {
            $action = $request->request->get('action');

            // Renvoi du code
            if ($action === 'resend') {
                $sent = $this->registrationService->resendOtp($user, $channel);
                $this->addFlash($sent ? 'success' : 'warning',
                    $sent ? 'Un nouveau code a été envoyé.' : 'Attendez au moins 60 secondes avant de renvoyer.');
                return $this->redirectToRoute('app_verify_otp');
            }

            // Vérification du code
            $digits    = $request->request->all('digit') ?? [];
            $submitted = is_array($digits) ? implode('', $digits) : $request->request->get('otp_code', '');
            $submitted = preg_replace('/\D/', '', $submitted);

            if (!$this->isCsrfTokenValid('verify_otp', $request->request->get('_token'))) {
                $error = 'Token invalide.';
            } else {
                $result = $this->otpService->verifyOtp($user, $submitted);

                if ($result->ok) {
                    $this->registrationService->activateAccount($user, $channel);
                    $request->getSession()->remove('pending_user_id');
                    $request->getSession()->remove('otp_channel');
                    $this->addFlash('success', 'Votre compte est activé ! Bienvenue sur ' . $this->appName . '.');
                    return $this->redirectToRoute('app_login');
                }

                $error = match(true) {
                    $result->isExpired() => 'Ce code a expiré. Cliquez sur "Renvoyer le code".',
                    $result->isLocked()  => "Trop de tentatives. Réessayez dans {$result->remaining} minutes.",
                    $result->isInvalid() => "Code incorrect. Il vous reste {$result->remaining} tentative(s).",
                    default              => 'Aucun code en attente. Cliquez sur "Renvoyer le code".',
                };
            }
        }

        return $this->render('auth/verify_otp.html.twig', [
            'error'   => $error,
            'channel' => $channel,
            'user'    => $user,
        ]);
    }

    // =========================================================
    // MOT DE PASSE OUBLIÉ — Étape 1
    // =========================================================
    #[Route('/forgot-password', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function forgotPassword(Request $request): Response
    {
        $sent  = false;
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('forgot_pw', $request->request->get('_token'))) {
                $error = 'Token invalide.';
            } else {
                $email = strtolower(trim($request->request->get('email', '')));
                $user  = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

                // Toujours afficher "succès" pour ne pas révéler si l'email existe
                if ($user && $user->getStatus() === User::STATUS_ACTIVE) {
                    $code = $this->otpService->assignOtp($user);
                    $this->otpService->sendByEmail($user, $code);
                    $request->getSession()->set('reset_user_id', $user->getId());
                }
                $sent = true;
            }
        }

        return $this->render('auth/forgot_password.html.twig', [
            'sent'  => $sent,
            'error' => $error,
        ]);
    }

    // =========================================================
    // MOT DE PASSE OUBLIÉ — Étape 2 : nouveau mot de passe
    // =========================================================
    #[Route('/reset-password', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function resetPassword(Request $request): Response
    {
        $userId = $request->getSession()->get('reset_user_id');
        if (!$userId) return $this->redirectToRoute('app_forgot_password');

        $user  = $this->em->getRepository(User::class)->find($userId);
        if (!$user) return $this->redirectToRoute('app_forgot_password');

        $error = null;

        if ($request->isMethod('POST')) {
            $action = $request->request->get('action');

            if ($action === 'verify') {
                $submitted = preg_replace('/\D/', '', $request->request->get('otp_code', ''));
                $result    = $this->otpService->verifyOtp($user, $submitted);

                if (!$result->ok) {
                    $error = $result->isExpired()
                        ? 'Code expiré.' : "Code incorrect. {$result->remaining} tentative(s) restante(s).";
                } else {
                    $request->getSession()->set('reset_verified', true);
                }
            }

            if ($action === 'reset' && $request->getSession()->get('reset_verified')) {
                $password = $request->request->get('password', '');
                $confirm  = $request->request->get('password_confirm', '');

                if (strlen($password) < 8) {
                    $error = 'Le mot de passe doit contenir au moins 8 caractères.';
                } elseif ($password !== $confirm) {
                    $error = 'Les mots de passe ne correspondent pas.';
                } else {
                    $user->setPasswordHash($this->hasher->hashPassword($user, $password));
                    $this->em->flush();
                    $request->getSession()->remove('reset_user_id');
                    $request->getSession()->remove('reset_verified');
                    $this->addFlash('success', 'Mot de passe modifié avec succès. Connectez-vous.');
                    return $this->redirectToRoute('app_login');
                }
            }
        }

        return $this->render('auth/reset_password.html.twig', [
            'error'    => $error,
            'verified' => (bool) $request->getSession()->get('reset_verified'),
            'user'     => $user,
        ]);
    }
}
