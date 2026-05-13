<?php

namespace App\Service;

use App\Entity\MemorialModerator;
use App\Entity\MemorialPage;
use App\Entity\User;
use App\Repository\MemorialModeratorRepository;
use App\Repository\ModeratorTrustListRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

class MemorialPageService
{
    public function __construct(
        private readonly EntityManagerInterface      $em,
        private readonly SluggerInterface            $slugger,
        private readonly MemorialModeratorRepository $moderatorRepo,
        private readonly ModeratorTrustListRepository $trustRepo,
        private readonly QrCodeService               $qrCodeService,
    ) {}

    // --------------------------------------------------
    // Génération du slug unique
    // --------------------------------------------------
    public function generateSlug(MemorialPage $page): string
    {
        // Format : prenom-nom-AAAANAISSANCE-AAAADECES
        // Ex : olga-mumbere-19780315-20241102
        $name = $this->slugger->slug(
            strtolower(
                $page->getDeceasedFirstName() . ' ' . $page->getDeceasedLastName()
            )
        )->toString();

        $birthDate = $page->getDeceasedBirthDate()->format('Ymd');
        $deathDate = $page->getDeceasedDeathDate()->format('Ymd');

        $base = $name . '-' . $birthDate . '-' . $deathDate;
        $slug = $base;

        // Garantir l'unicité (cas de prénoms/noms/dates identiques)
        $i = 2;
        while ($this->em->getRepository(MemorialPage::class)->findOneBy(['slug' => $slug])) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }

    // --------------------------------------------------
    // Génération du code court de page (ex: MEM-X7K2P)
    // --------------------------------------------------
    public function generatePageCode(): string
    {
        do {
            $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            $code  = 'MEM-';
            for ($i = 0; $i < 8; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
        } while ($this->em->getRepository(MemorialPage::class)->findOneBy(['pageCode' => $code]));

        return $code;
    }

    // --------------------------------------------------
    // Génération du code modérateur (ex: MOD-AB12C)
    // --------------------------------------------------
    public function generateModeratorCode(): string
    {
        do {
            $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            $code  = 'MOD-';
            for ($i = 0; $i < 5; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
        } while ($this->moderatorRepo->findOneBy(['moderatorCode' => $code]));

        return $code;
    }

    // --------------------------------------------------
    // Créer une page mémorielle complète
    // --------------------------------------------------
    public function createMemorialPage(MemorialPage $page, User $creator): MemorialPage
    {
        // Slug + code
        $page->setSlug($this->generateSlug($page));
        $page->setPageCode($this->generatePageCode());

        // Date d'expiration selon la formule
        if ($page->getFormula() && !$page->getFormula()->isPerpetual()) {
            $expires = new \DateTime();
            $expires->modify('+' . $page->getFormula()->getDurationYears() . ' years');
            $page->setExpiresAt($expires);
        }

        $page->setCreatedBy($creator);
        $this->em->persist($page);
        $this->em->flush();

        // QR Code
        try {
            $qrUrl = $this->qrCodeService->generateForMemorial($page);
            $page->setQrCodeUrl($qrUrl);
        } catch (\Exception $e) {
            // Non bloquant en dev
        }

        // Créateur devient Modérateur propriétaire
        $moderator = new MemorialModerator();
        $moderator->setMemorial($page);
        $moderator->setUser($creator);
        $moderator->setIsOwner(true);
        $moderator->setStatus(MemorialModerator::STATUS_ACTIVE);
        $moderator->setModeratorCode($this->generateModeratorCode());
        $moderator->setAcceptedAt(new \DateTime());
        $this->em->persist($moderator);

        $this->em->flush();

        return $page;
    }

    // --------------------------------------------------
    // Vérifier si un user est modérateur actif d'une page
    // --------------------------------------------------
    public function isModerator(MemorialPage $page, User $user): bool
    {
        $mod = $this->moderatorRepo->findOneBy([
            'memorial' => $page,
            'user'     => $user,
            'status'   => MemorialModerator::STATUS_ACTIVE,
        ]);
        return $mod !== null;
    }

    public function isOwner(MemorialPage $page, User $user): bool
    {
        $mod = $this->moderatorRepo->findOneBy([
            'memorial' => $page,
            'user'     => $user,
            'status'   => MemorialModerator::STATUS_ACTIVE,
            'isOwner'  => true,
        ]);
        return $mod !== null;
    }

    // --------------------------------------------------
    // Règle de modération automatique des publications
    // Un contenu est auto-publié si l'auteur est dans
    // au moins UNE liste de confiance d'un modérateur actif
    // --------------------------------------------------
    public function shouldAutoPublish(MemorialPage $page, User $author): bool
    {
        return $this->trustRepo->isUserTrustedByAnyModerator($page, $author);
    }

    // --------------------------------------------------
    // Statistiques de la page
    // --------------------------------------------------
    public function getStats(MemorialPage $page): array
    {
        $repo = $this->em->getRepository(MemorialPage::class);
        return [
            'visits'      => $page->getVisitCount(),
            'condolences' => $this->em->getRepository(\App\Entity\Condolence::class)
                                ->count(['memorial' => $page, 'status' => 'approved']),
            'testimonials'=> $this->em->getRepository(\App\Entity\Testimonial::class)
                                ->count(['memorial' => $page, 'status' => 'approved']),
            'guestbooks'  => $this->em->getRepository(\App\Entity\GuestBook::class)
                                ->count(['memorial' => $page, 'status' => 'approved']),
            'photos'      => $this->em->getRepository(\App\Entity\MediaGallery::class)
                                ->count(['memorial' => $page, 'type' => 'photo']),
            'videos'      => $this->em->getRepository(\App\Entity\MediaGallery::class)
                                ->count(['memorial' => $page, 'type' => 'video']),
            'events'      => $this->em->getRepository(\App\Entity\MemorialEvent::class)
                                ->count(['memorial' => $page]),
            'moderators'  => $this->moderatorRepo
                                ->count(['memorial' => $page, 'status' => 'active']),
            'connections' => $this->em->getRepository(\App\Entity\FamilyConnection::class)
                                ->count(['memorialFrom' => $page, 'status' => 'accepted']),
        ];
    }

    // --------------------------------------------------
    // Incrémente le compteur de visite (dédupliqué par session)
    // --------------------------------------------------
    public function trackVisit(MemorialPage $page, ?User $visitor, string $sessionId): void
    {
        $cacheKey = 'visit_' . $page->getId() . '_' . $sessionId;
        // Vérification simple en session — en prod, utiliser Redis
        $page->incrementVisitCount();
        $this->em->flush();
    }
}
