<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SiteController extends AbstractController
{
    #[Route('/login', name: 'login', methods: ['GET'])]
    public function loginRedirect(): RedirectResponse
    {
        return $this->redirectToRoute('home');
    }

    #[Route('/', name: 'home', methods: ['GET'])]
    public function home(AuthenticationUtils $authUtils): Response|RedirectResponse
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('dashboard');
        }

        return $this->render('site/home.html.twig', [
            'title' => 'Sign in — Logswire',
            'error' => $authUtils->getLastAuthenticationError(),
            'last_username' => $authUtils->getLastUsername(),
        ]);
    }

    /**
     * The firewall intercepts POST /login before the router reaches this
     * controller. The route must still exist so check_path resolves.
     */
    #[Route('/login', name: 'app_login', methods: ['POST'])]
    public function loginCheck(): never
    {
        throw new \LogicException('POST /login is intercepted by the firewall.');
    }
}
