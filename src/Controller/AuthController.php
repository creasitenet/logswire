<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;

final class AuthController extends AbstractController
{
    #[Route('/logout', name: 'logout')]
    public function logout(): never
    {
        // The firewall intercepts /logout before this method runs.
        throw new \LogicException('Logout is handled by the firewall.');
    }
}
