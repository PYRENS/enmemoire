<?php
namespace App\Entity;
use App\Repository\AuditLogRepository;
use Doctrine\DBAL\Types\Types; use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity(repositoryClass: AuditLogRepository::class)] #[ORM\Table(name: 'audit_logs')]
class AuditLog {
    #[ORM\Id,ORM\GeneratedValue,ORM\Column(type:Types::BIGINT,options:['unsigned'=>true])] private ?int $id=null;
    #[ORM\ManyToOne(targetEntity:User::class)] #[ORM\JoinColumn(name: 'actor_id', nullable:false)] private ?User $actor=null;
    #[ORM\Column(length:120)] private string $action='';
    #[ORM\Column(length:80,nullable:true)] private ?string $targetType=null;
    #[ORM\Column(type:Types::BIGINT,nullable:true,options:['unsigned'=>true])] private ?int $targetId=null;
    #[ORM\Column(type:Types::JSON,nullable:true)] private ?array $oldValue=null;
    #[ORM\Column(type:Types::JSON,nullable:true)] private ?array $newValue=null;
    #[ORM\Column(length:45,nullable:true)] private ?string $ipAddress=null;
    #[ORM\Column(type:Types::DATETIME_MUTABLE)] private \DateTimeInterface $createdAt;
    public function __construct(){$this->createdAt=new \DateTime();}
    public function getId():?int{return $this->id;}
    public function getActor():?User{return $this->actor;} public function setActor(?User $u):static{$this->actor=$u;return $this;}
    public function getAction():string{return $this->action;} public function setAction(string $a):static{$this->action=$a;return $this;}
    public function getTargetType():?string{return $this->targetType;} public function setTargetType(?string $t):static{$this->targetType=$t;return $this;}
    public function getTargetId():?int{return $this->targetId;} public function setTargetId(?int $id):static{$this->targetId=$id;return $this;}
    public function getOldValue():?array{return $this->oldValue;} public function setOldValue(?array $v):static{$this->oldValue=$v;return $this;}
    public function getNewValue():?array{return $this->newValue;} public function setNewValue(?array $v):static{$this->newValue=$v;return $this;}
    public function getIpAddress():?string{return $this->ipAddress;} public function setIpAddress(?string $ip):static{$this->ipAddress=$ip;return $this;}
    public function getCreatedAt():\DateTimeInterface{return $this->createdAt;}
}
