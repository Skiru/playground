<?php

declare(strict_types=1);

namespace App\Tests\PlaceDiscovery\Infrastructure;

use App\PlaceDiscovery\Application\Port\ProviderSchemaChanged;
use App\PlaceDiscovery\Application\Port\ProviderSchemaViolation;
use App\PlaceDiscovery\Application\Port\ProviderTimeout;
use App\PlaceDiscovery\Application\Port\ProviderUnavailable;
use App\PlaceDiscovery\Domain\Aggregate\DiscoveryArea;
use App\PlaceDiscovery\Infrastructure\Overture\OverturePlaceDiscoveryProvider;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $record = ['id' => 'one', 'name' => 'Family Park', 'latitude' => 50.0, 'longitude' => 20.0, 'basic_category' => 'park', 'sources' => [['property' => '', 'dataset' => 'Overture', 'license' => null, 'provider' => 'Overture Maps Foundation', 'resource' => 'places', 'version' => '1', 'confidence' => 0.8], ['property' => '/names/primary', 'dataset' => 'Foursquare', 'license' => 'Apache-2.0', 'record_id' => 'fsq-1', 'update_time' => '2026-07-01T00:00:00Z']]];
        $provider = $this->provider('if (in_array("--check-release", $argv, true)) exit(0); fwrite(STDERR, str_repeat("x", 100000)); fwrite(STDOUT, '.var_export(json_encode($record, \JSON_THROW_ON_ERROR), true).');');

        $places = iterator_to_array($provider->streamPlaces($this->area(), 'family-v1', '2026-07-22.0', 1));

        self::assertCount(1, $places);
        self::assertSame([], $places[0]->categories);
        self::assertSame('park', $places[0]->basicCategory);
        self::assertSame('', $places[0]->provenance[0]->property);
        self::assertNull($places[0]->provenance[0]->license);
        self::assertSame('Overture Maps Foundation', $places[0]->provenance[0]->provider);
        self::assertSame('/names/primary', $places[0]->provenance[1]->property);
        self::assertSame('Apache-2.0', $places[0]->provenance[1]->license);
    }

    public function testRejectsMalformedOptionalLicenseType(): void
    {
        $provider = $this->provider('if (in_array("--check-release", $argv, true)) exit(0); echo json_encode(["id"=>"one","name"=>"Park","latitude"=>50,"longitude"=>20,"sources"=>[["property"=>"","dataset"=>"Overture","license"=>[]]]]), "\n";');
        $this->expectException(ProviderSchemaViolation::class);
        iterator_to_array($provider->streamPlaces($this->area(), 'family-v1', '2026-07-22.0', 1));
    }

    public function testAcceptsExactProvenanceCountAndFieldBounds(): void
    {
        $sources = array_fill(0, 32, ['property' => str_repeat('p', 255), 'dataset' => str_repeat('d', 255), 'record_id' => str_repeat('r', 255), 'license' => str_repeat('l', 255)]);
        $record = ['id' => 'one', 'name' => 'Park', 'latitude' => 50, 'longitude' => 20, 'sources' => $sources];
        $provider = $this->provider('if (in_array("--check-release", $argv, true)) exit(0); echo '.var_export(json_encode($record, \JSON_THROW_ON_ERROR), true).', "\n";');

        $places = iterator_to_array($provider->streamPlaces($this->area(), 'family-v1', '2026-07-22.0', 1));

        self::assertCount(32, $places[0]->provenance);
        self::assertSame(str_repeat('p', 255), $places[0]->provenance[0]->property);
    }

    public function testRejectsProvenanceCountOverflowWithoutOmittingRows(): void
    {
        $record = ['id' => 'one', 'name' => 'Park', 'latitude' => 50, 'longitude' => 20, 'sources' => array_fill(0, 33, ['property' => '', 'dataset' => 'Overture'])];
        $provider = $this->provider('if (in_array("--check-release", $argv, true)) exit(0); echo '.var_export(json_encode($record, \JSON_THROW_ON_ERROR), true).', "\n";');
        $this->expectException(ProviderSchemaViolation::class);
        $this->expectExceptionMessage('32-item');

        iterator_to_array($provider->streamPlaces($this->area(), 'family-v1', '2026-07-22.0', 1));
    }

    public function testRejectsObjectShapedProvenanceWithoutPartialNormalization(): void
    {
        $record = ['id' => 'one', 'name' => 'Park', 'latitude' => 50, 'longitude' => 20, 'sources' => ['unexpected' => ['property' => '', 'dataset' => 'Overture']]];
        $provider = $this->provider('if (in_array("--check-release", $argv, true)) exit(0); echo '.var_export(json_encode($record, \JSON_THROW_ON_ERROR), true).', "\n";');
        $this->expectException(ProviderSchemaViolation::class);
        $this->expectExceptionMessage('must be an array');

        iterator_to_array($provider->streamPlaces($this->area(), 'family-v1', '2026-07-22.0', 1));
    }

    #[DataProvider('overlongProvenanceFields')]
    public function testRejectsOverlongProvenanceFieldsWithoutSlicing(string $field): void
    {
        $source = ['property' => '', 'dataset' => 'Overture', $field => str_repeat('x', 256)];
        $record = ['id' => 'one', 'name' => 'Park', 'latitude' => 50, 'longitude' => 20, 'sources' => [$source]];
        $provider = $this->provider('if (in_array("--check-release", $argv, true)) exit(0); echo '.var_export(json_encode($record, \JSON_THROW_ON_ERROR), true).', "\n";');
        $this->expectException(ProviderSchemaViolation::class);
        $this->expectExceptionMessage($field);

        iterator_to_array($provider->streamPlaces($this->area(), 'family-v1', '2026-07-22.0', 1));
    }

    /** @return iterable<string, array{string}> */
    public static function overlongProvenanceFields(): iterable
    {
        foreach (['property', 'dataset', 'record_id', 'license'] as $field) {
            yield $field => [$field];
        }
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
