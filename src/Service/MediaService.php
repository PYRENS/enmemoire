<?php

namespace App\Service;

use App\Entity\MediaGallery;
use App\Entity\MemorialPage;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

class MediaService
{
    // Types autorisés
    public const ALLOWED_IMAGES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    public const ALLOWED_VIDEOS = ['video/mp4', 'video/webm', 'video/quicktime'];
    public const ALLOWED_ALL    = [...self::ALLOWED_IMAGES, ...self::ALLOWED_VIDEOS];

    // Limites
    public const MAX_IMAGE_SIZE = 10 * 1024 * 1024; // 10 Mo
    public const MAX_VIDEO_SIZE = 100 * 1024 * 1024; // 100 Mo

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SluggerInterface       $slugger,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%env(CLOUDINARY_URL)%')] private readonly string $cloudinaryUrl,
        #[Autowire('%env(MEDIA_STORAGE)%')]   private readonly string $storage, // 'local' | 'cloudinary'
    ) {}

    // --------------------------------------------------
    // Upload photo principale du défunt
    // --------------------------------------------------
    public function uploadMainPhoto(UploadedFile $file, MemorialPage $page): string
    {
        $this->validateFile($file, self::ALLOWED_IMAGES, self::MAX_IMAGE_SIZE);

        if ($this->isCloudinary()) {
            return $this->uploadToCloudinary($file, "enmemoire/memorials/{$page->getId()}/main");
        }

        return $this->uploadLocal($file, "uploads/memorials/{$page->getId()}/main");
    }

    // --------------------------------------------------
    // Upload photo de couverture
    // --------------------------------------------------
    public function uploadCoverPhoto(UploadedFile $file, MemorialPage $page): string
    {
        $this->validateFile($file, self::ALLOWED_IMAGES, self::MAX_IMAGE_SIZE);

        if ($this->isCloudinary()) {
            return $this->uploadToCloudinary($file, "enmemoire/memorials/{$page->getId()}/cover");
        }

        return $this->uploadLocal($file, "uploads/memorials/{$page->getId()}/cover");
    }

    // --------------------------------------------------
    // Upload image de couverture d'un événement
    // --------------------------------------------------
    public function uploadEventCover(UploadedFile $file, \App\Entity\MemorialEvent $event): string
    {
        $this->validateFile($file, self::ALLOWED_IMAGES, self::MAX_IMAGE_SIZE);

        $pageId   = $event->getMemorial()?->getId() ?? 'unknown';
        $eventId  = $event->getId() ?? 'new';

        if ($this->isCloudinary()) {
            return $this->uploadToCloudinary(
                $file,
                "enmemoire/memorials/{$pageId}/events/{$eventId}/cover"
            );
        }

        return $this->uploadLocal(
            $file,
            "uploads/memorials/{$pageId}/events/{$eventId}/cover"
        );
    }

    // --------------------------------------------------
    // Upload média galerie (photo ou vidéo)
    // --------------------------------------------------
    public function uploadGalleryMedia(
        UploadedFile $file,
        MemorialPage $page,
        User         $uploader,
        ?string      $caption = null,
    ): MediaGallery {
        $isVideo = in_array($file->getMimeType(), self::ALLOWED_VIDEOS);
        $maxSize = $isVideo ? self::MAX_VIDEO_SIZE : self::MAX_IMAGE_SIZE;

        $this->validateFile($file, self::ALLOWED_ALL, $maxSize);

        $folder = "uploads/memorials/{$page->getId()}/gallery";

        if ($this->isCloudinary()) {
            $url = $this->uploadToCloudinary(
                $file,
                "enmemoire/memorials/{$page->getId()}/gallery",
                $isVideo ? 'video' : 'image'
            );
            $thumbUrl = $isVideo ? $this->cloudinaryVideoThumbnail($url) : $url;
        } else {
            $url      = $this->uploadLocal($file, $folder);
            $thumbUrl = $isVideo ? null : $url;
        }

        $media = new MediaGallery();
        $media->setMemorial($page)
              ->setUploadedBy($uploader)
              ->setUrl($url)
              ->setThumbnailUrl($thumbUrl)
              ->setType($isVideo ? 'video' : 'photo')
              ->setCaption($caption)
              ;

        $this->em->persist($media);
        $this->em->flush();

        return $media;
    }

    // --------------------------------------------------
    // Supprimer un média
    // --------------------------------------------------
    public function deleteMedia(MediaGallery $media): void
    {
        if (!$this->isCloudinary()) {
            $path = $this->projectDir . '/public/' . $media->getUrl();
            if (file_exists($path)) unlink($path);
            if ($media->getThumbnailUrl()) {
                $thumb = $this->projectDir . '/public/' . $media->getThumbnailUrl();
                if (file_exists($thumb)) unlink($thumb);
            }
        }
        // Cloudinary : supprimer via API si nécessaire

        $this->em->remove($media);
        $this->em->flush();
    }

    // --------------------------------------------------
    // UPLOAD LOCAL
    // --------------------------------------------------
    private function uploadLocal(UploadedFile $file, string $subDir): string
    {
        $dir = $this->projectDir . '/public/' . $subDir;
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $ext      = $file->guessExtension() ?? 'bin';
        $filename = uniqid('m_', true) . '.' . $ext;

        $file->move($dir, $filename);

        return $subDir . '/' . $filename;
    }

    // --------------------------------------------------
    // UPLOAD CLOUDINARY
    // --------------------------------------------------
    private function uploadToCloudinary(UploadedFile $file, string $folder, string $type = 'image'): string
    {
        // Parser l'URL Cloudinary : cloudinary://api_key:api_secret@cloud_name
        $parsed    = parse_url($this->cloudinaryUrl);
        $cloudName = $parsed['host'];
        $apiKey    = $parsed['user'];
        $apiSecret = $parsed['pass'];

        $timestamp = time();
        $params    = [
            'folder'    => $folder,
            'timestamp' => $timestamp,
        ];

        // Cloudinary signature : clés triées, séparées par & sans encodage URL
        ksort($params);
        $sigParts = [];
        foreach ($params as $k => $v) {
            $sigParts[] = $k . '=' . $v;
        }
        $sigStr    = implode('&', $sigParts);
        $signature = sha1($sigStr . $apiSecret);

        $endpoint  = "https://api.cloudinary.com/v1_1/{$cloudName}/{$type}/upload";

        $postData = array_merge($params, [
            'file'      => new \CURLFile($file->getRealPath(), $file->getMimeType(), $file->getClientOriginalName()),
            'api_key'   => $apiKey,
            'signature' => $signature,
        ]);

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException('Cloudinary upload failed: ' . $response);
        }

        $data = json_decode($response, true);
        return $data['secure_url'];
    }

    private function cloudinaryVideoThumbnail(string $videoUrl): string
    {
        // Générer une miniature JPG depuis la vidéo Cloudinary
        // Remplacer /video/upload/ par /video/upload/so_0/ et changer l'extension en jpg
        $thumb = preg_replace('/\/upload\//', '/upload/so_0,w_400,h_300,c_fill/', $videoUrl);
        // Changer l'extension en jpg
        $thumb = preg_replace('/\.(mp4|webm|mov|avi)$/i', '.jpg', $thumb);
        // Remplacer /video/ par /image/ dans l'URL pour la miniature
        $thumb = str_replace('/video/upload/', '/video/upload/', $thumb);
        return $thumb;
    }

    // --------------------------------------------------
    // VALIDATION
    // --------------------------------------------------
    private function validateFile(UploadedFile $file, array $allowed, int $maxSize): void
    {
        if (!in_array($file->getMimeType(), $allowed)) {
            throw new \InvalidArgumentException(
                'Format non supporté. Formats acceptés : ' . implode(', ', array_map(fn($m) => explode('/', $m)[1], $allowed))
            );
        }
        if ($file->getSize() > $maxSize) {
            throw new \InvalidArgumentException(
                'Fichier trop lourd. Maximum : ' . round($maxSize / 1024 / 1024) . ' Mo'
            );
        }
    }

    private function isCloudinary(): bool
    {
        return $this->storage === 'cloudinary' && !empty($this->cloudinaryUrl) && $this->cloudinaryUrl !== 'null';
    }

    public function getStorageMode(): string
    {
        return $this->isCloudinary() ? 'cloudinary' : 'local';
    }
}
