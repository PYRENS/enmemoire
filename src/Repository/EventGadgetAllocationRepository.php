<?php
namespace App\Repository;
use App\Entity\EventGadgetAllocation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
/** @extends ServiceEntityRepository<EventGadgetAllocation> */
class EventGadgetAllocationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventGadgetAllocation::class);
    }
}
