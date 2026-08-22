<?php

declare(strict_types=1);

namespace App\Tests\Administration\Infrastructure\EasyAdmin;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EasyAdminAssetRegressionTest extends WebTestCase
{
    public function testEasyAdminAssetsInHtmlAreAccessibleAndPhysicalFilesExist(): void
    {
        $client = self::createClient();
        $login = $client->request('GET', '/admin/login');
        $client->request('POST', '/admin/login', [
            '_username' => 'admin@example.test',
            '_password' => 'test-password',
            '_csrf_token' => $login->filter('input[name="_csrf_token"]')->attr('value'),
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseRedirects('/admin');

        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $assetUrls = [];

        $crawler->filter('link[rel="stylesheet"]')->each(static function ($node) use (&$assetUrls): void {
            $href = $node->attr('href');
            if ($href && (str_contains($href, '/bundles/') || str_contains($href, '/admin/'))) {
                $assetUrls[] = $href;
            }
        });

        $crawler->filter('script[src]')->each(static function ($node) use (&$assetUrls): void {
            $src = $node->attr('src');
            if ($src && (str_contains($src, '/bundles/') || str_contains($src, '/admin/'))) {
                $assetUrls[] = $src;
            }
        });

        self::assertNotEmpty($assetUrls, 'No EasyAdmin bundle/admin assets found in HTML output.');

        $publicDir = self::getContainer()->getParameter('kernel.project_dir').'/public';

        foreach ($assetUrls as $url) {
            $cleanUrl = parse_url($url, \PHP_URL_PATH);
            self::assertIsString($cleanUrl);

            // Verify physical file exists on disk inside public/
            $filePath = $publicDir.$cleanUrl;
            self::assertFileExists($filePath, \sprintf('Asset file missing on disk: %s (URL: %s)', $filePath, $url));
        }
    }

    public function testCaddyfileContainsBundlesRoutingRule(): void
    {
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        $possiblePaths = [
            $projectDir.'/infra/deployment/Caddyfile',
            $projectDir.'/../../infra/deployment/Caddyfile',
            $projectDir.'/../infra/deployment/Caddyfile',
            \dirname($projectDir, 2).'/infra/deployment/Caddyfile',
            '/workspace/infra/deployment/Caddyfile',
        ];

        $caddyfilePath = null;
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $caddyfilePath = $path;
                break;
            }
        }

        self::assertNotNull($caddyfilePath, 'Could not locate infra/deployment/Caddyfile for validation.');
        $content = file_get_contents($caddyfilePath);
        self::assertIsString($content);

        self::assertStringContainsString('handle /bundles/*', $content, 'Caddyfile must route /bundles/* to api:80 to serve EasyAdmin assets in production.');
    }
}
