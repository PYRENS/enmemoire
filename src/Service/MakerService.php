<?php

namespace App\Service;

use App\Entity\EventGadgetAllocation;
use App\Entity\GadgetCatalog;
use App\Entity\GadgetInteraction;
use App\Entity\MemorialEvent;
use App\Entity\User;
use App\Entity\UserGadgetWallet;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * MakerService — Phase 3 Gadgets MAKER
 *
 * Gère l'allocation et la distribution de gadgets sur les événements mémoriels.
 *
 * 3 sources d'allocation :
 *   - ADMIN        : illimité, gratuit (source_type = 'maker')
 *   - PROPRIÉTAIRE : depuis son portefeuille (source_type = 'user_purchase')
 *   - UTILISATEUR  : depuis son portefeuille (source_type = 'user_purchase')
 *
 * 3 modes de distribution :
 *   - EXPOSED      : bouton sur la page événement → visiteur clique → reçoit 1 gadget
 *   - DIRECT       : envoi immédiat à tous les inscrits à l'événement
 *   - NOMINATED    : attribution nominative à un utilisateur spécifique
 */
class MakerService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface        $logger,
    ) {}

    // =========================================================
    // VÉRIFICATIONS D'AUTORISATION
    // =========================================================

    /**
     * Vérifie si un utilisateur peut allouer des gadgets sur un événement.
     * Admin → toujours oui.
     * Propriétaire / User → doit avoir le gadget en portefeuille.
     */
    public function canAllocate(User $user, GadgetCatalog $gadget, int $quantity, bool $isAdmin): bool
    {
        if ($isAdmin) {
            return true;
        }

        $walletItem = $this->em->getRepository(UserGadgetWallet::class)
            ->findOneBy(['user' => $user, 'gadget' => $gadget]);

        return $walletItem && $walletItem->getQuantity() >= $quantity;
    }

    /**
     * Vérifie si un utilisateur a déjà réclamé un gadget exposé sur cet événement.
     */
    public function hasAlreadyClaimed(User $user, EventGadgetAllocation $allocation): bool
    {
        $count = $this->em->getRepository(GadgetInteraction::class)->count([
            'user'       => $user,
            'allocation' => $allocation,
        ]);

        $perVisitor = $allocation->getPerVisitorLimit() ?? 1;
        return $count >= $perVisitor;
    }

    // =========================================================
    // CRÉER UNE ALLOCATION
    // =========================================================

    /**
     * Crée une allocation MAKER sur un événement.
     *
     * @param User           $allocator      Qui alloue
     * @param MemorialEvent  $event          L'événement cible
     * @param GadgetCatalog  $gadget         Le gadget alloué
     * @param int            $quantity       Quantité totale (null = illimité pour admin)
     * @param string         $distMode       'exposed' | 'direct' | 'nominated'
     * @param int            $perVisitorLimit Max par visiteur (mode exposé)
     * @param bool           $isAdmin        Source maker (illimité)
     * @param \DateTime|null $expiresAt      Expiration optionnelle
     *
     * @return array{success: bool, allocation: ?EventGadgetAllocation, error: ?string}
     */
    public function createAllocation(
        User           $allocator,
        MemorialEvent  $event,
        GadgetCatalog  $gadget,
        ?int           $quantity,
        string         $distMode        = EventGadgetAllocation::DIST_EXPOSED,
        int            $perVisitorLimit = 1,
        bool           $isAdmin         = false,
        ?\DateTime     $expiresAt       = null,
    ): array {
        // Vérifier l'autorisation
        $qty = $quantity ?? 0;
        if (!$isAdmin && !$this->canAllocate($allocator, $gadget, $qty, false)) {
            return [
                'success'    => false,
                'allocation' => null,
                'error'      => "Vous n'avez pas assez de {$gadget->getName()} dans votre portefeuille.",
            ];
        }

        // Débiter le portefeuille si source = user
        if (!$isAdmin && $qty > 0) {
            $walletItem = $this->em->getRepository(UserGadgetWallet::class)
                ->findOneBy(['user' => $allocator, 'gadget' => $gadget]);
            $walletItem->setQuantity($walletItem->getQuantity() - $qty);
        }

        // Créer l'allocation
        $allocation = new EventGadgetAllocation();
        $allocation->setEvent($event)
                   ->setGadget($gadget)
                   ->setAllocatedBy($allocator)
                   ->setSourceType($isAdmin
                       ? EventGadgetAllocation::SRC_MAKER
                       : EventGadgetAllocation::SRC_USER)
                   ->setAllocationType($quantity === null
                       ? EventGadgetAllocation::TYPE_DONATION
                       : EventGadgetAllocation::TYPE_FIXED)
                   ->setTotalQuantity($quantity)
                   ->setRemainingQuantity($quantity)
                   ->setDistributionMode($distMode)
                   ->setPerVisitorLimit($perVisitorLimit)
                   ->setIsActive(true)
                   ->setExpiresAt($expiresAt);

        $this->em->persist($allocation);
        $this->em->flush();

        $this->logger->info('[MAKER] Allocation créée', [
            'event'    => $event->getId(),
            'gadget'   => $gadget->getName(),
            'qty'      => $quantity ?? 'illimité',
            'mode'     => $distMode,
            'source'   => $isAdmin ? 'maker' : 'user',
            'allocator'=> $allocator->getId(),
        ]);

        // Si mode DIRECT → distribuer immédiatement
        if ($distMode === EventGadgetAllocation::DIST_DIRECT) {
            $this->distributeDirectly($allocation, $event);
        }

        return ['success' => true, 'allocation' => $allocation, 'error' => null];
    }

    // =========================================================
    // MODE EXPOSÉ — visiteur réclame un gadget
    // =========================================================

    /**
     * Un visiteur connecté réclame un gadget exposé sur un événement.
     * Appelé depuis GadgetController::claimExposed() via Ajax.
     */
    public function claimExposed(
        User                   $claimer,
        EventGadgetAllocation  $allocation,
    ): array {
        // Vérifier que l'allocation est active
        if (!$allocation->isActive()) {
            return ['success' => false, 'error' => 'Cette offre de gadgets n\'est plus active.'];
        }

        // Vérifier expiration
        if ($allocation->getExpiresAt() && $allocation->getExpiresAt() < new \DateTime()) {
            $allocation->setIsActive(false);
            $this->em->flush();
            return ['success' => false, 'error' => 'Cette offre a expiré.'];
        }

        // Vérifier limite par visiteur
        if ($this->hasAlreadyClaimed($claimer, $allocation)) {
            $limit = $allocation->getPerVisitorLimit() ?? 1;
            return ['success' => false, 'error' => "Vous avez déjà réclamé votre gadget pour cet événement (max {$limit})."];
        }

        // Vérifier stock restant (null = illimité)
        if ($allocation->getRemainingQuantity() !== null && $allocation->getRemainingQuantity() <= 0) {
            $allocation->setIsActive(false);
            $this->em->flush();
            return ['success' => false, 'error' => 'Plus de gadgets disponibles pour cet événement.'];
        }

        // Enregistrer l'interaction
        $interaction = $this->createInteraction($claimer, $allocation, null);

        // Décrémenter le stock
        if ($allocation->getRemainingQuantity() !== null) {
            $allocation->setRemainingQuantity($allocation->getRemainingQuantity() - 1);
            if ($allocation->getRemainingQuantity() <= 0) {
                $allocation->setIsActive(false);
            }
        }

        $this->em->flush();

        $this->logger->info('[MAKER] Gadget réclamé (exposé)', [
            'user'       => $claimer->getId(),
            'allocation' => $allocation->getId(),
            'gadget'     => $allocation->getGadget()->getName(),
        ]);

        return [
            'success'   => true,
            'gadgetName'=> $allocation->getGadget()->getName(),
            'gadgetType'=> $allocation->getGadget()->getType(),
            'emoji'     => $this->typeEmoji($allocation->getGadget()->getType()),
            'remaining' => $allocation->getRemainingQuantity(),
        ];
    }

    // =========================================================
    // MODE DIRECT — distribuer à tous les inscrits
    // =========================================================

    private function distributeDirectly(EventGadgetAllocation $allocation, MemorialEvent $event): void
    {
        // Récupérer tous les utilisateurs ayant interagi avec la page mémorielle
        // (condoléances, témoignages, livre d'or — proxy pour "inscrits")
        $page = $event->getMemorial();

        $users = $this->em->createQuery(
            'SELECT DISTINCT u FROM App\Entity\User u
             WHERE u.id IN (
                SELECT IDENTITY(c.user) FROM App\Entity\Condolence c WHERE c.memorial = :page
                UNION
                SELECT IDENTITY(g.user) FROM App\Entity\GuestBook g WHERE g.memorial = :page
             )'
        )->setParameter('page', $page)->getResult();

        $count = 0;
        foreach ($users as $user) {
            // Vérifier le stock
            if ($allocation->getRemainingQuantity() !== null && $allocation->getRemainingQuantity() <= 0) {
                break;
            }

            // Pas de double envoi
            if ($this->hasAlreadyClaimed($user, $allocation)) {
                continue;
            }

            $this->createInteraction($user, $allocation, null);

            if ($allocation->getRemainingQuantity() !== null) {
                $allocation->setRemainingQuantity($allocation->getRemainingQuantity() - 1);
            }
            $count++;
        }

        if ($allocation->getRemainingQuantity() !== null && $allocation->getRemainingQuantity() <= 0) {
            $allocation->setIsActive(false);
        }

        $this->em->flush();

        $this->logger->info('[MAKER] Distribution directe', [
            'allocation' => $allocation->getId(),
            'sent'       => $count,
        ]);
    }

    // =========================================================
    // MODE NOMINATIF — attribuer à un utilisateur spécifique
    // =========================================================

    /**
     * Attribue directement un gadget à un utilisateur nommément.
     */
    public function attributeToUser(
        EventGadgetAllocation $allocation,
        User                  $recipient,
        ?string               $customText = null,
    ): array {
        if (!$allocation->isActive()) {
            return ['success' => false, 'error' => 'Allocation inactive.'];
        }

        if ($allocation->getRemainingQuantity() !== null && $allocation->getRemainingQuantity() <= 0) {
            return ['success' => false, 'error' => 'Stock épuisé.'];
        }

        $this->createInteraction($recipient, $allocation, $customText);

        if ($allocation->getRemainingQuantity() !== null) {
            $allocation->setRemainingQuantity($allocation->getRemainingQuantity() - 1);
            if ($allocation->getRemainingQuantity() <= 0) {
                $allocation->setIsActive(false);
            }
        }

        $this->em->flush();

        $this->logger->info('[MAKER] Attribution nominative', [
            'allocation' => $allocation->getId(),
            'recipient'  => $recipient->getId(),
        ]);

        return ['success' => true, 'error' => null];
    }

    // =========================================================
    // RECHERCHE UTILISATEUR (pour attribution nominative)
    // =========================================================

    /**
     * Recherche des utilisateurs par nom ou email pour l'attribution nominative.
     */
    public function searchUsers(string $query): array
    {
        if (strlen($query) < 2) {
            return [];
        }

        return $this->em->createQuery(
            'SELECT u.id, u.firstName, u.lastName, u.email
             FROM App\Entity\User u
             WHERE (LOWER(u.firstName) LIKE LOWER(:q)
                OR LOWER(u.lastName) LIKE LOWER(:q)
                OR LOWER(u.email) LIKE LOWER(:q))
             AND u.status = \'active\'
             ORDER BY u.lastName ASC'
        )
        ->setParameter('q', '%' . $query . '%')
        ->setMaxResults(10)
        ->getScalarResult();
    }

    // =========================================================
    // LISTER LES ALLOCATIONS D'UN ÉVÉNEMENT
    // =========================================================

    public function getAllocationsForEvent(MemorialEvent $event): array
    {
        return $this->em->getRepository(EventGadgetAllocation::class)
            ->findBy(['event' => $event], ['createdAt' => 'DESC']);
    }

    /**
     * Stats d'une allocation : combien réclamé, restant, taux.
     */
    public function getAllocationStats(EventGadgetAllocation $allocation): array
    {
        $claimed = $this->em->getRepository(GadgetInteraction::class)
            ->count(['allocation' => $allocation]);

        $total   = $allocation->getTotalQuantity();
        $remaining = $allocation->getRemainingQuantity();

        return [
            'claimed'   => $claimed,
            'total'     => $total,
            'remaining' => $remaining,
            'rate'      => $total ? round($claimed / $total * 100) : null,
            'unlimited' => $total === null,
        ];
    }

    // =========================================================
    // DÉSACTIVER / SUPPRIMER UNE ALLOCATION
    // =========================================================

    public function deactivateAllocation(EventGadgetAllocation $allocation): void
    {
        $allocation->setIsActive(false);
        $this->em->flush();
    }

    // =========================================================
    // HELPER — créer une GadgetInteraction liée à une allocation
    // =========================================================

    private function createInteraction(
        User                  $user,
        EventGadgetAllocation $allocation,
        ?string               $customText,
    ): GadgetInteraction {
        $gadget = $allocation->getGadget();
        $event  = $allocation->getEvent();

        $interaction = new GadgetInteraction();
        $interaction->setMemorial($event->getMemorial())
                    ->setEvent($event)
                    ->setUser($user)
                    ->setGadget($gadget)
                    ->setAllocation($allocation)
                    ->setAction($this->typeAction($gadget->getType()))
                    ->setCustomText($customText);

        $this->em->persist($interaction);
        return $interaction;
    }

    private function typeEmoji(string $type): string
    {
        return match ($type) {
            'flower' => '🌸', 'candle' => '🕯️', 'dove' => '🕊️', default => '✨'
        };
    }

    private function typeAction(string $type): string
    {
        return match ($type) {
            'flower' => GadgetInteraction::ACTION_FLOWER,
            'candle' => GadgetInteraction::ACTION_CANDLE,
            'dove'   => GadgetInteraction::ACTION_DOVE,
            default  => GadgetInteraction::ACTION_OTHER,
        };
    }
}
