<?php
namespace App\Entity;
use App\Repository\MediaGalleryRepository;
use Doctrine\DBAL\Types\Types; use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity(repositoryClass:MediaGalleryRepository::class)] #[ORM\Table(name:'media_gallery')]
class MediaGallery {
    public const TYPE_PHOTO='photo'; public const TYPE_VIDEO='video';
    #[ORM\Id,ORM\GeneratedValue,ORM\Column(type:Types::BIGINT,options:['unsigned'=>true])] private ?int $id=null;
    #[ORM\Column(type:Types::STRING,length:36,unique:true)] private string $uuid='';
    #[ORM\ManyToOne(targetEntity:MemorialPage::class,inversedBy:'mediaGalleries')] #[ORM\JoinColumn(name:'memorial_id', nullable:false,onDelete:'CASCADE')] private ?MemorialPage $memorial=null;
    #[ORM\ManyToOne(targetEntity:MemorialEvent::class,inversedBy:'mediaGalleries')] #[ORM\JoinColumn(name:'event_id', nullable:true,onDelete:'SET NULL')] private ?MemorialEvent $event=null;
    #[ORM\Column(length:10,options:['default'=>self::TYPE_PHOTO])] private string $type=self::TYPE_PHOTO;
    #[ORM\Column(length:500)] private string $url='';
    #[ORM\Column(length:500,nullable:true)] private ?string $thumbnailUrl=null;
    #[ORM\Column(length:500,nullable:true)] private ?string $caption=null;
    #[ORM\Column(type:Types::INTEGER,nullable:true,options:['unsigned'=>true])] private ?int $fileSizeKb=null;
    #[ORM\Column(type:Types::INTEGER,nullable:true,options:['unsigned'=>true])] private ?int $durationSec=null;
    #[ORM\Column(type:Types::SMALLINT,options:['default'=>0])] private int $sortOrder=0;
    #[ORM\Column(options:['default'=>false])] private bool $isFeatured=false;
    #[ORM\ManyToOne(targetEntity:User::class)] #[ORM\JoinColumn(name:'uploaded_by', nullable:false)] private ?User $uploadedBy=null;
    #[ORM\Column(type:Types::DATETIME_MUTABLE)] private \DateTimeInterface $createdAt;
    public function __construct(){$this->uuid=self::generateUuid();$this->createdAt=new \DateTime();}
    public function getId():?int{return $this->id;} public function getUuid():string{return $this->uuid;}
    public function getMemorial():?MemorialPage{return $this->memorial;} public function setMemorial(?MemorialPage $m):static{$this->memorial=$m;return $this;}
    public function getEvent():?MemorialEvent{return $this->event;} public function setEvent(?MemorialEvent $e):static{$this->event=$e;return $this;}
    public function getType():string{return $this->type;} public function setType(string $t):static{$this->type=$t;return $this;}
    public function isPhoto():bool{return $this->type===self::TYPE_PHOTO;} public function isVideo():bool{return $this->type===self::TYPE_VIDEO;}
    public function getUrl():string{return $this->url;} public function setUrl(string $u):static{$this->url=$u;return $this;}
    public function getThumbnailUrl():?string{return $this->thumbnailUrl;} public function setThumbnailUrl(?string $u):static{$this->thumbnailUrl=$u;return $this;}
    public function getCaption():?string{return $this->caption;} public function setCaption(?string $c):static{$this->caption=$c;return $this;}
    public function getFileSizeKb():?int{return $this->fileSizeKb;} public function setFileSizeKb(?int $n):static{$this->fileSizeKb=$n;return $this;}
    public function getDurationSec():?int{return $this->durationSec;} public function setDurationSec(?int $n):static{$this->durationSec=$n;return $this;}
    public function getSortOrder():int{return $this->sortOrder;} public function setSortOrder(int $n):static{$this->sortOrder=$n;return $this;}
    public function isFeatured():bool{return $this->isFeatured;} public function setIsFeatured(bool $v):static{$this->isFeatured=$v;return $this;}
    public function getUploadedBy():?User{return $this->uploadedBy;} public function setUploadedBy(?User $u):static{$this->uploadedBy=$u;return $this;}
    public function getCreatedAt():\DateTimeInterface{return $this->createdAt;}

    private static function generateUuid(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0,0xffff), mt_rand(0,0xffff),
            mt_rand(0,0xffff),
            mt_rand(0,0x0fff)|0x4000,
            mt_rand(0,0x3fff)|0x8000,
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff)
        );
    }
}
