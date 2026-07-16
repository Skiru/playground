<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\Http;

use App\Places\Application\Command\AgeZoneInput;
use App\Places\Application\Command\ArchivePlace;
use App\Places\Application\Command\CreatePlaceDraft;
use App\Places\Application\Command\ExternalReferenceInput;
use App\Places\Application\Command\MarkPlaceNeedsReverification;
use App\Places\Application\Command\MarkPlaceTemporarilyClosed;
use App\Places\Application\Command\PublishPlace;
use App\Places\Application\Command\ReplaceExternalReferences;
use App\Places\Application\Command\ReplacePlaceAgeZones;
use App\Places\Application\Command\ReplacePlaceAmenities;
use App\Places\Application\Command\ReplacePlaceCategories;
use App\Places\Application\Command\ReplaceSpecialOpeningDays;
use App\Places\Application\Command\ReplaceWeeklyOpeningHours;
use App\Places\Application\Command\SpecialOpeningDayInput;
use App\Places\Application\Command\SpecialOpeningIntervalInput;
use App\Places\Application\Command\SubmitPlaceForReview;
use App\Places\Application\Command\UnpublishPlace;
use App\Places\Application\Command\UpdatePlaceCoreDetails;
use App\Places\Application\Command\WeeklyOpeningIntervalInput;
use App\Places\Application\ConcurrentPlaceModification;
use App\Places\Application\PlaceCommandHandler;
use App\Places\Application\PlaceRepository;
use App\Places\Domain\VerificationStatus;
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
                $id = $this->commands->create(new CreatePlaceDraft(
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
                if ('' !== $request->request->getString('minAgeMonths')) {
                    $this->commands->replaceAgeZones(new ReplacePlaceAgeZones($id, 1, [new AgeZoneInput('Strefa główna', $request->request->getInt('minAgeMonths'), '' === $request->request->getString('maxAgeMonths') ? null : $request->request->getInt('maxAgeMonths'))]));
                }
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

    #[Route('/{id}', name: 'admin_places_view', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET'])]
    public function view(string $id): Response
    {
        return $this->render('admin/places/view.html.twig', ['place' => $this->places->get($id)]);
    }

    #[Route('/{id}/edit', name: 'admin_places_edit', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $place = $this->places->get($id);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit-place-'.$id, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }
            try {
                $version = $request->request->getInt('version');
                $core = new UpdatePlaceCoreDetails($id, $version++, $request->request->getString('name'), $request->request->getString('slug'), $request->request->getString('shortDescription'), $request->request->getString('description'), $request->request->getString('addressLine1'), $request->request->getString('postalCode'), $request->request->getString('city'), $request->request->getString('countryCode'), (float) $request->request->getString('latitude'), (float) $request->request->getString('longitude'), $request->request->getString('timezone'), $request->request->getBoolean('indoor'), $request->request->getBoolean('outdoor'), $request->request->getBoolean('freeEntry'), VerificationStatus::from($request->request->getString('verificationStatus')), self::nullable($request->request->getString('addressLine2')), self::nullable($request->request->getString('priceDescription')), self::nullable($request->request->getString('websiteUrl')), self::nullable($request->request->getString('phone')));
                $categorySlugs = self::csv($request->request->getString('categories'));
                $categories = new ReplacePlaceCategories($id, $version++, $categorySlugs, $request->request->getString('primaryCategory'));
                $amenities = new ReplacePlaceAmenities($id, $version++, self::csv($request->request->getString('amenities')));
                $ageZones = new ReplacePlaceAgeZones($id, $version++, self::ageZones($request->request->getString('ageZones')));
                $weekly = new ReplaceWeeklyOpeningHours($id, $version++, self::weeklyHours($request->request->getString('weeklyOpeningHours')));
                $special = new ReplaceSpecialOpeningDays($id, $version++, self::specialDays($request->request->getString('specialOpeningDays')));
                $references = new ReplaceExternalReferences($id, $version, self::externalReferences($request->request->getString('externalReferences')));
                $this->commands->edit($core, $categories, $amenities, $ageZones, $weekly, $special, $references);
                $this->addFlash('success', 'Place aggregate updated.');

                return $this->redirectToRoute('admin_places_view', ['id' => $id]);
            } catch (ConcurrentPlaceModification|\DomainException|\InvalidArgumentException $exception) {
                $this->addFlash('error', $exception->getMessage());
                $place = $this->places->get($id);
            }
        }

        return $this->render('admin/places/edit.html.twig', ['place' => $place, 'cities' => $this->places->allCities(), 'categories' => $this->places->allCategories(), 'amenities' => $this->places->allAmenities()]);
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

    private static function nullable(string $value): ?string
    {
        $value = trim($value);

        return '' === $value ? null : $value;
    }

    /** @return list<string> */
    private static function csv(string $value): array
    {
        return array_values(array_unique(array_filter(array_map(trim(...), explode(',', $value)), static fn (string $item): bool => '' !== $item)));
    }

    /** @return list<AgeZoneInput> */
    private static function ageZones(string $value): array
    {
        $result = [];
        foreach (self::lines($value) as $line) {
            $parts = array_pad(explode('|', $line), 4, '');
            $result[] = new AgeZoneInput(trim($parts[0]), (int) $parts[1], '' === trim($parts[2]) ? null : (int) $parts[2], self::nullable($parts[3]));
        }

        return $result;
    }

    /** @return list<WeeklyOpeningIntervalInput> */
    private static function weeklyHours(string $value): array
    {
        $result = [];
        foreach (self::lines($value) as $line) {
            $parts = array_pad(explode('|', $line), 5, '');
            $result[] = new WeeklyOpeningIntervalInput((int) $parts[0], (int) $parts[1], trim($parts[2]), trim($parts[3]), '1' === trim($parts[4]));
        }

        return $result;
    }

    /** @return list<SpecialOpeningDayInput> */
    private static function specialDays(string $value): array
    {
        $result = [];
        foreach (self::lines($value) as $line) {
            $parts = array_pad(explode('|', $line), 4, '');
            $closed = '1' === trim($parts[1]);
            $intervals = [];
            if (!$closed) {
                foreach (array_filter(explode(';', $parts[3])) as $encodedInterval) {
                    $interval = array_pad(explode(',', $encodedInterval), 4, '');
                    $intervals[] = new SpecialOpeningIntervalInput((int) $interval[0], trim($interval[1]), trim($interval[2]), '1' === trim($interval[3]));
                }
            }
            $result[] = new SpecialOpeningDayInput(trim($parts[0]), $closed, self::nullable($parts[2]), $intervals);
        }

        return $result;
    }

    /** @return list<ExternalReferenceInput> */
    private static function externalReferences(string $value): array
    {
        $result = [];
        foreach (self::lines($value) as $line) {
            $parts = array_pad(explode('|', $line), 3, '');
            $result[] = new ExternalReferenceInput(trim($parts[0]), trim($parts[1]), self::nullable($parts[2]));
        }

        return $result;
    }

    /** @return list<string> */
    private static function lines(string $value): array
    {
        return array_values(array_filter(array_map(trim(...), preg_split('/\R/', $value) ?: []), static fn (string $line): bool => '' !== $line));
    }
}
