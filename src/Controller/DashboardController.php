<?php

namespace App\Controller;

use App\Entity\Announcement;
use App\Entity\Condolence;
use App\Entity\FamilyConnection;
use App\Entity\GuestBook;
use App\Entity\LifeTimeline;
use App\Entity\MemorialEvent;
use App\Entity\MemorialModerator;
use App\Entity\MemorialPage;
use App\Entity\MemorialTheme;
use App\Entity\ModeratorTrustList;
use App\Entity\Testimonial;
use App\Entity\User;
use App\Repository\MemorialModeratorRepository;
use App\Repository\MemorialPageRepository;
use App\Security\Voter\MemorialPageVoter;
use App\Service\ModerationService;
use App\Service\MediaService;
use App\Service\MemorialPageService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/dashboard')]
#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface      $em,
        private readonly MemorialPageService         $memorialService,
        private readonly ModerationService           $moderationService,
        private readonly NotificationService         $notifService,
        private readonly MemorialPageRepository      $memorialRepo,
        private readonly MemorialModeratorRepository $moderatorRepo,
        private readonly MediaService                $mediaService,
    ) {}

    // =========================================================
    // TABLEAU DE BORD GLOBAL DE L'UTILISATEUR
    // =========================================================
    #[Route('', name: 'app_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $myPages       = $this->memorialRepo->findManagedByUser($user);
        $notifications = $this->em->getRepository(\App\Entity\Notification::class)
            ->findBy(['user' => $user], ['createdAt' => 'DESC'], 10);

        return $this->render('dashboard/index.html.twig', [
            'myPages'       => $myPages,
            'notifications' => $notifications,
            'unreadCount'   => $this->notifService->getUnreadCount($user),
        ]);
    }

    // =========================================================
    // DASHBOARD D'UNE PAGE MÉMORIELLE
    // =========================================================
    #[Route('/memorial/{slug}', name: 'app_dashboard_memorial', methods: ['GET'])]
    public function memorialDashboard(string $slug): Response
    {
        $page = $this->getPageOrDeny($slug);
        $stats = $this->memorialService->getStats($page);
        $pending = $this->moderationService->getPendingCount($page);

        return $this->render('dashboard/memorial.html.twig', [
            'page'        => $page,
            'stats'       => $stats,
            'pending'     => $pending,
            'moderators'  => $this->moderatorRepo->findActiveModeratorsForPage($page),
            'events'      => $page->getEvents()->toArray(),
            'storageMode' => $this->mediaService->getStorageMode(),
            'themes'      => $this->em->getRepository(MemorialTheme::class)
                ->findBy(['isActive' => true], ['sortOrder' => 'ASC']),
        ]);
    }

    // =========================================================
    // ÉDITER LE PROFIL DU DÉFUNT
    // =========================================================
    #[Route('/memorial/{slug}/edit-profile', name: 'app_dashboard_edit_profile', methods: ['GET', 'POST'])]
    public function editProfile(string $slug, Request $request): Response
    {
        $page = $this->getPageOrDeny($slug);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_profile_' . $page->getId(), $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token de sécurité invalide.');
                return $this->redirectToRoute('app_dashboard_edit_profile', ['slug' => $slug]);
            }

            $page->setDeceasedFirstName($request->request->get('first_name', $page->getDeceasedFirstName()))
                 ->setDeceasedLastName($request->request->get('last_name', $page->getDeceasedLastName()))
                 ->setDeceasedNickname($request->request->get('nickname') ?: null)
                 ->setDeceasedProfession($request->request->get('profession') ?: null)
                 ->setDeceasedBirthPlace($request->request->get('birth_place') ?: null)
                 ->setDeceasedDeathPlace($request->request->get('death_place', $page->getDeceasedDeathPlace()))
                 ->setDeceasedQuote($request->request->get('quote') ?: null)
                 ->setObituaryText($request->request->get('obituary') ?: null)
                 ->setBiographyText($request->request->get('biography') ?: null);

            $this->em->flush();
            $this->addFlash('success', 'Profil mis à jour avec succès.');
            return $this->redirectToRoute('app_dashboard_memorial', ['slug' => $slug]);
        }

        return $this->render('dashboard/edit_profile.html.twig', ['page' => $page]);
    }

    // =========================================================
    // ÉDITER LA LIGNE DE VIE
    // =========================================================
    #[Route('/memorial/{slug}/timeline', name: 'app_dashboard_timeline', methods: ['GET'])]
    public function timeline(string $slug): Response
    {
        $page = $this->getPageOrDeny($slug);
        return $this->render('dashboard/timeline.html.twig', [
            'page'     => $page,
            'timeline' => $page->getLifeTimelines()->toArray(),
        ]);
    }

    #[Route('/memorial/{slug}/timeline/add', name: 'app_dashboard_timeline_add', methods: ['POST'])]
    public function timelineAdd(string $slug, Request $request): Response
    {
        $page = $this->getPageOrDeny($slug);

        if (!$this->isCsrfTokenValid('timeline_' . $page->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token invalide.');
            return $this->redirectToRoute('app_dashboard_timeline', ['slug' => $slug]);
        }

        $entry = new LifeTimeline();
        $entry->setMemorial($page)
              ->setTitle($request->request->get('title', ''))
              ->setDescription($request->request->get('description') ?: null)
              ->setEventDate(new \DateTime($request->request->get('event_date', 'now')))
              ->setEventDatePrecision($request->request->get('precision', 'day'))
              ->setCreatedBy($this->getUser());

        $this->em->persist($entry);
        $this->em->flush();

        $this->addFlash('success', 'Étape ajoutée à la ligne de vie.');
        return $this->redirectToRoute('app_dashboard_timeline', ['slug' => $slug]);
    }

    #[Route('/memorial/{slug}/timeline/{id}/delete', name: 'app_dashboard_timeline_delete', methods: ['POST'])]
    public function timelineDelete(string $slug, int $id, Request $request): Response
    {
        $page  = $this->getPageOrDeny($slug);
        $entry = $this->em->getRepository(LifeTimeline::class)->find($id);

        if ($entry && $entry->getMemorial() === $page) {
            if ($this->isCsrfTokenValid('timeline_delete_' . $id, $request->request->get('_token'))) {
                $this->em->remove($entry);
                $this->em->flush();
                $this->addFlash('success', 'Étape supprimée.');
            }
        }
        return $this->redirectToRoute('app_dashboard_timeline', ['slug' => $slug]);
    }

    // =========================================================
    // GESTION DES ÉVÉNEMENTS
    // =========================================================
    #[Route('/memorial/{slug}/events', name: 'app_dashboard_events', methods: ['GET'])]
    public function events(string $slug): Response
    {
        $page = $this->getPageOrDeny($slug);
        return $this->render('dashboard/events.html.twig', [
            'page'   => $page,
            'events' => $page->getEvents()->toArray(),
        ]);
    }

    #[Route('/memorial/{slug}/events/add', name: 'app_dashboard_event_add', methods: ['POST'])]
    public function eventAdd(string $slug, Request $request): Response
    {
        $page = $this->getPageOrDeny($slug);

        if (!$this->isCsrfTokenValid('event_add_' . $page->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token invalide.');
            return $this->redirectToRoute('app_dashboard_events', ['slug' => $slug]);
        }

        $event = new MemorialEvent();
        $event->setMemorial($page)
              ->setType($request->request->get('type', MemorialEvent::TYPE_FUNERAL))
              ->setTitle($request->request->get('title', ''))
              ->setDescription($request->request->get('description') ?: null)
              ->setEventDate(new \DateTime($request->request->get('event_date', 'now')))
              ->setLocationName($request->request->get('location_name') ?: null)
              ->setLocationAddress($request->request->get('location_address') ?: null)
              ->setLiveUrl($request->request->get('live_url') ?: null)
              ->setCreatedBy($this->getUser());

        $this->em->persist($event);
        $this->em->flush();

        // Upload image de couverture
        $coverFile = $request->files->get('cover_image');
        if ($coverFile) {
            try {
                $url = $this->mediaService->uploadEventCover($coverFile, $event);
                $event->setCoverImageUrl($url);
                $this->em->flush();
            } catch (\Exception $e) {
                $this->addFlash('warning', 'Image de couverture non uploadée : ' . $e->getMessage());
            }
        }

        $this->addFlash('success', 'Événement créé avec succès.');
        return $this->redirectToRoute('app_dashboard_events', ['slug' => $slug]);
    }


    #[Route('/memorial/{slug}/events/{eventId}/edit', name: 'app_dashboard_event_edit', methods: ['POST'])]
    public function eventEdit(string $slug, int $eventId, Request $request): Response
    {
        $page = $this->getPageOrDeny($slug);
 
        if (!$this->isCsrfTokenValid('event_edit', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token invalide.');
            return $this->redirectToRoute('app_dashboard_events', ['slug' => $slug]);
        }
 
        $event = $this->em->getRepository(\App\Entity\MemorialEvent::class)->find($eventId);
        if (!$event || $event->getMemorial() !== $page) {
            throw $this->createNotFoundException();
        }
 
        $event->setType($request->request->get('type', \App\Entity\MemorialEvent::TYPE_FUNERAL))
              ->setTitle($request->request->get('title', ''))
              ->setDescription($request->request->get('description') ?: null)
              ->setEventDate(new \DateTime($request->request->get('event_date', 'now')))
              ->setLocationName($request->request->get('location_name') ?: null)
              ->setLocationAddress($request->request->get('location_address') ?: null)
              ->setLiveUrl($request->request->get('live_url') ?: null);
 
        // Upload image de couverture
        $coverFile = $request->files->get('cover_image');
        if ($coverFile) {
            try {
                $url = $this->mediaService->uploadEventCover($coverFile, $event);
                $event->setCoverImageUrl($url);
            } catch (\Exception $e) {
                $this->addFlash('warning', 'Image non uploadée : ' . $e->getMessage());
            }
        }
 
        $this->em->flush();
        $this->addFlash('success', 'Événement mis à jour.');
        return $this->redirectToRoute('app_dashboard_events', ['slug' => $slug]);
    }



    #[Route('/memorial/{slug}/events/{eventId}/delete', name: 'app_dashboard_event_delete', methods: ['POST'])]
    public function eventDelete(string $slug, int $eventId, Request $request): Response
    {
        $page  = $this->getPageOrDeny($slug);
        $event = $this->em->getRepository(MemorialEvent::class)->find($eventId);

        if ($event && $event->getMemorial() === $page) {
            if ($this->isCsrfTokenValid('event_delete_' . $eventId, $request->request->get('_token'))) {
                $this->em->remove($event);
                $this->em->flush();
                $this->addFlash('success', 'Événement supprimé.');
            }
        }
        return $this->redirectToRoute('app_dashboard_events', ['slug' => $slug]);
    }

    // =========================================================
    // MODÉRATION DES PUBLICATIONS
    // =========================================================
    #[Route('/memorial/{slug}/moderate', name: 'app_dashboard_moderate', methods: ['GET'])]
    public function moderate(string $slug): Response
    {
        $page = $this->getPageOrDeny($slug);

        return $this->render('dashboard/moderate.html.twig', [
            'page'                => $page,
            'condolences'         => $this->em->getRepository(Condolence::class)
                ->findBy(['memorial' => $page, 'status' => 'pending'], ['createdAt' => 'ASC']),
            'testimonials'        => $this->em->getRepository(Testimonial::class)
                ->findBy(['memorial' => $page, 'status' => 'pending'], ['createdAt' => 'ASC']),
            'guestbooks'          => $this->em->getRepository(GuestBook::class)
                ->findBy(['memorial' => $page, 'status' => 'pending'], ['signedAt' => 'ASC']),
            'rejectedCondolences' => $this->em->getRepository(Condolence::class)
                ->findBy(['memorial' => $page, 'status' => 'rejected'], ['createdAt' => 'DESC']),
            'rejectedTestimonials'=> $this->em->getRepository(Testimonial::class)
                ->findBy(['memorial' => $page, 'status' => 'rejected'], ['createdAt' => 'DESC']),
            'rejectedGuestbooks'  => $this->em->getRepository(GuestBook::class)
                ->findBy(['memorial' => $page, 'status' => 'rejected'], ['signedAt' => 'DESC']),
            'pending'             => $this->moderationService->getPendingCount($page),
        ]);
    }

    #[Route('/memorial/{slug}/moderate/condolence/{id}/{action}', name: 'app_dashboard_moderate_condolence', methods: ['POST'])]
    public function moderateCondolence(string $slug, int $id, string $action, Request $request): Response
    {
        $page = $this->getPageOrDeny($slug);

        if (!$this->isCsrfTokenValid('moderate_' . $id, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token invalide.');
            return $this->redirectToRoute('app_dashboard_moderate', ['slug' => $slug]);
        }

        $item = $this->em->getRepository(Condolence::class)->find($id);
        if ($item && $item->getMemorial() === $page) {
            /** @var User $user */
            $user = $this->getUser();
            match ($action) {
                'approve' => $this->moderationService->approveCondolence($item, $user),
                'reject'  => $this->moderationService->rejectCondolence($item, $user),
                default   => null,
            };
            $this->addFlash('success', $action === 'approve' ? 'Condoléance approuvée.' : 'Condoléance rejetée.');
        }

        return $this->redirectToRoute('app_dashboard_moderate', ['slug' => $slug]);
    }

    #[Route('/memorial/{slug}/moderate/testimonial/{id}/{action}', name: 'app_dashboard_moderate_testimonial', methods: ['POST'])]
    public function moderateTestimonial(string $slug, int $id, string $action, Request $request): Response
    {
        $page = $this->getPageOrDeny($slug);

        if (!$this->isCsrfTokenValid('moderate_' . $id, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token invalide.'); return $this->redirectToRoute('app_dashboard_moderate', ['slug' => $slug]);
        }

        $item = $this->em->getRepository(Testimonial::class)->find($id);
        if ($item && $item->getMemorial() === $page) {
            /** @var User $user */
            $user = $this->getUser();
            if ($action === 'approve') $this->moderationService->approveTestimonial($item, $user);
            else {
                $item->setStatus('rejected');
                $this->em->flush();
            }
            $this->addFlash('success', 'Témoignage ' . ($action === 'approve' ? 'approuvé' : 'rejeté') . '.');
        }
        return $this->redirectToRoute('app_dashboard_moderate', ['slug' => $slug]);
    }

    #[Route('/memorial/{slug}/moderate/guestbook/{id}/{action}', name: 'app_dashboard_moderate_guestbook', methods: ['POST'])]
    public function moderateGuestbook(string $slug, int $id, string $action, Request $request): Response
    {
        $page = $this->getPageOrDeny($slug);

        if (!$this->isCsrfTokenValid('moderate_' . $id, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token invalide.');
            return $this->redirectToRoute('app_dashboard_moderate', ['slug' => $slug]);
        }

        $item = $this->em->getRepository(GuestBook::class)->find($id);
        if ($item && $item->getMemorial() === $page) {
            $item->setStatus($action === 'approve' ? 'approved' : 'rejected');
            $item->setModeratedBy($this->getUser());
            $item->setModeratedAt(new \DateTime());
            $this->em->flush();
            $this->addFlash('success', 'Signature ' . ($action === 'approve' ? 'approuvée' : 'rejetée') . '.');
        }

        return $this->redirectToRoute('app_dashboard_moderate', ['slug' => $slug]);
    }

    // =========================================================
    // GESTION DES MODÉRATEURS
    // =========================================================
    #[Route('/memorial/{slug}/moderators', name: 'app_dashboard_moderators', methods: ['GET'])]
    public function moderators(string $slug): Response
    {
        $page = $this->getPageOrDeny($slug);
        $currentMod = $this->moderatorRepo->findModeratorForUser($page, $this->getUser());

        return $this->render('dashboard/moderators.html.twig', [
            'page'       => $page,
            'moderators' => $this->moderatorRepo->findActiveModeratorsForPage($page),
            'trustList'  => $currentMod ? $currentMod->getTrustList()->toArray() : [],
            'currentMod' => $currentMod,
        ]);
    }

    #[Route('/memorial/{slug}/moderators/invite', name: 'app_dashboard_moderator_invite', methods: ['POST'])]
    public function moderatorInvite(string $slug, Request $request): Response
    {
        $page = $this->getPageOrDeny($slug);

        if (!$this->isCsrfTokenValid('mod_invite_' . $page->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token invalide.');
            return $this->redirectToRoute('app_dashboard_moderators', ['slug' => $slug]);
        }

        // Max 3 modérateurs
        if ($this->moderatorRepo->countActiveModerators($page) >= 3) {
            $this->addFlash('warning', 'Nombre maximum de modérateurs atteint (3).');
            return $this->redirectToRoute('app_dashboard_moderators', ['slug' => $slug]);
        }

        $email  = trim($request->request->get('email', ''));
        $target = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

        if (!$target) {
            $this->addFlash('danger', 'Aucun utilisateur trouvé avec cet email.');
            return $this->redirectToRoute('app_dashboard_moderators', ['slug' => $slug]);
        }

        if ($this->moderatorRepo->findModeratorForUser($page, $target)) {
            $this->addFlash('warning', 'Cet utilisateur est déjà modérateur.');
            return $this->redirectToRoute('app_dashboard_moderators', ['slug' => $slug]);
        }

        $mod = new MemorialModerator();
        $mod->setMemorial($page)
            ->setUser($target)
            ->setStatus(MemorialModerator::STATUS_PENDING)
            ->setModeratorCode($this->memorialService->generateModeratorCode())
            ->setInvitedBy($this->getUser())
            ->setInvitedAt(new \DateTime());

        $this->em->persist($mod);
        $this->em->flush();

        // Notification
        $this->notifService->send(
            $target,
            'moderator_invitation',
            'Invitation à devenir modérateur',
            'Vous êtes invité à co-gérer la page de ' . $page->getDeceasedFullName(),
            '/dashboard/invitation/' . $mod->getId() . '/accept',
        );

        $this->addFlash('success', 'Invitation envoyée à ' . $target->getFullName() . '.');
        return $this->redirectToRoute('app_dashboard_moderators', ['slug' => $slug]);
    }

    // =========================================================
    // LISTE DE CONFIANCE
    // =========================================================
    #[Route('/memorial/{slug}/trust/add', name: 'app_dashboard_trust_add', methods: ['POST'])]
    public function trustAdd(string $slug, Request $request): JsonResponse
    {
        $page = $this->getPageOrDeny($slug);

        if (!$this->isCsrfTokenValid('trust_' . $page->getId(), $request->getPayload()->getString('_token'))) {
            return $this->json(['error' => 'Token invalide.'], 403);
        }

        $email  = trim($request->getPayload()->getString('email'));
        $target = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

        if (!$target) return $this->json(['error' => 'Utilisateur introuvable.'], 404);

        $currentMod = $this->moderatorRepo->findModeratorForUser($page, $this->getUser());
        if (!$currentMod) return $this->json(['error' => 'Accès refusé.'], 403);

        $existing = $this->em->getRepository(ModeratorTrustList::class)
            ->findOneBy(['moderator' => $currentMod, 'trustedUser' => $target]);

        if ($existing) return $this->json(['error' => 'Déjà dans votre liste de confiance.'], 409);

        $trust = new ModeratorTrustList();
        $trust->setModerator($currentMod)->setTrustedUser($target);
        $this->em->persist($trust);
        $this->em->flush();

        return $this->json(['success' => true, 'name' => $target->getFullName()]);
    }

    // =========================================================
    // RAPPROCHEMENT FAMILIAL
    // =========================================================
    #[Route('/memorial/{slug}/connection/request', name: 'app_dashboard_connection_request', methods: ['POST'])]
    public function connectionRequest(string $slug, Request $request): Response
    {
        $page = $this->getPageOrDeny($slug);

        if (!$this->isCsrfTokenValid('connection_' . $page->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token invalide.');
            return $this->redirectToRoute('app_dashboard_memorial', ['slug' => $slug]);
        }

        $targetCode  = strtoupper(trim($request->request->get('target_page_code', '')));
        $relationFrom= trim($request->request->get('relation_from', ''));

        $targetPage  = $this->em->getRepository(MemorialPage::class)->findOneBy(['pageCode' => $targetCode]);

        if (!$targetPage) {
            $this->addFlash('danger', 'Aucune page mémorielle trouvée avec ce code.');
            return $this->redirectToRoute('app_dashboard_memorial', ['slug' => $slug]);
        }

        if ($targetPage === $page) {
            $this->addFlash('warning', 'Vous ne pouvez pas rapprocher une page avec elle-même.');
            return $this->redirectToRoute('app_dashboard_memorial', ['slug' => $slug]);
        }

        // Vérifier qu'une connexion n'existe pas déjà
        $existing = $this->em->getRepository(FamilyConnection::class)
            ->findOneBy(['memorialFrom' => $page, 'memorialTo' => $targetPage]);

        if ($existing) {
            $this->addFlash('warning', 'Une demande de rapprochement existe déjà.');
            return $this->redirectToRoute('app_dashboard_memorial', ['slug' => $slug]);
        }

        $connection = new FamilyConnection();
        $connection->setMemorialFrom($page)
                   ->setMemorialTo($targetPage)
                   ->setRelationFrom($relationFrom)
                   ->setRelationTo('') // Sera défini par le modérateur cible
                   ->setRequestedBy($this->getUser())
                   ->setStatus(FamilyConnection::STATUS_PENDING);

        $this->em->persist($connection);
        $this->em->flush();

        // Notifier les modérateurs de la page cible
        foreach ($this->moderatorRepo->findActiveModeratorsForPage($targetPage) as $mod) {
            $this->notifService->send(
                $mod->getUser(),
                'connection_request',
                'Demande de rapprochement familial',
                'La page de ' . $page->getDeceasedFullName() . ' souhaite être rapprochée de ' . $targetPage->getDeceasedFullName(),
                '/dashboard/memorial/' . $targetPage->getSlug() . '/connections',
            );
        }

        $this->addFlash('success', 'Demande de rapprochement envoyée.');
        return $this->redirectToRoute('app_dashboard_memorial', ['slug' => $slug]);
    }

    // =========================================================
    // MESSAGE DE REMERCIEMENT
    // =========================================================
    #[Route('/memorial/{slug}/thank-you', name: 'app_dashboard_thankyou', methods: ['POST'])]
    public function thankYou(string $slug, Request $request): Response
    {
        $page = $this->getPageOrDeny($slug);

        if (!$this->isCsrfTokenValid('thankyou_' . $page->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token invalide.');
            return $this->redirectToRoute('app_dashboard_memorial', ['slug' => $slug]);
        }

        $page->setThankYouMessage($request->request->get('message') ?: null);
        $this->em->flush();

        $this->addFlash('success', 'Message de remerciement enregistré.');
        return $this->redirectToRoute('app_dashboard_memorial', ['slug' => $slug]);
    }

    // =========================================================
    // CHANGER LE THÈME
    // =========================================================
    #[Route('/memorial/{slug}/theme', name: 'app_dashboard_theme', methods: ['POST'])]
    public function changeTheme(string $slug, Request $request): Response
    {
        $page = $this->getPageOrDeny($slug);
        $isAjax = $request->isXmlHttpRequest();

        if (!$this->isCsrfTokenValid('theme_' . $page->getId(), $request->request->get('_token'))) {
            if ($isAjax) return $this->json(['success' => false, 'error' => 'Token invalide.'], 403);
            $this->addFlash('danger', 'Token invalide.');
            return $this->redirectToRoute('app_dashboard_memorial', ['slug' => $slug]);
        }

        $themeId = (int) $request->request->get('theme_id');
        $theme   = $this->em->getRepository(MemorialTheme::class)->find($themeId);

        if (!$theme || !$theme->isActive()) {
            if ($isAjax) return $this->json(['success' => false, 'error' => 'Thème introuvable.'], 404);
            $this->addFlash('warning', 'Thème introuvable.');
            return $this->redirectToRoute('app_dashboard_memorial', ['slug' => $slug]);
        }

        // Thème spécial : réservé aux admins
        if ($theme->isSpecial() && !$this->isGranted('ROLE_ADMIN')) {
            if ($isAjax) return $this->json(['success' => false, 'error' => 'Thème réservé aux administrateurs.'], 403);
            $this->addFlash('warning', 'Ce thème est réservé aux administrateurs.');
            return $this->redirectToRoute('app_dashboard_memorial', ['slug' => $slug]);
        }

        $page->setTheme($theme);
        $this->em->flush();

        if ($isAjax) {
            return $this->json([
                'success'   => true,
                'themeName' => $theme->getName(),
                'themeSlug' => $theme->getSlug(),
                'message'   => 'Thème "' . $theme->getName() . '" appliqué avec succès !',
            ]);
        }

        $this->addFlash('success', 'Thème « ' . $theme->getName() . ' » appliqué.');
        return $this->redirectToRoute('app_dashboard_memorial', ['slug' => $slug]);
    }

    // =========================================================
    // UTILITAIRE PRIVÉ
    // =========================================================
    private function getPageOrDeny(string $slug): MemorialPage
    {
        $page = $this->memorialRepo->findBySlugActive($slug);

        if (!$page) {
            throw $this->createNotFoundException('Page mémorielle introuvable.');
        }

        $this->denyAccessUnlessGranted(MemorialPageVoter::MANAGE, $page);

        return $page;
    }
}