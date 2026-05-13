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
        return $this->createQueryBuilder('m')
            ->join('m.user', 'u')
            ->where('m.memorial = :page')
            ->andWhere('m.status = :status')
            ->setParameter('page', $page)
            ->setParameter('status', 'active')
            ->orderBy('m.isOwner', 'DESC')
            ->addOrderBy('m.acceptedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countActiveModerators(MemorialPage $page): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.memorial = :page')
            ->andWhere('m.status = :status')
            ->setParameter('page', $page)
            ->setParameter('status', 'active')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findModeratorForUser(MemorialPage $page, User $user): ?MemorialModerator
    {
        return $this->findOneBy([
            'memorial' => $page,
            'user'     => $user,
            'status'   => 'active',
        ]);
    }
}
