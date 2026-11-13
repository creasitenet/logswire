<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Database\DatabaseStatsService;
use App\Service\Retention\RetentionRunner;
use App\Service\Telegram\TelegramChannel;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/dashboard/settings')]
#[IsGranted('ROLE_ADMIN')]
final class SettingsController extends AbstractController
{
    use CsrfGuardTrait;

    private const string CSRF_TOKEN = 'settings_action';

    public function __construct(
        private readonly TelegramChannel $telegram,
        private readonly RetentionRunner $retention,
        private readonly DatabaseStatsService $databaseStats,
    ) {
    }

    #[Route('', name: 'settings', methods: ['GET'])]
    public function index(): Response
    {
        $lastRun = $this->retention->lastRunAt();

        return $this->render('settings/index.html.twig', [
            'title' => 'Settings — Logswire',
            'telegram' => [
                'configured' => $this->telegram->isConfigured(),
                'token_mask' => $this->telegram->maskedBotToken(),
                'chat_mask' => $this->telegram->maskedChatId(),
            ],
            'retention' => [
                'enabled' => $this->retention->isEnabled(),
                'days' => $this->retention->retentionDays(),
                'last_run_at' => $lastRun?->format(\DateTimeInterface::ATOM),
            ],
            'capacity' => $this->databaseStats->capacitySnapshot(),
        ]);
    }

    #[Route('/telegram-test', name: 'settings_telegram_test', methods: ['POST'])]
    public function telegramTest(Request $request): Response
    {
        $this->guardCsrf($request, self::CSRF_TOKEN);

        $result = $this->telegram->notify(
            "Logswire test — Telegram alerts are working.\nTime (UTC): ".gmdate('Y-m-d H:i:s'),
        );

        if ($result['sent'] ?? false) {
            $this->addFlash('success', 'Test message sent to Telegram.');
        } else {
            $this->addFlash('error', 'Could not send: '.$result['detail']);
        }

        return $this->redirectToRoute('settings');
    }
}
