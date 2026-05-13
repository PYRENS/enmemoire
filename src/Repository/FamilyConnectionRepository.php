<?php

namespace App\Repository;

use App\Entity\FamilyConnection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * ✅ FIX 3 : Repository généré pour satisfaire la déclaration dans l'entité FamilyConnection.
 * Étendre ce repository avec des méthodes métier au besoin.
 *
 * @extends ServiceEntityRepository<FamilyConnection>
 */
class FamilyConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FamilyConnection::class);
    }
}
