<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\MemorialPageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/profile')]
#[IsGranted('ROLE_USER')]
class ProfileController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface      $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly SluggerInterface            $slugger,
        private readonly MemorialPageRepository      $memorialRepo,
    ) {}

    // =========================================================
    // PROFIL PRINCIPAL
    // =========================================================
    #[Route('', name: 'app_profile', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $pages  = $this->memorialRepo->findManagedByUser($user);
        $stats  = $this->getUserStats($user);

        return $this->render('profile/index.html.twig', [
            'user'  => $user,
            'pages' => $pages,
            'stats' => $stats,
        ]);
    }

    // =========================================================
    // ÉDITION DES INFORMATIONS
    // =========================================================
    #[Route('/edit', name: 'app_profile_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user  = $this->getUser();
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('profile_edit', $request->request->get('_token'))) {
                $error = 'Token de sécurité invalide.';
            } else {
                $firstName = trim($request->request->get('first_name', ''));
                $lastName  = trim($request->request->get('last_name', ''));
                $phone     = trim($request->request->get('phone_whatsapp', ''));
                $locale    = $request->request->get('locale', 'fr');

                if (strlen($firstName) < 2 || strlen($lastName) < 2) {
                    $error = 'Prénom et nom doivent contenir au moins 2 caractères.';
                } else {
                    $user->setFirstName($firstName)
                         ->setLastName($lastName)
                         ->setPhoneWhatsapp($phone ?: null)
                         ->setLocale(in_array($locale, ['fr', 'en']) ? $locale : 'fr');

                    $this->em->flush();
                    $this->addFlash('success', 'Profil mis à jour avec succès.');
                    return $this->redirectToRoute('app_profile');
                }
            }
        }

        return $this->render('profile/edit.html.twig', [
            'user'  => $user,
            'error' => $error,
        ]);
    }

    // =========================================================
    // CHANGEMENT DE MOT DE PASSE
    // =========================================================
    #[Route('/password', name: 'app_profile_password', methods: ['GET', 'POST'])]
    public function changePassword(Request $request): Response
    {
        /** @var User $user */
        $user  = $this->getUser();
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('profile_password', $request->request->get('_token'))) {
                $error = 'Token invalide.';
            } else {
                $current  = $request->request->get('current_password', '');
                $new      = $request->request->get('new_password', '');
                $confirm  = $request->request->get('confirm_password', '');

                if (!$this->hasher->isPasswordValid($user, $current)) {
                    $error = 'Mot de passe actuel incorrect.';
                } elseif (strlen($new) < 8) {
                    $error = 'Le nouveau mot de passe doit contenir au moins 8 caractères.';
                } elseif ($new !== $confirm) {
                    $error = 'Les mots de passe ne correspondent pas.';
                } elseif ($current === $new) {
                    $error = 'Le nouveau mot de passe doit être différent de l\'ancien.';
                } else {
                    $user->setPasswordHash($this->hasher->hashPassword($user, $new));
                    $this->em->flush();
                    $this->addFlash('success', 'Mot de passe modifié avec succès.');
                    return $this->redirectToRoute('app_profile');
                }
            }
        }

        return $this->render('profile/password.html.twig', [
            'error' => $error,
        ]);
    }

    // =========================================================
    // UPLOAD AVATAR
    // =========================================================
    #[Route('/avatar', name: 'app_profile_avatar', methods: ['POST'])]
    public function uploadAvatar(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('profile_avatar', $request->request->get('_token'))) {
            return $this->json(['error' => 'Token invalide'], 403);
        }

        $file = $request->files->get('avatar');
        if (!$file) {
            return $this->json(['error' => 'Aucun fichier envoyé'], 400);
        }

        // Validation
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($file->getMimeType(), $allowed)) {
            return $this->json(['error' => 'Format non supporté. Utilisez JPG, PNG ou WebP.'], 422);
        }

        if ($file->getSize() > 5 * 1024 * 1024) {
            return $this->json(['error' => 'Fichier trop lourd (max 5 Mo).'], 422);
        }

        /** @var User $user */
        $user      = $this->getUser();
        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/avatars';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Supprimer l'ancien avatar
        if ($user->getAvatarUrl()) {
            $oldFile = $this->getParameter('kernel.project_dir') . '/public/' . $user->getAvatarUrl();
            if (file_exists($oldFile)) {
                unlink($oldFile);
            }
        }

        $extension = $file->guessExtension();
        $filename  = $this->slugger->slug($user->getFirstName() . '-' . $user->getId())
                     . '-' . uniqid() . '.' . $extension;

        try {
            $file->move($uploadDir, $filename);
            $user->setAvatarUrl('uploads/avatars/' . $filename);
            $this->em->flush();

            return $this->json([
                'success'    => true,
                'avatar_url' => '/uploads/avatars/' . $filename,
            ]);
        } catch (FileException $e) {
            return $this->json(['error' => 'Erreur lors de l\'upload.'], 500);
        }
    }

    // =========================================================
    // SUPPRIMER AVATAR
    // =========================================================
    #[Route('/avatar/delete', name: 'app_profile_avatar_delete', methods: ['POST'])]
    public function deleteAvatar(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('profile_avatar_delete', $request->request->get('_token'))) {
            return $this->json(['error' => 'Token invalide'], 403);
        }

        /** @var User $user */
        $user = $this->getUser();

        if ($user->getAvatarUrl()) {
            $filePath = $this->getParameter('kernel.project_dir') . '/public/' . $user->getAvatarUrl();
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            $user->setAvatarUrl(null);
            $this->em->flush();
        }

        return $this->json(['success' => true]);
    }

    // =========================================================
    // PRÉFÉRENCES
    // =========================================================
    #[Route('/preferences', name: 'app_profile_preferences', methods: ['GET', 'POST'])]
    public function preferences(Request $request): Response
    {
        /** @var User $user */
        $user  = $this->getUser();
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('profile_prefs', $request->request->get('_token'))) {
                $error = 'Token invalide.';
            } else {
                $locale           = $request->request->get('locale', 'fr');
                $emailNotif       = (bool) $request->request->get('email_notifications', false);
                $emailCondolences = (bool) $request->request->get('email_condolences', false);
                $emailExpiry      = (bool) $request->request->get('email_expiry', false);

                $user->setLocale(in_array($locale, ['fr', 'en']) ? $locale : 'fr');

                // Stocker les préférences de notification en metadata
                $prefs = $user->getPreferences() ?? [];
                $prefs['email_notifications']  = $emailNotif;
                $prefs['email_condolences']    = $emailCondolences;
                $prefs['email_expiry_warning'] = $emailExpiry;
                $user->setPreferences($prefs);

                $this->em->flush();
                $this->addFlash('success', 'Préférences enregistrées.');
                return $this->redirectToRoute('app_profile_preferences');
            }
        }

        return $this->render('profile/preferences.html.twig', [
            'user'  => $user,
            'prefs' => $user->getPreferences() ?? [],
            'error' => $error,
        ]);
    }

    // =========================================================
    // SUPPRESSION DU COMPTE
    // =========================================================
    #[Route('/delete', name: 'app_profile_delete', methods: ['GET', 'POST'])]
    public function deleteAccount(Request $request): Response
    {
        /** @var User $user */
        $user  = $this->getUser();
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('profile_delete', $request->request->get('_token'))) {
                $error = 'Token invalide.';
            } else {
                $password    = $request->request->get('password', '');
                $confirmation = $request->request->get('confirmation', '');

                if (!$this->hasher->isPasswordValid($user, $password)) {
                    $error = 'Mot de passe incorrect.';
                } elseif ($confirmation !== 'SUPPRIMER') {
                    $error = 'Tapez exactement "SUPPRIMER" pour confirmer.';
                } else {
                    // Anonymiser les données plutôt que supprimer (RGPD)
                    $user->setFirstName('Utilisateur')
                         ->setLastName('Supprimé')
                         ->setEmail('deleted_' . $user->getId() . '@enmemoire.deleted')
                         ->setStatus(User::STATUS_SUSPENDED)
                         ->setAvatarUrl(null)
                         ->setPhoneWhatsapp(null)
                         ->setPhoneVerified(false)
                         ->setEmailVerified(false);

                    $this->em->flush();

                    // Déconnecter
                    $this->container->get('security.token_storage')->setToken(null);
                    $request->getSession()->invalidate();

                    $this->addFlash('info', 'Votre compte a été supprimé. Nous espérons vous revoir.');
                    return $this->redirectToRoute('app_home');
                }
            }
        }

        return $this->render('profile/delete.html.twig', [
            'error' => $error,
        ]);
    }

    // =========================================================
    // Stats utilisateur
    // =========================================================
    private function getUserStats(User $user): array
    {
        $conn = $this->em->getConnection();
        $id   = $user->getId();

        return [
            'pages'       => (int) $conn->executeQuery(
                "SELECT COUNT(*) FROM memorial_pages WHERE created_by = ?", [$id]
            )->fetchOne(),
            'condolences' => (int) $conn->executeQuery(
                "SELECT COUNT(*) FROM condolences WHERE user_id = ?", [$id]
            )->fetchOne(),
            'testimonials'=> (int) $conn->executeQuery(
                "SELECT COUNT(*) FROM testimonials WHERE user_id = ?", [$id]
            )->fetchOne(),
            'member_since'=> $user->getCreatedAt()->format('d/m/Y'),
        ];
    }
}
