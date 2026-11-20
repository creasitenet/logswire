<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Ingest\TestEventService;
use App\Service\Project\ProjectPresenter;
use App\Service\Project\ProjectService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dashboard/projects')]
final class ProjectController extends AbstractController
{
    use CsrfGuardTrait;
    use UuidParamTrait;

    private const string CSRF_TOKEN = 'project_action';

    public function __construct(
        private readonly ProjectService $projects,
        private readonly ProjectPresenter $presenter,
        private readonly TestEventService $testEvents,
    ) {
    }

    #[Route('', name: 'projects_list', methods: ['GET'])]
    public function list(): Response
    {
        return $this->render('projects/list.html.twig', [
            'title' => 'Projects — Logswire',
            'projects' => $this->presenter->listRows($this->projects->list()),
        ]);
    }

    #[Route('/new', name: 'projects_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->guardCsrf($request, self::CSRF_TOKEN);

            $name = trim((string) $request->request->get('name', ''));
            try {
                $project = $this->projects->create($name);
            } catch (BadRequestHttpException $e) {
                return $this->render('projects/new.html.twig', [
                    'title' => 'New project — Logswire',
                    'error' => $e->getMessage(),
                    'name' => $name,
                ]);
            }

            $this->addFlash('success', sprintf('Project "%s" created.', $project->getName()));

            return $this->redirectToRoute('projects_get', ['id' => $project->getId()]);
        }

        return $this->render('projects/new.html.twig', [
            'title' => 'New project — Logswire',
            'error' => null,
        ]);
    }

    #[Route('/{id}', name: 'projects_get', methods: ['GET'])]
    public function get(string $id): Response
    {
        $project = $this->projects->get($this->uuidOr404($id));

        return $this->render('projects/detail.html.twig', [
            'title' => $project->getName().' — Logswire',
            'project' => $this->projects->viewData($project),
        ]);
    }

    #[Route('/{id}/edit', name: 'projects_edit', methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $project = $this->projects->get($this->uuidOr404($id));

        if ($request->isMethod('POST')) {
            $this->guardCsrf($request, self::CSRF_TOKEN);

            $name = trim((string) $request->request->get('name', ''));
            $active = $request->request->has('active');
            try {
                $project = $this->projects->update($project, $name, $active);
            } catch (BadRequestHttpException $e) {
                return $this->render('projects/edit.html.twig', [
                    'title' => 'Edit '.$project->getName().' — Logswire',
                    'project' => $this->projects->viewData($project),
                    'error' => $e->getMessage(),
                ]);
            }

            $this->addFlash('success', 'Project updated.');

            return $this->redirectToRoute('projects_get', ['id' => $project->getId()]);
        }

        return $this->render('projects/edit.html.twig', [
            'title' => 'Edit '.$project->getName().' — Logswire',
            'project' => $this->projects->viewData($project),
        ]);
    }

    #[Route('/{id}/ingest', name: 'projects_set_ingest', methods: ['POST'])]
    public function setIngest(string $id, Request $request): RedirectResponse
    {
        $this->guardCsrf($request, self::CSRF_TOKEN);

        $project = $this->projects->get($this->uuidOr404($id));
        $active = $request->request->getBoolean('active');
        $this->projects->setIngestActive($project, $active);

        $this->addFlash(
            'success',
            $active
                ? sprintf('Ingest enabled for "%s".', $project->getName())
                : sprintf('Ingest paused for "%s".', $project->getName()),
        );

        $target = $request->headers->get('Referer');
        if ($target !== null && str_starts_with($target, $request->getSchemeAndHttpHost().'/dashboard/projects')) {
            return $this->redirect($target);
        }

        return $this->redirectToRoute('projects_list');
    }

    #[Route('/{id}/delete', name: 'projects_delete', methods: ['POST'])]
    public function delete(string $id, Request $request): RedirectResponse
    {
        $this->guardCsrf($request, self::CSRF_TOKEN);

        $project = $this->projects->get($this->uuidOr404($id));
        $this->projects->delete($project);

        $this->addFlash('success', sprintf('Project "%s" deleted.', $project->getName()));

        return $this->redirectToRoute('projects_list');
    }

    #[Route('/{id}/regenerate-dsn', name: 'projects_regenerate_dsn', methods: ['POST'])]
    public function regenerateDsn(string $id, Request $request): RedirectResponse
    {
        $this->guardCsrf($request, self::CSRF_TOKEN);

        $project = $this->projects->get($this->uuidOr404($id));
        $this->projects->regenerateDsn($project);

        $this->addFlash('success', 'DSN regenerated. Update your SDK to use the new key.');

        return $this->redirectToRoute('projects_get', ['id' => $id]);
    }

    #[Route('/{id}/test-event', name: 'projects_test_event', methods: ['POST'])]
    public function testEvent(string $id, Request $request): RedirectResponse
    {
        $this->guardCsrf($request, self::CSRF_TOKEN);

        $project = $this->projects->get($this->uuidOr404($id));

        if (!$project->isActive()) {
            $this->addFlash('error', 'This project is inactive. Activate it before sending a test event.');

            return $this->redirectToRoute('projects_get', ['id' => $id]);
        }

        $message = trim((string) $request->request->get('message', '')) ?: null;
        $this->testEvents->send($project, $message);

        $this->addFlash('success', 'Test event sent. Check your issues in a few seconds.');

        return $this->redirectToRoute('projects_get', ['id' => $id]);
    }

}