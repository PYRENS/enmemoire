<?php

namespace App\Repository;

use App\Entity\MemorialPage;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MemorialPageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MemorialPage::class);
    }

    public function findBySlugActive(string $slug): ?MemorialPage
    {
        return $this->createQueryBuilder('m')
            ->where('m.slug = :slug')
            ->andWhere('m.status = :status')
            ->setParameter('slug', $slug)
            ->setParameter('status', MemorialPage::STATUS_ACTIVE)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Trouve par slug sans filtre de statut.
     * Utilisé par le dashboard du propriétaire — la page peut être
     * dans n'importe quel statut (active, suspended, etc.)
     */
    public function findBySlug(string $slug): ?MemorialPage
    {
        return $this->createQueryBuilder('m')
            ->where('m.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findRecentPublic(int $limit = 12): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.status = :status')
            ->andWhere('m.visibility = :vis')
            ->setParameter('status', MemorialPage::STATUS_ACTIVE)
            ->setParameter('vis', MemorialPage::VISIBILITY_PUBLIC)
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function searchByName(string $query, int $limit = 20): array
    {
        $terms = '%' . mb_strtolower($query) . '%';
        return $this->createQueryBuilder('m')
            ->where('LOWER(m.deceasedFirstName) LIKE :q OR LOWER(m.deceasedLastName) LIKE :q')
            ->andWhere('m.status = :status')
            ->andWhere('m.visibility = :vis')
            ->setParameter('q', $terms)
            ->setParameter('status', MemorialPage::STATUS_ACTIVE)
            ->setParameter('vis', MemorialPage::VISIBILITY_PUBLIC)
            ->orderBy('m.deceasedDeathDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Pages dont l'utilisateur est modérateur */
    public function findManagedByUser(User $user): array
    {
        // SQL natif — contourne tous les problèmes de mapping JoinColumn
        // Cherche les pages où l'user est modérateur actif OU créateur direct
        $conn = $this->getEntityManager()->getConnection();
        $sql  = '
            SELECT DISTINCT mp.id
            FROM memorial_pages mp
            LEFT JOIN memorial_moderators mm
                ON mm.memorial_id = mp.id
                AND mm.user_id = :userId
                AND mm.status = :status
            WHERE mm.id IS NOT NULL
               OR mp.created_by = :userId
            ORDER BY mp.created_at DESC
        ';

        $ids = $conn->executeQuery($sql, [
            'userId' => $user->getId(),
            'status' => 'active',
        ])->fetchFirstColumn();

        if (empty($ids)) {
            return [];
        }

        return $this->createQueryBuilder('m')
            ->where('m.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
