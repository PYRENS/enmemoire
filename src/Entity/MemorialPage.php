<?php

namespace App\Entity;

use App\Repository\MemorialPageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MemorialPageRepository::class)]
#[ORM\Table(name: 'memorial_pages')]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(name: 'idx_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_status', columns: ['status'])]
#[ORM\Index(name: 'idx_deceased_name', columns: ['deceased_last_name', 'deceased_first_name'])]
class MemorialPage
{
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_EXPIRED   = 'expired';
    public const STATUS_ARCHIVED  = 'archived';

    public const VISIBILITY_PUBLIC  = 'public';
    public const VISIBILITY_PRIVATE = 'private';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT,  options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    /** Code court affiché sur la page (ex: MEM-X7K2P) */
    #[ORM\Column(length: 12, unique: true)]
    private string $pageCode = '';

    #[ORM\Column(length: 100)]
    private string $deceasedFirstName = '';

    #[ORM\Column(length: 100)]
    private string $deceasedLastName = '';

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $deceasedNickname = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private \DateTimeInterface $deceasedBirthDate;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private \DateTimeInterface $deceasedDeathDate;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $deceasedBirthPlace = null;

    #[ORM\Column(length: 200)]
    private string $deceasedDeathPlace = '';

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $deceasedProfession = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $deceasedQuote = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $mainPhotoUrl = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $obituaryText = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $biographyText = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $thankYouMessage = null;

    #[ORM\ManyToOne(targetEntity: MemorialFormula::class, inversedBy: 'memorialPages')]
    #[ORM\JoinColumn(name: 'formula_id', nullable: false)]
    private ?MemorialFormula $formula = null;

    #[ORM\ManyToOne(targetEntity: MemorialTheme::class, inversedBy: 'memorialPages')]
    #[ORM\JoinColumn(name: 'theme_id', nullable: false)]
    private ?MemorialTheme $theme = null;

