<?php

namespace App\Entity;

use App\Repository\MemorialFormulaRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MemorialFormulaRepository::class)]
#[ORM\Table(name: 'memorial_formulas')]
class MemorialFormula
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER,  options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 80, unique: true)]
    private string $slug = '';

    #[ORM\Column(length: 100)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $price = '0.00';

    #[ORM\Column(length: 3, options: ['default' => 'USD'])]
    private string $currency = 'USD';

    /** NULL = perpétuel */
    #[ORM\Column(type: Types::SMALLINT, nullable: true, options: ['unsigned' => true])]
    private ?int $durationYears = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 1, 'unsigned' => true])]
    private int $maxEvents = 1;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 1, 'unsigned' => true])]
    private int $maxMediaGb = 1;

    #[ORM\Column(options: ['default' => false])]
    private bool $hasLive = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $hasPremiumThemes = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $hasQrCode = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $hasVideo = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $hasAdvancedStats = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    #[ORM\OneToMany(targetEntity: MemorialPage::class, mappedBy: 'formula')]
    private Collection $memorialPages;

    public function __construct()
    {
        $this->createdAt    = new \DateTime();
        $this->updatedAt    = new \DateTime();
        $this->memorialPages = new ArrayCollection();
    }

    public function isPerpetual(): bool { return $this->durationYears === null; }

    public function getId(): ?int { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $s): static { $this->slug = $s; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $n): static { $this->name = $n; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): static { $this->description = $d; return $this; }
    public function getPrice(): string { return $this->price; }
    public function setPrice(string $p): static { $this->price = $p; return $this; }
    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $c): static { $this->currency = $c; return $this; }
    public function getDurationYears(): ?int { return $this->durationYears; }
    public function setDurationYears(?int $y): static { $this->durationYears = $y; return $this; }
    public function getMaxEvents(): int { return $this->maxEvents; }
    public function setMaxEvents(int $n): static { $this->maxEvents = $n; return $this; }
    public function getMaxMediaGb(): int { return $this->maxMediaGb; }
    public function setMaxMediaGb(int $n): static { $this->maxMediaGb = $n; return $this; }
    public function isHasLive(): bool { return $this->hasLive; }
    public function setHasLive(bool $v): static { $this->hasLive = $v; return $this; }
    public function isHasPremiumThemes(): bool { return $this->hasPremiumThemes; }
    public function setHasPremiumThemes(bool $v): static { $this->hasPremiumThemes = $v; return $this; }
    public function isHasQrCode(): bool { return $this->hasQrCode; }
    public function setHasQrCode(bool $v): static { $this->hasQrCode = $v; return $this; }
    public function isHasVideo(): bool { return $this->hasVideo; }
    public function setHasVideo(bool $v): static { $this->hasVideo = $v; return $this; }
    public function isHasAdvancedStats(): bool { return $this->hasAdvancedStats; }
    public function setHasAdvancedStats(bool $v): static { $this->hasAdvancedStats = $v; return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $n): static { $this->sortOrder = $n; return $this; }
    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeInterface { return $this->updatedAt; }
    public function getMemorialPages(): Collection { return $this->memorialPages; }
}
