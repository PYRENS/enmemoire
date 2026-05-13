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
        private readonly EntityManagerInterface     $em,
        private readonly MemorialPageService        $memorialService,
        private readonly ModerationService          $moderationService,
        private readonly NotificationService        $notifService,
        private readonly MemorialPageRepository     $memorialRepo,
        private readonly MemorialModeratorRepository $moderatorRepo,
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

        // Galerie principale (photos)
        $gallery = $this->em->getRepository(\App\Entity\MediaGallery::class)
            ->findBy(['memorial' => $page, 'type' => 'photo', 'event' => null], ['sortOrder' => 'ASC', 'createdAt' => 'DESC'], 30);

        // Connexions familiales acceptées
        $familyConnections = $this->em->getRepository(\App\Entity\FamilyConnection::class)
            ->findBy(['memorialFrom' => $page, 'status' => 'accepted']);

        // Statistiques
        $stats = $this->memorialService->getStats($page);

        // Annonces épinglées
        $announcements = $this->em->getRepository(Announcement::class)
            ->findBy(['memorial' => $page], ['isPinned' => 'DESC', 'createdAt' => 'DESC'], 5);

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
}
