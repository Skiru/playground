<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\Http;

use App\Places\Application\ManagePlace;
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
    public function __construct(private readonly ManagePlace $places)
    {
    }

    #[Route('', name: 'admin_places', methods: ['GET'])]
    public function list(): Response
    {
        return $this->render('admin/places/list.html.twig', ['places' => $this->places->list()]);
    }

    #[Route('/new', name: 'admin_places_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('create-place', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }
            try {
                $data = [];
                foreach (['name', 'slug', 'shortDescription', 'description', 'city', 'category', 'addressLine1', 'postalCode', 'latitude', 'longitude', 'minAgeMonths', 'maxAgeMonths', 'indoor', 'outdoor', 'freeEntry'] as $field) {
                    $data[$field] = $request->request->get($field);
                }
                $this->places->createDraft($data);
                $this->addFlash('success', 'Draft place created.');

                return $this->redirectToRoute('admin_places');
            } catch (\InvalidArgumentException|\Doctrine\DBAL\Exception $exception) {
                $this->addFlash('error', $exception->getMessage());
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
                'submit' => $this->places->submitForReview($id),
                'publish' => $this->places->publish($id),
                'unpublish' => $this->places->unpublish($id),
                'reverify' => $this->places->markNeedsReverification($id),
                'close' => $this->places->markTemporarilyClosed($id),
                'archive' => $this->places->archive($id),
                default => throw new \InvalidArgumentException('Unsupported action.'),
            };
            $this->addFlash('success', 'Place workflow updated.');
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_places');
    }
}
