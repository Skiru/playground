<?php

declare(strict_types=1);

namespace App\Tests\Community\UI\Http;

use App\Identity\Domain\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class ModerationClaimLifecycleTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;

    private function createUser(string $email, string $displayName, array $roles = ['ROLE_USER']): User
    {
        $user = new User(
            email: new \App\Identity\Domain\ValueObject\EmailAddress($email),
            displayName: $displayName,
            createdAt: new \DateTimeImmutable(),
            roles: $roles,
        );

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createReport(User $reporter, Uuid $targetId): Uuid
    {
        $reportId = Uuid::v7();
        $this->connection->executeStatement(
            'INSERT INTO content_reports (id, reporter_id, target_type, target_id, reason, details, status, created_at, resolved_at, resolved_by, claimed_by, claimed_at)
             VALUES (:id, :reporter_id, :target_type, :target_id, :reason, :details, :status, :created_at, NULL, NULL, NULL, NULL)',
            [
                'id' => $reportId->toRfc4122(),
                'reporter_id' => $reporter->getId()->toRfc4122(),
                'target_type' => 'REVIEW',
                'target_id' => $targetId->toRfc4122(),
                'reason' => 'SPAM',
                'details' => 'Spam review',
                'status' => 'OPEN',
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]
        );

        return $reportId;
    }

    private function getCsrfHeaders($client): array
    {
        $client->request('GET', '/api/v1/session');
        $sessionData = json_decode($client->getResponse()->getContent(), true);
        $csrfToken = $sessionData['csrfToken'] ?? '';

        return [
            'HTTP_X-CSRF-Token' => $csrfToken,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_CONTENT_TYPE' => 'application/json',
        ];
    }

    public function testClaimLifecycleExpiryAndDbInvariant(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->connection = static::getContainer()->get(Connection::class);

        $reporter = $this->createUser('reporter_claim_'.Uuid::v7()->toRfc4122().'@example.test', 'Reporter');
        $mod1 = $this->createUser('mod1_claim_'.Uuid::v7()->toRfc4122().'@example.test', 'Mod 1', ['ROLE_USER', 'ROLE_MODERATOR']);
        $mod2 = $this->createUser('mod2_claim_'.Uuid::v7()->toRfc4122().'@example.test', 'Mod 2', ['ROLE_USER', 'ROLE_MODERATOR']);
        $targetId = Uuid::v7();

        // Get or create city and place
        $cityId = $this->connection->fetchOne('SELECT id FROM cities LIMIT 1');
        if (false === $cityId) {
            $cityId = Uuid::v7()->toRfc4122();
            $this->connection->executeStatement("INSERT INTO cities (id, name, slug, country_code, center, latitude, longitude, default_zoom, default_radius_km, timezone, enabled, created_at, updated_at) VALUES (:id, 'Warszawa', 'warszawa', 'PL', ST_SetSRID(ST_MakePoint(21.0122, 52.2297), 4326)::geography, 52.2297, 21.0122, 12, 10, 'Europe/Warsaw', true, NOW(), NOW())", ['id' => $cityId]);
        }
        $categoryId = $this->connection->fetchOne('SELECT id FROM categories LIMIT 1');
        if (false === $categoryId) {
            $categoryId = Uuid::v7()->toRfc4122();
            $this->connection->executeStatement(
                "INSERT INTO categories (id, name, slug, icon_key, display_order, enabled) VALUES (:id, 'Bawialnie', 'bawialnie', 'play', 1, true)",
                ['id' => $categoryId]
            );
        }
        $placeId = $this->connection->fetchOne('SELECT id FROM places LIMIT 1');
        if (false === $placeId) {
            $placeId = Uuid::v7()->toRfc4122();
            $this->connection->executeStatement(
                "INSERT INTO places (id, name, normalized_name, slug, short_description, description, address_line1, postal_code, country_code, longitude, latitude, location, city_id, primary_category_id, status, verification_status, opening_hours_mode, timezone, indoor, outdoor, free_entry, version, created_at, updated_at)
                 VALUES (:id, 'Test Place', 'test place', 'test-place', 'A short desc', 'Full description', 'Address 1', '00-001', 'PL', 21.0122, 52.2297, ST_SetSRID(ST_MakePoint(21.0122, 52.2297), 4326)::geography, :city_id, :cat_id, 'published', 'unverified', 'unknown', 'Europe/Warsaw', true, false, false, 1, NOW(), NOW())",
                ['id' => $placeId, 'city_id' => $cityId, 'cat_id' => $categoryId]
            );
        }

        // Create target review so resolution works
        $this->connection->executeStatement(
            'INSERT INTO reviews (id, author_id, place_id, rating, body, status, created_at, updated_at)
             VALUES (:id, :author_id, :place_id, 1, \'Bad review\', \'PUBLISHED\', NOW(), NOW())',
            [
                'id' => $targetId->toRfc4122(),
                'author_id' => $reporter->getId()->toRfc4122(),
                'place_id' => $placeId,
            ]
        );

        $reportId = $this->createReport($reporter, $targetId);

        // 1. Mod 1 claims OPEN report
        $client->loginUser($mod1);
        $mod1Headers = $this->getCsrfHeaders($client);
        $client->request('POST', \sprintf('/api/v1/moderation/case/%s/claim', $reportId->toRfc4122()), [], [], $mod1Headers);
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        // Check DB state
        $row = $this->connection->fetchAssociative('SELECT status, claimed_by, claimed_at FROM content_reports WHERE id = :id', ['id' => $reportId->toRfc4122()]);
        $this->assertSame('IN_REVIEW', $row['status']);
        $this->assertSame($mod1->getId()->toRfc4122(), $row['claimed_by']);
        $this->assertNotNull($row['claimed_at']);

        // 2. Mod 2 tries to claim while claim is active -> 409
        $client->loginUser($mod2);
        $mod2Headers = $this->getCsrfHeaders($client);
        $client->request('POST', \sprintf('/api/v1/moderation/case/%s/claim', $reportId->toRfc4122()), [], [], $mod2Headers);
        $this->assertSame(409, $client->getResponse()->getStatusCode());

        // 3. Mod 1 continues/refreshes active claim -> 200
        $client->loginUser($mod1);
        $mod1Headers = $this->getCsrfHeaders($client);
        $client->request('POST', \sprintf('/api/v1/moderation/case/%s/claim', $reportId->toRfc4122()), [], [], $mod1Headers);
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        // 4. Fast forward claim timestamp into past (expired > 15 minutes)
        $this->connection->executeStatement(
            'UPDATE content_reports SET claimed_at = :old_at WHERE id = :id',
            [
                'old_at' => (new \DateTimeImmutable('-20 minutes'))->format('Y-m-d H:i:s'),
                'id' => $reportId->toRfc4122(),
            ]
        );

        // 5. Mod 2 claims expired case -> 200 success
        $client->loginUser($mod2);
        $mod2Headers = $this->getCsrfHeaders($client);
        $client->request('POST', \sprintf('/api/v1/moderation/case/%s/claim', $reportId->toRfc4122()), [], [], $mod2Headers);
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $rowAfterRecovery = $this->connection->fetchAssociative('SELECT status, claimed_by FROM content_reports WHERE id = :id', ['id' => $reportId->toRfc4122()]);
        $this->assertSame('IN_REVIEW', $rowAfterRecovery['status']);
        $this->assertSame($mod2->getId()->toRfc4122(), $rowAfterRecovery['claimed_by']);

        // 6. Mod 2 resolves case -> DB invariant check (claimed_by and claimed_at must be NULL)
        $idempotencyKey = Uuid::v7()->toRfc4122();
        $actionHeaders = $mod2Headers;
        $actionHeaders['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey;
        $client->request(
            'POST',
            '/api/v1/moderation/action',
            [],
            [],
            $actionHeaders,
            (string) json_encode([
                'reportId' => $reportId->toRfc4122(),
                'action' => 'RESOLVE_REPORT',
                'reason' => 'Reviewed and resolved',
            ])
        );
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $rowResolved = $this->connection->fetchAssociative('SELECT status, claimed_by, claimed_at, resolved_by FROM content_reports WHERE id = :id', ['id' => $reportId->toRfc4122()]);
        $this->assertSame('RESOLVED', $rowResolved['status']);
        $this->assertNull($rowResolved['claimed_by']);
        $this->assertNull($rowResolved['claimed_at']);
        $this->assertSame($mod2->getId()->toRfc4122(), $rowResolved['resolved_by']);
    }
}
