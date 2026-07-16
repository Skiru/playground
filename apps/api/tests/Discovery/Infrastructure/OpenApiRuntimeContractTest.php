<?php

declare(strict_types=1);

namespace App\Tests\Discovery\Infrastructure;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OpenApiRuntimeContractTest extends WebTestCase
{
    public function testCollectionDetailsMapAndProblemResponsesMatchRuntimeOpenApi(): void
    {
        $client = self::createClient();
        $factory = self::getContainer()->get(OpenApiFactoryInterface::class);
        self::assertInstanceOf(OpenApiFactoryInterface::class, $factory);
        $openApi = $factory([]);

        foreach ([
            ['/api/v1/places?pageSize=2', '/api/v1/places', '200', 'application/json'],
            ['/api/v1/places/demo-1-demo-bawialnia-mokotow', '/api/v1/places/{slug}', '200', 'application/json'],
            ['/api/v1/map/places?west=21&south=52&east=21.1&north=52.3&zoom=10', '/api/v1/map/places', '200', 'application/json'],
            ['/api/v1/places?pageSize=51', '/api/v1/places', '400', 'application/problem+json'],
        ] as [$uri, $path, $status, $mediaType]) {
            $client->request('GET', $uri);
            self::assertResponseStatusCodeSame((int) $status);
            $payload = self::json((string) $client->getResponse()->getContent());
            $operation = $openApi->getPaths()->getPath($path)?->getGet();
            self::assertNotNull($operation);
            $response = $operation->getResponses()[$status] ?? null;
            self::assertNotNull($response);
            $content = $response->getContent();
            self::assertNotNull($content);
            $media = $content[$mediaType] ?? null;
            self::assertIsArray($media);
            $schema = $media['schema'] ?? null;
            self::assertIsArray($schema);
            self::assertSchema($payload, $schema, '$');
        }
    }

    /** @return array<string, mixed> */
    private static function json(string $content): array
    {
        $decoded = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @param array<string, mixed> $schema */
    private static function assertSchema(mixed $value, array $schema, string $path): void
    {
        $type = $schema['type'] ?? null;
        if (\is_array($type) && null === $value && \in_array('null', $type, true)) {
            return;
        }
        if (\is_array($type)) {
            $type = array_values(array_filter($type, static fn (string $candidate): bool => 'null' !== $candidate))[0] ?? null;
        }
        match ($type) {
            'object' => self::assertIsArray($value, $path.' must be an object'),
            'array' => self::assertIsArray($value, $path.' must be an array'),
            'string' => self::assertIsString($value, $path.' must be a string'),
            'integer' => self::assertIsInt($value, $path.' must be an integer'),
            'number' => self::assertTrue(\is_int($value) || \is_float($value), $path.' must be numeric'),
            'boolean' => self::assertIsBool($value, $path.' must be a boolean'),
            null => null,
            default => self::fail($path.' uses unsupported schema type '.(string) $type),
        };
        if ('object' === $type) {
            foreach ($schema['required'] ?? [] as $required) {
                self::assertArrayHasKey($required, $value, $path.' is missing '.$required);
            }
            foreach ($schema['properties'] ?? [] as $name => $propertySchema) {
                if (\array_key_exists($name, $value)) {
                    self::assertSchema($value[$name], $propertySchema, $path.'.'.$name);
                }
            }
            if (false === ($schema['additionalProperties'] ?? true)) {
                self::assertSame([], array_diff(array_keys($value), array_keys($schema['properties'] ?? [])), $path.' exposes undocumented fields');
            }
        }
        if ('array' === $type && isset($schema['items'])) {
            if (isset($schema['maxItems'])) {
                self::assertLessThanOrEqual($schema['maxItems'], \count($value), $path.' exceeds maxItems');
            }
            foreach ($value as $index => $item) {
                self::assertSchema($item, $schema['items'], $path.'['.$index.']');
            }
        }
    }
}
