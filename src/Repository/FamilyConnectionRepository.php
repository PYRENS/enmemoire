<?php

namespace App\Repository;

use App\Entity\FamilyConnection;
use App\Entity\MemorialPage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class FamilyConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FamilyConnection::class);
    }

    public function findAcceptedForPage(MemorialPage $page): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.memorialFrom = :page OR c.memorialTo = :page')
            ->andWhere('c.status = :status')
            ->setParameter('page', $page)
            ->setParameter('status', FamilyConnection::STATUS_ACCEPTED)
            ->orderBy('c.requestedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findAllForPage(MemorialPage $page): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.memorialFrom = :page OR c.memorialTo = :page')
            ->setParameter('page', $page)
            ->orderBy('c.requestedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countForPage(MemorialPage $page): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.memorialFrom = :page OR c.memorialTo = :page')
            ->andWhere('c.status = :status')
            ->setParameter('page', $page)
            ->setParameter('status', FamilyConnection::STATUS_ACCEPTED)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findPendingFrom(MemorialPage $page): array
    {
        return $this->findBy(
            ['memorialFrom' => $page, 'status' => FamilyConnection::STATUS_PENDING],
            ['requestedAt' => 'DESC']
        );
    }

    public function findPendingTo(MemorialPage $page): array
    {
        return $this->findBy(
            ['memorialTo' => $page, 'status' => FamilyConnection::STATUS_PENDING],
            ['requestedAt' => 'DESC']
        );
    }

    public function countPendingForPage(MemorialPage $page): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.memorialTo = :page')
            ->andWhere('c.status = :status')
            ->setParameter('page', $page)
            ->setParameter('status', FamilyConnection::STATUS_PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
