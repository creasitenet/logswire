<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Overview\OverviewService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dashboard')]
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly OverviewService $overview,
    ) {
    }

    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('dashboard/index.html.twig', [
            'title' => 'Overview — Logswire',
            'stats' => $this->overview->snapshot(),
        ]);
    }
}
