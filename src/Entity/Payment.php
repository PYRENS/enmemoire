<?php
namespace App\Entity;
use App\Repository\PaymentRepository;
use Doctrine\DBAL\Types\Types; use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity(repositoryClass: PaymentRepository::class)] #[ORM\Table(name: 'payments')]
#[ORM\HasLifecycleCallbacks]
class Payment {
    public const TYPE_FORMULA='memorial_formula'; public const TYPE_THEME='theme_purchase';
    public const TYPE_GADGET='gadget_purchase';   public const TYPE_PROMO='promotion';
    public const PROVIDER_STRIPE='stripe';         public const PROVIDER_AIRTEL='airtel_money';
    public const PROVIDER_MPESA='mpesa';           public const PROVIDER_ORANGE='orange_money';
    public const PROVIDER_MANUAL='manual';
    public const STATUS_PENDING='pending'; public const STATUS_COMPLETED='completed';
    public const STATUS_FAILED='failed';   public const STATUS_REFUNDED='refunded';
    #[ORM\Id,ORM\GeneratedValue,ORM\Column(type:Types::BIGINT,options:['unsigned'=>true])] private ?int $id=null;
    #[ORM\Column(type:Types::STRING,length:36,unique:true)] private string $uuid = '';
    #[ORM\ManyToOne(targetEntity:User::class)] #[ORM\JoinColumn(name: 'user_id', nullable:false)] private ?User $user=null;
    #[ORM\Column(length:30)] private string $type=self::TYPE_FORMULA;
    #[ORM\Column(type:Types::BIGINT,nullable:true,options:['unsigned'=>true])] private ?int $referenceId=null;
    #[ORM\Column(type:Types::DECIMAL,precision:12,scale:2,options:['unsigned'=>true])] private string $amount='0.00';
    #[ORM\Column(length:3,options:['default'=>'USD'])] private string $currency='USD';
    #[ORM\Column(length:30)] private string $provider=self::PROVIDER_STRIPE;
    #[ORM\Column(length:255,nullable:true)] private ?string $providerTxId=null;
    #[ORM\Column(length:20,options:['default'=>self::STATUS_PENDING])] private string $status=self::STATUS_PENDING;
    #[ORM\Column(type:Types::JSON,nullable:true)] private ?array $metadata=null;
    #[ORM\Column(type:Types::DATETIME_MUTABLE)] private \DateTimeInterface $createdAt;
    #[ORM\Column(type:Types::DATETIME_MUTABLE)] private \DateTimeInterface $updatedAt;
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

    public function __construct(){$this->uuid=self::generateUuid();$this->createdAt=new \DateTime();$this->updatedAt=new \DateTime();}
    #[ORM\PreUpdate] public function onPreUpdate():void{$this->updatedAt=new \DateTime();}
    public function getId():?int{return $this->id;} public function getUuid():Uuid{return $this->uuid;}
    public function getUser():?User{return $this->user;} public function setUser(?User $u):static{$this->user=$u;return $this;}
    public function getType():string{return $this->type;} public function setType(string $t):static{$this->type=$t;return $this;}
    public function getReferenceId():?int{return $this->referenceId;} public function setReferenceId(?int $id):static{$this->referenceId=$id;return $this;}
    public function getAmount():string{return $this->amount;} public function setAmount(string $a):static{$this->amount=$a;return $this;}
    public function getCurrency():string{return $this->currency;} public function setCurrency(string $c):static{$this->currency=$c;return $this;}
    public function getProvider():string{return $this->provider;} public function setProvider(string $p):static{$this->provider=$p;return $this;}
    public function getProviderTxId():?string{return $this->providerTxId;} public function setProviderTxId(?string $id):static{$this->providerTxId=$id;return $this;}
    public function getStatus():string{return $this->status;} public function setStatus(string $s):static{$this->status=$s;return $this;}
    public function isCompleted():bool{return $this->status===self::STATUS_COMPLETED;}
    public function getMetadata():?array{return $this->metadata;} public function setMetadata(?array $m):static{$this->metadata=$m;return $this;}
    public function getCreatedAt():\DateTimeInterface{return $this->createdAt;} public function getUpdatedAt():\DateTimeInterface{return $this->updatedAt;}
}
