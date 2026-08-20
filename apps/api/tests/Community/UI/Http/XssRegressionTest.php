<?php

declare(strict_types=1);

namespace App\Tests\Community\UI\Http;

use App\Identity\Domain\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class XssRegressionTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;

    private function createUser(string $email, string $displayName): User
    {
        $user = new User(
            email: new \App\Identity\Domain\ValueObject\EmailAddress($email),
            displayName: $displayName,
            createdAt: new \DateTimeImmutable(),
            roles: ['ROLE_USER'],
        );

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function getCsrfHeaders($client): array
    {
        $client->request('GET', '/api/v1/session');
        $sessionData = json_decode($client->getResponse()->getContent(), true);

        return [
            'HTTP_X-CSRF-Token' => $sessionData['csrfToken'] ?? '',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_CONTENT_TYPE' => 'application/json',
        ];
    }

    public function testXssPayloadsInCommunityPostArePreservedAsRawTextAndJsonEncoded(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->connection = static::getContainer()->get(Connection::class);

        $payloads = [
            '<script>alert(1)</script>',
            '<img src=x onerror=alert(1)>',
            '"><script>alert(1)</script>',
            'javascript:alert(1)',
        ];

        $author = $this->createUser('xss_author_'.Uuid::v7()->toRfc4122().'@example.test', '<script>alert("name")</script>');
        $client->loginUser($author);
        $headers = $this->getCsrfHeaders($client);

        $categoryId = $this->connection->fetchOne('SELECT id FROM forum_categories WHERE active = true LIMIT 1');

        foreach ($payloads as $payload) {
            $client->request(
                'POST',
                '/api/v1/forum/categories/'.$categoryId.'/threads',
                [],
                [],
                $headers,
                (string) json_encode([
                    'title' => 'XSS Test '.$payload,
                    'body' => 'Body containing '.$payload,
                ])
            );

            $this->assertSame(201, $client->getResponse()->getStatusCode());
            $content = $client->getResponse()->getContent();

            // JSON response must be proper application/json and contain raw string safely JSON-escaped
            $this->assertStringContainsString('application/json', (string) $client->getResponse()->headers->get('Content-Type'));
            $data = json_decode($content, true);
            $this->assertIsArray($data);
            $this->assertSame('XSS Test '.$payload, $data['title']);
        }
    }
}
