<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\UI\Admin;

use App\PlaceDiscovery\Application\PlaceDiscoveryService;
use App\PlaceDiscovery\Domain\Aggregate\DiscoveryArea;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/admin/place-discovery', name: 'admin_place_discovery_')]
#[IsGranted('ROLE_ADMIN')]
final class PlaceDiscoveryAdminController extends AbstractController
{
    public function __construct(private readonly Connection $connection, private readonly PlaceDiscoveryService $service)
    {
    }

    #[Route('/candidates', name: 'candidates', methods: ['GET'])]
    public function candidates(Request $request): Response
    {
        $where = [];
        $params = [];
        foreach (['status', 'source', 'source_release', 'locality'] as $filter) {
            if ('' !== ($value = trim((string) $request->query->get($filter)))) {
                $where[] = 'c.'.$filter.' = ?';
                $params[] = $value;
            }
        }
        $sql = 'SELECT c.*, category.name AS category_name FROM place_candidates c LEFT JOIN categories category ON category.id = c.suggested_place_category_id'.($where ? ' WHERE '.implode(' AND ', $where) : '').' ORDER BY c.created_at DESC LIMIT 200';

        return $this->render('admin/place_discovery/candidates.html.twig', ['candidates' => $this->connection->fetchAllAssociative($sql, $params), 'filters' => $request->query->all()]);
    }

    #[Route('/candidates/{id}', name: 'candidate', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET'])]
    public function candidate(string $id): Response
    {
        $candidate = $this->connection->fetchAssociative('SELECT c.*, category.name AS category_name FROM place_candidates c LEFT JOIN categories category ON category.id = c.suggested_place_category_id WHERE c.id = ?', [$id]);
        if (false === $candidate) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/place_discovery/candidate.html.twig', ['candidate' => $candidate, 'categories' => $this->connection->fetchAllAssociative('SELECT id, name FROM categories WHERE enabled = true ORDER BY name'), 'places' => $this->connection->fetchAllAssociative('SELECT p.id, p.name FROM places p ORDER BY p.updated_at DESC LIMIT 200')]);
    }

    #[Route('/candidates/{id}/{action}', name: 'candidate_action', requirements: ['id' => '[0-9a-f-]{36}', 'action' => 'approve|edit|reject|duplicate|clear-duplicate|refresh'], methods: ['POST'])]
    public function candidateAction(string $id, string $action, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('candidate-'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $version = $request->request->getInt('version');
        $reviewer = $this->getUser()?->getUserIdentifier() ?? 'admin';
        try {
            match ($action) {
                'approve' => $this->service->approve($id, $version, $reviewer),
                'edit' => $this->service->editCandidate($id, $version, $request->request->all()),
                'reject' => $this->service->reject($id, $version, $reviewer, (string) $request->request->get('reason')),
                'duplicate' => $this->service->markDuplicate($id, $version, $reviewer, (string) $request->request->get('place_id')),
                'clear-duplicate' => $this->clearDuplicate($id, $version),
                'refresh' => $this->service->refreshCandidateFromSource($id, $version),
                default => throw new \DomainException('Unsupported candidate action.'),
            };
            $this->addFlash('success', 'Zaktualizowano kandydata. Zatwierdzenie tworzy wyłącznie szkic miejsca.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin', ['routeName' => 'admin_place_discovery_candidate', 'routeParams' => ['id' => $id]]);
    }

    #[Route('/areas', name: 'areas', methods: ['GET', 'POST'])]
    public function areas(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('discovery-area', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }
            try {
                $area = new DiscoveryArea(Uuid::v7()->toRfc4122(), trim((string) $request->request->get('name')), false, strtoupper(trim((string) $request->request->get('country_code'))), (float) $request->request->get('latitude'), (float) $request->request->get('longitude'), (float) $request->request->get('radius_km'), (float) $request->request->get('minimum_confidence'), $request->request->getInt('maximum_candidates'), 'family-v1');
                [$west, $south, $east, $north] = $area->boundingBox();
                $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
                $this->connection->insert('place_discovery_areas', ['id' => $area->id, 'name' => $area->name, 'enabled' => 'false', 'country_code' => $area->countryCode, 'center_latitude' => $area->centerLatitude, 'center_longitude' => $area->centerLongitude, 'radius_km' => $area->radiusKm, 'bbox_west' => $west, 'bbox_south' => $south, 'bbox_east' => $east, 'bbox_north' => $north, 'minimum_confidence' => $area->minimumConfidence, 'maximum_candidates_per_run' => $area->maximumCandidatesPerRun, 'discovery_profile' => $area->profile, 'created_at' => $now, 'updated_at' => $now]);

                return $this->redirectToRoute('admin', ['routeName' => 'admin_place_discovery_areas']);
            } catch (\DomainException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->render('admin/place_discovery/areas.html.twig', ['areas' => $this->connection->fetchAllAssociative('SELECT * FROM place_discovery_areas ORDER BY created_at DESC')]);
    }

    #[Route('/areas/{id}/toggle', name: 'area_toggle', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function toggleArea(string $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('area-'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $this->connection->executeStatement('UPDATE place_discovery_areas SET enabled = NOT enabled, updated_at = ?, version = version + 1 WHERE id = ?', [(new \DateTimeImmutable())->format(\DateTimeInterface::ATOM), $id]);

        return $this->redirectToRoute('admin', ['routeName' => 'admin_place_discovery_areas']);
    }

    #[Route('/runs', name: 'runs', methods: ['GET'])]
    public function runs(): Response
    {
        return $this->render('admin/place_discovery/runs.html.twig', ['runs' => $this->connection->fetchAllAssociative('SELECT r.*, a.name AS area_name FROM place_discovery_runs r JOIN place_discovery_areas a ON a.id = r.area_id ORDER BY r.created_at DESC LIMIT 200')]);
    }

    private function clearDuplicate(string $id, int $version): void
    {
        if (1 !== $this->connection->executeStatement("UPDATE place_candidates SET status = CASE WHEN suggested_place_category_id IS NULL THEN 'NEEDS_MAPPING' ELSE 'PENDING' END, duplicate_score = NULL, duplicate_reasons = NULL, possible_duplicate_place_id = NULL, updated_at = ?, version = version + 1 WHERE id = ? AND version = ? AND status = 'POSSIBLE_DUPLICATE'", [(new \DateTimeImmutable())->format(\DateTimeInterface::ATOM), $id, $version])) {
            throw new \DomainException('Candidate changed or has no duplicate warning.');
        }
    }
}
