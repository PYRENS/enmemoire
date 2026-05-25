<?php

namespace App\Controller;

use App\Entity\MemorialFormula;
use App\Entity\MemorialPage;
use App\Entity\MemorialTheme;
use App\Entity\Payment;
use App\Entity\User;
use App\Repository\MemorialPageRepository;
use App\Repository\PaymentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface       $em,
        private readonly UserPasswordHasherInterface  $hasher,
    ) {}

    // =========================================================
    // DASHBOARD
    // =========================================================
    #[Route('', name: 'app_admin_dashboard')]
    public function dashboard(): Response
    {
        $stats = [
            'users'     => $this->em->getRepository(User::class)->count([]),
            'pages'     => $this->em->getRepository(MemorialPage::class)->count([]),
            'payments'  => $this->em->getRepository(Payment::class)->count(['status' => 'completed']),
            'revenue'   => $this->em->getConnection()->executeQuery(
                "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'completed'"
            )->fetchOne(),
            'pending'   => $this->em->getRepository(Payment::class)->count(['status' => 'pending']),
            'suspended' => $this->em->getRepository(User::class)->count(['status' => 'suspended']),
        ];

        $recentPayments = $this->em->getRepository(Payment::class)
            ->findBy([], ['createdAt' => 'DESC'], 10);

        $recentUsers = $this->em->getRepository(User::class)
            ->findBy([], ['createdAt' => 'DESC'], 5);

        return $this->render('admin/dashboard.html.twig', [
            'stats'          => $stats,
            'recentPayments' => $recentPayments,
            'recentUsers'    => $recentUsers,
        ]);
    }

    // =========================================================
    // FORMULES
    // =========================================================
    #[Route('/formules', name: 'app_admin_formulas')]
    public function formulas(): Response
    {
        $formulas = $this->em->getRepository(MemorialFormula::class)
            ->findBy([], ['sortOrder' => 'ASC']);

        return $this->render('admin/formulas/index.html.twig', [
            'formulas' => $formulas,
        ]);
    }

    #[Route('/formules/new', name: 'app_admin_formula_new', methods: ['GET', 'POST'])]
    public function formulaNew(Request $request): Response
    {
        $formula = new MemorialFormula();
        if ($request->isMethod('POST')) {
            $this->fillFormula($formula, $request);
            $this->em->persist($formula);
            $this->em->flush();
            $this->addFlash('success', 'Formule créée.');
            return $this->redirectToRoute('app_admin_formulas');
        }
        return $this->render('admin/formulas/form.html.twig', ['formula' => $formula, 'isNew' => true]);
    }

    #[Route('/formules/{id}/edit', name: 'app_admin_formula_edit', methods: ['GET', 'POST'])]
    public function formulaEdit(int $id, Request $request): Response
    {
        $formula = $this->em->getRepository(MemorialFormula::class)->find($id);
        if (!$formula) throw $this->createNotFoundException();

        if ($request->isMethod('POST')) {
            $this->fillFormula($formula, $request);
            $this->em->flush();
            $this->addFlash('success', 'Formule mise à jour.');
            return $this->redirectToRoute('app_admin_formulas');
        }
        return $this->render('admin/formulas/form.html.twig', ['formula' => $formula, 'isNew' => false]);
    }

    #[Route('/formules/{id}/toggle', name: 'app_admin_formula_toggle', methods: ['POST'])]
    public function formulaToggle(int $id): JsonResponse
    {
        $formula = $this->em->getRepository(MemorialFormula::class)->find($id);
        if (!$formula) return $this->json(['error' => 'Not found'], 404);
        $formula->setIsActive(!$formula->isActive());
        $this->em->flush();
        return $this->json(['active' => $formula->isActive()]);
    }

    #[Route('/formules/{id}/delete', name: 'app_admin_formula_delete', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function formulaDelete(int $id, Request $request): Response
    {
        $formula = $this->em->getRepository(MemorialFormula::class)->find($id);
        if ($formula && $this->isCsrfTokenValid('formula_delete_' . $id, $request->request->get('_token'))) {
            if ($formula->getMemorialPages()->count() > 0) {
                $this->addFlash('danger', 'Impossible : des pages utilisent cette formule.');
            } else {
                $this->em->remove($formula);
                $this->em->flush();
                $this->addFlash('success', 'Formule supprimée.');
            }
        }
        return $this->redirectToRoute('app_admin_formulas');
    }

    private function fillFormula(MemorialFormula $f, Request $r): void
    {
        $f->setSlug($r->request->get('slug', ''))
          ->setName($r->request->get('name', ''))
          ->setDescription($r->request->get('description') ?: null)
          ->setPrice($r->request->get('price', '0.00'))
          ->setCurrency($r->request->get('currency', 'EUR'))
          ->setDurationYears($r->request->get('duration_years') !== '' ? (int)$r->request->get('duration_years') : null)
          ->setMaxEvents((int)$r->request->get('max_events', 1))
          ->setMaxMediaGb((int)$r->request->get('max_media_gb', 1))
          ->setHasLive($r->request->get('has_live') === '1')
          ->setHasPremiumThemes($r->request->get('has_premium_themes') === '1')
          ->setHasQrCode($r->request->get('has_qr_code') === '1')
          ->setHasVideo($r->request->get('has_video') === '1')
          ->setHasAdvancedStats($r->request->get('has_advanced_stats') === '1')
          ->setIsActive($r->request->get('is_active') === '1')
          ->setSortOrder((int)$r->request->get('sort_order', 0));
    }

    // =========================================================
    // THÈMES
    // =========================================================
    #[Route('/themes', name: 'app_admin_themes')]
    public function themes(): Response
    {
        $themes = $this->em->getRepository(MemorialTheme::class)
            ->findBy([], ['sortOrder' => 'ASC']);

        return $this->render('admin/themes/index.html.twig', ['themes' => $themes]);
    }

    #[Route('/themes/new', name: 'app_admin_theme_new', methods: ['GET', 'POST'])]
    public function themeNew(Request $request): Response
    {
        $theme = new MemorialTheme();
        if ($request->isMethod('POST')) {
            $this->fillTheme($theme, $request);
            $this->em->persist($theme);
            $this->em->flush();
            $this->addFlash('success', 'Thème créé.');
            return $this->redirectToRoute('app_admin_themes');
        }
        return $this->render('admin/themes/form.html.twig', ['theme' => $theme, 'isNew' => true]);
    }

    #[Route('/themes/{id}/edit', name: 'app_admin_theme_edit', methods: ['GET', 'POST'])]
    public function themeEdit(int $id, Request $request): Response
    {
        $theme = $this->em->getRepository(MemorialTheme::class)->find($id);
        if (!$theme) throw $this->createNotFoundException();

        if ($request->isMethod('POST')) {
            $this->fillTheme($theme, $request);
            $this->em->flush();
            $this->addFlash('success', 'Thème mis à jour.');
            return $this->redirectToRoute('app_admin_themes');
        }
        return $this->render('admin/themes/form.html.twig', ['theme' => $theme, 'isNew' => false]);
    }

    #[Route('/themes/{id}/toggle', name: 'app_admin_theme_toggle', methods: ['POST'])]
    public function themeToggle(int $id): JsonResponse
    {
        $theme = $this->em->getRepository(MemorialTheme::class)->find($id);
        if (!$theme) return $this->json(['error' => 'Not found'], 404);
        $theme->setIsActive(!$theme->isActive());
        $this->em->flush();
        return $this->json(['active' => $theme->isActive()]);
    }

    #[Route('/themes/{id}/delete', name: 'app_admin_theme_delete', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function themeDelete(int $id, Request $request): Response
    {
        $theme = $this->em->getRepository(MemorialTheme::class)->find($id);
        if ($theme && $this->isCsrfTokenValid('theme_delete_' . $id, $request->request->get('_token'))) {
            if ($theme->getMemorialPages()->count() > 0) {
                $this->addFlash('danger', 'Impossible : des pages utilisent ce thème.');
            } else {
                $this->em->remove($theme);
                $this->em->flush();
                $this->addFlash('success', 'Thème supprimé.');
            }
        }
        return $this->redirectToRoute('app_admin_themes');
    }

    private function fillTheme(MemorialTheme $t, Request $r): void
    {
        $t->setSlug($r->request->get('slug', ''))
          ->setName($r->request->get('name', ''))
          ->setDescription($r->request->get('description') ?: null)
          ->setCssClass($r->request->get('css_class') ?: null)
          ->setType($r->request->get('type', MemorialTheme::TYPE_FREE))
          ->setPrice($r->request->get('price', '0.00'))
          ->setCurrency($r->request->get('currency', 'EUR'))
          ->setIsActive($r->request->get('is_active') === '1')
          ->setSortOrder((int)$r->request->get('sort_order', 0));
    }

    // =========================================================
    // UTILISATEURS
    // =========================================================
    #[Route('/utilisateurs', name: 'app_admin_users')]
    public function users(Request $request): Response
    {
        $search = $request->query->get('q', '');
        $status = $request->query->get('status', '');
        $role   = $request->query->get('role', '');
        $page   = max(1, (int)$request->query->get('page', 1));
        $limit  = 20;

        $qb = $this->em->getRepository(User::class)->createQueryBuilder('u');

        if ($search) {
            $qb->andWhere('u.firstName LIKE :q OR u.lastName LIKE :q OR u.email LIKE :q')
               ->setParameter('q', '%' . $search . '%');
        }
        if ($status) $qb->andWhere('u.status = :status')->setParameter('status', $status);
        if ($role)   $qb->andWhere('u.role = :role')->setParameter('role', $role);

        $total = (clone $qb)->select('COUNT(u.id)')->getQuery()->getSingleScalarResult();

        $users = $qb->orderBy('u.createdAt', 'DESC')
                    ->setFirstResult(($page - 1) * $limit)
                    ->setMaxResults($limit)
                    ->getQuery()->getResult();

        return $this->render('admin/users/index.html.twig', [
            'users'  => $users,
            'total'  => $total,
            'page'   => $page,
            'pages'  => ceil($total / $limit),
            'search' => $search,
            'status' => $status,
            'role'   => $role,
        ]);
    }

    #[Route('/utilisateurs/{id}', name: 'app_admin_user_show')]
    public function userShow(int $id): Response
    {
        $user = $this->em->getRepository(User::class)->find($id);
        if (!$user) throw $this->createNotFoundException();

        $payments = $this->em->getRepository(Payment::class)
            ->findBy(['user' => $user], ['createdAt' => 'DESC'], 20);

        return $this->render('admin/users/show.html.twig', [
            'user'     => $user,
            'payments' => $payments,
        ]);
    }

    #[Route('/utilisateurs/{id}/role', name: 'app_admin_user_role', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function userRole(int $id, Request $request): JsonResponse
    {
        $user = $this->em->getRepository(User::class)->find($id);
        if (!$user) return $this->json(['error' => 'Not found'], 404);

        $role = $request->request->get('role');
        $validRoles = [User::ROLE_USER, User::ROLE_MAKER, User::ROLE_MANAGER, User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN];
        if (!in_array($role, $validRoles)) return $this->json(['error' => 'Rôle invalide'], 400);

        $user->setRole($role);
        $this->em->flush();
        return $this->json(['success' => true, 'role' => $role]);
    }

    #[Route('/utilisateurs/{id}/status', name: 'app_admin_user_status', methods: ['POST'])]
    public function userStatus(int $id, Request $request): JsonResponse
    {
        $user = $this->em->getRepository(User::class)->find($id);
        if (!$user) return $this->json(['error' => 'Not found'], 404);

        $status = $request->request->get('status');
        $validStatuses = [User::STATUS_ACTIVE, User::STATUS_SUSPENDED, User::STATUS_DISABLED];
        if (!in_array($status, $validStatuses)) return $this->json(['error' => 'Statut invalide'], 400);

        $user->setStatus($status);
        $this->em->flush();
        return $this->json(['success' => true, 'status' => $status]);
    }

    #[Route('/utilisateurs/{id}/reset-password', name: 'app_admin_user_reset_password', methods: ['POST'])]
    public function userResetPassword(int $id, Request $request): JsonResponse
    {
        $user = $this->em->getRepository(User::class)->find($id);
        if (!$user) return $this->json(['error' => 'Not found'], 404);

        $newPassword = $request->request->get('password', '');
        if (strlen($newPassword) < 8) return $this->json(['error' => 'Mot de passe trop court (min 8 caractères)'], 400);

        $hash = $this->hasher->hashPassword($user, $newPassword);
        $user->setPasswordHash($hash);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    // =========================================================
    // PAIEMENTS
    // =========================================================
    #[Route('/paiements', name: 'app_admin_payments')]
    public function payments(Request $request): Response
    {
        $status   = $request->query->get('status', '');
        $provider = $request->query->get('provider', '');
        $page     = max(1, (int)$request->query->get('page', 1));
        $limit    = 25;

        $qb = $this->em->getRepository(Payment::class)->createQueryBuilder('p')
            ->leftJoin('p.user', 'u')->addSelect('u');

        if ($status)   $qb->andWhere('p.status = :status')->setParameter('status', $status);
        if ($provider) $qb->andWhere('p.provider = :provider')->setParameter('provider', $provider);

        $total = (clone $qb)->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();

        $payments = $qb->orderBy('p.createdAt', 'DESC')
                       ->setFirstResult(($page - 1) * $limit)
                       ->setMaxResults($limit)
                       ->getQuery()->getResult();

        $totalRevenue = $this->em->getConnection()->executeQuery(
            "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'completed'"
        )->fetchOne();

        return $this->render('admin/payments/index.html.twig', [
            'payments'     => $payments,
            'total'        => $total,
            'page'         => $page,
            'pages'        => ceil($total / $limit),
            'status'       => $status,
            'provider'     => $provider,
            'totalRevenue' => $totalRevenue,
        ]);
    }

    #[Route('/paiements/{id}/validate', name: 'app_admin_payment_validate', methods: ['POST'])]
    public function paymentValidate(int $id, Request $request): JsonResponse
    {
        $payment = $this->em->getRepository(Payment::class)->find($id);
        if (!$payment) return $this->json(['error' => 'Not found'], 404);
        if ($payment->getStatus() !== Payment::STATUS_PENDING) {
            return $this->json(['error' => 'Paiement non en attente'], 400);
        }

        $payment->setStatus(Payment::STATUS_COMPLETED);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/paiements/{id}/refund', name: 'app_admin_payment_refund', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function paymentRefund(int $id): JsonResponse
    {
        $payment = $this->em->getRepository(Payment::class)->find($id);
        if (!$payment) return $this->json(['error' => 'Not found'], 404);

        $payment->setStatus(Payment::STATUS_REFUNDED);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    // =========================================================
    // PAGES MÉMORIELLES
    // =========================================================
    #[Route('/memoriaux', name: 'app_admin_memorials')]
    public function memorials(Request $request): Response
    {
        $search = $request->query->get('q', '');
        $status = $request->query->get('status', '');
        $page   = max(1, (int)$request->query->get('page', 1));
        $limit  = 20;

        $qb = $this->em->getRepository(MemorialPage::class)->createQueryBuilder('m')
            ->leftJoin('m.createdBy', 'u')->addSelect('u')
            ->leftJoin('m.formula', 'f')->addSelect('f');

        if ($search) {
            $qb->andWhere('m.deceasedFirstName LIKE :q OR m.deceasedLastName LIKE :q OR m.slug LIKE :q')
               ->setParameter('q', '%' . $search . '%');
        }
        if ($status) $qb->andWhere('m.status = :status')->setParameter('status', $status);

        $total = (clone $qb)->select('COUNT(m.id)')->getQuery()->getSingleScalarResult();

        $memorials = $qb->orderBy('m.createdAt', 'DESC')
                        ->setFirstResult(($page - 1) * $limit)
                        ->setMaxResults($limit)
                        ->getQuery()->getResult();

        return $this->render('admin/memorials/index.html.twig', [
            'memorials' => $memorials,
            'total'     => $total,
            'page'      => $page,
            'pages'     => ceil($total / $limit),
            'search'    => $search,
            'status'    => $status,
        ]);
    }

    #[Route('/memoriaux/{id}/status', name: 'app_admin_memorial_status', methods: ['POST'])]
    public function memorialStatus(int $id, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('memorial_status_' . $id, $request->request->get('_token'))) {
            return $this->json(['error' => 'Token invalide'], 403);
        }

        $page = $this->em->getRepository(MemorialPage::class)->find($id);
        if (!$page) return $this->json(['error' => 'Not found'], 404);

        $status = $request->request->get('status');
        $valid  = [MemorialPage::STATUS_ACTIVE, MemorialPage::STATUS_SUSPENDED, MemorialPage::STATUS_ARCHIVED];
        if (!in_array($status, $valid)) return $this->json(['error' => 'Statut invalide'], 400);

        $page->setStatus($status);
        $this->em->flush();
        return $this->json(['success' => true]);
    }
}
