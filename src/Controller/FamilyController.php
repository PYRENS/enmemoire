<?php

namespace App\Controller;

use App\Entity\FamilyConnection;
use App\Entity\MemorialPage;
use App\Repository\FamilyConnectionRepository;
use App\Repository\MemorialPageRepository;
use App\Service\MemorialPageService;
use App\Service\NotificationService;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/memorial/{slug}/famille')]
class FamilyController extends AbstractController
{
    // Relations disponibles
    public const RELATIONS = [
        'Père'          => 'Enfant',
        'Mère'          => 'Enfant',
        'Fils'          => 'Père/Mère',
        'Fille'         => 'Père/Mère',
        'Époux'         => 'Épouse',
        'Épouse'        => 'Époux',
        'Frère'         => 'Frère/Sœur',
        'Sœur'          => 'Frère/Sœur',
        'Grand-père'    => 'Petit-enfant',
        'Grand-mère'    => 'Petit-enfant',
        'Petit-fils'    => 'Grand-parent',
        'Petite-fille'  => 'Grand-parent',
        'Oncle'         => 'Neveu/Nièce',
        'Tante'         => 'Neveu/Nièce',
        'Neveu'         => 'Oncle/Tante',
        'Nièce'         => 'Oncle/Tante',
        'Cousin'        => 'Cousin/Cousine',
        'Cousine'       => 'Cousin/Cousine',
        'Beau-père'     => 'Beau-fils/Belle-fille',
        'Belle-mère'    => 'Beau-fils/Belle-fille',
        'Beau-fils'     => 'Beau-père/Belle-mère',
        'Belle-fille'   => 'Beau-père/Belle-mère',
        'Autre'         => 'Autre',
    ];

    public function __construct(
        private readonly EntityManagerInterface     $em,
        private readonly MemorialPageRepository     $memorialRepo,
        private readonly FamilyConnectionRepository $connectionRepo,
        private readonly MemorialPageService        $memorialService,
        private readonly NotificationService        $notifService,
        private readonly MailerInterface            $mailer,
        private readonly UrlGeneratorInterface      $router,
    ) {}

    // =========================================================
    // ARBRE FAMILIAL (page publique)
    // =========================================================
    #[Route('', name: 'app_family_tree', methods: ['GET'])]
    public function index(string $slug): Response
    {
        $page        = $this->getPageOrNotFound($slug);
        $connections = $this->getAcceptedConnections($page);
        $isModerator = $this->isModerator($page);

        return $this->render('family/tree.html.twig', [
            'page'        => $page,
            'connections' => $connections,
            'treeData'    => $this->buildTreeData($page, $connections),
            'relations'   => array_keys(self::RELATIONS),
            'isModerator' => $isModerator,
        ]);
    }

    // =========================================================
    // DONNÉES JSON POUR LE GRAPHIQUE
    // =========================================================
    #[Route('/data', name: 'app_family_tree_data', methods: ['GET'])]
    public function treeData(string $slug): JsonResponse
    {
        $page        = $this->getPageOrNotFound($slug);
        $connections = $this->getAcceptedConnections($page);

        return $this->json($this->buildTreeData($page, $connections));
    }

