<?php

namespace App\Controller;

use App\Entity\MemorialPage;
use App\Repository\MemorialPageRepository;
use App\Security\Voter\MemorialPageVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/dashboard/stats')]
#[IsGranted('ROLE_USER')]
class StatsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface  $em,
        private readonly MemorialPageRepository  $memorialRepo,
    ) {}

    // =========================================================
    // STATISTIQUES D'UNE PAGE MÉMORIELLE
    // =========================================================
    #[Route('/{slug}', name: 'app_stats_memorial', methods: ['GET'])]
    public function memorialStats(string $slug): Response
    {
        $page = $this->memorialRepo->findBySlug($slug);
        if (!$page) throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(MemorialPageVoter::MANAGE, $page);

        $stats = $this->getDetailedStats($page);

        return $this->render('stats/memorial.html.twig', [
            'page'  => $page,
            'stats' => $stats,
        ]);
    }

    // =========================================================
    // DONNÉES GRAPHIQUE — Ajax
    // =========================================================
    #[Route('/{slug}/chart', name: 'app_stats_chart', methods: ['GET'])]
    public function chartData(string $slug, Request $request): JsonResponse
    {
        $page = $this->memorialRepo->findBySlug($slug);
        if (!$page) return $this->json(['error' => 'Not found'], 404);
        $this->denyAccessUnlessGranted(MemorialPageVoter::MANAGE, $page);

        $days   = (int) $request->query->get('days', 30);
        $days   = min(max($days, 7), 365);
        $conn   = $this->em->getConnection();
        $data   = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date  = (new \DateTime())->modify("-{$i} days")->format('Y-m-d');
            $label = (new \DateTime())->modify("-{$i} days")->format('d/m');

            $visits = (int) $conn->executeQuery(
                "SELECT COUNT(*) FROM page_visits WHERE memorial_id = ? AND DATE(visited_at) = ?",
                [$page->getId(), $date]
            )->fetchOne();

            $condolences = (int) $conn->executeQuery(
                "SELECT COUNT(*) FROM condolences WHERE memorial_id = ? AND DATE(created_at) = ? AND status = 'approved'",
                [$page->getId(), $date]
            )->fetchOne();

            $data[] = [
                'date'        => $label,
                'visits'      => $visits,
                'condolences' => $condolences,
            ];
        }

        return $this->json($data);
    }

    // =========================================================
    // Calcul des statistiques détaillées
    // =========================================================
    private function getDetailedStats(MemorialPage $page): array
    {
        $conn = $this->em->getConnection();
        $id   = $page->getId();

        $totalVisits = (int) $conn->executeQuery(
            "SELECT COUNT(*) FROM page_visits WHERE memorial_id = ?", [$id]
        )->fetchOne();

        $uniqueVisitors = (int) $conn->executeQuery(
            "SELECT COUNT(DISTINCT COALESCE(user_id, ip_hash)) FROM page_visits WHERE memorial_id = ?", [$id]
        )->fetchOne();

        $visitsToday = (int) $conn->executeQuery(
            "SELECT COUNT(*) FROM page_visits WHERE memorial_id = ? AND DATE(visited_at) = CURDATE()", [$id]
        )->fetchOne();

        $visitsThisWeek = (int) $conn->executeQuery(
            "SELECT COUNT(*) FROM page_visits WHERE memorial_id = ? AND visited_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)", [$id]
        )->fetchOne();

        $condolences = (int) $conn->executeQuery(
            "SELECT COUNT(*) FROM condolences WHERE memorial_id = ? AND status = 'approved'", [$id]
        )->fetchOne();

        $pendingCondolences = (int) $conn->executeQuery(
            "SELECT COUNT(*) FROM condolences WHERE memorial_id = ? AND status = 'pending'", [$id]
        )->fetchOne();

        $testimonials = (int) $conn->executeQuery(
            "SELECT COUNT(*) FROM testimonials WHERE memorial_id = ? AND status = 'approved'", [$id]
        )->fetchOne();

        $guestbookSigned = (int) $conn->executeQuery(
            "SELECT COUNT(*) FROM guestbook WHERE memorial_id = ? AND status = 'approved'", [$id]
        )->fetchOne();

        $photos = (int) $conn->executeQuery(
            "SELECT COUNT(*) FROM media_gallery WHERE memorial_id = ? AND type = 'photo'", [$id]
        )->fetchOne();

        $gadgetInteractions = (int) $conn->executeQuery(
            "SELECT COUNT(*) FROM gadget_interactions WHERE memorial_id = ?", [$id]
        )->fetchOne();

        // Top pays visiteurs (si table page_visits a un champ country)
        $topDays = $conn->executeQuery(
            "SELECT DATE(visited_at) as day, COUNT(*) as visits
             FROM page_visits WHERE memorial_id = ?
             AND visited_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY DATE(visited_at)
             ORDER BY visits DESC LIMIT 5",
            [$id]
        )->fetchAllAssociative();

        return [
            'total_visits'       => $totalVisits,
            'unique_visitors'    => $uniqueVisitors,
            'visits_today'       => $visitsToday,
            'visits_this_week'   => $visitsThisWeek,
            'condolences'        => $condolences,
            'pending_condolences'=> $pendingCondolences,
            'testimonials'       => $testimonials,
            'guestbook_signed'   => $guestbookSigned,
            'photos'             => $photos,
            'gadget_interactions'=> $gadgetInteractions,
            'top_days'           => $topDays,
            'engagement_rate'    => $totalVisits > 0
                ? round(($condolences + $testimonials + $guestbookSigned) / $totalVisits * 100, 1)
                : 0,
        ];
    }
}
