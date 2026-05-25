<?php

namespace App\Controller;

use App\Entity\GadgetCatalog;
use App\Entity\GadgetInteraction;
use App\Entity\GadgetPurchase;
use App\Entity\MemorialPage;
use App\Entity\UserGadgetWallet;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class GadgetController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    // =========================================================
    // BOUTIQUE
    // =========================================================
    #[Route('/boutique', name: 'app_gadget_shop')]
    public function shop(): Response
    {
        $gadgets = $this->em->getRepository(GadgetCatalog::class)
            ->findBy(['status' => GadgetCatalog::STATUS_ACTIVE], ['type' => 'ASC', 'price' => 'ASC']);

        $wallet = [];
        if ($this->getUser()) {
            $walletItems = $this->em->getRepository(UserGadgetWallet::class)
                ->findBy(['user' => $this->getUser()]);
            foreach ($walletItems as $item) {
                $wallet[$item->getGadget()->getId()] = $item->getQuantity();
            }
        }

        // Grouper par type
        $byType = [];
        foreach ($gadgets as $gadget) {
            $byType[$gadget->getType()][] = $gadget;
        }

        return $this->render('gadget/shop.html.twig', [
            'byType' => $byType,
            'wallet' => $wallet,
        ]);
    }

    // =========================================================
    // PORTEFEUILLE
    // =========================================================
    #[Route('/boutique/portefeuille', name: 'app_gadget_wallet')]
    #[IsGranted('ROLE_USER')]
    public function wallet(): Response
    {
        $walletItems = $this->em->getRepository(UserGadgetWallet::class)
            ->findBy(['user' => $this->getUser()], []);

        $purchases = $this->em->getRepository(GadgetPurchase::class)
            ->findBy(['user' => $this->getUser()], ['createdAt' => 'DESC'], 20);

        return $this->render('gadget/wallet.html.twig', [
            'walletItems' => $walletItems,
            'purchases'   => $purchases,
        ]);
    }

    // =========================================================
    // ACHAT DIRECT (sans paiement — pour test / gratuit)
    // =========================================================
    #[Route('/boutique/acheter/{id}', name: 'app_gadget_buy', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function buy(int $id, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('gadget_buy_' . $id, $request->request->get('_token'))) {
            return $this->json(['error' => 'Token invalide'], 403);
        }

        $gadget = $this->em->getRepository(GadgetCatalog::class)->find($id);
        if (!$gadget || !$gadget->isActive()) {
            return $this->json(['error' => 'Gadget introuvable'], 404);
        }

        $quantity = max(1, (int)$request->request->get('quantity', 1));
        $user     = $this->getUser();

        // Enregistrer l'achat
        $purchase = new GadgetPurchase();
        $purchase->setUser($user)
                 ->setGadget($gadget)
                 ->setQuantity($quantity)
                 ->setUnitPrice($gadget->getPrice())
                 ->setTotalPrice(bcmul($gadget->getPrice(), (string)$quantity, 2))
                 ->setCurrency($gadget->getCurrency());
        $this->em->persist($purchase);

        // Mettre à jour le portefeuille
        $walletItem = $this->em->getRepository(UserGadgetWallet::class)
            ->findOneBy(['user' => $user, 'gadget' => $gadget]);

        if (!$walletItem) {
            $walletItem = new UserGadgetWallet();
            $walletItem->setUser($user)->setGadget($gadget)->setQuantity(0);
            $this->em->persist($walletItem);
        }
        $walletItem->setQuantity($walletItem->getQuantity() + $quantity);

        $this->em->flush();

        return $this->json([
            'success'     => true,
            'newQuantity' => $walletItem->getQuantity(),
            'gadgetName'  => $gadget->getName(),
        ]);
    }

    // =========================================================
    // API — Gadgets du portefeuille (pour dépôt sur page)
    // =========================================================
    #[Route('/api/gadgets/wallet', name: 'app_api_gadget_wallet', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function apiWallet(): JsonResponse
    {
        $items = $this->em->getRepository(UserGadgetWallet::class)
            ->findBy(['user' => $this->getUser()]);

        $data = array_map(fn($i) => [
            'id'           => $i->getGadget()->getId(),
            'name'         => $i->getGadget()->getName(),
            'type'         => $i->getGadget()->getType(),
            'imageUrl'     => $i->getGadget()->getImageUrl(),
            'animationUrl' => $i->getGadget()->getAnimationUrl(),
            'allowsText'   => $i->getGadget()->isAllowsCustomText(),
            'maxTextLen'   => $i->getGadget()->getMaxTextLength(),
            'quantity'     => $i->getQuantity(),
        ], array_filter($items, fn($i) => $i->getQuantity() > 0));

        return $this->json(array_values($data));
    }

    // =========================================================
    // DÉPÔT sur page mémorielle
    // =========================================================
    #[Route('/boutique/deposer/{slug}', name: 'app_gadget_deposit', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function deposit(string $slug, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('gadget_deposit', $request->request->get('_token'))) {
            return $this->json(['error' => 'Token invalide'], 403);
        }

        $page = $this->em->getRepository(MemorialPage::class)->findBySlug($slug);
        if (!$page) return $this->json(['error' => 'Page introuvable'], 404);

        $gadgetId = (int)$request->request->get('gadget_id');
        $gadget   = $this->em->getRepository(GadgetCatalog::class)->find($gadgetId);
        if (!$gadget || !$gadget->isActive()) {
            return $this->json(['error' => 'Gadget introuvable'], 404);
        }

        $user = $this->getUser();

        // Vérifier le portefeuille
        $walletItem = $this->em->getRepository(UserGadgetWallet::class)
            ->findOneBy(['user' => $user, 'gadget' => $gadget]);

        if (!$walletItem || $walletItem->getQuantity() < 1) {
            return $this->json(['error' => 'Vous n\'avez pas ce gadget dans votre portefeuille.'], 400);
        }

        // Décrémenter le portefeuille
        $walletItem->setQuantity($walletItem->getQuantity() - 1);

        // Enregistrer l'interaction
        $interaction = new GadgetInteraction();
        $interaction->setMemorial($page)
                    ->setUser($user)
                    ->setGadget($gadget)
                    ->setAction($gadget->getType())
                    ->setCustomText($request->request->get('custom_text') ?: null);

        $this->em->persist($interaction);
        $this->em->flush();

        $counts = $this->getInteractionCounts($page->getId());

        return $this->json([
            'success'           => true,
            'gadgetName'        => $gadget->getName(),
            'gadgetType'        => $gadget->getType(),
            'gadgetEmoji'       => $this->typeEmoji($gadget->getType()),
            'customText'        => $interaction->getCustomText(),
            'userName'          => $user->getFullName(),
            'remainingInWallet' => $walletItem->getQuantity(),
            'counts'            => $counts,
        ]);
    }

    // =========================================================
    // API — Interactions sur une page (gadgets déposés)
    // =========================================================
    #[Route('/api/gadgets/interactions/{slug}', name: 'app_api_gadget_interactions', methods: ['GET'])]
    public function interactions(string $slug): JsonResponse
    {
        $page = $this->em->getRepository(MemorialPage::class)->findBySlug($slug);
        if (!$page) return $this->json([]);

        $interactions = $this->em->getRepository(GadgetInteraction::class)
            ->findBy(['memorial' => $page], ['createdAt' => 'DESC'], 50);

        $data = array_map(fn($i) => [
            'id'         => $i->getId(),
            'type'       => $i->getGadget()->getType(),
            'name'       => $i->getGadget()->getName(),
            'emoji'      => $this->typeEmoji($i->getGadget()->getType()),
            'customText' => $i->getCustomText(),
            'userName'   => $i->getUser()->getFullName(),
            'createdAt'  => $i->getCreatedAt()->format('d/m/Y à H:i'),
        ], $interactions);

        return $this->json([
            'interactions' => $data,
            'counts'       => $this->getInteractionCounts($page->getId()),
        ]);
    }

    private function getInteractionCounts(int $pageId): array
    {
        $rows = $this->em->getConnection()->executeQuery(
            "SELECT g.type, COUNT(*) as cnt
             FROM gadget_interactions gi
             JOIN gadget_catalog g ON g.id = gi.gadget_id
             WHERE gi.memorial_id = ?
             GROUP BY g.type",
            [$pageId]
        )->fetchAllAssociative();

        $counts = ['flower' => 0, 'candle' => 0, 'dove' => 0, 'other' => 0];
        foreach ($rows as $row) {
            $counts[$row['type']] = (int)$row['cnt'];
        }
        return $counts;
    }

    private function typeEmoji(string $type): string
    {
        return match($type) {
            'flower' => '🌸', 'candle' => '🕯️', 'dove' => '🕊️', default => '✨'
        };
    }
}