    // =========================================================
    // CONNECTER UNE PAGE (chercher + proposer)
    // =========================================================
    #[Route('/connecter', name: 'app_family_connect', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function connect(string $slug, Request $request): Response
    {
        $page = $this->getPageOrNotFound($slug);

        if (!$this->isModerator($page)) {
            throw $this->createAccessDeniedException();
        }

        $error   = null;
        $success = null;
        $results = [];

        if ($request->isMethod('POST')) {
            $action = $request->request->get('action');

            if ($action === 'search') {
                $query   = trim($request->request->get('q', ''));
                $results = $query ? $this->memorialRepo->searchByName($query, 10) : [];
                // Exclure la page courante et les connexions existantes
                $existing = array_map(
                    fn($c) => $c->getMemorialFrom()->getId() === $page->getId()
                        ? $c->getMemorialTo()->getId()
                        : $c->getMemorialFrom()->getId(),
                    $this->connectionRepo->findAllForPage($page)
                );
                $results = array_filter($results, fn($p) => $p->getId() !== $page->getId() && !in_array($p->getId(), $existing));

            } elseif ($action === 'connect') {
                if (!$this->isCsrfTokenValid('family_connect_' . $page->getId(), $request->request->get('_token'))) {
                    $error = 'Token invalide.';
                } else {
                    $targetId    = (int) $request->request->get('target_id');
                    $relationFrom = trim($request->request->get('relation_from', ''));
                    $relationTo   = trim($request->request->get('relation_to', ''));

                    $target = $this->memorialRepo->find($targetId);

                    if (!$target) {
                        $error = 'Page mémorielle introuvable.';
                    } elseif (!$relationFrom || !$relationTo) {
                        $error = 'Veuillez sélectionner les deux relations.';
                    } else {
                        // Vérifier si connexion déjà existante
                        $existing = $this->connectionRepo->findOneBy([
                            'memorialFrom' => $page,
                            'memorialTo'   => $target,
                        ]) ?? $this->connectionRepo->findOneBy([
                            'memorialFrom' => $target,
                            'memorialTo'   => $page,
                        ]);

                        if ($existing) {
                            $error = 'Une connexion existe déjà entre ces deux pages.';
                        } else {
                            $connection = new FamilyConnection();
                            $connection->setMemorialFrom($page)
                                       ->setMemorialTo($target)
                                       ->setRelationFrom($relationFrom)
                                       ->setRelationTo($relationTo)
                                       ->setRequestedBy($this->getUser())
                                       ->setStatus(FamilyConnection::STATUS_PENDING);

                            $this->em->persist($connection);
                            $this->em->flush();

                            // Notifier le propriétaire de la page cible
                            $targetOwner = $this->em->getConnection()->executeQuery(
                                'SELECT u.id FROM users u INNER JOIN memorial_pages mp ON mp.created_by = u.id WHERE mp.id = ?',
                                [$target->getId()]
                            )->fetchOne();

                            if ($targetOwner) {
                                $targetUser = $this->em->getRepository(\App\Entity\User::class)->find($targetOwner);
                                if ($targetUser) {
                                    $treeUrl = $this->router->generate(
                                        'app_family_tree',
                                        ['slug' => $target->getSlug()],
                                        UrlGeneratorInterface::ABSOLUTE_URL
                                    );

                                    // URL vers les demandes de la page CIBLE
                                    $requestsUrl = $this->router->generate(
                                        'app_family_requests',
                                        ['slug' => $target->getSlug()],
                                        UrlGeneratorInterface::ABSOLUTE_URL
                                    );

                                    // Notification in-app
                                    $this->notifService->send(
                                        $targetUser,
                                        'family_connect_request',
                                        '🌳 Demande de connexion familiale',
                                        sprintf(
                                            '%s demande à connecter la page de %s à celle de %s (%s ↔ %s). Cliquez pour accepter ou refuser.',
                                            $this->getUser()->getFullName(),
                                            $page->getDeceasedFullName(),
                                            $target->getDeceasedFullName(),
                                            $relationFrom,
                                            $relationTo
                                        ),
                                        $requestsUrl
                                    );

                                    // Email
                                    try {
                                        $email = (new TemplatedEmail())
                                            ->from(new \Symfony\Component\Mime\Address(
                                                $_ENV['MAILER_FROM_ADDRESS'] ?? 'noreply@enmemoire.com',
                                                $_ENV['MAILER_FROM_NAME']    ?? 'EnMémoire.com'
                                            ))
                                            ->to($targetUser->getEmail())
                                            ->subject('🌳 Connexion familiale — ' . $target->getDeceasedFullName())
                                            ->htmlTemplate('emails/family_connected.html.twig')
                                            ->context([
                                                'page'         => $page,
                                                'target'       => $target,
                                                'relationFrom' => $relationFrom,
                                                'relationTo'   => $relationTo,
                                                'requestedBy'  => $this->getUser(),
                                                'tree_url'     => $treeUrl,
                                                'requests_url' => $requestsUrl,
                                            ]);
                                        $this->mailer->send($email);
                                    } catch (\Exception $e) {
                                        error_log('[EnMémoire] Family email failed: ' . $e->getMessage());
                                    }
                                }
                            }

                            $success = sprintf(
                                'Connexion établie : %s (%s) ↔ %s (%s)',
                                $page->getDeceasedFullName(), $relationFrom,
                                $target->getDeceasedFullName(), $relationTo
                            );
                        }
                    }
                }
            }
        }

        return $this->render('family/connect.html.twig', [
            'page'      => $page,
            'results'   => array_values($results),
            'relations' => array_keys(self::RELATIONS),
            'error'     => $error,
            'success'   => $success,
        ]);
    }


