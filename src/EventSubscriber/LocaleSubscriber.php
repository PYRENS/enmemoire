<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class LocaleSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => [['onRequest', 20]]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) return;

        $request = $event->getRequest();
        $session = $request->getSession();

        // Lire ?_locale= depuis l'URL
        $locale = $request->query->get('_locale');
        if (in_array($locale, ['fr', 'en'], true)) {
            $session->set('_locale', $locale);
        }

        // Appliquer depuis la session (défaut : fr)
        $request->setLocale($session->get('_locale', 'fr'));
    }
}
