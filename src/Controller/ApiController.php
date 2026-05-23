<?php

namespace App\Controller;

use App\Entity\GuestBook;
use App\Entity\User;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api')]
class ApiController extends AbstractController
{
    public function __construct(
        private readonly NotificationService    $notifService,
        private readonly EntityManagerInterface $em,
    ) {}

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

    #[Route('/signatures/recentes', name: 'app_api_signatures_recentes', methods: ['GET'])]
    public function signaturesRecentes(): JsonResponse
    {
        $signatures = $this->em->getRepository(GuestBook::class)
            ->findBy(['status' => 'approved'], ['signedAt' => 'DESC'], 10);

        $data = array_map(function (GuestBook $s) {
            return [
                'id'       => $s->getId(),
                'name'     => $s->getUser()?->getFullName() ?? 'Anonyme',
                'message'  => $s->getSignatureText(),
                'signedAt' => $s->getSignedAt()->format('d/m/Y'),
                'memorial' => $s->getMemorial()?->getDeceasedFullName(),
                'slug'     => $s->getMemorial()?->getSlug(),
            ];
        }, $signatures);

        return $this->json($data);
    }
}
