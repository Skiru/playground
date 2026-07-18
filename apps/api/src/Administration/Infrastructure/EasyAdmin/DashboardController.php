<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\EasyAdmin;

use App\Administration\UI\Form\PlaceAdminCommandFactory;
use App\Administration\UI\Form\PlaceAdminFormData;
use App\Administration\UI\Form\PlaceAdminFormMapper;
use App\Administration\UI\Form\Type\PlaceAdminFormType;
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
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
#[IsGranted('ROLE_ADMIN')]
final class DashboardController extends AbstractDashboardController
{
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            PlaceRepository::class => '?'.PlaceRepository::class,
            PlaceCommandHandler::class => '?'.PlaceCommandHandler::class,
            PlaceAdminFormMapper::class => '?'.PlaceAdminFormMapper::class,
            PlaceAdminCommandFactory::class => '?'.PlaceAdminCommandFactory::class,
            LoggerInterface::class => '?'.LoggerInterface::class,
        ]);
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
        return $this->render('admin/dashboard.html.twig');
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
}
