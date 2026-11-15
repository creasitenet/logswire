<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Monitor\MonitorPresenter;
use App\Service\Monitor\MonitorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dashboard/monitors')]
final class MonitorController extends AbstractController
{
    use CsrfGuardTrait;

    private const string CSRF_TOKEN = 'monitor_action';

    public function __construct(
        private readonly MonitorService $monitors,
        private readonly MonitorPresenter $presenter,
    ) {
    }

    #[Route('', name: 'monitors_list', methods: ['GET'])]
    public function list(): Response
    {
        return $this->render('monitors/list.html.twig', [
            'title' => 'Monitors — Logswire',
            'monitors' => $this->presenter->listRows($this->monitors->list()),
        ]);
    }

    #[Route('/new', name: 'monitors_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $form = $this->readForm($request);

        if ($request->isMethod('POST')) {
            $this->guardCsrf($request, self::CSRF_TOKEN);

            try {
                $monitor = $this->monitors->create($form['name'], $form['url'], $form['interval_sec']);
            } catch (BadRequestHttpException $e) {
                return $this->render('monitors/new.html.twig', [
                    'title' => 'New monitor — Logswire',
                    'error' => $e->getMessage(),
                    'form' => $form,
                ]);
            }

            $this->addFlash('success', sprintf('Monitor "%s" created.', $monitor->getName()));

            return $this->redirectToRoute('monitors_get', ['id' => $monitor->getId()]);
        }

        return $this->render('monitors/new.html.twig', [
            'title' => 'New monitor — Logswire',
            'error' => null,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'monitors_get', methods: ['GET'])]
    public function get(string $id, Request $request): Response
    {
        $monitor = $this->monitors->get($id);

        return $this->render('monitors/detail.html.twig', [
            'title' => $monitor->getName().' — Logswire',
            ...$this->presenter->detail($monitor, $request->query->getString('range')),
        ]);
    }

    /**
     * htmx OOB fragment consumed by the detail poller (header meta, stat cards, chart).
     */
    #[Route('/{id}/live', name: 'monitors_live', methods: ['GET'])]
    public function live(string $id, Request $request): Response
    {
        $monitor = $this->monitors->get($id);

        $response = $this->render('monitors/live.html.twig', [
            ...$this->presenter->live($monitor, $request->query->getString('range')),
            'oob' => true,
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/{id}/edit', name: 'monitors_edit', methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $monitor = $this->monitors->get($id);

        if ($request->isMethod('POST')) {
            $this->guardCsrf($request, self::CSRF_TOKEN);
            $form = $this->readForm($request);
            $enabled = $request->request->has('enabled');

            try {
                $monitor = $this->monitors->update(
                    $monitor,
                    $form['name'],
                    $form['url'],
                    $form['interval_sec'],
                    $enabled,
                );
            } catch (BadRequestHttpException $e) {
                return $this->render('monitors/edit.html.twig', [
                    'title' => 'Edit '.$monitor->getName().' — Logswire',
                    'error' => $e->getMessage(),
                    'form' => $form + ['enabled' => $enabled],
                    'monitor' => MonitorService::viewData($monitor),
                ]);
            }

            $this->addFlash('success', 'Monitor updated.');

            return $this->redirectToRoute('monitors_get', ['id' => $monitor->getId()]);
        }

        return $this->render('monitors/edit.html.twig', [
            'title' => 'Edit '.$monitor->getName().' — Logswire',
            'error' => null,
            'form' => [
                'name' => $monitor->getName(),
                'url' => $monitor->getUrl(),
                'interval_sec' => $monitor->getIntervalSec(),
                'enabled' => $monitor->isEnabled(),
            ],
            'monitor' => MonitorService::viewData($monitor),
        ]);
    }

    #[Route('/{id}/toggle', name: 'monitors_toggle', methods: ['POST'])]
    public function toggle(string $id, Request $request): RedirectResponse
    {
        $this->guardCsrf($request, self::CSRF_TOKEN);

        $monitor = $this->monitors->get($id);
        $enabled = $request->request->getBoolean('enabled');
        $this->monitors->setEnabled($monitor, $enabled);

        $this->addFlash(
            'success',
            $enabled
                ? sprintf('Monitor "%s" resumed.', $monitor->getName())
                : sprintf('Monitor "%s" paused.', $monitor->getName()),
        );

        $target = $request->headers->get('Referer');
        if ($target !== null && str_starts_with($target, $request->getSchemeAndHttpHost().'/dashboard/monitors')) {
            return $this->redirect($target);
        }

        return $this->redirectToRoute('monitors_list');
    }

    #[Route('/{id}/delete', name: 'monitors_delete', methods: ['POST'])]
    public function delete(string $id, Request $request): RedirectResponse
    {
        $this->guardCsrf($request, self::CSRF_TOKEN);

        $monitor = $this->monitors->get($id);
        $this->monitors->delete($monitor);

        $this->addFlash('success', sprintf('Monitor "%s" deleted.', $monitor->getName()));

        return $this->redirectToRoute('monitors_list');
    }

    /** @return array{name: string, url: string, interval_sec: int} */
    private function readForm(Request $request): array
    {
        return [
            'name' => trim((string) $request->request->get('name', '')),
            'url' => trim((string) $request->request->get('url', '')),
            'interval_sec' => (int) $request->request->get('interval_sec', 300),
        ];
    }
}