    // =========================================================
    // LISTE DES DEMANDES (reçues + envoyées)
    // =========================================================
    #[Route('/demandes', name: 'app_family_requests', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function requests(string $slug): Response
    {
        $page = $this->getPageOrNotFound($slug);

        if (!$this->isModerator($page)) {
            throw $this->createAccessDeniedException();
        }

        $sent     = $this->connectionRepo->findPendingFrom($page);
        $received = $this->connectionRepo->findPendingTo($page);
        $accepted = $this->connectionRepo->findAcceptedForPage($page);

        return $this->render('family/requests.html.twig', [
            'page'     => $page,
            'sent'     => $sent,
            'received' => $received,
            'accepted' => $accepted,
        ]);
    }

    // =========================================================
    // ACCEPTER UNE DEMANDE
    // =========================================================
    #[Route('/accepter/{connectionId}', name: 'app_family_accept', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function acceptConnection(string $slug, int $connectionId, Request $request): JsonResponse
    {
        $page = $this->getPageOrNotFound($slug);

        if (!$this->isModerator($page)) {
            return $this->json(['error' => 'Accès refusé'], 403);
        }

        if (!$this->isCsrfTokenValid('family_accept_' . $connectionId, $request->request->get('_token'))) {
            return $this->json(['error' => 'Token invalide'], 403);
        }

        $connection = $this->connectionRepo->find($connectionId);

        if (!$connection || $connection->getMemorialTo()->getId() !== $page->getId()) {
            return $this->json(['error' => 'Demande introuvable'], 404);
        }

        $connection->setStatus(FamilyConnection::STATUS_ACCEPTED)
                   ->setConfirmedBy($this->getUser())
                   ->setRespondedAt(new \DateTime());
        $this->em->flush();

        // Notifier l'expéditeur
        $fromOwner = $this->em->getConnection()->executeQuery(
            'SELECT u.id FROM users u INNER JOIN memorial_pages mp ON mp.created_by = u.id WHERE mp.id = ?',
            [$connection->getMemorialFrom()->getId()]
        )->fetchOne();

        if ($fromOwner) {
            $fromUser = $this->em->getRepository(\App\Entity\User::class)->find($fromOwner);
            if ($fromUser) {
                $this->notifService->send(
                    $fromUser,
                    'family_accepted',
                    '✅ Connexion familiale acceptée',
                    sprintf(
                        '%s a accepté la connexion familiale avec la page de %s.',
                        $page->getDeceasedFullName(),
                        $connection->getMemorialFrom()->getDeceasedFullName()
                    ),
                    $this->router->generate('app_family_tree', ['slug' => $connection->getMemorialFrom()->getSlug()])
                );
            }
        }

        return $this->json(['success' => true, 'message' => 'Connexion acceptée.']);
    }

    // =========================================================
    // REFUSER UNE DEMANDE
    // =========================================================
    #[Route('/refuser/{connectionId}', name: 'app_family_reject', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function rejectConnection(string $slug, int $connectionId, Request $request): JsonResponse
    {
        $page = $this->getPageOrNotFound($slug);

        if (!$this->isModerator($page)) {
            return $this->json(['error' => 'Accès refusé'], 403);
        }

        if (!$this->isCsrfTokenValid('family_reject_' . $connectionId, $request->request->get('_token'))) {
            return $this->json(['error' => 'Token invalide'], 403);
        }

        $connection = $this->connectionRepo->find($connectionId);

        if (!$connection || $connection->getMemorialTo()->getId() !== $page->getId()) {
            return $this->json(['error' => 'Demande introuvable'], 404);
        }

        $connection->setStatus(FamilyConnection::STATUS_REJECTED)
                   ->setRespondedAt(new \DateTime());
        $this->em->flush();

        // Notifier l'expéditeur
        $fromOwner = $this->em->getConnection()->executeQuery(
            'SELECT u.id FROM users u INNER JOIN memorial_pages mp ON mp.created_by = u.id WHERE mp.id = ?',
            [$connection->getMemorialFrom()->getId()]
        )->fetchOne();

        if ($fromOwner) {
            $fromUser = $this->em->getRepository(\App\Entity\User::class)->find($fromOwner);
            if ($fromUser) {
                $this->notifService->send(
                    $fromUser,
                    'family_rejected',
                    '❌ Connexion familiale refusée',
                    sprintf(
                        'La page de %s a refusé la connexion familiale avec %s.',
                        $page->getDeceasedFullName(),
                        $connection->getMemorialFrom()->getDeceasedFullName()
                    ),
                    ''
                );
            }
        }

        return $this->json(['success' => true, 'message' => 'Demande refusée.']);
    }

