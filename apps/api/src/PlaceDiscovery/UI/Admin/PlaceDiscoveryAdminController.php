<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\UI\Admin;

use App\PlaceDiscovery\Application\DiscoveryRunOrchestrator;
use App\PlaceDiscovery\Application\PlaceDiscoveryService;
use App\PlaceDiscovery\Application\Port\PlaceDiscoveryProvider;
use App\PlaceDiscovery\Domain\Aggregate\CandidateStatus;
use App\PlaceDiscovery\Domain\Aggregate\DiscoveryArea;
use App\PlaceDiscovery\Domain\SourceProvenanceFingerprint;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/admin/place-discovery', name: 'admin_place_discovery_')]
#[IsGranted('ROLE_ADMIN')]
final class PlaceDiscoveryAdminController extends AbstractController
{
    public function __construct(private readonly Connection $connection, private readonly PlaceDiscoveryService $service, private readonly DiscoveryRunOrchestrator $runs, private readonly PlaceDiscoveryProvider $provider, #[Autowire('%env(bool:PLACE_DISCOVERY_ENABLED)%')] private readonly bool $enabled)
    {
    }

    #[Route('/candidates', name: 'candidates', methods: ['GET'])]
    public function candidates(Request $request): Response
    {
        $where = [];
        $params = [];
        $limits = ['source' => 40, 'source_release' => 40, 'locality' => 160];
        $status = trim((string) $request->query->get('status'));
        if ('' !== $status) {
            if (!\in_array($status, array_column(CandidateStatus::cases(), 'value'), true)) {
                throw new BadRequestHttpException('Invalid candidate status filter.');
            }
            $where[] = 'c.status = ?';
            $params[] = $status;
        }
        foreach ($limits as $filter => $maxBytes) {
            if ('' !== ($value = trim((string) $request->query->get($filter)))) {
                if (\strlen($value) > $maxBytes) {
                    throw new BadRequestHttpException('Candidate filter exceeds its bound.');
                }
                $where[] = 'c.'.$filter.' = ?';
                $params[] = $value;
            }
        }
        foreach (['license_review_required' => 'source_license_review_required', 'closure_review_required' => 'source_closed_review_required'] as $filter => $column) {
            $value = trim((string) $request->query->get($filter));
            if ('' !== $value) {
                if (!\in_array($value, ['0', '1'], true)) {
                    throw new BadRequestHttpException('Invalid candidate review filter.');
                }
                $where[] = 'c.'.$column.' = ?';
                $params[] = '1' === $value ? 'true' : 'false';
            }
        }
        $actionable = trim((string) $request->query->get('actionable'));
        if ('' !== $actionable) {
            if ('1' !== $actionable) {
                throw new BadRequestHttpException('Invalid actionable filter.');
            }
            $where[] = '(c.source_license_review_required OR c.source_closed_review_required)';
        }
        $pageValue = (string) $request->query->get('page', '1');
        $perPageValue = (string) $request->query->get('perPage', '25');
        if (!preg_match('/^[1-9][0-9]*$/', $pageValue) || !\in_array($perPageValue, ['25', '50', '100'], true)) {
            throw new BadRequestHttpException('Invalid candidate pagination.');
        }
        $page = (int) $pageValue;
        $perPage = (int) $perPageValue;
        $whereSql = $where ? ' WHERE '.implode(' AND ', $where) : '';
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM place_candidates c'.$whereSql, $params);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;
        $sql = 'SELECT c.*, category.name AS category_name FROM place_candidates c LEFT JOIN categories category ON category.id = c.suggested_place_category_id'.$whereSql.' ORDER BY (c.source_license_review_required OR c.source_closed_review_required) DESC, c.updated_at DESC, c.id ASC LIMIT ? OFFSET ?';
        $queryParams = [...$params, $perPage, $offset];
        $filters = array_filter($request->query->all(), static fn (mixed $value): bool => \is_scalar($value) && '' !== (string) $value);

        return $this->render('admin/place_discovery/candidates.html.twig', ['candidates' => $this->connection->fetchAllAssociative($sql, $queryParams), 'filters' => $filters, 'page' => $page, 'perPage' => $perPage, 'lastPage' => $lastPage, 'total' => $total, 'rangeStart' => 0 === $total ? 0 : $offset + 1, 'rangeEnd' => min($offset + $perPage, $total)]);
    }

    #[Route('/candidates/{id}', name: 'candidate', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET'])]
    public function candidate(string $id): Response
    {
        $candidate = $this->connection->fetchAssociative('SELECT c.*, category.name AS category_name, city.name AS selected_city_name FROM place_candidates c LEFT JOIN categories category ON category.id = c.suggested_place_category_id LEFT JOIN cities city ON city.id = c.suggested_city_id WHERE c.id = ?', [$id]);
        if (false === $candidate) {
            throw $this->createNotFoundException();
        }
        $candidate['source_license_resolutions'] = json_decode((string) $candidate['source_license_resolutions'], true, 16, \JSON_THROW_ON_ERROR);
        $candidate['source_provenance'] = json_decode((string) $candidate['source_provenance'], true, 16, \JSON_THROW_ON_ERROR);
        foreach ($candidate['source_provenance'] as &$source) {
            if (\is_array($source)) {
                $source['fingerprint'] = SourceProvenanceFingerprint::fromArray($source);
                $providerLicense = isset($source['license']) && \is_string($source['license']) && '' !== trim($source['license']);
                $reviewed = $candidate['source_license_resolutions'][$source['fingerprint']] ?? null;
                $source['effective_license'] = $providerLicense ? $source['license'] : (\is_array($reviewed) && \is_string($reviewed['license'] ?? null) ? $reviewed['license'] : null);
                $source['license_origin'] = $providerLicense ? 'provider' : (null !== $source['effective_license'] ? 'reviewed' : null);
            }
        }
        unset($source);
        $candidate['source_snapshot'] = json_decode((string) $candidate['source_snapshot'], true, 32, \JSON_THROW_ON_ERROR);

        $history = $this->connection->fetchAllAssociative('SELECT * FROM place_candidate_audit_events WHERE candidate_id = ? ORDER BY created_at, id', [$id]);
        foreach ($history as &$event) {
            $event['changed_fields'] = json_decode((string) $event['changed_fields'], true, 16, \JSON_THROW_ON_ERROR);
            $event['details'] = json_decode((string) $event['details'], true, 16, \JSON_THROW_ON_ERROR);
        }
        unset($event);

        return $this->render('admin/place_discovery/candidate.html.twig', ['candidate' => $candidate, 'categories' => $this->connection->fetchAllAssociative('SELECT id, name FROM categories WHERE enabled = true ORDER BY name'), 'cities' => $this->connection->fetchAllAssociative('SELECT id, name, country_code FROM cities WHERE enabled = true ORDER BY country_code, name, id'), 'places' => $this->connection->fetchAllAssociative('SELECT p.id, p.name FROM places p ORDER BY p.updated_at DESC LIMIT 200'), 'history' => $history]);
    }

    #[Route('/candidates/{id}/{action}', name: 'candidate_action', requirements: ['id' => '[0-9a-f-]{36}', 'action' => 'approve|edit|reject|duplicate|clear-duplicate|refresh|resolve-license'], methods: ['POST'])]
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
                'edit' => $this->service->editCandidate($id, $version, $request->request->all(), $reviewer),
                'reject' => $this->service->reject($id, $version, $reviewer, (string) $request->request->get('reason')),
                'duplicate' => $this->service->markDuplicate($id, $version, $reviewer, (string) $request->request->get('place_id')),
                'clear-duplicate' => $this->service->clearDuplicate($id, $version, $reviewer),
                'refresh' => $this->service->refreshCandidateFromSource($id, $version, $reviewer),
                'resolve-license' => $this->service->resolveUnlicensedProvenance($id, $version, (string) $request->request->get('fingerprint'), (string) $request->request->get('license'), $reviewer),
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
        return $this->render('admin/place_discovery/runs.html.twig', ['runs' => $this->connection->fetchAllAssociative('SELECT r.*, a.name AS area_name FROM place_discovery_runs r JOIN place_discovery_areas a ON a.id = r.area_id ORDER BY r.created_at DESC LIMIT 200'), 'areas' => $this->connection->fetchAllAssociative('SELECT id, name FROM place_discovery_areas WHERE enabled = true ORDER BY name, id'), 'discovery_enabled' => $this->enabled]);
    }

    #[Route('/runs/action', name: 'run_action', methods: ['POST'])]
    public function runAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('discovery-runs', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $actor = $this->getUser()?->getUserIdentifier() ?? 'admin';
        try {
            if (!$this->enabled) {
                throw new \DomainException('Place discovery is disabled. No run was created or dispatched.');
            }
            match ((string) $request->request->get('action')) {
                'run-now' => $this->runs->enqueueAndDispatch((string) $request->request->get('area_id'), $this->provider->getLatestRelease(), $actor),
                'retry' => $this->runs->retry((string) $request->request->get('run_id'), $actor),
                'recover-stale' => $this->runs->recoverStale((string) $request->request->get('run_id'), $actor),
                'reconcile' => $this->runs->reconcilePendingDispatches(),
                default => throw new \DomainException('Unsupported run action.'),
            };
            $this->addFlash('success', 'Zlecono ograniczony przebieg odkrywania.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin', ['routeName' => 'admin_place_discovery_runs']);
    }
}
