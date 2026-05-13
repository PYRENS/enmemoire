<?php

namespace App\Repository;

use App\Entity\Condolence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * ✅ FIX 3 : Repository généré pour satisfaire la déclaration dans l'entité Condolence.
 * Étendre ce repository avec des méthodes métier au besoin.
 *
 * @extends ServiceEntityRepository<Condolence>
 */
class CondolenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Condolence::class);
    }
}
