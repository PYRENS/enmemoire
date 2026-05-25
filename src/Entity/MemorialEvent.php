<?php

namespace App\Entity;

use App\Repository\MemorialEventRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MemorialEventRepository::class)]
#[ORM\Table(name: 'memorial_events')]
#[ORM\HasLifecycleCallbacks]
class MemorialEvent
{
    public const TYPE_FUNERAL        = 'funeral';
    public const TYPE_COMMEMORATION  = 'commemoration';
    public const TYPE_ANNIVERSARY    = 'anniversary_mass';
    public const TYPE_RECEPTION      = 'reception';
    public const TYPE_OTHER          = 'other';

    public const STATUS_UPCOMING  = 'upcoming';
    public const STATUS_LIVE      = 'live';
    public const STATUS_ENDED     = 'ended';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::BIGINT,  options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: MemorialPage::class, inversedBy: 'events')]
    #[ORM\JoinColumn(name: 'memorial_id', nullable: false, onDelete: 'CASCADE')]
    private ?MemorialPage $memorial = null;

    #[ORM\Column(length: 30)]
    private string $type = self::TYPE_FUNERAL;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $customType = null;

    #[ORM\Column(length: 255)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $eventDate;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $locationName = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $locationAddress = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $liveUrl = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $coverImageUrl = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $programText = null;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_UPCOMING])]
    private string $status = self::STATUS_UPCOMING;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0, 'unsigned' => true])]
    private int $visitCount = 0;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by', nullable: false)]
    private ?User $createdBy = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    #[ORM\OneToMany(targetEntity: MediaGallery::class, mappedBy: 'event')]
    private Collection $mediaGalleries;

    #[ORM\OneToMany(targetEntity: Condolence::class, mappedBy: 'event')]
    private Collection $condolences;

    public function __construct()
    {
        $this->uuid          = Uuid::v4();
        $this->eventDate     = new \DateTime('+1 week');
        $this->createdAt     = new \DateTime();
        $this->updatedAt     = new \DateTime();
        $this->mediaGalleries = new ArrayCollection();
        $this->condolences   = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if (empty((string) $this->uuid)) {
            $this->uuid = Uuid::v4();
        }
        $this->createdAt = $this->createdAt ?? new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void { $this->updatedAt = new \DateTime(); }

    public function isLive(): bool { return $this->status === self::STATUS_LIVE; }
    public function isUpcoming(): bool { return $this->status === self::STATUS_UPCOMING; }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): Uuid { return $this->uuid; }
    public function getMemorial(): ?MemorialPage { return $this->memorial; }
    public function setMemorial(?MemorialPage $m): static { $this->memorial = $m; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $t): static { $this->type = $t; return $this; }
    public function getCustomType(): ?string { return $this->customType; }
    public function setCustomType(?string $t): static { $this->customType = $t; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $t): static { $this->title = $t; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): static { $this->description = $d; return $this; }
    public function getEventDate(): \DateTimeInterface { return $this->eventDate; }
    public function setEventDate(\DateTimeInterface $d): static { $this->eventDate = $d; return $this; }
    public function getLocationName(): ?string { return $this->locationName; }
    public function setLocationName(?string $n): static { $this->locationName = $n; return $this; }
    public function getLocationAddress(): ?string { return $this->locationAddress; }
    public function setLocationAddress(?string $a): static { $this->locationAddress = $a; return $this; }
    public function getLiveUrl(): ?string { return $this->liveUrl; }
    public function setLiveUrl(?string $u): static { $this->liveUrl = $u; return $this; }
    public function getCoverImageUrl(): ?string { return $this->coverImageUrl; }
    public function setCoverImageUrl(?string $u): static { $this->coverImageUrl = $u; return $this; }
    public function getProgramText(): ?string { return $this->programText; }
    public function setProgramText(?string $t): static { $this->programText = $t; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): static { $this->status = $s; return $this; }
    public function getVisitCount(): int { return $this->visitCount; }
    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $u): static { $this->createdBy = $u; return $this; }
    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeInterface { return $this->updatedAt; }
    public function getMediaGalleries(): Collection { return $this->mediaGalleries; }
    public function getCondolences(): Collection { return $this->condolences; }
}
