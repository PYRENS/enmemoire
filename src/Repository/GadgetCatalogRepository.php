<?php

namespace App\Repository;

use App\Entity\GadgetCatalog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * ✅ FIX 3 : Repository généré pour satisfaire la déclaration dans l'entité GadgetCatalog.
 * Étendre ce repository avec des méthodes métier au besoin.
 *
 * @extends ServiceEntityRepository<GadgetCatalog>
 */
class GadgetCatalogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GadgetCatalog::class);
    }
}
