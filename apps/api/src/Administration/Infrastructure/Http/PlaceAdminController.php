<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\Http;

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
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/places')]
#[IsGranted('ROLE_ADMIN')]
final class PlaceAdminController extends AbstractController
{
    public function __construct(
        private readonly PlaceRepository $places,
        private readonly PlaceCommandHandler $commands,
        private readonly PlaceAdminFormMapper $formMapper,
        private readonly PlaceAdminCommandFactory $commandFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'admin_places', methods: ['GET'])]
    public function list(): Response
    {
        return $this->render('admin/places/list.html.twig', ['places' => $this->places->listForAdministration()]);
    }

    #[Route('/new', name: 'admin_places_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $data = $this->formMapper->createData();
        $form = $this->createForm(PlaceAdminFormType::class, $data, ['csrf_token_id' => 'create-place']);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->commands->create($this->commandFactory->create($data));
                $this->addFlash('success', 'Draft place created.');

                return $this->redirectToRoute('admin_places');
            } catch (\DomainException|\InvalidArgumentException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            } catch (\Doctrine\DBAL\Exception $exception) {
                $this->logger->error('Place draft creation failed.', ['exception' => $exception]);
                $form->addError(new FormError('Place could not be created. Try again.'));
            }
        }

        return $this->render('admin/places/new.html.twig', ['form' => $form]);
    }

    #[Route('/{id}', name: 'admin_places_view', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET'])]
    public function view(string $id): Response
    {
        return $this->render('admin/places/view.html.twig', ['place' => $this->places->get($id)]);
    }

    #[Route('/{id}/edit', name: 'admin_places_edit', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $data = $request->isMethod('POST') ? new PlaceAdminFormData() : $this->formMapper->editData($this->places->get($id));
        $form = $this->createForm(PlaceAdminFormType::class, $data, ['csrf_token_id' => 'edit-place-'.$id]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->commands->update($this->commandFactory->update($id, $data));
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

    #[Route('/{id}/{action}', name: 'admin_places_action', requirements: ['id' => '[0-9a-f-]{36}', 'action' => 'submit|publish|unpublish|reverify|close|archive'], methods: ['POST'])]
    public function action(string $id, string $action, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('place-'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        try {
            match ($action) {
                'submit' => $this->commands->submit(new SubmitPlaceForReview($id, $request->request->getInt('version'))),
                'publish' => $this->commands->publish(new PublishPlace($id, $request->request->getInt('version'))),
                'unpublish' => $this->commands->unpublish(new UnpublishPlace($id, $request->request->getInt('version'))),
                'reverify' => $this->commands->markNeedsReverification(new MarkPlaceNeedsReverification($id, $request->request->getInt('version'))),
                'close' => $this->commands->markTemporarilyClosed(new MarkPlaceTemporarilyClosed($id, $request->request->getInt('version'))),
                'archive' => $this->commands->archive(new ArchivePlace($id, $request->request->getInt('version'))),
                default => throw new \InvalidArgumentException('Unsupported action.'),
            };
            $this->addFlash('success', 'Place workflow updated.');
        } catch (ConcurrentPlaceModification|\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_places');
    }
}
