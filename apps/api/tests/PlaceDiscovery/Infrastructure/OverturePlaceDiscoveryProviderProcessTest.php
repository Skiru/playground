<?php

declare(strict_types=1);

namespace App\Tests\PlaceDiscovery\Infrastructure;

use App\PlaceDiscovery\Application\Port\ProviderSchemaChanged;
use App\PlaceDiscovery\Application\Port\ProviderSchemaViolation;
use App\PlaceDiscovery\Application\Port\ProviderTimeout;
use App\PlaceDiscovery\Application\Port\ProviderUnavailable;
use App\PlaceDiscovery\Domain\Aggregate\DiscoveryArea;
use App\PlaceDiscovery\Infrastructure\Overture\OverturePlaceDiscoveryProvider;
use PHPUnit\Framework\TestCase;

final class OverturePlaceDiscoveryProviderProcessTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testStreamsOptionalTaxonomyProvenanceAndFinalRecordWithoutNewlineWhileDrainingLargeStderr(): void
    {
        $record = ['id' => 'one', 'name' => 'Family Park', 'latitude' => 50.0, 'longitude' => 20.0, 'basic_category' => 'park', 'sources' => [['property_path' => 'names.primary', 'dataset' => 'Foursquare', 'license' => 'Apache-2.0', 'record_id' => 'fsq-1']]];
        $provider = $this->provider('if (in_array("--check-release", $argv, true)) exit(0); fwrite(STDERR, str_repeat("x", 100000)); fwrite(STDOUT, '.var_export(json_encode($record, \JSON_THROW_ON_ERROR), true).');');

        $places = iterator_to_array($provider->streamPlaces($this->area(), 'family-v1', '2026-07-22.0', 1));

        self::assertCount(1, $places);
        self::assertSame([], $places[0]->categories);
        self::assertSame('park', $places[0]->basicCategory);
        self::assertSame('Apache-2.0', $places[0]->provenance[0]->license);
    }

    public function testRejectsMalformedKnownTaxonomyType(): void
    {
        $provider = $this->provider('if (in_array("--check-release", $argv, true)) exit(0); echo json_encode(["id"=>"one","name"=>"Park","latitude"=>50,"longitude"=>20,"taxonomy"=>"bad"]), "\n";');
        $this->expectException(ProviderSchemaViolation::class);
        iterator_to_array($provider->streamPlaces($this->area(), 'family-v1', '2026-07-22.0', 1));
    }

    public function testReportsNonZeroExitWithBoundedDiagnostic(): void
    {
        $provider = $this->provider('if (in_array("--check-release", $argv, true)) exit(0); fwrite(STDERR, str_repeat("provider failed", 1000)); exit(7);');
        $this->expectException(ProviderUnavailable::class);
        iterator_to_array($provider->streamPlaces($this->area(), 'family-v1', '2026-07-22.0', 1));
    }

    public function testEnforcesTimeout(): void
    {
        $provider = $this->provider('if (in_array("--check-release", $argv, true)) exit(0); sleep(3);', 1);
        $this->expectException(ProviderTimeout::class);
        iterator_to_array($provider->streamPlaces($this->area(), 'family-v1', '2026-07-22.0', 1));
    }

    public function testRejectsMalformedNdjson(): void
    {
        $provider = $this->provider('if (in_array("--check-release", $argv, true)) exit(0); echo "not-json\n";');
        $this->expectException(\App\PlaceDiscovery\Domain\InvalidProviderRecord::class);
        iterator_to_array($provider->streamPlaces($this->area(), 'family-v1', '2026-07-22.0', 1));
    }

    public function testEnforcesOutputByteLimit(): void
    {
        $provider = $this->provider('if (in_array("--check-release", $argv, true)) exit(0); fwrite(STDOUT, str_repeat("x", 9*1024*1024));');
        $this->expectException(ProviderSchemaChanged::class);
        iterator_to_array($provider->streamPlaces($this->area(), 'family-v1', '2026-07-22.0', 1));
    }

    public function testEnforcesRecordLimit(): void
    {
        $provider = $this->provider('if (in_array("--check-release", $argv, true)) exit(0); $r=["id"=>"one","name"=>"Park","latitude"=>50,"longitude"=>20]; echo json_encode($r),"\n"; $r["id"]="two"; echo json_encode($r),"\n";');
        $this->expectException(ProviderSchemaChanged::class);
        iterator_to_array($provider->streamPlaces($this->area(), 'family-v1', '2026-07-22.0', 1));
    }

    public function testMissingExecutableIsTyped(): void
    {
        $provider = new OverturePlaceDiscoveryProvider('/missing/helper.py', '/definitely/missing/python');
        $this->expectException(ProviderUnavailable::class);
        $provider->assertReleaseAvailable('2026-07-22.0');
    }

    public function testGeneratorCancellationStopsTheChild(): void
    {
        $provider = $this->provider('if (in_array("--check-release", $argv, true)) exit(0); echo json_encode(["id"=>"one","name"=>"Park","latitude"=>50,"longitude"=>20]),"\n"; flush(); sleep(10);');
        $stream = $provider->streamPlaces($this->area(), 'family-v1', '2026-07-22.0', 10);
        foreach ($stream as $place) {
            self::assertSame('one', $place->externalId);
            break;
        }
        unset($stream);
        self::assertTrue(true);
    }

    private function provider(string $body, int $timeout = 5): OverturePlaceDiscoveryProvider
    {
        $path = tempnam(sys_get_temp_dir(), 'overture-provider-');
        if (false === $path) {
            self::fail('Unable to create provider fixture.');
        }
        $this->files[] = $path;
        file_put_contents($path, "<?php\n".$body."\n");
        chmod($path, 0700);

        return new OverturePlaceDiscoveryProvider($path, \PHP_BINARY, $timeout);
    }

    private function area(): DiscoveryArea
    {
        return new DiscoveryArea('00000000-0000-7000-8000-000000000900', 'test', true, 'PL', 50.0, 20.0, 1.0, 0.5, 20, 'family-v1');
    }
}