    // =========================================================
    // SUPPRIMER UNE CONNEXION (Ajax)
    // =========================================================
    #[Route('/supprimer/{connectionId}', name: 'app_family_delete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function deleteConnection(string $slug, int $connectionId, Request $request): JsonResponse
    {
        $page = $this->getPageOrNotFound($slug);

        if (!$this->isModerator($page)) {
            return $this->json(['error' => 'Accès refusé'], 403);
        }

        if (!$this->isCsrfTokenValid('family_delete_' . $connectionId, $request->request->get('_token'))) {
            return $this->json(['error' => 'Token invalide'], 403);
        }

        $connection = $this->connectionRepo->find($connectionId);

        if (!$connection ||
            ($connection->getMemorialFrom()->getId() !== $page->getId() &&
             $connection->getMemorialTo()->getId()   !== $page->getId())) {
            return $this->json(['error' => 'Connexion introuvable'], 404);
        }

        $this->em->remove($connection);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    // =========================================================
    // HELPERS
    // =========================================================
    private function getPageOrNotFound(string $slug): MemorialPage
    {
        $page = $this->memorialRepo->findBySlug($slug);
        if (!$page) throw $this->createNotFoundException();
        return $page;
    }

    private function isModerator(MemorialPage $page): bool
    {
        $user = $this->getUser();
        if (!$user) return false;

        $row = $this->em->getConnection()->executeQuery(
            'SELECT created_by FROM memorial_pages WHERE id = ?', [$page->getId()]
        )->fetchAssociative();

        if ($row && (int)$row['created_by'] === $user->getId()) return true;
        return $this->memorialService->isModerator($page, $user);
    }

    private function getAcceptedConnections(MemorialPage $page): array
    {
        return $this->connectionRepo->findAcceptedForPage($page);
    }

    private function buildTreeData(MemorialPage $page, array $connections): array
    {
        $nodes = [];
        $edges = [];

        // Nœud central
        $nodes[$page->getId()] = [
            'id'       => $page->getId(),
            'label'    => $page->getDeceasedFullName(),
            'dates'    => $page->getDeceasedBirthDate()?->format('Y') . '–' . $page->getDeceasedDeathDate()?->format('Y'),
            'photo'    => $page->getMainPhotoUrl(),
            'slug'     => $page->getSlug(),
            'isCentral' => true,
        ];

        foreach ($connections as $conn) {
            $from = $conn->getMemorialFrom();
            $to   = $conn->getMemorialTo();

            // Ajouter nœuds si absents
            foreach ([$from, $to] as $p) {
                if (!isset($nodes[$p->getId()])) {
                    $nodes[$p->getId()] = [
                        'id'       => $p->getId(),
                        'label'    => $p->getDeceasedFullName(),
                        'dates'    => $p->getDeceasedBirthDate()?->format('Y') . '–' . $p->getDeceasedDeathDate()?->format('Y'),
                        'photo'    => $p->getMainPhotoUrl(),
                        'slug'     => $p->getSlug(),
                        'isCentral' => false,
                    ];
                }
            }

            $edges[] = [
                'id'           => $conn->getId(),
                'from'         => $from->getId(),
                'to'           => $to->getId(),
                'relationFrom' => $conn->getRelationFrom(),
                'relationTo'   => $conn->getRelationTo(),
            ];
        }

        return [
            'nodes' => array_values($nodes),
            'edges' => $edges,
        ];
    }
}
