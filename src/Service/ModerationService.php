<?php

namespace App\Service;

use App\Entity\Condolence;
use App\Entity\GuestBook;
use App\Entity\MemorialPage;
use App\Entity\Testimonial;
use App\Entity\User;
use App\Repository\ModeratorTrustListRepository;
use Doctrine\ORM\EntityManagerInterface;

class ModerationService
{
    public function __construct(
        private readonly EntityManagerInterface      $em,
        private readonly ModeratorTrustListRepository $trustRepo,
        private readonly NotificationService         $notifService,
    ) {}

    /**
     * Détermine le statut initial d'une publication
     * selon les listes de confiance des modérateurs
     */
    public function resolveStatus(MemorialPage $page, User $author): string
    {
        if ($this->trustRepo->isUserTrustedByAnyModerator($page, $author)) {
            return 'approved';
        }
        return 'pending';
    }

    /**
     * Approuver une condoléance
     */
    public function approveCondolence(Condolence $condolence, User $moderator): void
    {
        $condolence->setStatus(Condolence::STATUS_APPROVED);
        $condolence->setModeratedBy($moderator);
        $condolence->setModeratedAt(new \DateTime());
        $this->em->flush();
    }

    /**
     * Rejeter une condoléance
     */
    public function rejectCondolence(Condolence $condolence, User $moderator): void
    {
        $condolence->setStatus(Condolence::STATUS_REJECTED);
        $condolence->setModeratedBy($moderator);
        $condolence->setModeratedAt(new \DateTime());
        $this->em->flush();
    }

    /**
     * Approuver un témoignage
     */
    public function approveTestimonial(Testimonial $testimonial, User $moderator): void
    {
        $testimonial->setStatus(Testimonial::STATUS_APPROVED);
        $testimonial->setModeratedBy($moderator);
        $testimonial->setModeratedAt(new \DateTime());
        $this->em->flush();
    }

    /**
     * Approuver une signature du livre d'or
     */
    public function approveGuestBook(GuestBook $entry, User $moderator): void
    {
        $entry->setStatus(GuestBook::STATUS_APPROVED);
        $entry->setModeratedBy($moderator);
        $entry->setModeratedAt(new \DateTime());
        $this->em->flush();
    }

    /**
     * Retourne le nombre de publications en attente sur une page
     */
    public function getPendingCount(MemorialPage $page): array
    {
        return [
            'condolences'  => $this->em->getRepository(Condolence::class)
                ->count(['memorial' => $page, 'status' => 'pending']),
            'testimonials' => $this->em->getRepository(Testimonial::class)
                ->count(['memorial' => $page, 'status' => 'pending']),
            'guestbooks'   => $this->em->getRepository(GuestBook::class)
                ->count(['memorial' => $page, 'status' => 'pending']),
        ];
    }

    /**
     * Total des publications en attente sur une page
     */
    public function getTotalPending(MemorialPage $page): int
    {
        $counts = $this->getPendingCount($page);
        return array_sum($counts);
    }
}
