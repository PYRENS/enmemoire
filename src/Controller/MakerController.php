<?php

namespace App\Controller;

use App\Entity\EventGadgetAllocation;
use App\Entity\GadgetCatalog;
use App\Entity\MemorialEvent;
use App\Entity\MemorialPage;
use App\Entity\User;
use App\Entity\UserGadgetWallet;
use App\Service\MakerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * MakerController — Phase 3 Gadgets MAKER
 *
 * Routes :
 *   /maker/event/{eventId}              → formulaire d'allocation (owner + admin)
 *   /maker/event/{eventId}/allocate     → POST créer une allocation
 *   /maker/event/{eventId}/claim/{id}   → POST réclamer un gadget exposé (visitor)
 *   /maker/event/{eventId}/nominate     → POST attribution nominative
 *   /maker/event/{eventId}/deactivate/{id} → POST désactiver une allocation
 *   /maker/mes-events                   → liste des événements où l'user peut allouer
 *   /api/maker/search-users             → GET recherche utilisateurs (Ajax)
 *   /api/maker/allocations/{eventId}    → GET liste des allocations d'un événement
 */
#[Route('/maker')]
#[IsGranted('ROLE_USER')]
class MakerController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MakerService           $makerService,
        private readonly LoggerInterface        $logger,
    ) {}

    // =========================================================
    // PAGE MAKER — liste des événements accessibles à l'user
    // =========================================================
    #[Route('/mes-events', name: 'app_maker_index')]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Pages dont l'user est propriétaire ou modérateur
        $pages = $this->em->createQuery(
            'SELECT p FROM App\Entity\MemorialPage p
             WHERE p.createdBy = :user
             ORDER BY p.createdAt DESC'
        )->setParameter('user', $user)->getResult();

        // Récupérer tous les événements de ces pages
        $events = [];
        foreach ($pages as $page) {
            foreach ($page->getEvents() as $event) {
                $events[] = [
                    'event'       => $event,
                    'page'        => $page,
                    'allocations' => $this->makerService->getAllocationsForEvent($event),
                ];
            }
        }

        // Portefeuille pour savoir quels gadgets l'user peut allouer
        $walletItems = $this->em->getRepository(UserGadgetWallet::class)
            ->findBy(['user' => $user]);

        $wallet = [];
        foreach ($walletItems as $item) {
            if ($item->getQuantity() > 0) {
                $wallet[$item->getGadget()->getId()] = $item->getQuantity();
            }
        }

        return $this->render('maker/index.html.twig', [
            'events'  => $events,
            'wallet'  => $wallet,
            'gadgets' => $this->em->getRepository(GadgetCatalog::class)
                ->findBy(['status' => GadgetCatalog::STATUS_ACTIVE]),
        ]);
    }

    // =========================================================
    // PAGE ALLOCATION — formulaire pour un événement spécifique
    // =========================================================
    #[Route('/event/{eventId}', name: 'app_maker_event', methods: ['GET'])]
    public function eventAllocate(int $eventId): Response
    {
        $event = $this->getEventOrDeny($eventId);
        $user  = $this->getUser();

        $allocations = $this->makerService->getAllocationsForEvent($event);

        // Stats par allocation
        $stats = [];
        foreach ($allocations as $alloc) {
            $stats[$alloc->getId()] = $this->makerService->getAllocationStats($alloc);
        }

        // Portefeuille de l'user
        $walletItems = $this->em->getRepository(UserGadgetWallet::class)
            ->findBy(['user' => $user]);
        $wallet = [];
        foreach ($walletItems as $item) {
            if ($item->getQuantity() > 0) {
                $wallet[$item->getGadget()->getId()] = $item->getQuantity();
            }
        }

        $gadgets = $this->em->getRepository(GadgetCatalog::class)
            ->findBy(['status' => GadgetCatalog::STATUS_ACTIVE]);

        $isAdmin = $this->isGranted('ROLE_ADMIN');

        return $this->render('maker/allocate.html.twig', [
            'event'       => $event,
            'page'        => $event->getMemorial(),
            'allocations' => $allocations,
            'stats'       => $stats,
            'wallet'      => $wallet,
            'gadgets'     => $gadgets,
            'isAdmin'     => $isAdmin,
        ]);
    }

    // =========================================================
    // POST — Créer une allocation
    // =========================================================
    #[Route('/event/{eventId}/allocate', name: 'app_maker_allocate', methods: ['POST'])]
    public function allocate(int $eventId, Request $request): Response
    {
        $event = $this->getEventOrDeny($eventId);

        if (!$this->isCsrfTokenValid('maker_allocate_' . $eventId, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token invalide.');
            return $this->redirectToRoute('app_maker_event', ['eventId' => $eventId]);
        }

        $gadgetId        = (int) $request->request->get('gadget_id');
        $quantityRaw     = $request->request->get('quantity', '');
        $distMode        = $request->request->get('dist_mode', EventGadgetAllocation::DIST_EXPOSED);
        $perVisitorLimit = max(1, (int) $request->request->get('per_visitor_limit', 1));
        $expiresRaw      = $request->request->get('expires_at', '');
        $isAdmin         = $this->isGranted('ROLE_ADMIN');

        // Quantité : vide ou 0 = illimité (admin seulement)
        $quantity = ($quantityRaw === '' || $quantityRaw === '0') ? null : (int) $quantityRaw;
        if ($quantity === null && !$isAdmin) {
            $this->addFlash('error', 'Quantité illimitée réservée à l\'admin.');
            return $this->redirectToRoute('app_maker_event', ['eventId' => $eventId]);
        }

        $gadget = $this->em->getRepository(GadgetCatalog::class)->find($gadgetId);
        if (!$gadget) {
            $this->addFlash('error', 'Gadget introuvable.');
            return $this->redirectToRoute('app_maker_event', ['eventId' => $eventId]);
        }

        // Valider le mode de distribution
        $validModes = [
            EventGadgetAllocation::DIST_EXPOSED,
            EventGadgetAllocation::DIST_DIRECT,
            'nominated',
        ];
        if (!in_array($distMode, $validModes, true)) {
            $distMode = EventGadgetAllocation::DIST_EXPOSED;
        }

        $expiresAt = null;
        if ($expiresRaw) {
            try {
                $expiresAt = new \DateTime($expiresRaw);
            } catch (\Exception) {
                $expiresAt = null;
            }
        }

        $result = $this->makerService->createAllocation(
            user:            $this->getUser(),
            event:           $event,
            gadget:          $gadget,
            quantity:        $quantity,
            distMode:        $distMode === 'nominated' ? EventGadgetAllocation::DIST_EXPOSED : $distMode,
            perVisitorLimit: $perVisitorLimit,
            isAdmin:         $isAdmin,
            expiresAt:       $expiresAt,
        );

        if ($result['success']) {
            $alloc = $result['allocation'];
            $qtyLabel = $quantity === null ? 'illimitée' : $quantity . ' gadgets';
            $this->addFlash('success', "✅ Allocation créée : {$qtyLabel} × {$gadget->getName()}.");

            // Mode nominatif → rester sur la page pour attribution
            if ($distMode === 'nominated') {
                $this->addFlash('info', 'Allocation prête. Utilisez le formulaire ci-dessous pour attribuer à un utilisateur.');
                return $this->redirectToRoute('app_maker_event', ['eventId' => $eventId, '_fragment' => 'nominate-' . $alloc->getId()]);
            }
        } else {
            $this->addFlash('error', $result['error']);
        }

        return $this->redirectToRoute('app_maker_event', ['eventId' => $eventId]);
    }

    // =========================================================
    // POST — Réclamer un gadget exposé (visiteur)
    // =========================================================
    #[Route('/event/{eventId}/claim/{allocationId}', name: 'app_maker_claim', methods: ['POST'])]
    public function claim(int $eventId, int $allocationId, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('maker_claim_' . $allocationId, $request->request->get('_token'))) {
            return $this->json(['error' => 'Token invalide'], 403);
        }

        $allocation = $this->em->getRepository(EventGadgetAllocation::class)->find($allocationId);
        if (!$allocation || $allocation->getEvent()->getId() !== $eventId) {
            return $this->json(['error' => 'Allocation introuvable'], 404);
        }

        $result = $this->makerService->claimExposed($this->getUser(), $allocation);

        if (!$result['success']) {
            return $this->json(['error' => $result['error']], 400);
        }

        return $this->json([
            'success'   => true,
            'gadgetName'=> $result['gadgetName'],
            'emoji'     => $result['emoji'],
            'remaining' => $result['remaining'],
        ]);
    }

    // =========================================================
    // POST — Attribution nominative
    // =========================================================
    #[Route('/event/{eventId}/nominate', name: 'app_maker_nominate', methods: ['POST'])]
    public function nominate(int $eventId, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('maker_nominate', $request->request->get('_token'))) {
            return $this->json(['error' => 'Token invalide'], 403);
        }

        $allocationId = (int) $request->request->get('allocation_id');
        $recipientId  = (int) $request->request->get('user_id');
        $customText   = $request->request->get('custom_text') ?: null;

        $allocation = $this->em->getRepository(EventGadgetAllocation::class)->find($allocationId);
        if (!$allocation || $allocation->getEvent()->getId() !== $eventId) {
            return $this->json(['error' => 'Allocation introuvable'], 404);
        }

        // Vérifier que l'appelant est bien l'allocateur ou un admin
        $user = $this->getUser();
        if (!$this->isGranted('ROLE_ADMIN') && $allocation->getAllocatedBy() !== $user) {
            return $this->json(['error' => 'Non autorisé'], 403);
        }

        $recipient = $this->em->getRepository(User::class)->find($recipientId);
        if (!$recipient) {
            return $this->json(['error' => 'Utilisateur introuvable'], 404);
        }

        $result = $this->makerService->attributeToUser($allocation, $recipient, $customText);

        if (!$result['success']) {
            return $this->json(['error' => $result['error']], 400);
        }

        return $this->json([
            'success'      => true,
            'recipientName'=> $recipient->getFullName(),
            'gadgetName'   => $allocation->getGadget()->getName(),
        ]);
    }

    // =========================================================
    // POST — Désactiver une allocation
    // =========================================================
    #[Route('/event/{eventId}/deactivate/{allocationId}', name: 'app_maker_deactivate', methods: ['POST'])]
    public function deactivate(int $eventId, int $allocationId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('maker_deactivate_' . $allocationId, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token invalide.');
            return $this->redirectToRoute('app_maker_event', ['eventId' => $eventId]);
        }

        $allocation = $this->em->getRepository(EventGadgetAllocation::class)->find($allocationId);
        if (!$allocation || $allocation->getEvent()->getId() !== $eventId) {
            throw $this->createNotFoundException();
        }

        // Vérifier autorisation
        $user = $this->getUser();
        if (!$this->isGranted('ROLE_ADMIN') && $allocation->getAllocatedBy() !== $user) {
            throw $this->createAccessDeniedException();
        }

        $this->makerService->deactivateAllocation($allocation);
        $this->addFlash('success', 'Allocation désactivée.');

        return $this->redirectToRoute('app_maker_event', ['eventId' => $eventId]);
    }

    // =========================================================
    // API — Recherche utilisateurs (Ajax attribution nominative)
    // =========================================================
    #[Route('/api/maker/search-users', name: 'app_api_maker_search_users', methods: ['GET'])]
    public function searchUsers(Request $request): JsonResponse
    {
        $q       = trim($request->query->get('q', ''));
        $results = $this->makerService->searchUsers($q);

        return $this->json(array_map(fn($u) => [
            'id'   => $u['id'],
            'name' => trim(($u['firstName'] ?? '') . ' ' . ($u['lastName'] ?? '')),
            'email'=> $u['email'],
        ], $results));
    }

    // =========================================================
    // API — Allocations d'un événement (Ajax)
    // =========================================================
    #[Route('/api/maker/allocations/{eventId}', name: 'app_api_maker_allocations', methods: ['GET'])]
    public function apiAllocations(int $eventId): JsonResponse
    {
        $event = $this->em->getRepository(MemorialEvent::class)->find($eventId);
        if (!$event) {
            return $this->json([]);
        }

        $allocations = $this->makerService->getAllocationsForEvent($event);

        return $this->json(array_map(function (EventGadgetAllocation $a) {
            $stats = $this->makerService->getAllocationStats($a);
            return [
                'id'          => $a->getId(),
                'gadget'      => $a->getGadget()->getName(),
                'gadgetType'  => $a->getGadget()->getType(),
                'mode'        => $a->getDistributionMode(),
                'source'      => $a->getSourceType(),
                'total'       => $a->getTotalQuantity(),
                'remaining'   => $a->getRemainingQuantity(),
                'claimed'     => $stats['claimed'],
                'isActive'    => $a->isActive(),
                'expiresAt'   => $a->getExpiresAt()?->format('d/m/Y H:i'),
                'perVisitor'  => $a->getPerVisitorLimit(),
            ];
        }, $allocations));
    }

    // =========================================================
    // HELPER — récupérer un événement et vérifier l'autorisation
    // =========================================================
    private function getEventOrDeny(int $eventId): MemorialEvent
    {
        $event = $this->em->getRepository(MemorialEvent::class)->find($eventId);

        if (!$event) {
            throw $this->createNotFoundException('Événement introuvable.');
        }

        $user  = $this->getUser();
        $page  = $event->getMemorial();

        // Admin → accès total
        if ($this->isGranted('ROLE_ADMIN')) {
            return $event;
        }

        // Propriétaire de la page
        if ($page->getCreatedBy() === $user) {
            return $event;
        }

        // Modérateur de la page (via DashboardController::getPageOrDeny pattern)
        $isModerator = $this->em->createQuery(
            'SELECT COUNT(m.id) FROM App\Entity\MemorialModerator m
             WHERE m.memorial = :page AND m.user = :user AND m.status = \'active\''
        )
        ->setParameter('page', $page)
        ->setParameter('user', $user)
        ->getSingleScalarResult();

        if ($isModerator > 0) {
            return $event;
        }

        // Utilisateur standard avec portefeuille → accès pour ses propres allocations
        // (vérification faite dans createAllocation via canAllocate)
        return $event;
    }
}
