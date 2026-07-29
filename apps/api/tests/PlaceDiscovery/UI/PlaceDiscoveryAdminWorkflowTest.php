<?php

declare(strict_types=1);

namespace App\Tests\PlaceDiscovery\UI;

use App\PlaceDiscovery\Application\Port\PlaceDiscoveryProvider;
use App\PlaceDiscovery\Domain\Aggregate\DiscoveryArea;
use App\PlaceDiscovery\Domain\SourceProvenanceFingerprint;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlaceDiscoveryAdminWorkflowTest extends WebTestCase
{
    private const CANDIDATE = '00000000-0000-7000-8000-000000000902';
    private const AREA = '00000000-0000-7000-8000-000000000900';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        self::ensureKernelShutdown();
    }

    public function testUnauthorizedUserIsDeniedAndSnapshotsHaveNoPublicRoute(): void
    {
        $this->client->request('GET', $this->candidateUrl(self::CANDIDATE));
        self::assertResponseRedirects('/admin/login');
        $this->client->request('GET', '/api/v1/place-candidates/'.self::CANDIDATE);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminDetailShowsPrivateProvenanceAndInvalidCsrfIsRejected(): void
    {
        $sources = [
            ['property' => '', 'dataset' => 'Overture', 'license' => null, 'record_id' => 'omf-1', 'provider' => 'Overture Maps Foundation', 'resource' => 'places', 'version' => '1'],
            ['property' => '/names/primary', 'dataset' => 'Foursquare', 'license' => null, 'record_id' => 'fsq-1', 'provider' => 'Foursquare', 'resource' => 'places', 'version' => '1'],
        ];
        $this->connection->update('place_candidates', ['source_provenance' => json_encode($sources, \JSON_THROW_ON_ERROR), 'source_license_review_required' => 'true'], ['id' => self::CANDIDATE]);
        $this->login();
        $page = $this->client->request('GET', $this->candidateUrl(self::CANDIDATE));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Proweniencja i licencje');
        self::assertSelectorTextContains('body', 'Prywatny snapshot źródłowy');
        self::assertSelectorTextContains('body', 'Licencja nierozstrzygnięta');
        self::assertSelectorCount(2, '.license-resolution-form');
        self::assertSelectorTextContains('body', 'Foursquare');

        $this->client->request('POST', '/admin/place-discovery/candidates/'.self::CANDIDATE.'/resolve-license', ['_token' => 'invalid', 'version' => 1, 'fingerprint' => SourceProvenanceFingerprint::fromArray($sources[0]), 'license' => 'Reviewed-1.0']);
        self::assertResponseStatusCodeSame(403);

        $token = (string) $page->filter('.license-resolution-form input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/admin/place-discovery/candidates/'.self::CANDIDATE.'/resolve-license', ['_token' => $token, 'version' => 1, 'fingerprint' => SourceProvenanceFingerprint::fromArray($sources[0]), 'license' => 'Reviewed-Overture-1.0']);
        self::assertResponseRedirects();
        $page = $this->client->followRedirect();
        self::assertNull($this->connection->fetchOne("SELECT source_provenance->0->>'license' FROM place_candidates WHERE id = ?", [self::CANDIDATE]));
        self::assertSame('Reviewed-Overture-1.0', $this->connection->fetchOne("SELECT source_license_resolutions->?->>'license' FROM place_candidates WHERE id = ?", [SourceProvenanceFingerprint::fromArray($sources[0]), self::CANDIDATE]));
        self::assertNull($this->connection->fetchOne("SELECT source_provenance->1->>'license' FROM place_candidates WHERE id = ?", [self::CANDIDATE]));
        self::assertSelectorCount(1, '.license-resolution-form');
        self::assertSelectorTextContains('body', 'Foursquare');
    }

    public function testCandidateEditApprovalUsesReviewedCityAndBooleansAndNeverPublishes(): void
    {
        $this->login();
        $page = $this->client->request('GET', $this->candidateUrl(self::CANDIDATE));
        $token = (string) $page->filter('input[name="_token"]')->first()->attr('value');
        $city = '00000000-0000-7000-8000-000000000105';
        $this->client->request('POST', '/admin/place-discovery/candidates/'.self::CANDIDATE.'/edit', ['_token' => $token, 'version' => 1, 'name' => 'Reviewed Family Park', 'address_line1' => 'Rynek 2', 'postal_code' => '35-001', 'locality' => 'neighborhood label', 'country_code' => 'PL', 'latitude' => '50.0413', 'longitude' => '21.999', 'category_id' => '00000000-0000-7000-8000-000000000201', 'city_id' => $city, 'indoor' => '1', 'outdoor' => '0', 'free_entry' => '1']);
        self::assertResponseRedirects();
        self::assertSame($city, $this->connection->fetchOne('SELECT suggested_city_id FROM place_candidates WHERE id = ?', [self::CANDIDATE]));
        self::assertTrue((bool) $this->connection->fetchOne('SELECT indoor FROM place_candidates WHERE id = ?', [self::CANDIDATE]));
        self::assertFalse((bool) $this->connection->fetchOne('SELECT outdoor FROM place_candidates WHERE id = ?', [self::CANDIDATE]));

        $this->client->request('POST', '/admin/place-discovery/candidates/'.self::CANDIDATE.'/approve', ['_token' => $token, 'version' => 2]);
        self::assertResponseRedirects();
        $place = $this->connection->fetchAssociative('SELECT p.id, p.slug, p.status, p.indoor, p.outdoor, p.free_entry FROM places p JOIN place_candidates c ON c.approved_place_id = p.id WHERE c.id = ?', [self::CANDIDATE]);
        self::assertIsArray($place);
        self::assertSame('draft', $place['status']);
        self::assertTrue((bool) $place['indoor']);
        self::assertFalse((bool) $place['outdoor']);
        self::assertTrue((bool) $place['free_entry']);
        $this->client->request('GET', '/api/v1/places/'.$place['slug']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['MANUAL_EDIT', 'APPROVED'], $this->connection->fetchFirstColumn('SELECT action FROM place_candidate_audit_events WHERE candidate_id = ? ORDER BY created_at, id', [self::CANDIDATE]));
    }

    public function testRejectDuplicateClearAndStaleVersionAreAudited(): void
    {
        $this->login();
        $unmapped = '00000000-0000-7000-8000-000000000903';
        $page = $this->client->request('GET', $this->candidateUrl($unmapped));
        $token = (string) $page->filter('input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/admin/place-discovery/candidates/'.$unmapped.'/reject', ['_token' => $token, 'version' => 1, 'reason' => 'Not suitable']);
        self::assertSame('REJECTED', $this->connection->fetchOne('SELECT status FROM place_candidates WHERE id = ?', [$unmapped]));

        $duplicate = '00000000-0000-7000-8000-000000000904';
        $page = $this->client->request('GET', $this->candidateUrl($duplicate));
        $token = (string) $page->filter('input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/admin/place-discovery/candidates/'.$duplicate.'/clear-duplicate', ['_token' => $token, 'version' => 1]);
        self::assertSame('PENDING', $this->connection->fetchOne('SELECT status FROM place_candidates WHERE id = ?', [$duplicate]));
        $this->client->request('POST', '/admin/place-discovery/candidates/'.$duplicate.'/duplicate', ['_token' => $token, 'version' => 2, 'place_id' => '00000000-0000-7000-8000-000000000400']);
        self::assertSame('DUPLICATE', $this->connection->fetchOne('SELECT status FROM place_candidates WHERE id = ?', [$duplicate]));

        $before = (string) $this->connection->fetchOne('SELECT name FROM place_candidates WHERE id = ?', [self::CANDIDATE]);
        $candidatePage = $this->client->request('GET', $this->candidateUrl(self::CANDIDATE));
        $candidateToken = (string) $candidatePage->filter('input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/admin/place-discovery/candidates/'.self::CANDIDATE.'/edit', ['_token' => $candidateToken, 'version' => 0, 'name' => 'stale', 'country_code' => 'PL', 'latitude' => 50, 'longitude' => 20]);
        self::assertSame($before, $this->connection->fetchOne('SELECT name FROM place_candidates WHERE id = ?', [self::CANDIDATE]));
    }

    public function testAuthorizedRunNowAndRetryCreateBoundedQueuedAttempts(): void
    {
        self::getContainer()->set(PlaceDiscoveryProvider::class, new AdminFixtureProvider());
        $this->connection->executeStatement('UPDATE place_discovery_areas SET enabled = true WHERE id = ?', [self::AREA]);
        $this->login();
        $page = $this->client->request('GET', '/admin?routeName=admin_place_discovery_runs');
        $token = (string) $page->filter('input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/admin/place-discovery/runs/action', ['_token' => $token, 'action' => 'run-now', 'area_id' => self::AREA]);
        self::assertResponseRedirects();
        $run = $this->connection->fetchAssociative("SELECT id, attempt, status FROM place_discovery_runs WHERE source_release = '2099-12-01.0'");
        self::assertIsArray($run);
        self::assertSame('QUEUED', $run['status']);
        $this->connection->executeStatement("UPDATE place_discovery_runs SET status = 'FAILED' WHERE id = ?", [$run['id']]);
        $this->client->request('POST', '/admin/place-discovery/runs/action', ['_token' => $token, 'action' => 'retry', 'run_id' => $run['id']]);
        self::assertSame(2, (int) $this->connection->fetchOne("SELECT MAX(attempt) FROM place_discovery_runs WHERE source_release = '2099-12-01.0'"));
    }

    private function login(): void
    {
        $page = $this->client->request('GET', '/admin/login');
        $this->client->request('POST', '/admin/login', ['_username' => 'admin@example.test', '_password' => 'test-password', '_csrf_token' => $page->filter('input[name="_csrf_token"]')->attr('value')], [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseRedirects('/admin');
    }

    private function candidateUrl(string $id): string
    {
        return '/admin?routeName=admin_place_discovery_candidate&routeParams%5Bid%5D='.$id;
    }
}

final class AdminFixtureProvider implements PlaceDiscoveryProvider
{
    public function getProviderName(): string
    {
        return 'overture';
    }

    public function getLatestRelease(): string
    {
        return '2099-12-01.0';
    }

    public function assertReleaseAvailable(string $release): void
    {
    }

    public function streamPlaces(DiscoveryArea $area, string $profile, string $release, int $limit): iterable
    {
        return [];
    }
}
