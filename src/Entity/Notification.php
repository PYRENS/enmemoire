<?php namespace App\Entity;
use Doctrine\DBAL\Types\Types; use Doctrine\ORM\Mapping as ORM;
use App\Repository\NotificationRepository;
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notifications')]
#[ORM\Index(name: 'idx_user_unread', columns: ['user_id', 'is_read'])]
class Notification {
    #[ORM\Id,ORM\GeneratedValue,ORM\Column(type:Types::BIGINT,options:['unsigned'=>true])] private ?int $id=null;
    #[ORM\ManyToOne(targetEntity:User::class,inversedBy:'notifications')] #[ORM\JoinColumn(name: 'user_id', nullable:false,onDelete:'CASCADE')] private ?User $user=null;
    #[ORM\Column(length:80)] private string $type='';
    #[ORM\Column(length:255)] private string $title='';
    #[ORM\Column(type:Types::TEXT,nullable:true)] private ?string $body=null;
    #[ORM\Column(length:500,nullable:true)] private ?string $linkUrl=null;
    #[ORM\Column(options:['default'=>false])] private bool $isRead=false;
    #[ORM\Column(length:80,nullable:true)] private ?string $relatedType=null;
    #[ORM\Column(type:Types::BIGINT,nullable:true,options:['unsigned'=>true])] private ?int $relatedId=null;
    #[ORM\Column(type:Types::DATETIME_MUTABLE)] private \DateTimeInterface $createdAt;
    public function __construct(){$this->createdAt=new \DateTime();}
    public function getId():?int{return $this->id;}
    public function getUser():?User{return $this->user;} public function setUser(?User $u):static{$this->user=$u;return $this;}
    public function getType():string{return $this->type;} public function setType(string $t):static{$this->type=$t;return $this;}
    public function getTitle():string{return $this->title;} public function setTitle(string $t):static{$this->title=$t;return $this;}
    public function getBody():?string{return $this->body;} public function setBody(?string $b):static{$this->body=$b;return $this;}
    public function getLinkUrl():?string{return $this->linkUrl;} public function setLinkUrl(?string $u):static{$this->linkUrl=$u;return $this;}
    public function isRead():bool{return $this->isRead;} public function setIsRead(bool $v):static{$this->isRead=$v;return $this;}
    public function getRelatedType():?string{return $this->relatedType;} public function setRelatedType(?string $t):static{$this->relatedType=$t;return $this;}
    public function getRelatedId():?int{return $this->relatedId;} public function setRelatedId(?int $id):static{$this->relatedId=$id;return $this;}
    public function getCreatedAt():\DateTimeInterface{return $this->createdAt;}
}
