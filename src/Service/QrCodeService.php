<?php

namespace App\Service;

use App\Entity\MemorialPage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class QrCodeService
{
    public function __construct(
        private readonly UrlGeneratorInterface $router,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%env(APP_URL)%')]        private readonly string $appUrl,
    ) {}

    // --------------------------------------------------
    // URL cible du QR Code
    // --------------------------------------------------
    public function getMemorialUrl(MemorialPage $page): string
    {
        return $this->appUrl . '/memorial/' . $page->getSlug();
    }

    // --------------------------------------------------
    // Générer le QR Code via l'API externe (fallback si lib absente)
    // --------------------------------------------------
    public function generateQrCodeUrl(MemorialPage $page): string
    {
        $url = urlencode($this->getMemorialUrl($page));
        // API QR Code gratuite — fonctionne sans composer require
        return "https://api.qrserver.com/v1/create-qr-code/?size=400x400&data={$url}&ecc=H&margin=10";
    }

    // --------------------------------------------------
    // Génère et sauvegarde le PNG en local (via GD ou cURL)
    // --------------------------------------------------
    public function generateAndSave(MemorialPage $page): string
    {
        $dir      = $this->projectDir . '/public/qrcodes';
        $filename = 'qr-' . $page->getUuid() . '.png';
        $filepath = $dir . '/' . $filename;
        $publicPath = 'qrcodes/' . $filename;

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Si déjà généré, retourner le chemin existant
        if (file_exists($filepath)) {
            return $publicPath;
        }

        // Télécharger via l'API QR Server
        $apiUrl = $this->generateQrCodeUrl($page);

        $ctx = stream_context_create([
            'http' => [
                'timeout'    => 10,
                'user_agent' => 'EnMémoire.com QrCodeService/1.0',
            ]
        ]);

        $imageData = @file_get_contents($apiUrl, false, $ctx);

        if ($imageData !== false) {
            file_put_contents($filepath, $imageData);
            return $publicPath;
        }

        // Fallback : retourner l'URL API directe
        return $apiUrl;
    }

    // --------------------------------------------------
    // Générer le SVG du QR Code pour gravure (haute résolution)
    // --------------------------------------------------
    public function generateSvgUrl(MemorialPage $page): string
    {
        $url = urlencode($this->getMemorialUrl($page));
        return "https://api.qrserver.com/v1/create-qr-code/?size=1000x1000&data={$url}&ecc=H&margin=10&format=svg";
    }

    // --------------------------------------------------
    // Données pour la plaquette PDF gravable
    // --------------------------------------------------
    public function getGravurePlaque(MemorialPage $page): array
    {
        return [
            'name'       => $page->getDeceasedFullName(),
            'birth_year' => $page->getDeceasedBirthDate()->format('Y'),
            'death_year' => $page->getDeceasedDeathDate()->format('Y'),
            'page_code'  => $page->getPageCode(),
            'qr_url'     => $this->generateQrCodeUrl($page),
            'qr_svg_url' => $this->generateSvgUrl($page),
            'memorial_url' => $this->getMemorialUrl($page),
        ];
    }
}
