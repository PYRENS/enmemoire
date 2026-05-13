<?php

namespace App\Repository;

use App\Entity\GuestBook;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * ✅ FIX 3 : Repository généré pour satisfaire la déclaration dans l'entité GuestBook.
 * Étendre ce repository avec des méthodes métier au besoin.
 *
 * @extends ServiceEntityRepository<GuestBook>
 */
class GuestBookRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GuestBook::class);
    }
}
