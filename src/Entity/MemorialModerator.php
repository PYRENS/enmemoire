<?php
// =============================================
// src/Entity/MemorialModerator.php
// =============================================
namespace App\Entity;

use App\Repository\MemorialModeratorRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MemorialModeratorRepository::class)]
#[ORM\Table(name: 'memorial_moderators')]
#[ORM\UniqueConstraint(name: 'uq_memorial_user', columns: ['memorial_id', 'user_id'])]
class MemorialModerator
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_REVOKED  = 'revoked';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT,  options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MemorialPage::class, inversedBy: 'moderators')]
    #[ORM\JoinColumn(name: 'memorial_id', nullable: false, onDelete: 'CASCADE')]
    private ?MemorialPage $memorial = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'moderatorRoles')]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /** Code affiché sur la page pour le système de rapprochement */
    #[ORM\Column(length: 12, unique: true)]
    private string $moderatorCode = '';

    #[ORM\Column(options: ['default' => false])]
    private bool $isOwner = false;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'invited_by', nullable: true)]
    private ?User $invitedBy = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $invitedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $acceptedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\OneToMany(targetEntity: ModeratorTrustList::class, mappedBy: 'moderator', cascade: ['remove'])]
    private Collection $trustList;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->trustList = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getMemorial(): ?MemorialPage { return $this->memorial; }
    public function setMemorial(?MemorialPage $m): static { $this->memorial = $m; return $this; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $u): static { $this->user = $u; return $this; }
    public function getModeratorCode(): string { return $this->moderatorCode; }
    public function setModeratorCode(string $c): static { $this->moderatorCode = $c; return $this; }
    public function isOwner(): bool { return $this->isOwner; }
    public function setIsOwner(bool $v): static { $this->isOwner = $v; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): static { $this->status = $s; return $this; }
    public function isActive(): bool { return $this->status === self::STATUS_ACTIVE; }
    public function getInvitedBy(): ?User { return $this->invitedBy; }
    public function setInvitedBy(?User $u): static { $this->invitedBy = $u; return $this; }
    public function getInvitedAt(): ?\DateTimeInterface { return $this->invitedAt; }
    public function setInvitedAt(?\DateTimeInterface $d): static { $this->invitedAt = $d; return $this; }
    public function getAcceptedAt(): ?\DateTimeInterface { return $this->acceptedAt; }
    public function setAcceptedAt(?\DateTimeInterface $d): static { $this->acceptedAt = $d; return $this; }
    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function getTrustList(): Collection { return $this->trustList; }
}
