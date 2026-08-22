<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\EasyAdmin;

use App\Administration\UI\Form\PlaceAdminCommandFactory;
use App\Administration\UI\Form\PlaceAdminFormData;
use App\Administration\UI\Form\PlaceAdminFormMapper;
use App\Administration\UI\Form\Type\PlaceAdminFormType;
use App\PlaceDiscovery\Application\DiscoveryRunOrchestrator;
use App\PlaceDiscovery\Application\PlaceDiscoveryService;
use App\PlaceDiscovery\Application\Port\PlaceDiscoveryProvider;
use App\PlaceDiscovery\Domain\Aggregate\CandidateStatus;
use App\PlaceDiscovery\Domain\Aggregate\DiscoveryArea;
use App\PlaceDiscovery\Domain\SourceProvenanceFingerprint;
use App\Places\Application\Command\ArchivePlace;
use App\Places\Application\Command\MarkPlaceNeedsReverification;
use App\Places\Application\Command\MarkPlaceTemporarilyClosed;
use App\Places\Application\Command\PublishPlace;
use App\Places\Application\Command\SubmitPlaceForReview;
use App\Places\Application\Command\UnpublishPlace;
use App\Places\Application\ConcurrentPlaceModification;
use App\Places\Application\PlaceCommandHandler;
use App\Places\Application\PlaceRepository;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\UserMenu;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
#[IsGranted('ROLE_ADMIN')]
final class DashboardController extends AbstractDashboardController
{
    private const array DICTIONARY_TABLES = ['cities' => 'cities', 'categories' => 'categories', 'amenities' => 'amenities'];

    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            PlaceRepository::class => '?'.PlaceRepository::class,
            PlaceCommandHandler::class => '?'.PlaceCommandHandler::class,
            PlaceAdminFormMapper::class => '?'.PlaceAdminFormMapper::class,
            PlaceAdminCommandFactory::class => '?'.PlaceAdminCommandFactory::class,
            LoggerInterface::class => '?'.LoggerInterface::class,
            \Doctrine\DBAL\Connection::class => '?'.\Doctrine\DBAL\Connection::class,
            PlaceDiscoveryService::class => '?'.PlaceDiscoveryService::class,
            DiscoveryRunOrchestrator::class => '?'.DiscoveryRunOrchestrator::class,
            PlaceDiscoveryProvider::class => '?'.PlaceDiscoveryProvider::class,
        ]);
    }

    private function discoveryService(): PlaceDiscoveryService
    {
        return $this->container->get(PlaceDiscoveryService::class);
    }

    private function discoveryRuns(): DiscoveryRunOrchestrator
    {
        return $this->container->get(DiscoveryRunOrchestrator::class);
    }

    private function discoveryProvider(): PlaceDiscoveryProvider
    {
        return $this->container->get(PlaceDiscoveryProvider::class);
    }

    private function isDiscoveryEnabled(): bool
    {
        return (bool) filter_var($_ENV['PLACE_DISCOVERY_ENABLED'] ?? $_SERVER['PLACE_DISCOVERY_ENABLED'] ?? false, \FILTER_VALIDATE_BOOLEAN);
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        return $this->container->get(\Doctrine\DBAL\Connection::class);
    }

    private function places(): PlaceRepository
    {
        return $this->container->get(PlaceRepository::class);
    }

    private function commands(): PlaceCommandHandler
    {
        return $this->container->get(PlaceCommandHandler::class);
    }

    private function formMapper(): PlaceAdminFormMapper
    {
        return $this->container->get(PlaceAdminFormMapper::class);
    }

    private function commandFactory(): PlaceAdminCommandFactory
    {
        return $this->container->get(PlaceAdminCommandFactory::class);
    }

    private function logger(): LoggerInterface
    {
        return $this->container->get(LoggerInterface::class);
    }

    public function index(): Response
    {
        $db = $this->connection();

        $placesCount = [
            'published' => (int) $db->fetchOne('SELECT COUNT(*) FROM places WHERE status = :status', ['status' => \App\Places\Domain\PlaceStatus::PUBLISHED->value]),
            'draft' => (int) $db->fetchOne('SELECT COUNT(*) FROM places WHERE status = :status', ['status' => \App\Places\Domain\PlaceStatus::DRAFT->value]),
            'pending' => (int) $db->fetchOne('SELECT COUNT(*) FROM places WHERE status = :status', ['status' => \App\Places\Domain\PlaceStatus::PENDING_REVIEW->value]),
            'temporarily_closed' => (int) $db->fetchOne('SELECT COUNT(*) FROM places WHERE status = :status', ['status' => \App\Places\Domain\PlaceStatus::TEMPORARILY_CLOSED->value]),
            'total' => (int) $db->fetchOne('SELECT COUNT(*) FROM places'),
        ];

        $candidatesCount = [
            'actionable' => (int) $db->fetchOne('SELECT COUNT(*) FROM place_candidates WHERE source_license_review_required = true OR source_closed_review_required = true'),
            'needs_mapping' => (int) $db->fetchOne("SELECT COUNT(*) FROM place_candidates WHERE status = 'NEEDS_MAPPING'"),
            'pending' => (int) $db->fetchOne("SELECT COUNT(*) FROM place_candidates WHERE status = 'PENDING'"),
            'total' => (int) $db->fetchOne('SELECT COUNT(*) FROM place_candidates'),
        ];

        $moderationCount = [
            'open' => (int) $db->fetchOne("SELECT COUNT(*) FROM content_reports WHERE status IN ('OPEN', 'IN_REVIEW')"),
            'total' => (int) $db->fetchOne('SELECT COUNT(*) FROM content_reports'),
        ];

        $dictionariesCount = [
            'cities' => (int) $db->fetchOne('SELECT COUNT(*) FROM cities'),
            'categories' => (int) $db->fetchOne('SELECT COUNT(*) FROM categories'),
            'amenities' => (int) $db->fetchOne('SELECT COUNT(*) FROM amenities'),
        ];

        return $this->render('admin/dashboard.html.twig', [
            'placesStats' => $placesCount,
            'candidatesStats' => $candidatesCount,
            'moderationStats' => $moderationCount,
            'dictionariesStats' => $dictionariesCount,
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('FamilyPlaces')
            ->setTranslationDomain('messages')
            ->renderContentMaximized();
    }

    public function configureAssets(): Assets
    {
        return Assets::new()
            ->addCssFile('admin/familyplaces-admin.css')
            ->addJsFile('admin/familyplaces-admin.js');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');

        yield MenuItem::section('Katalog');
        yield MenuItem::linkToRoute('Miejsca', 'fa fa-map-marker-alt', 'admin_places');
        yield MenuItem::linkToRoute('Dodaj miejsce', 'fa fa-plus', 'admin_places_new');

        yield MenuItem::section('Odkrywanie miejsc');
        yield MenuItem::linkToRoute('Kandydaci', 'fa fa-magnifying-glass-location', 'admin_place_discovery_candidates');
        yield MenuItem::linkToRoute('Przebiegi', 'fa fa-clock-rotate-left', 'admin_place_discovery_runs');
        yield MenuItem::linkToRoute('Obszary', 'fa fa-map', 'admin_place_discovery_areas');

        yield MenuItem::section('Społeczność i Moderacja');
        yield MenuItem::linkToUrl('Kolejka moderacji', 'fa fa-shield-halved', '/moderator/queue');

        yield MenuItem::section('Słowniki');
        yield MenuItem::linkToRoute('Miasta', 'fa fa-city', 'admin_dictionary_list', ['type' => 'cities']);
        yield MenuItem::linkToRoute('Kategorie', 'fa fa-tags', 'admin_dictionary_list', ['type' => 'categories']);
        yield MenuItem::linkToRoute('Udogodnienia', 'fa fa-concierge-bell', 'admin_dictionary_list', ['type' => 'amenities']);

        yield MenuItem::section('Serwis');
        yield MenuItem::linkToUrl('Aplikacja publiczna', 'fa fa-external-link-alt', '/')->setLinkTarget('_blank');
    }

    public function configureUserMenu(UserInterface $user): UserMenu
    {
        return parent::configureUserMenu($user)
            ->setName($user->getUserIdentifier());
    }

    #[AdminRoute(path: '/places', name: 'places', options: ['methods' => ['GET']])]
    public function listPlaces(Request $request): Response
    {
        $page = $request->query->getInt('page', 1);
        $pageSize = $request->query->getInt('pageSize', 20);
        if ($pageSize < 10) {
            $pageSize = 10;
        } elseif ($pageSize > 100) {
            $pageSize = 100;
        }
        $request->query->set('pageSize', $pageSize);
        $search = $request->query->get('search');
        $status = $request->query->get('status');
        $city = $request->query->get('city');
        $sort = $request->query->get('sort', 'updated_at');

        if ($page < 1) {
            $page = 1;
        }

        $result = $this->places()->listForAdministration($search, $status, $city, $sort, $page, $pageSize);
        $totalPages = (int) ceil($result['total'] / $pageSize);

        return $this->render('admin/places/list.html.twig', [
            'places' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'pageSize' => $pageSize,
            'totalPages' => $totalPages,
            'search' => $search,
            'status' => $status,
            'city' => $city,
            'sort' => $sort,
            'cities' => $this->places()->allCities(),
            'statuses' => \App\Places\Domain\PlaceStatus::cases(),
        ]);
    }

    #[AdminRoute(path: '/places/new', name: 'places_new', options: ['methods' => ['GET', 'POST']])]
    public function createPlace(Request $request): Response
    {
        $data = $this->formMapper()->createData();
        $form = $this->createForm(PlaceAdminFormType::class, $data, ['csrf_token_id' => 'create-place']);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->commands()->create($this->commandFactory()->create($data));
                $this->addFlash('success', 'Draft place created.');

                return $this->redirectToRoute('admin_places');
            } catch (\DomainException|\InvalidArgumentException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            } catch (\Doctrine\DBAL\Exception $exception) {
                $this->logger()->error('Place draft creation failed.', ['exception' => $exception]);
                $form->addError(new FormError('Place could not be created. Try again.'));
            }
        }

        return $this->render('admin/places/new.html.twig', ['form' => $form]);
    }

    #[AdminRoute(path: '/places/{id}', name: 'places_view', options: ['requirements' => ['id' => '[0-9a-f-]{36}'], 'methods' => ['GET']])]
    public function viewPlace(string $id): Response
    {
        return $this->render('admin/places/view.html.twig', ['place' => $this->places()->get($id)]);
    }

    #[AdminRoute(path: '/places/{id}/edit', name: 'places_edit', options: ['requirements' => ['id' => '[0-9a-f-]{36}'], 'methods' => ['GET', 'POST']])]
    public function editPlace(string $id, Request $request): Response
    {
        $data = $request->isMethod('POST') ? new PlaceAdminFormData() : $this->formMapper()->editData($this->places()->get($id));
        $form = $this->createForm(PlaceAdminFormType::class, $data, ['csrf_token_id' => 'edit-place-'.$id]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->commands()->update($this->commandFactory()->update($id, $data));
                $this->addFlash('success', 'Place aggregate updated.');

                return $this->redirectToRoute('admin_places_view', ['id' => $id]);
            } catch (ConcurrentPlaceModification) {
                $form->addError(new FormError('This place was changed by another administrator. Your submitted data is preserved; reload the latest version before saving again.'));
            } catch (\DomainException|\InvalidArgumentException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('admin/places/edit.html.twig', ['form' => $form, 'placeId' => $id]);
    }

    #[AdminRoute(path: '/places/{id}/{action}', name: 'places_action', options: ['requirements' => ['id' => '[0-9a-f-]{36}', 'action' => 'submit|publish|unpublish|reverify|close|archive'], 'methods' => ['POST']])]
    public function actionPlace(string $id, string $action, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('place-'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        try {
            match ($action) {
                'submit' => $this->commands()->submit(new SubmitPlaceForReview($id, $request->request->getInt('version'))),
                'publish' => $this->commands()->publish(new PublishPlace($id, $request->request->getInt('version'))),
                'unpublish' => $this->commands()->unpublish(new UnpublishPlace($id, $request->request->getInt('version'))),
                'reverify' => $this->commands()->markNeedsReverification(new MarkPlaceNeedsReverification($id, $request->request->getInt('version'))),
                'close' => $this->commands()->markTemporarilyClosed(new MarkPlaceTemporarilyClosed($id, $request->request->getInt('version'))),
                'archive' => $this->commands()->archive(new ArchivePlace($id, $request->request->getInt('version'))),
                default => throw new \InvalidArgumentException('Unsupported action.'),
            };
            $this->addFlash('success', 'Place workflow updated.');
        } catch (ConcurrentPlaceModification|\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_places');
    }

    #[AdminRoute(path: '/places/{id}/photos/upload', name: 'places_photos_upload', options: ['requirements' => ['id' => '[0-9a-f-]{36}'], 'methods' => ['POST']])]
    public function uploadPhotos(string $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('place-photos-'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $uploadedFiles = $request->files->get('photos');
        if ($uploadedFiles) {
            $files = \is_array($uploadedFiles) ? array_values($uploadedFiles) : [$uploadedFiles];

            try {
                $this->commands()->uploadPhotos(new \App\Places\Application\Command\UploadPlacePhotos($id, $files));
                $this->addFlash('success', 'Photos uploaded successfully and queued for processing.');
            } catch (\InvalidArgumentException|\DomainException $exception) {
                $this->addFlash('error', $exception->getMessage());
            } catch (\Throwable $exception) {
                $this->logger()->error('Photos upload failed.', ['exception' => $exception]);
                $this->addFlash('error', 'Failed to upload photos.');
            }
        }

        return $this->redirectToRoute('admin_places_view', ['id' => $id]);
    }

    #[AdminRoute(path: '/places/{id}/photos/{photoId}/delete', name: 'places_photos_delete', options: ['requirements' => ['id' => '[0-9a-f-]{36}', 'photoId' => '[0-9a-f-]{36}'], 'methods' => ['POST']])]
    public function deletePhoto(string $id, string $photoId, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('place-photo-ops-'.$photoId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $this->commands()->deletePlacePhoto(new \App\Places\Application\Command\DeletePlacePhoto($id, $photoId));
            $this->addFlash('success', 'Photo deleted.');
        } catch (\InvalidArgumentException|\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_places_view', ['id' => $id]);
    }

    #[AdminRoute(path: '/places/{id}/photos/{photoId}/set-main', name: 'places_photos_set_main', options: ['requirements' => ['id' => '[0-9a-f-]{36}', 'photoId' => '[0-9a-f-]{36}'], 'methods' => ['POST']])]
    public function setMainPhoto(string $id, string $photoId, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('place-photo-ops-'.$photoId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $this->commands()->setMainPhoto(new \App\Places\Application\Command\SetMainPlacePhoto($id, $photoId));
            $this->addFlash('success', 'Main photo updated.');
        } catch (\InvalidArgumentException|\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_places_view', ['id' => $id]);
    }

    #[AdminRoute(path: '/places/{id}/photos/{photoId}/update', name: 'places_photos_update', options: ['requirements' => ['id' => '[0-9a-f-]{36}', 'photoId' => '[0-9a-f-]{36}'], 'methods' => ['POST']])]
    public function updatePhoto(string $id, string $photoId, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('place-photo-ops-'.$photoId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $altText = $request->request->get('alt_text') ? trim((string) $request->request->get('alt_text')) : null;
            $caption = $request->request->get('caption') ? trim((string) $request->request->get('caption')) : null;
            $displayOrder = $request->request->getInt('display_order', 0);

            $this->commands()->updatePhotoMetadata(new \App\Places\Application\Command\UpdatePlacePhotoMetadata($id, $photoId, $altText, $caption, $displayOrder));
            $this->addFlash('success', 'Photo details updated.');
        } catch (\InvalidArgumentException|\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_places_view', ['id' => $id]);
    }

    #[AdminRoute(path: '/places/{id}/photos/reorder', name: 'places_photos_reorder', options: ['requirements' => ['id' => '[0-9a-f-]{36}'], 'methods' => ['POST']])]
    public function reorderPhotos(string $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('place-photos-reorder-'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $photoIds = array_values($request->request->all('photo_ids'));
        try {
            $this->commands()->reorderPlacePhotos(new \App\Places\Application\Command\ReorderPlacePhotos($id, $photoIds));
            $this->addFlash('success', 'Photos reordered successfully.');
        } catch (\InvalidArgumentException|\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_places_view', ['id' => $id]);
    }

    #[AdminRoute(path: '/places/{id}/photos/{photoId}/reprocess', name: 'places_photos_reprocess', options: ['requirements' => ['id' => '[0-9a-f-]{36}', 'photoId' => '[0-9a-f-]{36}'], 'methods' => ['POST']])]
    public function reprocessPhoto(string $id, string $photoId, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('place-photo-ops-'.$photoId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $this->commands()->requestPlacePhotoReprocessing(new \App\Places\Application\Command\RequestPlacePhotoReprocessing($id, $photoId));
            $this->addFlash('success', 'Reprocessing queued.');
        } catch (\InvalidArgumentException|\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_places_view', ['id' => $id]);
    }

    #[AdminRoute(path: '/place-discovery/candidates', name: 'place_discovery_candidates', options: ['methods' => ['GET']])]
    public function placeDiscoveryCandidates(Request $request): Response
    {
        $db = $this->connection();
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
        $total = (int) $db->fetchOne('SELECT COUNT(*) FROM place_candidates c'.$whereSql, $params);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;
        $sql = 'SELECT c.*, category.name AS category_name FROM place_candidates c LEFT JOIN categories category ON category.id = c.suggested_place_category_id'.$whereSql.' ORDER BY (c.source_license_review_required OR c.source_closed_review_required) DESC, c.updated_at DESC, c.id ASC LIMIT ? OFFSET ?';
        $queryParams = [...$params, $perPage, $offset];
        $filters = array_filter($request->query->all(), static fn (mixed $value): bool => \is_scalar($value) && '' !== (string) $value);

        return $this->render('admin/place_discovery/candidates.html.twig', [
            'candidates' => $db->fetchAllAssociative($sql, $queryParams),
            'filters' => $filters,
            'page' => $page,
            'perPage' => $perPage,
            'lastPage' => $lastPage,
            'total' => $total,
            'rangeStart' => 0 === $total ? 0 : $offset + 1,
            'rangeEnd' => min($offset + $perPage, $total),
        ]);
    }

    #[AdminRoute(path: '/place-discovery/candidates/{id}', name: 'place_discovery_candidate', options: ['requirements' => ['id' => '[0-9a-f-]{36}'], 'methods' => ['GET']])]
    public function placeDiscoveryCandidate(string $id): Response
    {
        $db = $this->connection();
        $candidate = $db->fetchAssociative('SELECT c.*, category.name AS category_name, city.name AS selected_city_name FROM place_candidates c LEFT JOIN categories category ON category.id = c.suggested_place_category_id LEFT JOIN cities city ON city.id = c.suggested_city_id WHERE c.id = ?', [$id]);
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

        $history = $db->fetchAllAssociative('SELECT * FROM place_candidate_audit_events WHERE candidate_id = ? ORDER BY created_at, id', [$id]);
        foreach ($history as &$event) {
            $event['changed_fields'] = json_decode((string) $event['changed_fields'], true, 16, \JSON_THROW_ON_ERROR);
            $event['details'] = json_decode((string) $event['details'], true, 16, \JSON_THROW_ON_ERROR);
        }
        unset($event);

        return $this->render('admin/place_discovery/candidate.html.twig', [
            'candidate' => $candidate,
            'categories' => $db->fetchAllAssociative('SELECT id, name FROM categories WHERE enabled = true ORDER BY name'),
            'cities' => $db->fetchAllAssociative('SELECT id, name, country_code FROM cities WHERE enabled = true ORDER BY country_code, name, id'),
            'places' => $db->fetchAllAssociative('SELECT p.id, p.name FROM places p ORDER BY p.updated_at DESC LIMIT 200'),
            'history' => $history,
        ]);
    }

    #[AdminRoute(path: '/place-discovery/candidates/{id}/{action}', name: 'place_discovery_candidate_action', options: ['requirements' => ['id' => '[0-9a-f-]{36}', 'action' => 'approve|edit|reject|duplicate|clear-duplicate|refresh|resolve-license'], 'methods' => ['POST']])]
    public function placeDiscoveryCandidateAction(string $id, string $action, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('candidate-'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $version = $request->request->getInt('version');
        $reviewer = $this->getUser()?->getUserIdentifier() ?? 'admin';
        try {
            match ($action) {
                'approve' => $this->discoveryService()->approve($id, $version, $reviewer),
                'edit' => $this->discoveryService()->editCandidate($id, $version, $request->request->all(), $reviewer),
                'reject' => $this->discoveryService()->reject($id, $version, $reviewer, (string) $request->request->get('reason')),
                'duplicate' => $this->discoveryService()->markDuplicate($id, $version, $reviewer, (string) $request->request->get('place_id')),
                'clear-duplicate' => $this->discoveryService()->clearDuplicate($id, $version, $reviewer),
                'refresh' => $this->discoveryService()->refreshCandidateFromSource($id, $version, $reviewer),
                'resolve-license' => $this->discoveryService()->resolveUnlicensedProvenance($id, $version, (string) $request->request->get('fingerprint'), (string) $request->request->get('license'), $reviewer),
                default => throw new \DomainException('Unsupported candidate action.'),
            };
            $this->addFlash('success', 'Zaktualizowano kandydata. Zatwierdzenie tworzy wyłącznie szkic miejsca.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_place_discovery_candidate', ['id' => $id]);
    }

    #[AdminRoute(path: '/place-discovery/areas', name: 'place_discovery_areas', options: ['methods' => ['GET', 'POST']])]
    public function placeDiscoveryAreas(Request $request): Response
    {
        $db = $this->connection();
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('discovery-area', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }
            try {
                $area = new DiscoveryArea(Uuid::v7()->toRfc4122(), trim((string) $request->request->get('name')), false, strtoupper(trim((string) $request->request->get('country_code'))), (float) $request->request->get('latitude'), (float) $request->request->get('longitude'), (float) $request->request->get('radius_km'), (float) $request->request->get('minimum_confidence'), $request->request->getInt('maximum_candidates'), 'family-v1');
                [$west, $south, $east, $north] = $area->boundingBox();
                $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
                $db->insert('place_discovery_areas', ['id' => $area->id, 'name' => $area->name, 'enabled' => 'false', 'country_code' => $area->countryCode, 'center_latitude' => $area->centerLatitude, 'center_longitude' => $area->centerLongitude, 'radius_km' => $area->radiusKm, 'bbox_west' => $west, 'bbox_south' => $south, 'bbox_east' => $east, 'bbox_north' => $north, 'minimum_confidence' => $area->minimumConfidence, 'maximum_candidates_per_run' => $area->maximumCandidatesPerRun, 'discovery_profile' => $area->profile, 'created_at' => $now, 'updated_at' => $now]);

                return $this->redirectToRoute('admin_place_discovery_areas');
            } catch (\DomainException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->render('admin/place_discovery/areas.html.twig', ['areas' => $db->fetchAllAssociative('SELECT * FROM place_discovery_areas ORDER BY created_at DESC')]);
    }

    #[AdminRoute(path: '/place-discovery/areas/{id}/toggle', name: 'place_discovery_area_toggle', options: ['requirements' => ['id' => '[0-9a-f-]{36}'], 'methods' => ['POST']])]
    public function placeDiscoveryToggleArea(string $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('area-'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $this->connection()->executeStatement('UPDATE place_discovery_areas SET enabled = NOT enabled, updated_at = ?, version = version + 1 WHERE id = ?', [(new \DateTimeImmutable())->format(\DateTimeInterface::ATOM), $id]);

        return $this->redirectToRoute('admin_place_discovery_areas');
    }

    #[AdminRoute(path: '/place-discovery/runs', name: 'place_discovery_runs', options: ['methods' => ['GET']])]
    public function placeDiscoveryRuns(): Response
    {
        $db = $this->connection();

        return $this->render('admin/place_discovery/runs.html.twig', [
            'runs' => $db->fetchAllAssociative('SELECT r.*, a.name AS area_name FROM place_discovery_runs r JOIN place_discovery_areas a ON a.id = r.area_id ORDER BY r.created_at DESC LIMIT 200'),
            'areas' => $db->fetchAllAssociative('SELECT id, name FROM place_discovery_areas WHERE enabled = true ORDER BY name, id'),
            'discovery_enabled' => $this->isDiscoveryEnabled(),
        ]);
    }

    #[AdminRoute(path: '/place-discovery/runs/action', name: 'place_discovery_run_action', options: ['methods' => ['POST']])]
    public function placeDiscoveryRunAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('discovery-runs', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $actor = $this->getUser()?->getUserIdentifier() ?? 'admin';
        try {
            if (!$this->isDiscoveryEnabled()) {
                throw new \DomainException('Place discovery is disabled. No run was created or dispatched.');
            }
            match ((string) $request->request->get('action')) {
                'run-now' => $this->discoveryRuns()->enqueueAndDispatch((string) $request->request->get('area_id'), $this->discoveryProvider()->getLatestRelease(), $actor),
                'retry' => $this->discoveryRuns()->retry((string) $request->request->get('run_id'), $actor),
                'recover-stale' => $this->discoveryRuns()->recoverStale((string) $request->request->get('run_id'), $actor),
                'reconcile' => $this->discoveryRuns()->reconcilePendingDispatches(),
                default => throw new \DomainException('Unsupported run action.'),
            };
            $this->addFlash('success', 'Zlecono ograniczony przebieg odkrywania.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_place_discovery_runs');
    }

    #[AdminRoute(path: '/dictionaries/{type}', name: 'dictionary_list', options: ['requirements' => ['type' => 'cities|categories|amenities'], 'methods' => ['GET']])]
    public function dictionaryList(string $type): Response
    {
        $db = $this->connection();

        return $this->render('admin/dictionaries/list.html.twig', [
            'type' => $type,
            'items' => $db->fetchAllAssociative('SELECT * FROM '.self::dictionaryTable($type).' ORDER BY '.('cities' === $type ? 'name,id' : 'display_order,name,id')),
        ]);
    }

    #[AdminRoute(path: '/dictionaries/{type}/new', name: 'dictionary_new', options: ['requirements' => ['type' => 'cities|categories|amenities'], 'methods' => ['GET', 'POST']])]
    public function dictionaryCreate(string $type, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->validateDictionaryCsrf('dictionary-'.$type, $request);
            try {
                $this->insertDictionary($type, $request);
                $this->addFlash('success', 'Wpis słownikowy został utworzony.');

                return $this->redirectToRoute('admin_dictionary_list', ['type' => $type]);
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException|\InvalidArgumentException $exception) {
                $this->addFlash('error', $exception instanceof \Doctrine\DBAL\Exception\UniqueConstraintViolationException ? 'Slug musi być unikalny.' : $exception->getMessage());
            }
        }

        return $this->render('admin/dictionaries/form.html.twig', ['type' => $type, 'item' => null]);
    }

    #[AdminRoute(path: '/dictionaries/{type}/{id}/edit', name: 'dictionary_edit', options: ['requirements' => ['type' => 'cities|categories|amenities', 'id' => '[0-9a-f-]{36}'], 'methods' => ['GET', 'POST']])]
    public function dictionaryEdit(string $type, string $id, Request $request): Response
    {
        $db = $this->connection();
        $item = $db->fetchAssociative('SELECT * FROM '.self::dictionaryTable($type).' WHERE id=:id', ['id' => $id]);
        if (false === $item) {
            throw $this->createNotFoundException();
        }
        if ($request->isMethod('POST')) {
            $this->validateDictionaryCsrf('dictionary-'.$type.'-'.$id, $request);
            try {
                $this->updateDictionary($type, $id, $request);
                $this->addFlash('success', 'Wpis słownikowy został zaktualizowany.');

                return $this->redirectToRoute('admin_dictionary_list', ['type' => $type]);
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException|\InvalidArgumentException $exception) {
                $this->addFlash('error', $exception instanceof \Doctrine\DBAL\Exception\UniqueConstraintViolationException ? 'Slug musi być unikalny.' : $exception->getMessage());
                $item = array_merge($item, $request->request->all());
            }
        }

        return $this->render('admin/dictionaries/form.html.twig', ['type' => $type, 'item' => $item]);
    }

    #[AdminRoute(path: '/dictionaries/{type}/{id}/delete', name: 'dictionary_delete', options: ['requirements' => ['type' => 'cities|categories|amenities', 'id' => '[0-9a-f-]{36}'], 'methods' => ['POST']])]
    public function dictionaryDelete(string $type, string $id, Request $request): RedirectResponse
    {
        $this->validateDictionaryCsrf('dictionary-delete-'.$id, $request);
        try {
            $this->connection()->delete(self::dictionaryTable($type), ['id' => $id]);
            $this->addFlash('success', 'Wpis słownikowy został usunięty.');
        } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException) {
            $this->addFlash('error', 'Ten wpis jest używany w systemie. Wyłącz go zamiast usuwać.');
        }

        return $this->redirectToRoute('admin_dictionary_list', ['type' => $type]);
    }

    private static function dictionaryTable(string $type): string
    {
        return self::DICTIONARY_TABLES[$type] ?? throw new \InvalidArgumentException('Unsupported dictionary.');
    }

    private function validateDictionaryCsrf(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function insertDictionary(string $type, Request $request): void
    {
        $id = Uuid::v7()->toRfc4122();
        if ('cities' === $type) {
            $data = $this->cityDictionaryData($request);
            $this->connection()->executeStatement('INSERT INTO cities (id,name,slug,country_code,center,latitude,longitude,default_zoom,default_radius_km,timezone,enabled,created_at,updated_at) VALUES (:id,:name,:slug,:country_code,ST_SetSRID(ST_MakePoint(:longitude,:latitude),4326)::geography,:latitude,:longitude,:default_zoom,:default_radius_km,:timezone,:enabled,NOW(),NOW())', ['id' => $id] + $data);

            return;
        }
        $this->connection()->insert(self::dictionaryTable($type), ['id' => $id] + $this->simpleDictionaryData($type, $request));
    }

    private function updateDictionary(string $type, string $id, Request $request): void
    {
        if ('cities' === $type) {
            $data = $this->cityDictionaryData($request);
            $this->connection()->executeStatement('UPDATE cities SET name=:name,slug=:slug,country_code=:country_code,center=ST_SetSRID(ST_MakePoint(:longitude,:latitude),4326)::geography,latitude=:latitude,longitude=:longitude,default_zoom=:default_zoom,default_radius_km=:default_radius_km,timezone=:timezone,enabled=:enabled,updated_at=NOW() WHERE id=:id', ['id' => $id] + $data);

            return;
        }
        $this->connection()->update(self::dictionaryTable($type), $this->simpleDictionaryData($type, $request), ['id' => $id]);
    }

    /** @return array<string, scalar|null> */
    private function simpleDictionaryData(string $type, Request $request): array
    {
        $name = trim((string) $request->request->get('name'));
        $slug = trim((string) $request->request->get('slug'));
        if ('' === $name || 1 !== preg_match('/^[a-z0-9-]+$/', $slug)) {
            throw new \InvalidArgumentException('Nazwa oraz poprawny unikalny slug są wymagane.');
        }

        return 'categories' === $type
            ? ['name' => $name, 'slug' => $slug, 'description' => self::nullableDictionaryString((string) $request->request->get('description')), 'icon_key' => (string) $request->request->get('iconKey'), 'enabled' => (int) $request->request->getBoolean('enabled'), 'display_order' => $request->request->getInt('displayOrder')]
            : ['name' => $name, 'slug' => $slug, 'amenity_group' => (string) $request->request->get('group'), 'icon_key' => (string) $request->request->get('iconKey'), 'enabled' => (int) $request->request->getBoolean('enabled'), 'display_order' => $request->request->getInt('displayOrder')];
    }

    /** @return array<string, scalar> */
    private function cityDictionaryData(Request $request): array
    {
        $latitude = (float) $request->request->get('latitude');
        $longitude = (float) $request->request->get('longitude');
        $timezone = (string) $request->request->get('timezone');
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 || false === timezone_open($timezone)) {
            throw new \InvalidArgumentException('Współrzędne lub strefa czasowa są nieprawidłowe.');
        }
        $common = $this->simpleDictionaryData('cities', $request);

        return ['name' => (string) $common['name'], 'slug' => (string) $common['slug'], 'country_code' => strtoupper((string) $request->request->get('countryCode')), 'latitude' => $latitude, 'longitude' => $longitude, 'default_zoom' => $request->request->getInt('defaultZoom'), 'default_radius_km' => $request->request->getInt('defaultRadiusKm'), 'timezone' => $timezone, 'enabled' => (int) $request->request->getBoolean('enabled')];
    }

    private static function nullableDictionaryString(string $value): ?string
    {
        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
