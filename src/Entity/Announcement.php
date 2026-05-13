<?php
namespace App\Entity;
use App\Repository\AnnouncementRepository;
use Doctrine\DBAL\Types\Types; use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity(repositoryClass:AnnouncementRepository::class)] #[ORM\Table(name:'announcements')]
#[ORM\HasLifecycleCallbacks]
class Announcement {
    #[ORM\Id,ORM\GeneratedValue,ORM\Column(type:Types::BIGINT,options:['unsigned'=>true])] private ?int $id=null;
    #[ORM\ManyToOne(targetEntity:MemorialPage::class,inversedBy:'announcements')] #[ORM\JoinColumn(name: 'memorial_id', nullable:false,onDelete:'CASCADE')] private ?MemorialPage $memorial=null;
    #[ORM\ManyToOne(targetEntity:MemorialEvent::class)] #[ORM\JoinColumn(name: 'event_id', nullable:true,onDelete:'SET NULL')] private ?MemorialEvent $event=null;
    #[ORM\ManyToOne(targetEntity:User::class)] #[ORM\JoinColumn(name: 'posted_by', nullable:false)] private ?User $postedBy=null;
    #[ORM\Column(length:255)] private string $title='';
    #[ORM\Column(type:Types::TEXT)] private string $content='';
    #[ORM\Column(options:['default'=>false])] private bool $isPinned=false;
    #[ORM\Column(type:Types::DATETIME_MUTABLE)] private \DateTimeInterface $createdAt;
    #[ORM\Column(type:Types::DATETIME_MUTABLE)] private \DateTimeInterface $updatedAt;
    public function __construct(){$this->createdAt=new \DateTime();$this->updatedAt=new \DateTime();}
    #[ORM\PreUpdate] public function onPreUpdate():void{$this->updatedAt=new \DateTime();}
    public function getId():?int{return $this->id;}
    public function getMemorial():?MemorialPage{return $this->memorial;} public function setMemorial(?MemorialPage $m):static{$this->memorial=$m;return $this;}
    public function getEvent():?MemorialEvent{return $this->event;} public function setEvent(?MemorialEvent $e):static{$this->event=$e;return $this;}
    public function getPostedBy():?User{return $this->postedBy;} public function setPostedBy(?User $u):static{$this->postedBy=$u;return $this;}
    public function getTitle():string{return $this->title;} public function setTitle(string $t):static{$this->title=$t;return $this;}
    public function getContent():string{return $this->content;} public function setContent(string $c):static{$this->content=$c;return $this;}
    public function isPinned():bool{return $this->isPinned;} public function setIsPinned(bool $v):static{$this->isPinned=$v;return $this;}
    public function getCreatedAt():\DateTimeInterface{return $this->createdAt;} public function getUpdatedAt():\DateTimeInterface{return $this->updatedAt;}
}
