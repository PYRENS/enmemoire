<?php

namespace App\Controller;

use App\Repository\MemorialPageRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ✅ FIX : SearchController extrait dans son propre fichier.
 * Il était auparavant déclaré dans HomeController.php avec un second namespace,
 * ce qui est invalide en PHP (un fichier = un namespace/classe PSR-4).
 */
class SearchController extends AbstractController
{
    public function __construct(
        private readonly MemorialPageRepository $memorialRepo,
    ) {}

    #[Route('/recherche', name: 'app_memorial_search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $query   = trim($request->query->get('q', ''));
        $results = [];

        if (strlen($query) >= 2) {
            $results = $this->memorialRepo->searchByName($query, 30);
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'html'  => $this->renderView('search/partials/_results.html.twig', [
                    'results' => $results,
                    'query'   => $query,
                ]),
                'count' => count($results),
            ]);
        }

        return $this->render('search/index.html.twig', [
            'query'   => $query,
            'results' => $results,
        ]);
    }

    #[Route('/api/search/autocomplete', name: 'app_search_autocomplete', methods: ['GET'])]
    public function autocomplete(Request $request): JsonResponse
    {
        $q    = trim($request->query->get('q', ''));
        $data = [];

        if (strlen($q) >= 2) {
            $results = $this->memorialRepo->searchByName($q, 6);
            foreach ($results as $page) {
                $data[] = [
                    'id'    => $page->getId(),
                    'name'  => $page->getDeceasedFullName(),
                    'years' => $page->getDeceasedBirthDate()->format('Y')
                               . ' — '
                               . $page->getDeceasedDeathDate()->format('Y'),
                    'slug'  => $page->getSlug(),
                    'photo' => $page->getMainPhotoUrl(),
                ];
            }
        }

        return $this->json($data);
    }
}
