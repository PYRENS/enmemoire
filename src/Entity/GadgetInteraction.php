<?php
namespace App\Entity;
use App\Repository\GadgetInteractionRepository;
use Doctrine\DBAL\Types\Types; use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity(repositoryClass: GadgetInteractionRepository::class)] #[ORM\Table(name: 'gadget_interactions')]
class GadgetInteraction {
    public const ACTION_FLOWER='deposit_flower'; public const ACTION_CANDLE='light_candle';
    public const ACTION_DOVE='release_dove';     public const ACTION_OTHER='other';
    #[ORM\Id,ORM\GeneratedValue,ORM\Column(type:Types::BIGINT,options:['unsigned'=>true])] private ?int $id=null;
    #[ORM\Column(type:Types::STRING,length:36,unique:true)] private string $uuid = '';
    #[ORM\ManyToOne(targetEntity:MemorialPage::class)] #[ORM\JoinColumn(name: 'memorial_id', nullable:false,onDelete:'CASCADE')] private ?MemorialPage $memorial=null;
    #[ORM\ManyToOne(targetEntity:MemorialEvent::class)] #[ORM\JoinColumn(name: 'event_id', nullable:true,onDelete:'SET NULL')] private ?MemorialEvent $event=null;
    #[ORM\ManyToOne(targetEntity:User::class)] #[ORM\JoinColumn(name: 'user_id', nullable:false)] private ?User $user=null;
    #[ORM\ManyToOne(targetEntity:GadgetCatalog::class)] #[ORM\JoinColumn(name: 'gadget_id', nullable:false)] private ?GadgetCatalog $gadget=null;
    #[ORM\ManyToOne(targetEntity:EventGadgetAllocation::class)] #[ORM\JoinColumn(name: 'allocation_id', nullable:true)] private ?EventGadgetAllocation $allocation=null;
    #[ORM\Column(length:255,nullable:true)] private ?string $customText=null;
    #[ORM\Column(length:30)] private string $action=self::ACTION_FLOWER;
    #[ORM\Column(type:Types::DATETIME_MUTABLE)] private \DateTimeInterface $createdAt;
    private static function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    public function __construct(){$this->uuid=self::generateUuid();$this->createdAt=new \DateTime();}
    public function getId():?int{return $this->id;} public function getUuid():Uuid{return $this->uuid;}
    public function getMemorial():?MemorialPage{return $this->memorial;} public function setMemorial(?MemorialPage $m):static{$this->memorial=$m;return $this;}
    public function getEvent():?MemorialEvent{return $this->event;} public function setEvent(?MemorialEvent $e):static{$this->event=$e;return $this;}
    public function getUser():?User{return $this->user;} public function setUser(?User $u):static{$this->user=$u;return $this;}
    public function getGadget():?GadgetCatalog{return $this->gadget;} public function setGadget(?GadgetCatalog $g):static{$this->gadget=$g;return $this;}
    public function getAllocation():?EventGadgetAllocation{return $this->allocation;} public function setAllocation(?EventGadgetAllocation $a):static{$this->allocation=$a;return $this;}
    public function getCustomText():?string{return $this->customText;} public function setCustomText(?string $t):static{$this->customText=$t;return $this;}
    public function getAction():string{return $this->action;} public function setAction(string $a):static{$this->action=$a;return $this;}
    public function getCreatedAt():\DateTimeInterface{return $this->createdAt;}
}
