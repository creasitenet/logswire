<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Issue\IssueFilters;
use App\Service\Issue\IssueService;
use App\Service\Project\ProjectService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/issues')]
final class IssueController extends AbstractController
{
    use CsrfGuardTrait;
    use UuidParamTrait;

    private const int PER_PAGE = 25;
    private const string CSRF_TOKEN = 'issue_action';

    public function __construct(
        private readonly IssueService $issues,
        private readonly ProjectService $projects,
    ) {
    }

    #[Route('', name: 'issues_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $filters = IssueFilters::fromRequest($request);
        $page = max(1, $request->query->getInt('page', 1));
        $offset = ($page - 1) * self::PER_PAGE;

        $total = $this->issues->count(
            status: $filters->status,
            projectId: $filters->projectId,
            q: $filters->q,
            levelExact: $filters->levelExact,
            since: $filters->since,
            until: $filters->until,
            environment: $filters->environment,
            release: $filters->release,
        );
        $pageCount = max(1, (int) ceil($total / self::PER_PAGE));
        if ($page > $pageCount) {
            $page = $pageCount;
            $offset = ($page - 1) * self::PER_PAGE;
        }

        $items = $this->issues->list(
            status: $filters->status,
            projectId: $filters->projectId,
            q: $filters->q,
            levelExact: $filters->levelExact,
            since: $filters->since,
            until: $filters->until,
            limit: self::PER_PAGE,
            offset: $offset,
            environment: $filters->environment,
            release: $filters->release,
            sort: $filters->sort,
            sortDir: $filters->sortDir,
        );

        $view = [
            'title' => 'Issues — Logswire',
            'items' => array_map($this->issues->serializeListItem(...), $items),
            'filters' => $filters->query(),
            'filters_active' => $filters->isActive(),
            'issues_list_url' => $this->generateUrl('issues_list'),
            'pagination' => [
                'page' => $page,
                'per_page' => self::PER_PAGE,
                'total' => $total,
                'page_count' => $pageCount,
            ],
            'projects' => array_map(
                static fn ($p) => ['id' => $p->getId(), 'name' => $p->getName()],
                $this->projects->list(),
            ),
        ];

        if ($request->headers->get('HX-Request')) {
            $view['hx_oob'] = true;

            return $this->render('issues/_results.html.twig', $view);
        }

        return $this->render('issues/list.html.twig', $view);
    }

    #[Route('/{id}', name: 'issues_get', requirements: ['id' => '[0-9a-f\-]{36}'], methods: ['GET'])]
    public function get(string $id): Response
    {
        $issue = $this->issues->get($this->uuidOr404($id));

        return $this->render('issues/detail.html.twig', [
            'title' => ($issue->getTitle() ?: 'Issue').' — Logswire',
            'issue' => $this->issues->serializeDetail($issue),
        ]);
    }

    #[Route('/{id}/resolve', name: 'issues_resolve', methods: ['POST'])]
    public function resolve(string $id, Request $request): Response
    {
        $this->guardCsrf($request, self::CSRF_TOKEN);

        $issue = $this->issues->get($this->uuidOr404($id));
        $this->issues->setStatus($issue, 'resolved');

        if ($request->headers->get('HX-Request')) {
            return $this->render('issues/_actions.html.twig', [
                'issue' => $this->issues->serializeDetail($issue),
                'flash' => ['success', 'Issue marked as resolved.'],
            ]);
        }

        $this->addFlash('success', 'Issue marked as resolved.');

        return $this->redirect($this->listRedirect($request) ?? $this->generateUrl('issues_get', ['id' => $id]));
    }

    #[Route('/{id}/unresolve', name: 'issues_unresolve', methods: ['POST'])]
    public function unresolve(string $id, Request $request): Response
    {
        $this->guardCsrf($request, self::CSRF_TOKEN);

        $issue = $this->issues->get($this->uuidOr404($id));
        $this->issues->setStatus($issue, 'unresolved');

        if ($request->headers->get('HX-Request')) {
            return $this->render('issues/_actions.html.twig', [
                'issue' => $this->issues->serializeDetail($issue),
                'flash' => ['success', 'Issue reopened.'],
            ]);
        }

        $this->addFlash('success', 'Issue reopened.');

        return $this->redirect($this->listRedirect($request) ?? $this->generateUrl('issues_get', ['id' => $id]));
    }

    /**
     * Row-level actions on the list send the filtered list URL back so the
     * user keeps their filters and page after resolving/reopening.
     */
    private function listRedirect(Request $request): ?string
    {
        $target = (string) $request->request->get('redirect', '');
        if (!str_starts_with($target, '/') || str_starts_with($target, '//')) {
            return null;
        }

        return $target;
    }
}
