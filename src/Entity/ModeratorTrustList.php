<?php

namespace App\Entity;

use App\Repository\ModeratorTrustListRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ModeratorTrustListRepository::class)]
#[ORM\Table(name: 'moderator_trust_list')]
#[ORM\UniqueConstraint(name: 'uq_trust', columns: ['moderator_id', 'trusted_user_id'])]
class ModeratorTrustList
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::BIGINT,  options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MemorialModerator::class, inversedBy: 'trustList')]
    #[ORM\JoinColumn(name: 'moderator_id', nullable: false, onDelete: 'CASCADE')]
    private ?MemorialModerator $moderator = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'trusted_user_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $trustedUser = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $addedAt;

    public function __construct() { $this->addedAt = new \DateTime(); }

    public function getId(): ?int { return $this->id; }
    public function getModerator(): ?MemorialModerator { return $this->moderator; }
    public function setModerator(?MemorialModerator $m): static { $this->moderator = $m; return $this; }
    public function getTrustedUser(): ?User { return $this->trustedUser; }
    public function setTrustedUser(?User $u): static { $this->trustedUser = $u; return $this; }
    public function getAddedAt(): \DateTimeInterface { return $this->addedAt; }
}
