<?php

namespace App\Service;

use App\Entity\MemorialPage;
use App\Entity\Payment;
use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class MemorialEmailService
{
    public function __construct(
        private readonly MailerInterface       $mailer,
        private readonly UrlGeneratorInterface $router,
        #[Autowire('%env(MAILER_FROM_ADDRESS)%')] private readonly string $fromAddress,
        #[Autowire('%env(MAILER_FROM_NAME)%')]    private readonly string $fromName,
        #[Autowire('%env(APP_URL)%')]             private readonly string $appUrl,
    ) {}

    /**
     * Envoie l'email de confirmation de création + facture au modérateur.
     */
    public function sendMemorialCreatedConfirmation(
        MemorialPage $page,
        Payment      $payment,
        User         $moderator,
    ): void {
        $pageUrl      = $this->router->generate('app_memorial_show',
            ['slug' => $page->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL);
        $dashboardUrl = $this->router->generate('app_dashboard_memorial',
            ['slug' => $page->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL);
        $qrUrl = $page->getQrCodeUrl()
            ? $this->appUrl . '/' . ltrim($page->getQrCodeUrl(), '/')
            : null;

        $email = (new TemplatedEmail())
            ->from(sprintf('%s <%s>', $this->fromName, $this->fromAddress))
            ->to($moderator->getEmail())
            ->subject(sprintf(
                '✅ Page mémorielle créée — %s | EnMémoire.com',
                $page->getDeceasedFullName()
            ))
            ->htmlTemplate('emails/memorial_created.html.twig')
            ->context([
                'page'          => $page,
                'payment'       => $payment,
                'moderator'     => $moderator,
                'page_url'      => $pageUrl,
                'dashboard_url' => $dashboardUrl,
                'qr_url'        => $qrUrl,
                'app_url'       => $this->appUrl,
            ]);

        $this->mailer->send($email);
    }
}