    #[ORM\Column(length: 20, options: ['default' => self::VISIBILITY_PUBLIC])]
    private string $visibility = self::VISIBILITY_PUBLIC;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_ACTIVE])]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $qrCodeUrl = null;

    /** URL-friendly : olga-mumbere-2025 */
    #[ORM\Column(length: 255, unique: true)]
    private string $slug = '';

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $expiresAt = null;

    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0, 'unsigned' => true])]
    private int $visitCount = 0;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'memorialPages')]
    #[ORM\JoinColumn(name: 'created_by', nullable: false)]
    private ?User $createdBy = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    // Relations
    #[ORM\OneToMany(targetEntity: MemorialModerator::class, mappedBy: 'memorial', cascade: ['remove'])]
    private Collection $moderators;

    #[ORM\OneToMany(targetEntity: MemorialEvent::class, mappedBy: 'memorial', cascade: ['remove'])]
    #[ORM\OrderBy(['eventDate' => 'ASC'])]
    private Collection $events;

    #[ORM\OneToMany(targetEntity: LifeTimeline::class, mappedBy: 'memorial', cascade: ['remove'])]
    #[ORM\OrderBy(['eventDate' => 'ASC'])]
    private Collection $lifeTimelines;

    #[ORM\OneToMany(targetEntity: MediaGallery::class, mappedBy: 'memorial', cascade: ['remove'])]
    private Collection $mediaGalleries;

    #[ORM\OneToMany(targetEntity: Condolence::class, mappedBy: 'memorial', cascade: ['remove'])]
    private Collection $condolences;

    #[ORM\OneToMany(targetEntity: Testimonial::class, mappedBy: 'memorial', cascade: ['remove'])]
    private Collection $testimonials;

    #[ORM\OneToMany(targetEntity: GuestBook::class, mappedBy: 'memorial', cascade: ['remove'])]
    private Collection $guestBooks;

    #[ORM\OneToMany(targetEntity: Announcement::class, mappedBy: 'memorial', cascade: ['remove'])]
    private Collection $announcements;

    #[ORM\OneToMany(targetEntity: FamilyConnection::class, mappedBy: 'memorialFrom', cascade: ['remove'])]
    private Collection $connectionsSent;

    #[ORM\OneToMany(targetEntity: FamilyConnection::class, mappedBy: 'memorialTo', cascade: ['remove'])]
    private Collection $connectionsReceived;

    public function __construct()
    {
        $this->uuid               = Uuid::v4();
        $this->createdAt          = new \DateTime();
        $this->updatedAt          = new \DateTime();
        $this->moderators         = new ArrayCollection();
        $this->events             = new ArrayCollection();
        $this->lifeTimelines      = new ArrayCollection();
        $this->mediaGalleries     = new ArrayCollection();
        $this->condolences        = new ArrayCollection();
        $this->testimonials       = new ArrayCollection();
        $this->guestBooks         = new ArrayCollection();
        $this->announcements      = new ArrayCollection();
        $this->connectionsSent    = new ArrayCollection();
        $this->connectionsReceived = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void { $this->updatedAt = new \DateTime(); }

    public function getDeceasedFullName(): string
    {
        return trim($this->deceasedFirstName . ' ' . $this->deceasedLastName);
    }

    public function getAge(): ?int
    {
        if (!isset($this->deceasedBirthDate, $this->deceasedDeathDate)) return null;
        return $this->deceasedBirthDate->diff($this->deceasedDeathDate)->y;
    }

    public function isExpired(): bool
    {
        if ($this->expiresAt === null) return false;
        return $this->expiresAt < new \DateTime();
    }

    public function incrementVisitCount(): static { $this->visitCount++; return $this; }

    // Getters / Setters
    public function getId(): ?int { return $this->id; }
    public function getUuid(): Uuid { return $this->uuid; }
    public function getPageCode(): string { return $this->pageCode; }
    public function setPageCode(string $c): static { $this->pageCode = $c; return $this; }
    public function getDeceasedFirstName(): string { return $this->deceasedFirstName; }
    public function setDeceasedFirstName(string $n): static { $this->deceasedFirstName = $n; return $this; }
    public function getDeceasedLastName(): string { return $this->deceasedLastName; }
    public function setDeceasedLastName(string $n): static { $this->deceasedLastName = $n; return $this; }
    public function getDeceasedNickname(): ?string { return $this->deceasedNickname; }
    public function setDeceasedNickname(?string $n): static { $this->deceasedNickname = $n; return $this; }
    public function getDeceasedBirthDate(): \DateTimeInterface { return $this->deceasedBirthDate; }
    public function setDeceasedBirthDate(\DateTimeInterface $d): static { $this->deceasedBirthDate = $d; return $this; }
    public function getDeceasedDeathDate(): \DateTimeInterface { return $this->deceasedDeathDate; }
    public function setDeceasedDeathDate(\DateTimeInterface $d): static { $this->deceasedDeathDate = $d; return $this; }
    public function getDeceasedBirthPlace(): ?string { return $this->deceasedBirthPlace; }
    public function setDeceasedBirthPlace(?string $p): static { $this->deceasedBirthPlace = $p; return $this; }
    public function getDeceasedDeathPlace(): string { return $this->deceasedDeathPlace; }
    public function setDeceasedDeathPlace(string $p): static { $this->deceasedDeathPlace = $p; return $this; }
    public function getDeceasedProfession(): ?string { return $this->deceasedProfession; }
    public function setDeceasedProfession(?string $p): static { $this->deceasedProfession = $p; return $this; }
    public function getDeceasedQuote(): ?string { return $this->deceasedQuote; }
    public function setDeceasedQuote(?string $q): static { $this->deceasedQuote = $q; return $this; }
    public function getMainPhotoUrl(): ?string { return $this->mainPhotoUrl; }
    public function setMainPhotoUrl(?string $u): static { $this->mainPhotoUrl = $u; return $this; }
    public function getObituaryText(): ?string { return $this->obituaryText; }
    public function setObituaryText(?string $t): static { $this->obituaryText = $t; return $this; }
    public function getBiographyText(): ?string { return $this->biographyText; }
    public function setBiographyText(?string $t): static { $this->biographyText = $t; return $this; }
    public function getThankYouMessage(): ?string { return $this->thankYouMessage; }
    public function setThankYouMessage(?string $t): static { $this->thankYouMessage = $t; return $this; }
    public function getFormula(): ?MemorialFormula { return $this->formula; }
    public function setFormula(?MemorialFormula $f): static { $this->formula = $f; return $this; }
    public function getTheme(): ?MemorialTheme { return $this->theme; }
    public function setTheme(?MemorialTheme $t): static { $this->theme = $t; return $this; }
    public function getVisibility(): string { return $this->visibility; }
    public function setVisibility(string $v): static { $this->visibility = $v; return $this; }
    public function isPublic(): bool { return $this->visibility === self::VISIBILITY_PUBLIC; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): static { $this->status = $s; return $this; }
    public function getQrCodeUrl(): ?string { return $this->qrCodeUrl; }
    public function setQrCodeUrl(?string $u): static { $this->qrCodeUrl = $u; return $this; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $s): static { $this->slug = $s; return $this; }
    public function getExpiresAt(): ?\DateTimeInterface { return $this->expiresAt; }
    public function setExpiresAt(?\DateTimeInterface $d): static { $this->expiresAt = $d; return $this; }
    public function getVisitCount(): int { return $this->visitCount; }
    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $u): static { $this->createdBy = $u; return $this; }
    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeInterface { return $this->updatedAt; }
    public function getModerators(): Collection { return $this->moderators; }
    public function getEvents(): Collection { return $this->events; }
    public function getLifeTimelines(): Collection { return $this->lifeTimelines; }
    public function getMediaGalleries(): Collection { return $this->mediaGalleries; }
    public function getCondolences(): Collection { return $this->condolences; }
    public function getTestimonials(): Collection { return $this->testimonials; }
    public function getGuestBooks(): Collection { return $this->guestBooks; }
    public function getAnnouncements(): Collection { return $this->announcements; }
    public function getConnectionsSent(): Collection { return $this->connectionsSent; }
    public function getConnectionsReceived(): Collection { return $this->connectionsReceived; }
}
