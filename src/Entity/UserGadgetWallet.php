<?php
// =========================================================
// src/Entity/UserGadgetWallet.php
// =========================================================
namespace App\Entity;

use App\Repository\UserGadgetWalletRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserGadgetWalletRepository::class)]
#[ORM\Table(name: 'user_gadget_wallet')]
#[ORM\UniqueConstraint(name: 'uq_user_gadget', columns: ['user_id', 'gadget_id'])]
class UserGadgetWallet
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::BIGINT,  options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: GadgetCatalog::class)]
    #[ORM\JoinColumn(name: 'gadget_id', nullable: false)]
    private ?GadgetCatalog $gadget = null;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true, 'default' => 0])]
    private int $quantity = 0;

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $u): static { $this->user = $u; return $this; }
    public function getGadget(): ?GadgetCatalog { return $this->gadget; }
    public function setGadget(?GadgetCatalog $g): static { $this->gadget = $g; return $this; }
    public function getQuantity(): int { return $this->quantity; }
    public function setQuantity(int $q): static { $this->quantity = $q; return $this; }
}
