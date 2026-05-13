<?php

namespace App\Entity;

use App\Repository\MemorialThemeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MemorialThemeRepository::class)]
#[ORM\Table(name: 'memorial_themes')]
class MemorialTheme
{
    public const TYPE_FREE    = 'free';
    public const TYPE_PAID    = 'paid';
    public const TYPE_SPECIAL = 'special';

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

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $previewUrl = null;

    /** Classe CSS racine du thème (ex: theme-classic-white) */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $cssClass = null;

    #[ORM\Column(length: 20, options: ['default' => self::TYPE_FREE])]
    private string $type = self::TYPE_FREE;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $price = '0.00';

    #[ORM\Column(length: 3, options: ['default' => 'USD'])]
    private string $currency = 'USD';

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    #[ORM\OneToMany(targetEntity: MemorialPage::class, mappedBy: 'theme')]
    private Collection $memorialPages;

    public function __construct()
    {
        $this->createdAt   = new \DateTime();
        $this->updatedAt   = new \DateTime();
        $this->memorialPages = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): static { $this->slug = $slug; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): static { $this->description = $d; return $this; }
    public function getPreviewUrl(): ?string { return $this->previewUrl; }
    public function setPreviewUrl(?string $url): static { $this->previewUrl = $url; return $this; }
    public function getCssClass(): ?string { return $this->cssClass; }
    public function setCssClass(?string $c): static { $this->cssClass = $c; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }
    public function isFree(): bool { return $this->type === self::TYPE_FREE; }
    public function isPaid(): bool { return $this->type === self::TYPE_PAID; }
    public function isSpecial(): bool { return $this->type === self::TYPE_SPECIAL; }
    public function getPrice(): string { return $this->price; }
    public function setPrice(string $price): static { $this->price = $price; return $this; }
    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $c): static { $this->currency = $c; return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $n): static { $this->sortOrder = $n; return $this; }
    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeInterface { return $this->updatedAt; }
    public function getMemorialPages(): Collection { return $this->memorialPages; }
}
