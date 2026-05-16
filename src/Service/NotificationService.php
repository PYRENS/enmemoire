<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class NotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function send(
        User    $user,
        string  $type,
        string  $title,
        string  $body       = '',
        string  $linkUrl    = '',
        ?string $relatedType = null,
        ?int    $relatedId   = null,
    ): Notification {
        $notif = new Notification();
        $notif->setUser($user)
              ->setType($type)
              ->setTitle($title)
              ->setBody($body ?: null)
              ->setLinkUrl($linkUrl ?: null)
              ->setRelatedType($relatedType)
              ->setRelatedId($relatedId)
              ->setIsRead(false);

        $this->em->persist($notif);
        $this->em->flush();

        return $notif;
    }

    public function getUnreadCount(User $user): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(n.id)')
            ->from(Notification::class, 'n')
            ->where('n.user = :user')
            ->andWhere('n.isRead = false')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function markAllAsRead(User $user): void
    {
        $this->em->createQueryBuilder()
            ->update(Notification::class, 'n')
            ->set('n.isRead', 'true')
            ->where('n.user = :user')
            ->andWhere('n.isRead = false')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    public function getRecent(User $user, int $limit = 5): array
    {
        return $this->em->getRepository(Notification::class)
            ->findBy(['user' => $user], ['createdAt' => 'DESC'], $limit);
    }
}
