<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/notifications')]
#[IsGranted('ROLE_USER')]
class NotificationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationService    $notifService,
    ) {}

    // =========================================================
    // CENTRE DE NOTIFICATIONS
    // =========================================================
    #[Route('', name: 'app_notifications', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user    = $this->getUser();
        $page    = max(1, (int) $request->query->get('page', 1));
        $perPage = 20;
        $filter  = $request->query->get('filter', 'all'); // all | unread

        $qb = $this->em->createQueryBuilder()
            ->select('n')->from(Notification::class, 'n')
            ->where('n.user = :user')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC');

        if ($filter === 'unread') {
            $qb->andWhere('n.isRead = false');
        }

        $total         = (clone $qb)->select('COUNT(n.id)')->getQuery()->getSingleScalarResult();
        $notifications = $qb->setFirstResult(($page - 1) * $perPage)
                            ->setMaxResults($perPage)
                            ->getQuery()->getResult();

        // Marquer tout comme lu si on regarde la page
        if ($filter !== 'unread') {
            $this->notifService->markAllAsRead($user);
        }

        return $this->render('notifications/index.html.twig', [
            'notifications' => $notifications,
            'total'         => $total,
            'page'          => $page,
            'totalPages'    => (int) ceil($total / $perPage),
            'filter'        => $filter,
            'unreadCount'   => $this->notifService->getUnreadCount($user),
        ]);
    }

    // =========================================================
    // MARQUER UNE NOTIFICATION COMME LUE (Ajax)
    // =========================================================
    #[Route('/{id}/read', name: 'app_notification_read', methods: ['POST'])]
    public function markRead(int $id): JsonResponse
    {
        $notif = $this->em->getRepository(Notification::class)->find($id);

        if (!$notif || $notif->getUser() !== $this->getUser()) {
            return $this->json(['error' => 'Introuvable'], 404);
        }

        $notif->setIsRead(true);
        $this->em->flush();

        return $this->json([
            'success'     => true,
            'unreadCount' => $this->notifService->getUnreadCount($this->getUser()),
        ]);
    }

    // =========================================================
    // MARQUER TOUT COMME LU (Ajax)
    // =========================================================
    #[Route('/read-all', name: 'app_notifications_read_all', methods: ['POST'])]
    public function markAllRead(): JsonResponse
    {
        $this->notifService->markAllAsRead($this->getUser());
        return $this->json(['success' => true, 'unreadCount' => 0]);
    }

    // =========================================================
    // SUPPRIMER UNE NOTIFICATION
    // =========================================================
    #[Route('/{id}/delete', name: 'app_notification_delete', methods: ['POST'])]
    public function delete(int $id): JsonResponse
    {
        $notif = $this->em->getRepository(Notification::class)->find($id);

        if (!$notif || $notif->getUser() !== $this->getUser()) {
            return $this->json(['error' => 'Introuvable'], 404);
        }

        $this->em->remove($notif);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    // =========================================================
    // COMPTE NON LUS — polling header (remplace ApiController)
    // =========================================================
    #[Route('/count', name: 'app_notifications_count', methods: ['GET'])]
    public function count(): JsonResponse
    {
        return $this->json([
            'count' => $this->notifService->getUnreadCount($this->getUser()),
        ]);
    }
}
