<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['email'], message: 'Cette adresse email est déjà utilisée.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE_USER        = 'ROLE_USER';
    public const ROLE_MAKER       = 'ROLE_MAKER';
    public const ROLE_MANAGER     = 'ROLE_MANAGER';
    public const ROLE_ADMIN       = 'ROLE_ADMIN';
    public const ROLE_SUPER_ADMIN = 'ROLE_SUPER_ADMIN';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_DISABLED  = 'disabled';

    public const LOCALE_FR = 'fr';
    public const LOCALE_EN = 'en';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT,  options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 36, unique: true)]
    private string $uuid = '';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $firstName = '';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $lastName = '';

    #[ORM\Column(length: 191, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $email = '';

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $phoneWhatsapp = null;

    #[ORM\Column(length: 255)]
    private string $passwordHash = '';

    #[ORM\Column(length: 30, options: ['default' => self::ROLE_USER])]
    private string $role = self::ROLE_USER;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $avatarUrl = null;

    #[ORM\Column(length: 5, options: ['default' => self::LOCALE_FR])]
    private string $locale = self::LOCALE_FR;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $otpCode = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $otpExpiresAt = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0, 'unsigned' => true])]
    private int $otpAttempts = 0;

    #[ORM\Column(options: ['default' => false])]
    private bool $emailVerified = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $phoneVerified = false;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $rememberToken = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastLoginAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $deletedAt = null;

    // Relations
    #[ORM\OneToMany(targetEntity: MemorialPage::class, mappedBy: 'createdBy')]
    private Collection $memorialPages;

    #[ORM\OneToMany(targetEntity: MemorialModerator::class, mappedBy: 'user')]
    private Collection $moderatorRoles;

    #[ORM\OneToMany(targetEntity: Notification::class, mappedBy: 'user', cascade: ['remove'])]
    private Collection $notifications;
    private static function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    

    public function __construct()
    {
        $this->uuid         = self::generateUuid();
        $this->createdAt    = new \DateTime();
        $this->updatedAt    = new \DateTime();
        $this->memorialPages  = new ArrayCollection();
        $this->moderatorRoles = new ArrayCollection();
        $this->notifications  = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTime();
    }

    // --- UserInterface ---
    public function getUserIdentifier(): string { return $this->email; }
    public function getRoles(): array { return [$this->role, self::ROLE_USER]; }
    public function getPassword(): string { return $this->passwordHash; }
    public function eraseCredentials(): void {}

    public function getFullName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }

    public function isActive(): bool { return $this->status === self::STATUS_ACTIVE; }
    public function isSuspended(): bool { return $this->status === self::STATUS_SUSPENDED; }

    // --- Getters / Setters ---
    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }

    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $firstName): static { $this->firstName = $firstName; return $this; }

    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $lastName): static { $this->lastName = $lastName; return $this; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): static { $this->email = $email; return $this; }

    public function getPhoneWhatsapp(): ?string { return $this->phoneWhatsapp; }
    public function setPhoneWhatsapp(?string $phone): static { $this->phoneWhatsapp = $phone; return $this; }

    public function getPasswordHash(): string { return $this->passwordHash; }
    public function setPasswordHash(string $hash): static { $this->passwordHash = $hash; return $this; }

    public function getRole(): string { return $this->role; }
    public function setRole(string $role): static { $this->role = $role; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getAvatarUrl(): ?string { return $this->avatarUrl; }
    public function setAvatarUrl(?string $url): static { $this->avatarUrl = $url; return $this; }

    public function getLocale(): string { return $this->locale; }
    public function setLocale(string $locale): static { $this->locale = $locale; return $this; }

    public function getOtpCode(): ?string { return $this->otpCode; }
    public function setOtpCode(?string $code): static { $this->otpCode = $code; return $this; }

    public function getOtpExpiresAt(): ?\DateTimeInterface { return $this->otpExpiresAt; }
    public function setOtpExpiresAt(?\DateTimeInterface $dt): static { $this->otpExpiresAt = $dt; return $this; }

    public function getOtpAttempts(): int { return $this->otpAttempts; }
    public function setOtpAttempts(int $n): static { $this->otpAttempts = $n; return $this; }
    public function incrementOtpAttempts(): static { $this->otpAttempts++; return $this; }
    public function resetOtp(): static { $this->otpCode = null; $this->otpExpiresAt = null; $this->otpAttempts = 0; return $this; }

    public function isEmailVerified(): bool { return $this->emailVerified; }
    public function setEmailVerified(bool $v): static { $this->emailVerified = $v; return $this; }

    public function isPhoneVerified(): bool { return $this->phoneVerified; }
    public function setPhoneVerified(bool $v): static { $this->phoneVerified = $v; return $this; }

    public function getRememberToken(): ?string { return $this->rememberToken; }
    public function setRememberToken(?string $t): static { $this->rememberToken = $t; return $this; }

    public function getLastLoginAt(): ?\DateTimeInterface { return $this->lastLoginAt; }
    public function setLastLoginAt(?\DateTimeInterface $dt): static { $this->lastLoginAt = $dt; return $this; }

    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeInterface { return $this->updatedAt; }

    public function getDeletedAt(): ?\DateTimeInterface { return $this->deletedAt; }
    public function setDeletedAt(?\DateTimeInterface $dt): static { $this->deletedAt = $dt; return $this; }

    public function getMemorialPages(): Collection { return $this->memorialPages; }
    public function getModeratorRoles(): Collection { return $this->moderatorRoles; }
    public function getNotifications(): Collection { return $this->notifications; }
}
