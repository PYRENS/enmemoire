<?php

namespace App\Controller;

use App\Entity\MemorialModerator;
use App\Entity\MemorialPage;
use App\Entity\User;
use App\Repository\MemorialModeratorRepository;
use App\Repository\MemorialPageRepository;
use App\Service\MemorialPageService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/dashboard/moderateurs')]
#[IsGranted('ROLE_USER')]
class ModeratorController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface      $em,
        private readonly MemorialPageRepository      $memorialRepo,
        private readonly MemorialModeratorRepository $moderatorRepo,
        private readonly MemorialPageService         $memorialService,
        private readonly NotificationService         $notifService,
        private readonly UrlGeneratorInterface       $router,
        private readonly MailerInterface             $mailer,
    ) {}

    // =========================================================
    // LISTE DES MODÉRATEURS D'UNE PAGE
    // =========================================================
    #[Route('/{slug}', name: 'app_moderators_index', methods: ['GET'])]
    public function index(string $slug): Response
    {
        $page = $this->getPageOrDeny($slug);
        $moderators = $this->moderatorRepo->findBy(
            ['memorial' => $page],
            ['isOwner' => 'DESC', 'createdAt' => 'ASC']
        );

        return $this->render('moderator/index.html.twig', [
            'page'       => $page,
            'moderators' => $moderators,
            'isOwner'    => $this->memorialService->isOwner($page, $this->getUser()),
        ]);
    }

    // =========================================================
    // INVITER UN MODÉRATEUR
    // =========================================================
    #[Route('/{slug}/inviter', name: 'app_moderator_invite', methods: ['GET', 'POST'])]
    public function invite(string $slug, Request $request): Response
    {
        $page = $this->getPageOrDeny($slug);

        // Seul le propriétaire peut inviter
        if (!$this->memorialService->isOwner($page, $this->getUser())) {
            throw $this->createAccessDeniedException('Seul le propriétaire peut inviter des modérateurs.');
        }

        $error   = null;
        $success = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('moderator_invite_' . $page->getId(), $request->request->get('_token'))) {
                $error = 'Token invalide.';
            } else {
                $email = trim($request->request->get('email', ''));
                $note  = trim($request->request->get('note', ''));

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Adresse email invalide.';
                } elseif ($email === $this->getUser()->getEmail()) {
                    $error = 'Vous ne pouvez pas vous inviter vous-même.';
                } else {
                    // Chercher si l'utilisateur existe déjà
                    $invitedUser = $this->em->getRepository(User::class)
                        ->findOneBy(['email' => $email]);

                    // Vérifier si déjà modérateur
                    $existing = $invitedUser
                        ? $this->moderatorRepo->findOneBy(['memorial' => $page, 'user' => $invitedUser])
                        : null;

                    if ($existing && $existing->getStatus() === MemorialModerator::STATUS_ACTIVE) {
                        $error = 'Cet utilisateur est déjà modérateur de cette page.';
                    } elseif ($existing && $existing->getStatus() === MemorialModerator::STATUS_PENDING) {
                        $error = 'Une invitation a déjà été envoyée à cet email.';
                    } else {
                        // Créer le modérateur en attente
                        $moderator = new MemorialModerator();
                        $moderator->setMemorial($page)
                                  ->setStatus(MemorialModerator::STATUS_PENDING)
                                  ->setIsOwner(false)
                                  ->setInvitedBy($this->getUser())
                                  ->setInvitedAt(new \DateTime())
                                  ->setModeratorCode($this->memorialService->generateModeratorCode());

                        if ($invitedUser) {
                            $moderator->setUser($invitedUser);
                        } else {
                            // Utilisateur externe — stocker l'email, user_id restera null
                            // jusqu'à ce qu'il crée un compte et accepte l'invitation
                            $moderator->setInvitedEmail($email);
                        }

                        $this->em->persist($moderator);
                        $this->em->flush();

                        // Envoyer la notification si l'user existe
                        if ($invitedUser) {
                            $inviteUrl = $this->router->generate(
                                'app_moderator_accept',
                                ['code' => $moderator->getModeratorCode()],
                                UrlGeneratorInterface::ABSOLUTE_URL
                            );

                            $this->notifService->send(
                                $invitedUser,
                                'moderator_invited',
                                '🕯️ Invitation à co-gérer une page mémorielle',
                                sprintf(
                                    '%s vous invite à co-gérer la page de %s.',
                                    $this->getUser()->getFullName(),
                                    $page->getDeceasedFullName()
                                ),
                                $inviteUrl
                            );
                        }

                        // Envoyer l'email d'invitation
                        $this->sendInvitationEmail($email, $page, $moderator, $note);

                        $success = sprintf(
                            'Invitation envoyée à <strong>%s</strong>. Code : <code>%s</code>',
                            htmlspecialchars($email),
                            $moderator->getModeratorCode()
                        );
                    }
                }
            }
        }

        $moderators = $this->moderatorRepo->findBy(['memorial' => $page]);

        return $this->render('moderator/invite.html.twig', [
            'page'       => $page,
            'moderators' => $moderators,
            'error'      => $error,
            'success'    => $success,
        ]);
    }

    // =========================================================
    // ACCEPTER UNE INVITATION (via lien email)
    // =========================================================
    #[Route('/accepter/{code}', name: 'app_moderator_accept', methods: ['GET', 'POST'])]
    public function accept(string $code, Request $request): Response
    {
        $moderator = $this->moderatorRepo->findOneBy(['moderatorCode' => $code]);

        if (!$moderator || $moderator->getStatus() !== MemorialModerator::STATUS_PENDING) {
            $this->addFlash('error', 'Ce lien d\'invitation est invalide ou a déjà été utilisé.');
            return $this->redirectToRoute('app_home');
        }

        $page = $moderator->getMemorial();

        // Si pas connecté → rediriger vers login avec retour
        if (!$this->getUser()) {
            $request->getSession()->set('moderator_invite_code', $code);
            $this->addFlash('info', 'Connectez-vous ou créez un compte pour accepter l\'invitation.');
            return $this->redirectToRoute('app_login');
        }

        /** @var User $user */
        $user = $this->getUser();

        // Vérifier que le code n'est pas pour quelqu'un d'autre
        if ($moderator->getUser() && $moderator->getUser()->getId() !== $user->getId()) {
            $this->addFlash('error', 'Cette invitation ne vous est pas destinée.');
            return $this->redirectToRoute('app_home');
        }

        if ($request->isMethod('POST')) {
            $action = $request->request->get('action');

            if ($action === 'accept') {
                // Vérifier si l'user a déjà une entrée active sur cette page
                $existing = $this->moderatorRepo->findOneBy([
                    'memorial' => $page,
                    'user'     => $user,
                    'status'   => MemorialModerator::STATUS_ACTIVE,
                ]);

                if ($existing) {
                    $this->addFlash('info', 'Vous êtes déjà modérateur de cette page.');
                    // Supprimer le doublon en attente
                    if ($moderator->getUser() === null) {
                        $this->em->remove($moderator);
                        $this->em->flush();
                    }
                    return $this->redirectToRoute('app_dashboard_memorial', ['slug' => $page->getSlug()]);
                }

                $moderator->setUser($user)
                          ->setStatus(MemorialModerator::STATUS_ACTIVE);
                $this->em->flush();

                // Notifier le propriétaire
                $owner = $moderator->getInvitedBy();
                if ($owner) {
                    $this->notifService->send(
                        $owner,
                        'moderator_accepted',
                        '✅ Invitation acceptée',
                        sprintf('%s a accepté de co-gérer la page de %s.', $user->getFullName(), $page->getDeceasedFullName()),
                        $this->router->generate('app_moderators_index', ['slug' => $page->getSlug()])
                    );
                }

                $this->addFlash('success', sprintf(
                    'Vous êtes maintenant co-modérateur de la page de %s.',
                    $page->getDeceasedFullName()
                ));
                return $this->redirectToRoute('app_dashboard_memorial', ['slug' => $page->getSlug()]);

            } else {
                // Refuser
                $moderator->setStatus(MemorialModerator::STATUS_REVOKED);
                $this->em->flush();

                $this->addFlash('info', 'Vous avez décliné l\'invitation.');
                return $this->redirectToRoute('app_home');
            }
        }

        return $this->render('moderator/accept.html.twig', [
            'moderator' => $moderator,
            'page'      => $page,
            'invitedBy' => $moderator->getInvitedBy(),
        ]);
    }

    // =========================================================
    // RÉVOQUER UN MODÉRATEUR (Ajax)
    // =========================================================
    #[Route('/{slug}/revoquer/{moderatorId}', name: 'app_moderator_revoke', methods: ['POST'])]
    public function revoke(string $slug, int $moderatorId, Request $request): JsonResponse
    {
        $page = $this->getPageOrDeny($slug);

        if (!$this->memorialService->isOwner($page, $this->getUser())) {
            return $this->json(['error' => 'Accès refusé.'], 403);
        }

        if (!$this->isCsrfTokenValid('moderator_revoke_' . $moderatorId, $request->request->get('_token'))) {
            return $this->json(['error' => 'Token invalide.'], 403);
        }

        $moderator = $this->moderatorRepo->find($moderatorId);

        if (!$moderator || $moderator->getMemorial()->getId() !== $page->getId()) {
            return $this->json(['error' => 'Modérateur introuvable.'], 404);
        }

        if ($moderator->isOwner()) {
            return $this->json(['error' => 'Impossible de révoquer le propriétaire.'], 422);
        }

        $revokedUser = $moderator->getUser();
        $moderator->setStatus(MemorialModerator::STATUS_REVOKED);
        $this->em->flush();

        // Notifier le modérateur révoqué
        if ($revokedUser) {
            $this->notifService->send(
                $revokedUser,
                'moderator_revoked',
                '⚠️ Accès modérateur retiré',
                sprintf('Votre accès à la page de %s a été révoqué.', $page->getDeceasedFullName()),
                ''
            );
        }

        return $this->json(['success' => true]);
    }

    // =========================================================
    // ANNULER UNE INVITATION EN ATTENTE (Ajax)
    // =========================================================
    #[Route('/{slug}/annuler/{moderatorId}', name: 'app_moderator_cancel', methods: ['POST'])]
    public function cancelInvite(string $slug, int $moderatorId, Request $request): JsonResponse
    {
        $page = $this->getPageOrDeny($slug);

        if (!$this->isCsrfTokenValid('moderator_cancel_' . $moderatorId, $request->request->get('_token'))) {
            return $this->json(['error' => 'Token invalide.'], 403);
        }

        $moderator = $this->moderatorRepo->find($moderatorId);

        if (!$moderator || $moderator->getMemorial()->getId() !== $page->getId()) {
            return $this->json(['error' => 'Invitation introuvable.'], 404);
        }

        if ($moderator->getStatus() !== MemorialModerator::STATUS_PENDING) {
            return $this->json(['error' => 'Cette invitation n\'est plus en attente.'], 422);
        }

        $this->em->remove($moderator);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    // =========================================================
    // RENVOYER UNE INVITATION (Ajax)
    // =========================================================
    #[Route('/{slug}/renvoyer/{moderatorId}', name: 'app_moderator_resend', methods: ['POST'])]
    public function resendInvite(string $slug, int $moderatorId, Request $request): JsonResponse
    {
        $page = $this->getPageOrDeny($slug);

        if (!$this->isCsrfTokenValid('moderator_resend_' . $moderatorId, $request->request->get('_token'))) {
            return $this->json(['error' => 'Token invalide.'], 403);
        }

        $moderator = $this->moderatorRepo->find($moderatorId);

        if (!$moderator || $moderator->getMemorial()->getId() !== $page->getId()) {
            return $this->json(['error' => 'Invitation introuvable.'], 404);
        }

        $email = $moderator->getUser()?->getEmail()
            ?? ($moderator->getMetadata()['invited_email'] ?? null);

        if ($email) {
            $this->sendInvitationEmail($email, $page, $moderator, '');
        }

        return $this->json(['success' => true, 'message' => 'Invitation renvoyée.']);
    }

    // =========================================================
    // HELPERS
    // =========================================================
    private function getPageOrDeny(string $slug): MemorialPage
    {
        $page = $this->memorialRepo->findBySlug($slug);
        if (!$page) throw $this->createNotFoundException();

        $user = $this->getUser();
        $row  = $this->em->getConnection()->executeQuery(
            'SELECT created_by FROM memorial_pages WHERE id = ?', [$page->getId()]
        )->fetchAssociative();

        $isOwner = $row && (int)$row['created_by'] === $user->getId();
        $isMod   = $this->memorialService->isModerator($page, $user);

        if (!$isOwner && !$isMod) {
            throw $this->createAccessDeniedException();
        }

        return $page;
    }

    private function sendInvitationEmail(
        string            $email,
        MemorialPage      $page,
        MemorialModerator $moderator,
        string            $note,
    ): void {
        $acceptUrl = $this->router->generate(
            'app_moderator_accept',
            ['code' => $moderator->getModeratorCode()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        try {
            $emailMessage = (new TemplatedEmail())
                ->from(new \Symfony\Component\Mime\Address(
                    $_ENV['MAILER_FROM_ADDRESS'] ?? 'noreply@enmemoire.com',
                    $_ENV['MAILER_FROM_NAME']    ?? 'EnMémoire.com'
                ))
                ->to($email)
                ->subject(sprintf('Invitation à co-gérer la page de %s', $page->getDeceasedFullName()))
                ->htmlTemplate('emails/moderator_invite.html.twig')
                ->context([
                    'page'       => $page,
                    'moderator'  => $moderator,
                    'invitedBy'  => $moderator->getInvitedBy(),
                    'accept_url' => $acceptUrl,
                    'note'       => $note,
                    'code'       => $moderator->getModeratorCode(),
                ]);

            $this->mailer->send($emailMessage);
        } catch (\Exception $e) {
            error_log('[EnMémoire] Moderator invite email failed: ' . $e->getMessage());
        }
    }
}
