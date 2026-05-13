<?php
namespace App\Entity;
use App\Repository\PromotionRepository;
use Doctrine\DBAL\Types\Types; use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity(repositoryClass: PromotionRepository::class)] #[ORM\Table(name: 'promotions')]
class Promotion {
    #[ORM\Id,ORM\GeneratedValue,ORM\Column(type:Types::INTEGER,options:['unsigned'=>true])] private ?int $id=null;
    #[ORM\Column(length:50,unique:true)] private string $code='';
    #[ORM\Column(length:255,nullable:true)] private ?string $description=null;
    #[ORM\Column(length:10,options:['default'=>'percent'])] private string $discountType='percent';
    #[ORM\Column(type:Types::DECIMAL,precision:10,scale:2,options:['unsigned'=>true])] private string $discountValue='0.00';
    #[ORM\Column(length:20,options:['default'=>'all'])] private string $appliesTo='all';
    #[ORM\Column(type:Types::INTEGER,nullable:true,options:['unsigned'=>true])] private ?int $maxUses=null;
    #[ORM\Column(type:Types::INTEGER,options:['unsigned'=>true,'default'=>0])] private int $usedCount=0;
    #[ORM\Column(type:Types::DATETIME_MUTABLE,nullable:true)] private ?\DateTimeInterface $validFrom=null;
    #[ORM\Column(type:Types::DATETIME_MUTABLE,nullable:true)] private ?\DateTimeInterface $validUntil=null;
    #[ORM\Column(options:['default'=>true])] private bool $isActive=true;
    #[ORM\ManyToOne(targetEntity:User::class)] #[ORM\JoinColumn(name: 'created_by', nullable:false)] private ?User $createdBy=null;
    #[ORM\Column(type:Types::DATETIME_MUTABLE)] private \DateTimeInterface $createdAt;
    public function __construct(){$this->createdAt=new \DateTime();}
    public function getId():?int{return $this->id;}
    public function getCode():string{return $this->code;} public function setCode(string $c):static{$this->code=$c;return $this;}
    public function getDescription():?string{return $this->description;} public function setDescription(?string $d):static{$this->description=$d;return $this;}
    public function getDiscountType():string{return $this->discountType;} public function setDiscountType(string $t):static{$this->discountType=$t;return $this;}
    public function getDiscountValue():string{return $this->discountValue;} public function setDiscountValue(string $v):static{$this->discountValue=$v;return $this;}
    public function getAppliesTo():string{return $this->appliesTo;} public function setAppliesTo(string $a):static{$this->appliesTo=$a;return $this;}
    public function getMaxUses():?int{return $this->maxUses;} public function setMaxUses(?int $n):static{$this->maxUses=$n;return $this;}
    public function getUsedCount():int{return $this->usedCount;} public function incrementUsed():static{$this->usedCount++;return $this;}
    public function isActive():bool{return $this->isActive;} public function setIsActive(bool $v):static{$this->isActive=$v;return $this;}
    public function getValidFrom():?\DateTimeInterface{return $this->validFrom;} public function setValidFrom(?\DateTimeInterface $d):static{$this->validFrom=$d;return $this;}
    public function getValidUntil():?\DateTimeInterface{return $this->validUntil;} public function setValidUntil(?\DateTimeInterface $d):static{$this->validUntil=$d;return $this;}
    public function getCreatedBy():?User{return $this->createdBy;} public function setCreatedBy(?User $u):static{$this->createdBy=$u;return $this;}
    public function getCreatedAt():\DateTimeInterface{return $this->createdAt;}
    public function isValid():bool{
        $now=new \DateTime();
        if(!$this->isActive) return false;
        if($this->validFrom && $now < $this->validFrom) return false;
        if($this->validUntil && $now > $this->validUntil) return false;
        if($this->maxUses !== null && $this->usedCount >= $this->maxUses) return false;
        return true;
    }
}
