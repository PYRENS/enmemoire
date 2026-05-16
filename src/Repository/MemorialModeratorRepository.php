<?php

namespace App\Repository;

use App\Entity\MemorialModerator;
use App\Entity\MemorialPage;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MemorialModeratorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MemorialModerator::class);
    }

    public function findActiveModeratorsForPage(MemorialPage $page): array
    {
        return $this->findBy(
            ['memorial' => $page, 'status' => MemorialModerator::STATUS_ACTIVE],
            ['isOwner' => 'DESC', 'createdAt' => 'ASC']
        );
    }

    public function findPendingForUser(User $user): array
    {
        return $this->findBy(
            ['user' => $user, 'status' => MemorialModerator::STATUS_PENDING],
            ['invitedAt' => 'DESC']
        );
    }

    public function findModeratorForUser(MemorialPage $page, User $user): ?MemorialModerator
    {
        return $this->findOneBy([
            'memorial' => $page,
            'user'     => $user,
        ]);
    }

    public function countActiveForPage(MemorialPage $page): int
    {
        return (int) $this->count([
            'memorial' => $page,
            'status'   => MemorialModerator::STATUS_ACTIVE,
        ]);
    }

    public function countActiveModerators(MemorialPage $page): int
    {
        return (int) $this->count([
            'memorial' => $page,
            'status'   => MemorialModerator::STATUS_ACTIVE,
        ]);
    }
}
