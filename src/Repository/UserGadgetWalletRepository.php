<?php
namespace App\Repository;
use App\Entity\UserGadgetWallet;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
/** @extends ServiceEntityRepository<UserGadgetWallet> */
class UserGadgetWalletRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserGadgetWallet::class);
    }
}
