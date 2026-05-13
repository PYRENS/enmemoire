<?php

namespace App\Security\Voter;

use App\Entity\MemorialPage;
use App\Entity\User;
use App\Service\MemorialPageService;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class MemorialPageVoter extends Voter
{
    public const VIEW     = 'MEMORIAL_VIEW';
    public const MODERATE = 'MEMORIAL_MODERATE';
    public const MANAGE   = 'MEMORIAL_MANAGE';
    public const OWN      = 'MEMORIAL_OWN';
    public const INTERACT = 'MEMORIAL_INTERACT';

    public function __construct(
        private readonly MemorialPageService $memorialService,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [
            self::VIEW, self::MODERATE, self::MANAGE, self::OWN, self::INTERACT,
        ]) && $subject instanceof MemorialPage;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        /** @var MemorialPage $page */
        $page = $subject;
        $user = $token->getUser();

        return match ($attribute) {

            // Vue publique — page active et publique
            self::VIEW => $page->isPublic()
                && $page->getStatus() === MemorialPage::STATUS_ACTIVE,

            // Interaction — connecté + page publique active
            self::INTERACT => $user instanceof User
                && $page->isPublic()
                && $page->getStatus() === MemorialPage::STATUS_ACTIVE,

            // MANAGE / MODERATE / OWN — même logique :
            // 1. Admin global
            // 2. Modérateur actif en base
            // 3. Créateur direct (fallback si JoinColumn broken)
            // 4. createdBy par ID (si lazy loading non résolu)
            self::MANAGE,
            self::MODERATE,
            self::OWN => $this->canManage($page, $user),

            default => false,
        };
    }

    private function canManage(MemorialPage $page, mixed $user): bool
    {
        if (!$user instanceof User) {
            return false;
        }

        // 1. Modérateur actif en base
        if ($this->memorialService->isModerator($page, $user)) {
            return true;
        }

        // 2. Créateur direct — via getCreatedBy() si disponible
        $creator = $page->getCreatedBy();
        if ($creator !== null && $creator->getId() === $user->getId()) {
            return true;
        }

        // 3. Fallback ultime — comparer les IDs sans lazy loading
        // (Si getCreatedBy() retourne un Proxy non initialisé)
        try {
            if ($page->getCreatedBy()?->getId() === $user->getId()) {
                return true;
            }
        } catch (\Exception) {
            // Proxy non initialisé — ignorer
        }

        return false;
    }
}
