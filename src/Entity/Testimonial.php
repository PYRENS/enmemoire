<?php
namespace App\Entity;
use App\Repository\TestimonialRepository;
use Doctrine\DBAL\Types\Types; use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity(repositoryClass:TestimonialRepository::class)] #[ORM\Table(name:'testimonials')]
class Testimonial {
    public const STATUS_PENDING='pending'; public const STATUS_APPROVED='approved'; public const STATUS_REJECTED='rejected';
    #[ORM\Id,ORM\GeneratedValue,ORM\Column(type:Types::BIGINT,options:['unsigned'=>true])] private ?int $id=null;
    #[ORM\ManyToOne(targetEntity:MemorialPage::class,inversedBy:'testimonials')] #[ORM\JoinColumn(name: 'memorial_id', nullable:false,onDelete:'CASCADE')] private ?MemorialPage $memorial=null;
    #[ORM\ManyToOne(targetEntity:User::class)] #[ORM\JoinColumn(name: 'user_id', nullable:false)] private ?User $user=null;
    #[ORM\Column(length:255,nullable:true)] private ?string $title=null;
    #[ORM\Column(type:Types::TEXT)] private string $content='';
    #[ORM\Column(length:100,nullable:true)] private ?string $relationToDeceased=null;
    #[ORM\Column(length:20,options:['default'=>self::STATUS_PENDING])] private string $status=self::STATUS_PENDING;
    #[ORM\ManyToOne(targetEntity:User::class)] #[ORM\JoinColumn(name: 'moderated_by', nullable:true)] private ?User $moderatedBy=null;
    #[ORM\Column(type:Types::DATETIME_MUTABLE,nullable:true)] private ?\DateTimeInterface $moderatedAt=null;
    #[ORM\Column(type:Types::DATETIME_MUTABLE)] private \DateTimeInterface $createdAt;
    public function __construct(){$this->createdAt=new \DateTime();}
    public function getId():?int{return $this->id;}
    public function getMemorial():?MemorialPage{return $this->memorial;} public function setMemorial(?MemorialPage $m):static{$this->memorial=$m;return $this;}
    public function getUser():?User{return $this->user;} public function setUser(?User $u):static{$this->user=$u;return $this;}
    public function getTitle():?string{return $this->title;} public function setTitle(?string $t):static{$this->title=$t;return $this;}
    public function getContent():string{return $this->content;} public function setContent(string $c):static{$this->content=$c;return $this;}
    public function getRelationToDeceased():?string{return $this->relationToDeceased;} public function setRelationToDeceased(?string $r):static{$this->relationToDeceased=$r;return $this;}
    public function getStatus():string{return $this->status;} public function setStatus(string $s):static{$this->status=$s;return $this;}
    public function isApproved():bool{return $this->status===self::STATUS_APPROVED;}
    public function getModeratedBy():?User{return $this->moderatedBy;} public function setModeratedBy(?User $u):static{$this->moderatedBy=$u;return $this;}
    public function getModeratedAt():?\DateTimeInterface{return $this->moderatedAt;} public function setModeratedAt(?\DateTimeInterface $d):static{$this->moderatedAt=$d;return $this;}
    public function getCreatedAt():\DateTimeInterface{return $this->createdAt;}
}
