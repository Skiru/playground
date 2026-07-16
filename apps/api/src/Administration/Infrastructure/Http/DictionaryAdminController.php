<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\Http;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/admin/dictionaries')]
#[IsGranted('ROLE_ADMIN')]
final class DictionaryAdminController extends AbstractController
{
    private const array TABLES = ['cities' => 'cities', 'categories' => 'categories', 'amenities' => 'amenities'];

    public function __construct(private readonly Connection $connection)
    {
    }

    #[Route('/{type}', name: 'admin_dictionary_list', requirements: ['type' => 'cities|categories|amenities'], methods: ['GET'])]
    public function list(string $type): Response
    {
        return $this->render('admin/dictionaries/list.html.twig', ['type' => $type, 'items' => $this->connection->fetchAllAssociative('SELECT * FROM '.self::table($type).' ORDER BY '.('cities' === $type ? 'name,id' : 'display_order,name,id'))]);
    }

    #[Route('/{type}/new', name: 'admin_dictionary_new', requirements: ['type' => 'cities|categories|amenities'], methods: ['GET', 'POST'])]
    public function create(string $type, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->validateCsrf('dictionary-'.$type, $request);
            try {
                $this->insert($type, $request);
                $this->addFlash('success', 'Dictionary entry created.');

                return $this->redirectToRoute('admin_dictionary_list', ['type' => $type]);
            } catch (UniqueConstraintViolationException|\InvalidArgumentException $exception) {
                $this->addFlash('error', $exception instanceof UniqueConstraintViolationException ? 'Slug must be unique.' : $exception->getMessage());
            }
        }

        return $this->render('admin/dictionaries/form.html.twig', ['type' => $type, 'item' => null]);
    }

    #[Route('/{type}/{id}/edit', name: 'admin_dictionary_edit', requirements: ['type' => 'cities|categories|amenities', 'id' => '[0-9a-f-]{36}'], methods: ['GET', 'POST'])]
    public function edit(string $type, string $id, Request $request): Response
    {
        $item = $this->connection->fetchAssociative('SELECT * FROM '.self::table($type).' WHERE id=:id', ['id' => $id]);
        if (false === $item) {
            throw $this->createNotFoundException();
        }
        if ($request->isMethod('POST')) {
            $this->validateCsrf('dictionary-'.$type.'-'.$id, $request);
            try {
                $this->update($type, $id, $request);
                $this->addFlash('success', 'Dictionary entry updated.');

                return $this->redirectToRoute('admin_dictionary_list', ['type' => $type]);
            } catch (UniqueConstraintViolationException|\InvalidArgumentException $exception) {
                $this->addFlash('error', $exception instanceof UniqueConstraintViolationException ? 'Slug must be unique.' : $exception->getMessage());
                $item = array_merge($item, $request->request->all());
            }
        }

        return $this->render('admin/dictionaries/form.html.twig', ['type' => $type, 'item' => $item]);
    }

    #[Route('/{type}/{id}/delete', name: 'admin_dictionary_delete', requirements: ['type' => 'cities|categories|amenities', 'id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function delete(string $type, string $id, Request $request): RedirectResponse
    {
        $this->validateCsrf('dictionary-delete-'.$id, $request);
        try {
            $this->connection->delete(self::table($type), ['id' => $id]);
            $this->addFlash('success', 'Dictionary entry deleted.');
        } catch (ForeignKeyConstraintViolationException) {
            $this->addFlash('error', 'This entry is in use. Disable it instead of deleting it.');
        }

        return $this->redirectToRoute('admin_dictionary_list', ['type' => $type]);
    }

    private function insert(string $type, Request $request): void
    {
        $id = Uuid::v7()->toRfc4122();
        if ('cities' === $type) {
            $data = $this->cityData($request);
            $this->connection->executeStatement('INSERT INTO cities (id,name,slug,country_code,center,latitude,longitude,default_zoom,default_radius_km,timezone,enabled,created_at,updated_at) VALUES (:id,:name,:slug,:country_code,ST_SetSRID(ST_MakePoint(:longitude,:latitude),4326)::geography,:latitude,:longitude,:default_zoom,:default_radius_km,:timezone,:enabled,NOW(),NOW())', ['id' => $id] + $data);

            return;
        }
        $this->connection->insert(self::table($type), ['id' => $id] + $this->simpleData($type, $request));
    }

    private function update(string $type, string $id, Request $request): void
    {
        if ('cities' === $type) {
            $data = $this->cityData($request);
            $this->connection->executeStatement('UPDATE cities SET name=:name,slug=:slug,country_code=:country_code,center=ST_SetSRID(ST_MakePoint(:longitude,:latitude),4326)::geography,latitude=:latitude,longitude=:longitude,default_zoom=:default_zoom,default_radius_km=:default_radius_km,timezone=:timezone,enabled=:enabled,updated_at=NOW() WHERE id=:id', ['id' => $id] + $data);

            return;
        }
        $this->connection->update(self::table($type), $this->simpleData($type, $request), ['id' => $id]);
    }

    /** @return array<string, scalar|null> */
    private function simpleData(string $type, Request $request): array
    {
        $name = trim($request->request->getString('name'));
        $slug = trim($request->request->getString('slug'));
        if ('' === $name || 1 !== preg_match('/^[a-z0-9-]+$/', $slug)) {
            throw new \InvalidArgumentException('Name and a valid slug are required.');
        }

        return 'categories' === $type
            ? ['name' => $name, 'slug' => $slug, 'description' => self::nullable($request->request->getString('description')), 'icon_key' => $request->request->getString('iconKey'), 'enabled' => (int) $request->request->getBoolean('enabled'), 'display_order' => $request->request->getInt('displayOrder')]
            : ['name' => $name, 'slug' => $slug, 'amenity_group' => $request->request->getString('group'), 'icon_key' => $request->request->getString('iconKey'), 'enabled' => (int) $request->request->getBoolean('enabled'), 'display_order' => $request->request->getInt('displayOrder')];
    }

    /** @return array<string, scalar> */
    private function cityData(Request $request): array
    {
        $latitude = (float) $request->request->getString('latitude');
        $longitude = (float) $request->request->getString('longitude');
        $timezone = $request->request->getString('timezone');
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 || false === timezone_open($timezone)) {
            throw new \InvalidArgumentException('Coordinates or timezone are invalid.');
        }
        $common = $this->simpleData('cities', $request);

        return ['name' => (string) $common['name'], 'slug' => (string) $common['slug'], 'country_code' => strtoupper($request->request->getString('countryCode')), 'latitude' => $latitude, 'longitude' => $longitude, 'default_zoom' => $request->request->getInt('defaultZoom'), 'default_radius_km' => $request->request->getInt('defaultRadiusKm'), 'timezone' => $timezone, 'enabled' => (int) $request->request->getBoolean('enabled')];
    }

    private function validateCsrf(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private static function table(string $type): string
    {
        return self::TABLES[$type] ?? throw new \InvalidArgumentException('Unsupported dictionary.');
    }

    private static function nullable(string $value): ?string
    {
        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
