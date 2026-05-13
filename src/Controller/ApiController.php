<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\NotificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * ✅ FIX : La route /api/notifications/count était dans MemorialController
 * avec le préfixe /memorial, ce qui générait l'URL /memorial/api/notifications/count.
 * Elle est maintenant dans ce contrôleur dédié avec le bon préfixe /api.
 */
#[Route('/api')]
class ApiController extends AbstractController
{
    public function __construct(
        private readonly NotificationService $notifService,
    ) {}

    /**
     * Retourne le nombre de notifications non lues de l'utilisateur connecté.
     * Utilisé par le polling JavaScript du header (app.js, toutes les 60s).
     */
    #[Route('/notifications/count', name: 'app_notifications_count', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function notificationsCount(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json([
            'count' => $this->notifService->getUnreadCount($user),
        ]);
    }
}
