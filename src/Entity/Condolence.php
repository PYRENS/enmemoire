<?php

namespace App\Entity;

use App\Repository\CondolenceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CondolenceRepository::class)]
#[ORM\Table(name: 'condolences')]
#[ORM\Index(name: 'idx_memorial_status', columns: ['memorial_id', 'status'])]
class Condolence
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::BIGINT,  options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MemorialPage::class, inversedBy: 'condolences')]
    #[ORM\JoinColumn(name: 'memorial_id', nullable: false, onDelete: 'CASCADE')]
    private ?MemorialPage $memorial = null;

    #[ORM\ManyToOne(targetEntity: MemorialEvent::class)]
    #[ORM\JoinColumn(name: 'event_id', nullable: true, onDelete: 'SET NULL')]
    private ?MemorialEvent $event = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false)]
    private ?User $user = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $message = '';

    #[ORM\Column(options: ['default' => false])]
    private bool $isAnonymous = false;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'moderated_by', nullable: true)]
    private ?User $moderatedBy = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $moderatedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    public function __construct() { $this->createdAt = new \DateTime(); }

    public function getId(): ?int { return $this->id; }
    public function getMemorial(): ?MemorialPage { return $this->memorial; }
    public function setMemorial(?MemorialPage $m): static { $this->memorial = $m; return $this; }
    public function getEvent(): ?MemorialEvent { return $this->event; }
    public function setEvent(?MemorialEvent $e): static { $this->event = $e; return $this; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $u): static { $this->user = $u; return $this; }
    public function getMessage(): string { return $this->message; }
    public function setMessage(string $m): static { $this->message = $m; return $this; }
    public function isAnonymous(): bool { return $this->isAnonymous; }
    public function setIsAnonymous(bool $v): static { $this->isAnonymous = $v; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): static { $this->status = $s; return $this; }
    public function isApproved(): bool { return $this->status === self::STATUS_APPROVED; }
    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }
    public function getModeratedBy(): ?User { return $this->moderatedBy; }
    public function setModeratedBy(?User $u): static { $this->moderatedBy = $u; return $this; }
    public function getModeratedAt(): ?\DateTimeInterface { return $this->moderatedAt; }
    public function setModeratedAt(?\DateTimeInterface $d): static { $this->moderatedAt = $d; return $this; }
    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
}
