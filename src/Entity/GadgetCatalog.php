<?php

namespace App\Entity;

use App\Repository\GadgetCatalogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GadgetCatalogRepository::class)]
#[ORM\Table(name: 'gadget_catalog')]
#[ORM\HasLifecycleCallbacks]
class GadgetCatalog
{
    public const TYPE_FLOWER = 'flower';
    public const TYPE_CANDLE = 'candle';
    public const TYPE_DOVE   = 'dove';
    public const TYPE_OTHER  = 'other';

    public const STATUS_ACTIVE    = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_ARCHIVED  = 'archived';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::INTEGER,  options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 80, unique: true)]
    private string $slug = '';

    #[ORM\Column(length: 20)]
    private string $type = self::TYPE_FLOWER;

    #[ORM\Column(length: 100)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $imageUrl = null;

    /** URL du fichier d'animation (Lottie JSON, GIF, CSS) */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $animationUrl = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $price = '0.00';

    #[ORM\Column(length: 3, options: ['default' => 'USD'])]
    private string $currency = 'USD';

    #[ORM\Column(options: ['default' => false])]
    private bool $allowsCustomText = false;

    #[ORM\Column(type: Types::SMALLINT, nullable: true, options: ['unsigned' => true])]
    private ?int $maxTextLength = null;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_ACTIVE])]
    private string $status = self::STATUS_ACTIVE;

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
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void { $this->updatedAt = new \DateTime(); }

    public function getId(): ?int { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $s): static { $this->slug = $s; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $t): static { $this->type = $t; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $n): static { $this->name = $n; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): static { $this->description = $d; return $this; }
    public function getImageUrl(): ?string { return $this->imageUrl; }
    public function setImageUrl(?string $u): static { $this->imageUrl = $u; return $this; }
    public function getAnimationUrl(): ?string { return $this->animationUrl; }
    public function setAnimationUrl(?string $u): static { $this->animationUrl = $u; return $this; }
    public function getPrice(): string { return $this->price; }
    public function setPrice(string $p): static { $this->price = $p; return $this; }
    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $c): static { $this->currency = $c; return $this; }
    public function isAllowsCustomText(): bool { return $this->allowsCustomText; }
    public function setAllowsCustomText(bool $v): static { $this->allowsCustomText = $v; return $this; }
    public function getMaxTextLength(): ?int { return $this->maxTextLength; }
    public function setMaxTextLength(?int $n): static { $this->maxTextLength = $n; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): static { $this->status = $s; return $this; }
    public function isActive(): bool { return $this->status === self::STATUS_ACTIVE; }
    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $u): static { $this->createdBy = $u; return $this; }
    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeInterface { return $this->updatedAt; }
}
