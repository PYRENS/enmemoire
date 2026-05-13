<?php

namespace App\Repository;

use App\Entity\MemorialPage;
use App\Entity\ModeratorTrustList;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ModeratorTrustListRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ModeratorTrustList::class);
    }

    /**
     * Retourne true si $user est dans au moins une liste de confiance
     * d'un modérateur actif de $page
     */
    public function isUserTrustedByAnyModerator(MemorialPage $page, User $user): bool
    {
        $result = $this->createQueryBuilder('t')
            ->join('t.moderator', 'mod')
            ->where('mod.memorial = :page')
            ->andWhere('mod.status = :modStatus')
            ->andWhere('t.trustedUser = :user')
            ->setParameter('page', $page)
            ->setParameter('modStatus', 'active')
            ->setParameter('user', $user)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result !== null;
    }
}
