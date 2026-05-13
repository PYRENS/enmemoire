<?php

namespace App\Entity;

use App\Repository\LifeTimelineRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LifeTimelineRepository::class)]
#[ORM\Table(name: 'life_timeline')]
class LifeTimeline
{
    public const PRECISION_DAY   = 'day';
    public const PRECISION_MONTH = 'month';
    public const PRECISION_YEAR  = 'year';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::BIGINT,  options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MemorialPage::class, inversedBy: 'lifeTimelines')]
    #[ORM\JoinColumn(name: 'memorial_id', nullable: false, onDelete: 'CASCADE')]
    private ?MemorialPage $memorial = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private \DateTimeInterface $eventDate;

    #[ORM\Column(length: 10, options: ['default' => self::PRECISION_DAY])]
    private string $eventDatePrecision = self::PRECISION_DAY;

    #[ORM\Column(length: 255)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $mediaUrl = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by', nullable: false)]
    private ?User $createdBy = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->eventDate = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }
    public function getMemorial(): ?MemorialPage { return $this->memorial; }
    public function setMemorial(?MemorialPage $m): static { $this->memorial = $m; return $this; }
    public function getEventDate(): \DateTimeInterface { return $this->eventDate; }
    public function setEventDate(\DateTimeInterface $d): static { $this->eventDate = $d; return $this; }
    public function getEventDatePrecision(): string { return $this->eventDatePrecision; }
    public function setEventDatePrecision(string $p): static { $this->eventDatePrecision = $p; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $t): static { $this->title = $t; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): static { $this->description = $d; return $this; }
    public function getMediaUrl(): ?string { return $this->mediaUrl; }
    public function setMediaUrl(?string $u): static { $this->mediaUrl = $u; return $this; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $n): static { $this->sortOrder = $n; return $this; }
    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $u): static { $this->createdBy = $u; return $this; }
    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeInterface { return $this->updatedAt; }
}
