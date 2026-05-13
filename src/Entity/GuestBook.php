<?php
namespace App\Entity;
use App\Repository\GuestBookRepository;
use Doctrine\DBAL\Types\Types; use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity(repositoryClass:GuestBookRepository::class)] #[ORM\Table(name:'guestbook')]
#[ORM\UniqueConstraint(name:'uq_user_memorial',columns:['user_id','memorial_id'])]
class GuestBook {
    public const STATUS_PENDING='pending';public const STATUS_APPROVED='approved';public const STATUS_REJECTED='rejected';
    #[ORM\Id,ORM\GeneratedValue,ORM\Column(type:Types::BIGINT,options:['unsigned'=>true])] private ?int $id=null;
    #[ORM\ManyToOne(targetEntity:MemorialPage::class,inversedBy:'guestBooks')] #[ORM\JoinColumn(name: 'memorial_id', nullable:false,onDelete:'CASCADE')] private ?MemorialPage $memorial=null;
    #[ORM\ManyToOne(targetEntity:User::class)] #[ORM\JoinColumn(name: 'user_id', nullable:false)] private ?User $user=null;
    #[ORM\Column(type:Types::TEXT,nullable:true)] private ?string $signatureText=null;
    #[ORM\Column(length:20,options:['default'=>self::STATUS_PENDING])] private string $status=self::STATUS_PENDING;
    #[ORM\ManyToOne(targetEntity:User::class)] #[ORM\JoinColumn(name: 'moderated_by', nullable:true)] private ?User $moderatedBy=null;
    #[ORM\Column(type:Types::DATETIME_MUTABLE,nullable:true)] private ?\DateTimeInterface $moderatedAt=null;
    #[ORM\Column(type:Types::DATETIME_MUTABLE)] private \DateTimeInterface $signedAt;
    public function __construct(){$this->signedAt=new \DateTime();}
    public function getId():?int{return $this->id;}
    public function getMemorial():?MemorialPage{return $this->memorial;} public function setMemorial(?MemorialPage $m):static{$this->memorial=$m;return $this;}
    public function getUser():?User{return $this->user;} public function setUser(?User $u):static{$this->user=$u;return $this;}
    public function getSignatureText():?string{return $this->signatureText;} public function setSignatureText(?string $t):static{$this->signatureText=$t;return $this;}
    public function getStatus():string{return $this->status;} public function setStatus(string $s):static{$this->status=$s;return $this;}
    public function isApproved():bool{return $this->status===self::STATUS_APPROVED;}
    public function getModeratedBy():?User{return $this->moderatedBy;} public function setModeratedBy(?User $u):static{$this->moderatedBy=$u;return $this;}
    public function getModeratedAt():?\DateTimeInterface{return $this->moderatedAt;} public function setModeratedAt(?\DateTimeInterface $d):static{$this->moderatedAt=$d;return $this;}
    public function getSignedAt():\DateTimeInterface{return $this->signedAt;}
}
