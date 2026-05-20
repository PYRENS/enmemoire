<?php

namespace App\Controller;

use App\Entity\Announcement;
use App\Entity\Condolence;
use App\Entity\GuestBook;
use App\Entity\MemorialModerator;
use App\Entity\MemorialPage;
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

#[Route('/memorial')]
class MemorialController extends AbstractController
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
    // PAGE MÉMORIELLE PUBLIQUE
    // =========================================================
    #[Route('/{slug}', name: 'app_memorial_show', methods: ['GET'], requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'])]
    public function show(string $slug, Request $request): Response
    {
        $page = $this->memorialRepo->findBySlugActive($slug);

        if (!$page) {
            throw $this->createNotFoundException('Page mémorielle introuvable.');
        }

        $this->denyAccessUnlessGranted(MemorialPageVoter::VIEW, $page);

        // Tracking visite (dédupliqué par session)
        $sessionKey = 'visited_' . $page->getId();
        if (!$request->getSession()->has($sessionKey)) {
            $this->memorialService->trackVisit($page, $this->getUser(), $request->getSession()->getId());
            $request->getSession()->set($sessionKey, true);
        }

        /** @var ?User $user */
        $user = $this->getUser();
        $isModerator = $user && $this->memorialService->isModerator($page, $user);

        // Modérateurs actifs
        $moderators = $this->moderatorRepo->findActiveModeratorsForPage($page);

        // Publications approuvées
        $condolences = $this->em->getRepository(Condolence::class)
            ->findBy(['memorial' => $page, 'status' => 'approved'], ['createdAt' => 'DESC'], 20);

        $testimonials = $this->em->getRepository(Testimonial::class)
            ->findBy(['memorial' => $page, 'status' => 'approved'], ['createdAt' => 'DESC'], 10);

        $guestbooks = $this->em->getRepository(GuestBook::class)
            ->findBy(['memorial' => $page, 'status' => 'approved'], ['signedAt' => 'DESC'], 30);

        // Événements à venir / passés
        $events = $page->getEvents()->toArray();
        usort($events, fn($a, $b) => $a->getEventDate() <=> $b->getEventDate());

        // Ligne de vie
        $lifeTimeline = $page->getLifeTimelines()->toArray();

        // Galerie principale (photos + videos)
        $gallery = $this->em->getRepository(\App\Entity\MediaGallery::class)
            ->findBy(['memorial' => $page, 'event' => null], ['sortOrder' => 'ASC', 'createdAt' => 'DESC'], 50);

        // Connexions familiales acceptées
        $familyConnections = $this->em->getRepository(\App\Entity\FamilyConnection::class)
            ->findAcceptedForPage($page);

        // Statistiques
        $stats = $this->memorialService->getStats($page);

        // Annonces épinglées
        $announcements = $this->em->getRepository(Announcement::class)
            ->findBy(['memorial' => $page], ['isPinned' => 'DESC', 'createdAt' => 'DESC'], 5);

        // Onglet par défaut : biographie si funérailles passées ou décès > 30 jours
        $defaultTab = 'obituary';
        $now = new \DateTime();
        foreach ($events as $event) {
            if ($event->getType() === 'funeral' && $event->getEventDate() < $now) {
                $defaultTab = 'timeline';
                break;
            }
        }
        if ($defaultTab === 'obituary' && $page->getDeceasedDeathDate() < (new \DateTime('-30 days'))) {
            $defaultTab = 'timeline';
        }


        return $this->render('memorial/show.html.twig', [
            'page'             => $page,
            'memorial_theme'   => $page->getTheme()?->getSlug() ?? 'classic-white',
            'isModerator'      => $isModerator,
            'moderators'       => $moderators,
            'condolences'      => $condolences,
            'testimonials'     => $testimonials,
            'guestbooks'       => $guestbooks,
            'events'           => $events,
            'lifeTimeline'     => $lifeTimeline,
            'gallery'          => $gallery,
            'familyConnections'=> $familyConnections,
            'announcements'    => $announcements,
            'stats'            => $stats,
            'userHasSigned'    => $user ? (bool) $this->em->getRepository(GuestBook::class)
                                    ->findOneBy(['memorial' => $page, 'user' => $user]) : false,
        ]);
    }

    // =========================================================
    // POST CONDOLÉANCE (Ajax)
    // =========================================================
    #[Route('/{slug}/condolence', name: 'app_memorial_condolence_post', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function postCondolence(string $slug, Request $request): JsonResponse
    {
        $page = $this->memorialRepo->findBySlugActive($slug);
        if (!$page) return $this->json(['error' => 'Page introuvable'], 404);

        $this->denyAccessUnlessGranted(MemorialPageVoter::INTERACT, $page);

        /** @var User $user */
        $user    = $this->getUser();
        $message = trim($request->getPayload()->getString('message'));

        if (mb_strlen($message) < 5) {
            return $this->json(['error' => 'Message trop court (5 caractères minimum).'], 422);
        }
        if (mb_strlen($message) > 2000) {
            return $this->json(['error' => 'Message trop long (2000 caractères maximum).'], 422);
        }

        // Vérification CSRF
        if (!$this->isCsrfTokenValid('condolence_' . $page->getId(), $request->getPayload()->getString('_token'))) {
            return $this->json(['error' => 'Token de sécurité invalide.'], 403);
        }

        $status = $this->moderationService->resolveStatus($page, $user);

        $condolence = new Condolence();
        $condolence->setMemorial($page)
                   ->setUser($user)
                   ->setMessage($message)
                   ->setIsAnonymous((bool) $request->getPayload()->get('anonymous', false))
                   ->setStatus($status);

        $this->em->persist($condolence);
        $this->em->flush();

        // Notifier les modérateurs si en attente
        if ($status === 'pending') {
            foreach ($this->moderatorRepo->findActiveModeratorsForPage($page) as $mod) {
                $this->notifService->send(
                    $mod->getUser(),
                    'condolence_pending',
                    'Nouvelle condoléance en attente',
                    $user->getFullName() . ' a posté une condoléance sur la page de ' . $page->getDeceasedFullName(),
                    '/dashboard/memorial/' . $page->getSlug() . '/moderate',
                    'memorial_page',
                    $page->getId(),
                );
            }
        }

        return $this->json([
            'status'  => $status,
            'message' => $status === 'approved'
                ? 'Votre condoléance a été publiée.'
                : 'Votre condoléance est en attente de validation.',
            'html'    => $status === 'approved' ? $this->renderView('memorial/partials/_condolence_item.html.twig', [
                'condolence' => $condolence,
            ]) : '',
        ]);
    }

    // =========================================================
    // POST TÉMOIGNAGE (Ajax)
    // =========================================================
    #[Route('/{slug}/testimonial', name: 'app_memorial_testimonial_post', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function postTestimonial(string $slug, Request $request): JsonResponse
    {
        $page = $this->memorialRepo->findBySlugActive($slug);
        if (!$page) return $this->json(['error' => 'Page introuvable'], 404);

        $this->denyAccessUnlessGranted(MemorialPageVoter::INTERACT, $page);

        /** @var User $user */
        $user    = $this->getUser();
        $content = trim($request->getPayload()->getString('content'));
        $title   = trim($request->getPayload()->getString('title', ''));
        $relation= trim($request->getPayload()->getString('relation', ''));

        if (!$this->isCsrfTokenValid('testimonial_' . $page->getId(), $request->getPayload()->getString('_token'))) {
            return $this->json(['error' => 'Token invalide.'], 403);
        }

        if (mb_strlen($content) < 10) {
            return $this->json(['error' => 'Témoignage trop court (10 caractères minimum).'], 422);
        }

        $status = $this->moderationService->resolveStatus($page, $user);

        $testimonial = new Testimonial();
        $testimonial->setMemorial($page)
                    ->setUser($user)
                    ->setTitle($title ?: null)
                    ->setContent($content)
                    ->setRelationToDeceased($relation ?: null)
                    ->setStatus($status);

        $this->em->persist($testimonial);
        $this->em->flush();

        // Notifier les modérateurs si en attente
        if ($status === 'pending') {
            foreach ($this->moderatorRepo->findActiveModeratorsForPage($page) as $mod) {
                $this->notifService->send(
                    $mod->getUser(),
                    'testimonial_pending',
                    'Nouveau témoignage en attente',
                    $user->getFullName() . ' a posté un témoignage sur la page de ' . $page->getDeceasedFullName(),
                    '/dashboard/memorial/' . $page->getSlug() . '/moderate',
                    'memorial_page',
                    $page->getId(),
                );
            }
        }

        return $this->json([
            'status'  => $status,
            'message' => $status === 'approved'
                ? 'Votre témoignage a été publié.'
                : 'Votre témoignage est en attente de validation.',
        ]);
    }

    // =========================================================
    // SIGNATURE LIVRE D'OR (Ajax)
    // =========================================================
    #[Route('/{slug}/guestbook', name: 'app_memorial_guestbook_post', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function signGuestBook(string $slug, Request $request): JsonResponse
    {
        $page = $this->memorialRepo->findBySlugActive($slug);
        if (!$page) return $this->json(['error' => 'Page introuvable'], 404);

        /** @var User $user */
        $user = $this->getUser();

        // Une seule signature par user par page
        $existing = $this->em->getRepository(GuestBook::class)->findOneBy(['memorial' => $page, 'user' => $user]);
        if ($existing) {
            return $this->json(['error' => 'Vous avez déjà signé ce livre d\'or.'], 409);
        }

        if (!$this->isCsrfTokenValid('guestbook_' . $page->getId(), $request->getPayload()->getString('_token'))) {
            return $this->json(['error' => 'Token invalide.'], 403);
        }

        $status  = $this->moderationService->resolveStatus($page, $user);
        $sigText = trim($request->getPayload()->getString('signature_text', ''));

        $entry = new GuestBook();
        $entry->setMemorial($page)
              ->setUser($user)
              ->setSignatureText($sigText ?: null)
              ->setStatus($status);

        $this->em->persist($entry);
        $this->em->flush();

        // Notifier les modérateurs si en attente
        if ($status === 'pending') {
            foreach ($this->moderatorRepo->findActiveModeratorsForPage($page) as $mod) {
                $this->notifService->send(
                    $mod->getUser(),
                    'guestbook_pending',
                    'Nouvelle signature en attente',
                    $user->getFullName() . ' a signé le livre d\'or de ' . $page->getDeceasedFullName(),
                    '/dashboard/memorial/' . $page->getSlug() . '/moderate',
                    'memorial_page',
                    $page->getId(),
                );
            }
        }

        return $this->json([
            'status'  => $status,
            'message' => $status === 'approved' ? 'Livre d\'or signé !' : 'Signature en attente de validation.',
        ]);
    }

    // =========================================================
    // NOTIFICATION COUNT (Ajax polling)
    // =========================================================
    #[Route('/api/notifications/count', name: 'app_notifications_count', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function notificationsCount(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        return $this->json(['count' => $this->notifService->getUnreadCount($user)]);
    }

    // =========================================================
    // PAGE PUBLIQUE D'UN ÉVÉNEMENT
    // =========================================================
    #[Route('/{slug}/evenement/{eventId}', name: 'app_memorial_event_show', methods: ['GET'])]
    public function eventShow(string $slug, int $eventId): Response
    {
        $page = $this->memorialRepo->findBySlugActive($slug);
        if (!$page) throw $this->createNotFoundException();

        $this->denyAccessUnlessGranted(MemorialPageVoter::VIEW, $page);

        $event = $this->em->getRepository(\App\Entity\MemorialEvent::class)->find($eventId);
        if (!$event || $event->getMemorial() !== $page) throw $this->createNotFoundException();

        $user        = $this->getUser();
        $isModerator = $user && $this->memorialService->isModerator($page, $user);

        $gallery = $this->em->getRepository(\App\Entity\MediaGallery::class)
            ->findBy(['event' => $event], ['sortOrder' => 'ASC', 'createdAt' => 'ASC']);

        $comments = $this->em->getRepository(Condolence::class)
            ->findBy(['event' => $event, 'status' => 'approved'], ['createdAt' => 'DESC']);

        return $this->render('memorial/event_show.html.twig', [
            'page'        => $page,
            'event'       => $event,
            'gallery'     => $gallery,
            'comments'    => $comments,
            'isModerator' => $isModerator,
        ]);
    }

    // =========================================================
    // COMMENTAIRE SUR UN ÉVÉNEMENT (Ajax)
    // =========================================================
    #[Route('/{slug}/evenement/{eventId}/comment', name: 'app_memorial_event_comment', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function eventComment(string $slug, int $eventId, Request $request): JsonResponse
    {
        $page = $this->memorialRepo->findBySlugActive($slug);
        if (!$page) return $this->json(['error' => 'Page introuvable'], 404);

        $event = $this->em->getRepository(\App\Entity\MemorialEvent::class)->find($eventId);
        if (!$event || $event->getMemorial() !== $page) {
            return $this->json(['error' => 'Événement introuvable'], 404);
        }

        if (!$this->isCsrfTokenValid('event_comment_' . $eventId, $request->getPayload()->getString('_token'))) {
            return $this->json(['error' => 'Token invalide'], 403);
        }

        /** @var User $user */
        $user    = $this->getUser();
        $message = trim($request->getPayload()->getString('message'));

        if (mb_strlen($message) < 3) return $this->json(['error' => 'Message trop court.'], 422);
        if (mb_strlen($message) > 1000) return $this->json(['error' => 'Message trop long.'], 422);

        $status = $this->moderationService->resolveStatus($page, $user);

        $comment = new Condolence();
        $comment->setMemorial($page)
                ->setEvent($event)
                ->setUser($user)
                ->setMessage($message)
                ->setIsAnonymous(false)
                ->setStatus($status);

        $this->em->persist($comment);
        $this->em->flush();

        // Notifier modérateurs si en attente
        if ($status === 'pending') {
            foreach ($this->moderatorRepo->findActiveModeratorsForPage($page) as $mod) {
                $this->notifService->send(
                    $mod->getUser(),
                    'event_comment_pending',
                    'Nouveau commentaire en attente',
                    $user->getFullName() . ' a commenté l\'événement "' . $event->getTitle() . '"',
                    '/dashboard/memorial/' . $page->getSlug() . '/moderate',
                    'memorial_page',
                    $page->getId(),
                );
            }
        }

        $html = '';
        if ($status === 'approved') {
            $html = sprintf(
                '<div class="event-comment"><div class="event-comment__avatar">%s%s</div>
                <div class="event-comment__body">
                  <div class="event-comment__header">
                    <span class="event-comment__name">%s</span>
                    <span class="event-comment__date">%s</span>
                  </div>
                  <div class="event-comment__text">%s</div>
                </div></div>',
                strtoupper(mb_substr($user->getFirstName(), 0, 1)),
                strtoupper(mb_substr($user->getLastName(), 0, 1)),
                htmlspecialchars($user->getFullName()),
                (new \DateTime())->format('d/m/Y à H:i'),
                nl2br(htmlspecialchars($message))
            );
        }

        return $this->json([
            'success' => true,
            'status'  => $status,
            'message' => $status === 'approved' ? 'Commentaire publié !' : 'En attente de validation.',
            'html'    => $html,
        ]);
    }

    // =========================================================
    // UPLOAD MÉDIA ÉVÉNEMENT (Ajax, modérateur)
    // =========================================================
    #[Route('/{slug}/evenement/{eventId}/media', name: 'app_memorial_event_media_upload', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function eventMediaUpload(string $slug, int $eventId, Request $request): JsonResponse
    {
        $page = $this->memorialRepo->findBySlugActive($slug);
        if (!$page) return $this->json(['error' => 'Page introuvable'], 404);

        /** @var User $user */
        $user = $this->getUser();
        if (!$this->memorialService->isModerator($page, $user)) {
            return $this->json(['error' => 'Accès refusé'], 403);
        }

        if (!$this->isCsrfTokenValid('event_media_' . $eventId, $request->getPayload()->getString('_token'))) {
            return $this->json(['error' => 'Token invalide'], 403);
        }

        $event = $this->em->getRepository(\App\Entity\MemorialEvent::class)->find($eventId);
        if (!$event || $event->getMemorial() !== $page) {
            return $this->json(['error' => 'Événement introuvable'], 404);
        }

        $file = $request->files->get('file');
        if (!$file) return $this->json(['error' => 'Aucun fichier'], 400);

        try {
            $media = $this->mediaService->uploadGalleryMedia($file, $page, $user);
            // Associer à l'événement
            $media->setEvent($event);
            $this->em->flush();

            return $this->json([
                'success' => true,
                'url'     => $media->getUrl(),
                'id'      => $media->getId(),
                'type'    => $media->getType(),
            ]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Erreur upload: ' . $e->getMessage()], 500);
        }
    }
}
