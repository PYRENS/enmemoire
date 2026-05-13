<?php

namespace App\Entity;

use App\Repository\FamilyConnectionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FamilyConnectionRepository::class)]
#[ORM\Table(name: 'family_connections')]
#[ORM\UniqueConstraint(name: 'uq_connection', columns: ['memorial_from_id', 'memorial_to_id'])]
class FamilyConnection
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_ACCEPTED  = 'accepted';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::BIGINT,  options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MemorialPage::class, inversedBy: 'connectionsSent')]
    #[ORM\JoinColumn(name: 'memorial_from_id', nullable: false, onDelete: 'CASCADE')]
    private ?MemorialPage $memorialFrom = null;

    #[ORM\ManyToOne(targetEntity: MemorialPage::class, inversedBy: 'connectionsReceived')]
    #[ORM\JoinColumn(name: 'memorial_to_id', nullable: false, onDelete: 'CASCADE')]
    private ?MemorialPage $memorialTo = null;

    /** Relation vue depuis la page FROM (ex: Fille) */
    #[ORM\Column(length: 100)]
    private string $relationFrom = '';

    /** Relation vue depuis la page TO (ex: Père) */
    #[ORM\Column(length: 100)]
    private string $relationTo = '';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'requested_by', nullable: false)]
    private ?User $requestedBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'confirmed_by', nullable: true)]
    private ?User $confirmedBy = null;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $requestedAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $respondedAt = null;

    public function __construct() { $this->requestedAt = new \DateTime(); }

    public function getId(): ?int { return $this->id; }
    public function getMemorialFrom(): ?MemorialPage { return $this->memorialFrom; }
    public function setMemorialFrom(?MemorialPage $m): static { $this->memorialFrom = $m; return $this; }
    public function getMemorialTo(): ?MemorialPage { return $this->memorialTo; }
    public function setMemorialTo(?MemorialPage $m): static { $this->memorialTo = $m; return $this; }
    public function getRelationFrom(): string { return $this->relationFrom; }
    public function setRelationFrom(string $r): static { $this->relationFrom = $r; return $this; }
    public function getRelationTo(): string { return $this->relationTo; }
    public function setRelationTo(string $r): static { $this->relationTo = $r; return $this; }
    public function getRequestedBy(): ?User { return $this->requestedBy; }
    public function setRequestedBy(?User $u): static { $this->requestedBy = $u; return $this; }
    public function getConfirmedBy(): ?User { return $this->confirmedBy; }
    public function setConfirmedBy(?User $u): static { $this->confirmedBy = $u; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): static { $this->status = $s; return $this; }
    public function isAccepted(): bool { return $this->status === self::STATUS_ACCEPTED; }
    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }
    public function getRequestedAt(): \DateTimeInterface { return $this->requestedAt; }
    public function getRespondedAt(): ?\DateTimeInterface { return $this->respondedAt; }
    public function setRespondedAt(?\DateTimeInterface $d): static { $this->respondedAt = $d; return $this; }
}
