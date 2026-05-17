<?php

namespace App\Controller;

use App\Entity\MediaGallery;
use App\Entity\MemorialPage;
use App\Repository\MemorialPageRepository;
use App\Service\MediaService;
use App\Service\MemorialPageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/memorial/{slug}/media')]
class MediaController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MemorialPageRepository $memorialRepo,
        private readonly MediaService           $mediaService,
        private readonly MemorialPageService    $memorialService,
    ) {}

    // =========================================================
    // UPLOAD PHOTO PRINCIPALE (photo de profil du défunt)
    // =========================================================
    #[Route('/photo-principale', name: 'app_media_main_photo', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function uploadMainPhoto(string $slug, Request $request): JsonResponse
    {
        $page = $this->getPageOrDeny($slug);

        if (!$this->isCsrfTokenValid('media_main_' . $page->getId(), $request->request->get('_token'))) {
            return $this->json(['error' => 'Token invalide'], 403);
        }

        $file = $request->files->get('photo');
        if (!$file) {
            return $this->json(['error' => 'Aucun fichier envoyé'], 400);
        }

        try {
            // Supprimer l'ancienne photo si locale
            if ($page->getMainPhotoUrl() && !str_starts_with($page->getMainPhotoUrl(), 'http')) {
                $old = $this->getParameter('kernel.project_dir') . '/public/' . $page->getMainPhotoUrl();
                if (file_exists($old)) unlink($old);
            }

            $url = $this->mediaService->uploadMainPhoto($file, $page);
            $page->setMainPhotoUrl($url);
            $this->em->flush();

            return $this->json([
                'success'   => true,
                'url'       => str_starts_with($url, 'http') ? $url : '/' . ltrim($url, '/'),
                'message'   => 'Photo de profil mise à jour.',
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }
    }

    // =========================================================
    // UPLOAD PHOTO DE COUVERTURE
    // =========================================================
    #[Route('/couverture', name: 'app_media_cover_photo', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function uploadCoverPhoto(string $slug, Request $request): JsonResponse
    {
        $page = $this->getPageOrDeny($slug);

        if (!$this->isCsrfTokenValid('media_cover_' . $page->getId(), $request->request->get('_token'))) {
            return $this->json(['error' => 'Token invalide'], 403);
        }

        $file = $request->files->get('cover');
        if (!$file) {
            return $this->json(['error' => 'Aucun fichier envoyé'], 400);
        }

        try {
            $url = $this->mediaService->uploadCoverPhoto($file, $page);
            $page->setCoverPhotoUrl($url);
            $this->em->flush();

            return $this->json([
                'success' => true,
                'url'     => str_starts_with($url, 'http') ? $url : '/' . ltrim($url, '/'),
                'message' => 'Photo de couverture mise à jour.',
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }
    }

    // =========================================================
    // UPLOAD GALERIE (photos + vidéos)
    // =========================================================
    #[Route('/galerie', name: 'app_media_gallery_upload', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function uploadGallery(string $slug, Request $request): JsonResponse
    {
        $page = $this->getPageOrDeny($slug);

        if (!$this->isCsrfTokenValid('media_gallery_' . $page->getId(), $request->request->get('_token'))) {
            return $this->json(['error' => 'Token invalide'], 403);
        }

        $files   = $request->files->get('files', []);
        $caption = trim($request->request->get('caption', ''));

        if (empty($files)) {
            return $this->json(['error' => 'Aucun fichier envoyé'], 400);
        }

        // Accepter un seul fichier ou un tableau
        if (!is_array($files)) $files = [$files];

        $uploaded = [];
        $errors   = [];

        foreach ($files as $file) {
            try {
                $media = $this->mediaService->uploadGalleryMedia(
                    $file, $page, $this->getUser(), $caption ?: null
                );
                $url = $media->getUrl();
                $uploaded[] = [
                    'id'       => $media->getId(),
                    'url'      => str_starts_with($url, 'http') ? $url : '/' . ltrim($url, '/'),
                    'thumb'    => $media->getThumbnailUrl()
                        ? (str_starts_with($media->getThumbnailUrl(), 'http')
                            ? $media->getThumbnailUrl()
                            : '/' . ltrim($media->getThumbnailUrl(), '/'))
                        : null,
                    'type'     => $media->getType(),
                    'caption'  => $media->getCaption(),
                ];
            } catch (\Exception $e) {
                $errors[] = $file->getClientOriginalName() . ' : ' . $e->getMessage();
            }
        }

        return $this->json([
            'success'  => count($uploaded) > 0,
            'uploaded' => $uploaded,
            'errors'   => $errors,
            'message'  => count($uploaded) . ' fichier(s) uploadé(s).',
        ]);
    }

    // =========================================================
    // SUPPRIMER UN MÉDIA
    // =========================================================
    #[Route('/galerie/{mediaId}/delete', name: 'app_media_delete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function deleteMedia(string $slug, int $mediaId, Request $request): JsonResponse
    {
        $page = $this->getPageOrDeny($slug);

        if (!$this->isCsrfTokenValid('media_delete_' . $mediaId, $request->request->get('_token'))) {
            return $this->json(['error' => 'Token invalide'], 403);
        }

        $media = $this->em->getRepository(MediaGallery::class)->find($mediaId);

        if (!$media || $media->getMemorial()->getId() !== $page->getId()) {
            return $this->json(['error' => 'Média introuvable'], 404);
        }

        try {
            $this->mediaService->deleteMedia($media);
            return $this->json(['success' => true]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }


    // =========================================================
    // MODIFIER LA LÉGENDE D'UN MÉDIA (Ajax)
    // =========================================================
    #[Route('/galerie/{mediaId}/caption', name: 'app_media_caption', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function updateCaption(string $slug, int $mediaId, Request $request): JsonResponse
    {
        $page = $this->getPageOrDeny($slug);

        if (!$this->isCsrfTokenValid('media_caption_' . $mediaId, $request->request->get('_token'))) {
            return $this->json(['error' => 'Token invalide'], 403);
        }

        $media = $this->em->getRepository(MediaGallery::class)->find($mediaId);

        if (!$media || $media->getMemorial()->getId() !== $page->getId()) {
            return $this->json(['error' => 'Média introuvable'], 404);
        }

        $caption = trim($request->request->get('caption', ''));
        $media->setCaption($caption ?: null);
        $this->em->flush();

        return $this->json(['success' => true, 'caption' => $caption]);
    }


    // =========================================================
    // RÉORDONNER LES MÉDIAS (Ajax)
    // =========================================================
    #[Route('/reorder', name: 'app_media_reorder', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function reorder(string $slug, Request $request): JsonResponse
    {
        $page = $this->getPageOrDeny($slug);

        if (!$this->isCsrfTokenValid('media_reorder', $request->request->get('_token'))) {
            return $this->json(['error' => 'Token invalide'], 403);
        }

        $order = $request->request->all('order');
        if (empty($order)) {
            return $this->json(['error' => 'Ordre vide'], 400);
        }

        foreach ($order as $position => $mediaId) {
            $media = $this->em->getRepository(MediaGallery::class)->find((int)$mediaId);
            if ($media && $media->getMemorial()->getId() === $page->getId()) {
                $media->setSortOrder((int)$position);
            }
        }
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    // =========================================================
    // Helper
    // =========================================================
    private function getPageOrDeny(string $slug): MemorialPage
    {
        $page = $this->memorialRepo->findBySlug($slug);
        if (!$page) throw $this->createNotFoundException();

        $user = $this->getUser();
        $row  = $this->em->getConnection()->executeQuery(
            'SELECT created_by FROM memorial_pages WHERE id = ?', [$page->getId()]
        )->fetchAssociative();

        $isOwner = $row && (int)$row['created_by'] === $user->getId();
        $isMod   = $this->memorialService->isModerator($page, $user);

        if (!$isOwner && !$isMod) {
            throw $this->createAccessDeniedException();
        }

        return $page;
    }
}
