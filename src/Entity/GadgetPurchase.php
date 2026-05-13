<?php
namespace App\Entity;
use App\Repository\GadgetPurchaseRepository;
use Doctrine\DBAL\Types\Types; use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity(repositoryClass: GadgetPurchaseRepository::class)] #[ORM\Table(name: 'gadget_purchases')]
class GadgetPurchase {
    #[ORM\Id,ORM\GeneratedValue,ORM\Column(type:Types::BIGINT,options:['unsigned'=>true])] private ?int $id=null;
    #[ORM\Column(type:Types::STRING,length:36,unique:true)] private string $uuid = '';
    #[ORM\ManyToOne(targetEntity:User::class)] #[ORM\JoinColumn(name: 'user_id', nullable:false)] private ?User $user=null;
    #[ORM\ManyToOne(targetEntity:GadgetCatalog::class)] #[ORM\JoinColumn(name: 'gadget_id', nullable:false)] private ?GadgetCatalog $gadget=null;
    #[ORM\Column(type:Types::SMALLINT,options:['unsigned'=>true,'default'=>1])] private int $quantity=1;
    #[ORM\Column(type:Types::DECIMAL,precision:10,scale:2,options:['unsigned'=>true])] private string $unitPrice='0.00';
    #[ORM\Column(type:Types::DECIMAL,precision:10,scale:2,options:['unsigned'=>true])] private string $totalPrice='0.00';
    #[ORM\Column(length:3,options:['default'=>'USD'])] private string $currency='USD';
    #[ORM\Column(type:Types::BIGINT,nullable:true,options:['unsigned'=>true])] private ?int $paymentId=null;
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
    public function getUser():?User{return $this->user;} public function setUser(?User $u):static{$this->user=$u;return $this;}
    public function getGadget():?GadgetCatalog{return $this->gadget;} public function setGadget(?GadgetCatalog $g):static{$this->gadget=$g;return $this;}
    public function getQuantity():int{return $this->quantity;} public function setQuantity(int $q):static{$this->quantity=$q;return $this;}
    public function getUnitPrice():string{return $this->unitPrice;} public function setUnitPrice(string $p):static{$this->unitPrice=$p;return $this;}
    public function getTotalPrice():string{return $this->totalPrice;} public function setTotalPrice(string $p):static{$this->totalPrice=$p;return $this;}
    public function getCurrency():string{return $this->currency;} public function setCurrency(string $c):static{$this->currency=$c;return $this;}
    public function getCreatedAt():\DateTimeInterface{return $this->createdAt;}
}
