<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LocaleController extends AbstractController
{
    /**
     * Change la langue de l'application et redirige vers la page précédente.
     * Route : /set-locale/{locale}
     */
    #[Route('/set-locale/{locale}', name: 'app_set_locale', requirements: ['locale' => 'fr|en'])]
    public function setLocale(string $locale, Request $request): Response
    {
        // Stocker la locale en session
        $request->getSession()->set('_locale', $locale);

        // Rediriger vers la page précédente, ou vers l'accueil
        $referer = $request->headers->get('referer');
        if ($referer) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('app_home');
    }
}
