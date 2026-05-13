<?php
namespace App\Entity;
use App\Repository\EventGadgetAllocationRepository;
use Doctrine\DBAL\Types\Types; use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity(repositoryClass: EventGadgetAllocationRepository::class)] #[ORM\Table(name: 'event_gadget_allocations')]
class EventGadgetAllocation {
    public const TYPE_DONATION='donation_all'; public const TYPE_FIXED='fixed_quantity';
    public const DIST_DIRECT='direct'; public const DIST_EXPOSED='exposed';
    public const SRC_MAKER='maker'; public const SRC_USER='user_purchase';
    #[ORM\Id,ORM\GeneratedValue,ORM\Column(type:Types::BIGINT,options:['unsigned'=>true])] private ?int $id=null;
    #[ORM\ManyToOne(targetEntity:MemorialEvent::class)] #[ORM\JoinColumn(name: 'event_id', nullable:false,onDelete:'CASCADE')] private ?MemorialEvent $event=null;
    #[ORM\ManyToOne(targetEntity:GadgetCatalog::class)] #[ORM\JoinColumn(name: 'gadget_id', nullable:false)] private ?GadgetCatalog $gadget=null;
    #[ORM\ManyToOne(targetEntity:User::class)] #[ORM\JoinColumn(name: 'allocated_by', nullable:false)] private ?User $allocatedBy=null;
    #[ORM\Column(length:30)] private string $allocationType=self::TYPE_DONATION;
    #[ORM\Column(type:Types::INTEGER,nullable:true,options:['unsigned'=>true])] private ?int $totalQuantity=null;
    #[ORM\Column(type:Types::INTEGER,nullable:true,options:['unsigned'=>true])] private ?int $remainingQuantity=null;
    #[ORM\Column(length:20,options:['default'=>self::DIST_EXPOSED])] private string $distributionMode=self::DIST_EXPOSED;
    #[ORM\Column(length:20,options:['default'=>self::SRC_MAKER])] private string $sourceType=self::SRC_MAKER;
    #[ORM\ManyToOne(targetEntity:User::class)] #[ORM\JoinColumn(name: 'source_user_id', nullable:true)] private ?User $sourceUser=null;
    #[ORM\Column(options:['default'=>true])] private bool $isActive=true;
    #[ORM\Column(type:Types::DATETIME_MUTABLE,nullable:true)] private ?\DateTimeInterface $expiresAt=null;
    #[ORM\Column(type:Types::DATETIME_MUTABLE)] private \DateTimeInterface $createdAt;
    public function __construct(){$this->createdAt=new \DateTime();}
    public function getId():?int{return $this->id;}
    public function getEvent():?MemorialEvent{return $this->event;} public function setEvent(?MemorialEvent $e):static{$this->event=$e;return $this;}
    public function getGadget():?GadgetCatalog{return $this->gadget;} public function setGadget(?GadgetCatalog $g):static{$this->gadget=$g;return $this;}
    public function getAllocatedBy():?User{return $this->allocatedBy;} public function setAllocatedBy(?User $u):static{$this->allocatedBy=$u;return $this;}
    public function getAllocationType():string{return $this->allocationType;} public function setAllocationType(string $t):static{$this->allocationType=$t;return $this;}
    public function getTotalQuantity():?int{return $this->totalQuantity;} public function setTotalQuantity(?int $q):static{$this->totalQuantity=$q;return $this;}
    public function getRemainingQuantity():?int{return $this->remainingQuantity;} public function setRemainingQuantity(?int $q):static{$this->remainingQuantity=$q;return $this;}
    public function getDistributionMode():string{return $this->distributionMode;} public function setDistributionMode(string $m):static{$this->distributionMode=$m;return $this;}
    public function isActive():bool{return $this->isActive;} public function setIsActive(bool $v):static{$this->isActive=$v;return $this;}
    public function getExpiresAt():?\DateTimeInterface{return $this->expiresAt;} public function setExpiresAt(?\DateTimeInterface $d):static{$this->expiresAt=$d;return $this;}
    public function getCreatedAt():\DateTimeInterface{return $this->createdAt;}
}
