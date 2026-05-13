<?php

namespace App\Controller;

use App\Entity\MemorialPage;
use App\Repository\MemorialPageRepository;
use App\Service\QrCodeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/qrcode')]
class QrCodeController extends AbstractController
{
    public function __construct(
        private readonly QrCodeService          $qrService,
        private readonly MemorialPageRepository $memorialRepo,
    ) {}

    // =========================================================
    // PAGE QR CODE — vue et téléchargements
    // =========================================================
    #[Route('/{slug}', name: 'app_qrcode_show', methods: ['GET'])]
    public function show(string $slug): Response
    {
        $page = $this->memorialRepo->findBySlug($slug);
        if (!$page) throw $this->createNotFoundException();

        $plaque = $this->qrService->getGravurePlaque($page);

        return $this->render('qrcode/show.html.twig', [
            'page'   => $page,
            'plaque' => $plaque,
        ]);
    }

    // =========================================================
    // TÉLÉCHARGER QR CODE PNG HD
    // =========================================================
    #[Route('/{slug}/download/png', name: 'app_qrcode_download_png', methods: ['GET'])]
    public function downloadPng(string $slug): Response
    {
        $page = $this->memorialRepo->findBySlug($slug);
        if (!$page) throw $this->createNotFoundException();

        $apiUrl = $this->qrService->generateQrCodeUrl($page);

        // Proxy du PNG depuis l'API
        $response = new StreamedResponse(function () use ($apiUrl) {
            $ctx = stream_context_create(['http' => ['timeout' => 15]]);
            $data = @file_get_contents($apiUrl, false, $ctx);
            echo $data ?: '';
        });

        $filename = 'qrcode-' . $page->getSlug() . '.png';
        $response->headers->set('Content-Type', 'image/png');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    // =========================================================
    // TÉLÉCHARGER QR CODE SVG (pour gravure)
    // =========================================================
    #[Route('/{slug}/download/svg', name: 'app_qrcode_download_svg', methods: ['GET'])]
    public function downloadSvg(string $slug): Response
    {
        $page = $this->memorialRepo->findBySlug($slug);
        if (!$page) throw $this->createNotFoundException();

        $svgUrl = $this->qrService->generateSvgUrl($page);

        $response = new StreamedResponse(function () use ($svgUrl) {
            $ctx = stream_context_create(['http' => ['timeout' => 15]]);
            $data = @file_get_contents($svgUrl, false, $ctx);
            echo $data ?: '';
        });

        $filename = 'qrcode-' . $page->getSlug() . '.svg';
        $response->headers->set('Content-Type', 'image/svg+xml');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    // =========================================================
    // PLAQUETTE GRAVABLE — page HTML imprimable en PDF
    // =========================================================
    #[Route('/{slug}/plaquette', name: 'app_qrcode_plaquette', methods: ['GET'])]
    public function plaquette(string $slug): Response
    {
        $page = $this->memorialRepo->findBySlug($slug);
        if (!$page) throw $this->createNotFoundException();

        $plaque = $this->qrService->getGravurePlaque($page);

        return $this->render('qrcode/plaquette.html.twig', [
            'page'   => $page,
            'plaque' => $plaque,
        ]);
    }
}
