<?php

namespace App\Command;

use App\Entity\MemorialPage;
use App\Repository\MemorialPageRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsCommand(
    name: 'app:renewal:remind',
    description: 'Envoie les rappels de renouvellement (J-30, J-7, J-1) et expire les pages',
)]
class RenewalReminderCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface  $em,
        private readonly MemorialPageRepository  $memorialRepo,
        private readonly NotificationService     $notifService,
        private readonly MailerInterface         $mailer,
        private readonly UrlGeneratorInterface   $router,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $now  = new \DateTime();
        $from = new Address(
            $_ENV['MAILER_FROM_ADDRESS'] ?? 'noreply@enmemoire.com',
            $_ENV['MAILER_FROM_NAME']    ?? 'EnMémoire.com'
        );

        // ── Rappels J-30, J-7, J-1 ───────────────────────────────
        $reminders = [30, 7, 1];
        $totalSent  = 0;

        foreach ($reminders as $days) {
            $targetDate = (clone $now)->modify("+{$days} days");
            $pages = $this->getPagesExpiringOn($targetDate);

            foreach ($pages as $page) {
                $owner = $page->getCreatedBy();
                if (!$owner) continue;

                $renewalUrl = $this->router->generate(
                    'app_renewal',
                    ['slug' => $page->getSlug()],
                    UrlGeneratorInterface::ABSOLUTE_URL
                );

                // Notification in-app
                $this->notifService->send(
                    $owner,
                    'renewal_reminder',
                    "⏰ Renouvellement dans {$days} jour" . ($days > 1 ? 's' : ''),
                    sprintf(
                        'La page mémorielle de %s expire le %s. Renouvelez maintenant pour maintenir l\'accès.',
                        $page->getDeceasedFullName(),
                        $page->getExpiresAt()->format('d/m/Y')
                    ),
                    $renewalUrl
                );

                // Email
                try {
                    $email = (new TemplatedEmail())
                        ->from($from)
                        ->to($owner->getEmail())
                        ->subject("⏰ Renouvellement dans {$days} jour" . ($days > 1 ? 's' : '') . " — {$page->getDeceasedFullName()}")
                        ->htmlTemplate('emails/renewal_reminder.html.twig')
                        ->context([
                            'page'        => $page,
                            'owner'       => $owner,
                            'days'        => $days,
                            'renewal_url' => $renewalUrl,
                            'expires_at'  => $page->getExpiresAt(),
                        ]);

                    $this->mailer->send($email);
                    $totalSent++;
                    $io->writeln("  ✅ Rappel J-{$days} envoyé à {$owner->getEmail()} pour {$page->getDeceasedFullName()}");
                } catch (\Exception $e) {
                    $io->writeln("  ❌ Email échoué: " . $e->getMessage());
                }
            }
        }

        // ── Expirer les pages échues ──────────────────────────────
        $expiredPages = $this->em->getRepository(MemorialPage::class)
            ->createQueryBuilder('p')
            ->where('p.expiresAt < :now')
            ->andWhere('p.status = :active')
            ->setParameter('now', $now)
            ->setParameter('active', MemorialPage::STATUS_ACTIVE)
            ->getQuery()
            ->getResult();

        $expiredCount = 0;
        foreach ($expiredPages as $page) {
            $page->setStatus(MemorialPage::STATUS_EXPIRED);
            $owner = $page->getCreatedBy();

            if ($owner) {
                $renewalUrl = $this->router->generate(
                    'app_renewal',
                    ['slug' => $page->getSlug()],
                    UrlGeneratorInterface::ABSOLUTE_URL
                );

                $this->notifService->send(
                    $owner,
                    'renewal_expired',
                    '❌ Page mémorielle expirée',
                    sprintf(
                        'La page mémorielle de %s a expiré. Renouvelez maintenant pour la réactiver.',
                        $page->getDeceasedFullName()
                    ),
                    $renewalUrl
                );

                try {
                    $email = (new TemplatedEmail())
                        ->from($from)
                        ->to($owner->getEmail())
                        ->subject("❌ Page expirée — {$page->getDeceasedFullName()}")
                        ->htmlTemplate('emails/renewal_expired.html.twig')
                        ->context([
                            'page'        => $page,
                            'owner'       => $owner,
                            'renewal_url' => $renewalUrl,
                        ]);

                    $this->mailer->send($email);
                } catch (\Exception $e) {
                    $io->writeln("  ❌ Email expiration échoué: " . $e->getMessage());
                }
            }
            $expiredCount++;
        }

        $this->em->flush();

        $io->success("Rappels envoyés: {$totalSent} | Pages expirées: {$expiredCount}");
        return Command::SUCCESS;
    }

    private function getPagesExpiringOn(\DateTime $date): array
    {
        $start = (clone $date)->setTime(0, 0, 0);
        $end   = (clone $date)->setTime(23, 59, 59);

        return $this->em->getRepository(MemorialPage::class)
            ->createQueryBuilder('p')
            ->where('p.expiresAt BETWEEN :start AND :end')
            ->andWhere('p.status = :active')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->setParameter('active', MemorialPage::STATUS_ACTIVE)
            ->getQuery()
            ->getResult();
    }
}
