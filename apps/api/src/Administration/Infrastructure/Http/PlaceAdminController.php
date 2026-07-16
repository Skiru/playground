<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\Http;

use App\Places\Application\Command\ArchivePlace;
use App\Places\Application\Command\CreatePlaceDraft;
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
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/places')]
#[IsGranted('ROLE_ADMIN')]
final class PlaceAdminController extends AbstractController
{
    public function __construct(private readonly PlaceRepository $places, private readonly PlaceCommandHandler $commands, private readonly LoggerInterface $logger)
    {
    }

    #[Route('', name: 'admin_places', methods: ['GET'])]
    public function list(): Response
    {
        return $this->render('admin/places/list.html.twig', ['places' => $this->places->listForAdministration()]);
    }

    #[Route('/new', name: 'admin_places_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('create-place', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }
            try {
                $this->commands->create(new CreatePlaceDraft(
                    name: $request->request->getString('name'),
                    slug: $request->request->getString('slug'),
                    shortDescription: $request->request->getString('shortDescription'),
                    description: $request->request->getString('description'),
                    addressLine1: $request->request->getString('addressLine1'),
                    postalCode: $request->request->getString('postalCode'),
                    citySlug: $request->request->getString('city'),
                    countryCode: 'PL',
                    latitude: (float) $request->request->getString('latitude'),
                    longitude: (float) $request->request->getString('longitude'),
                    timezone: 'Europe/Warsaw',
                    categorySlug: $request->request->getString('category'),
                    indoor: $request->request->getBoolean('indoor'),
                    outdoor: $request->request->getBoolean('outdoor'),
                    freeEntry: $request->request->getBoolean('freeEntry'),
                ));
                $this->addFlash('success', 'Draft place created.');

                return $this->redirectToRoute('admin_places');
            } catch (\InvalidArgumentException $exception) {
                $this->addFlash('error', $exception->getMessage());
            } catch (\Doctrine\DBAL\Exception $exception) {
                $this->logger->error('Place draft creation failed.', ['exception' => $exception]);
                $this->addFlash('error', 'Place could not be created. Try again.');
            }
        }

        return $this->render('admin/places/new.html.twig');
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
