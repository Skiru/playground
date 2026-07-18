<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Application\Storage\StorageInterface;
use App\Shared\Application\Storage\StorageObjectKey;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class MediaController
{
    public function __construct(private StorageInterface $storage)
    {
    }

    #[Route('/media/{path}', name: 'media_serve', requirements: ['path' => '.+'], methods: ['GET'])]
    public function serve(string $path): Response
    {
        try {
            $key = new StorageObjectKey($path);

            if (!str_contains($key->toString(), '/variants/')) {
                return new Response('Access denied to private source.', 403);
            }

            $contents = $this->storage->read($key->toString());

            return new Response($contents, 200, [
                'Content-Type' => 'image/webp',
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ]);
        } catch (\Throwable) {
            return new Response('File not found.', 404);
        }
    }
}
