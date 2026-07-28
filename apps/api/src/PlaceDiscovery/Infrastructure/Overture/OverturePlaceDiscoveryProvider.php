<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Infrastructure\Overture;

use App\PlaceDiscovery\Application\Port\PlaceDiscoveryProvider;
use App\PlaceDiscovery\Application\Port\ProviderSchemaChanged;
use App\PlaceDiscovery\Application\Port\ProviderTimeout;
use App\PlaceDiscovery\Application\Port\ProviderUnavailable;
use App\PlaceDiscovery\Application\Port\ReleaseNotFound;
use App\PlaceDiscovery\Domain\Aggregate\DiscoveryArea;
use App\PlaceDiscovery\Domain\InvalidProviderRecord;
use App\PlaceDiscovery\Domain\ProviderPlace;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final readonly class OverturePlaceDiscoveryProvider implements PlaceDiscoveryProvider
{
    public function __construct(
        private string $helperPath,
        private string $pythonBinary = 'python3',
        private int $timeoutSeconds = 120,
    ) {
    }

    public function getProviderName(): string
    {
        return 'overture';
    }

    public function getLatestRelease(): string
    {
        $process = $this->process(['--latest']);
        $process->run();
        $this->assertSuccessful($process);
        $release = trim($process->getOutput());
        if (!preg_match('/^20\d{2}-\d{2}-\d{2}\.\d+$/', $release)) {
            throw new ReleaseNotFound('STAC did not return a valid Overture release.');
        }

        return $release;
    }

    public function streamPlaces(DiscoveryArea $area, string $profile, string $release, int $limit): iterable
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Provider record limit exceeds the hard cap.');
        }
        if (!preg_match('/^20\d{2}-\d{2}-\d{2}\.\d+$/', $release)) {
            throw new \InvalidArgumentException('Invalid Overture release identifier.');
        }
        $bbox = implode(',', array_map(static fn (float $value): string => number_format($value, 7, '.', ''), $area->boundingBox()));
        $process = $this->process(['--bbox', $bbox, '--release', $release, '--limit', (string) $limit]);
        $buffer = '';
        $records = 0;
        try {
            $process->start();
            foreach ($process as $type => $chunk) {
                if (Process::ERR === $type) {
                    continue;
                }
                $buffer .= $chunk;
                if (\strlen($buffer) > 1_048_576) {
                    throw new ProviderSchemaChanged('Provider emitted an oversized record.');
                }
                while (false !== ($position = strpos($buffer, "\n"))) {
                    $line = substr($buffer, 0, $position);
                    $buffer = substr($buffer, $position + 1);
                    if ('' === trim($line)) {
                        continue;
                    }
                    if (++$records > $limit) {
                        throw new ProviderSchemaChanged('Provider exceeded the requested record limit.');
                    }
                    yield $this->record($line, $release);
                }
            }
            $this->assertSuccessful($process);
        } catch (ProcessTimedOutException $exception) {
            $process->stop(1);
            throw new ProviderTimeout('Overture helper timed out.', 0, $exception);
        } finally {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
    }

    /** @param list<string> $arguments */
    private function process(array $arguments): Process
    {
        $process = new Process([$this->pythonBinary, $this->helperPath, ...$arguments], null, ['PYTHONUNBUFFERED' => '1']);
        $process->setTimeout($this->timeoutSeconds);
        $process->setIdleTimeout(30);

        return $process;
    }

    private function assertSuccessful(Process $process): void
    {
        if (!$process->isSuccessful()) {
            $message = mb_substr(trim($process->getErrorOutput()), 0, 1000);
            throw new ProviderUnavailable('Overture helper failed: '.('' === $message ? 'no diagnostic output' : $message));
        }
    }

    private function record(string $line, string $release): ProviderPlace
    {
        try {
            $data = json_decode($line, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidProviderRecord('Provider emitted malformed NDJSON.', 0, $e);
        }
        if (!\is_array($data) || !isset($data['id'], $data['name'], $data['latitude'], $data['longitude'], $data['taxonomy'])) {
            throw new ProviderSchemaChanged('Required Overture Places v1.18 fields are absent.');
        }
        $snapshot = array_intersect_key($data, array_flip(['id', 'version', 'name', 'address', 'website', 'phone', 'basic_category', 'taxonomy', 'confidence', 'operating_status']));

        return new ProviderPlace((string) $data['id'], $release, isset($data['version']) ? (string) $data['version'] : null, (string) $data['name'], (float) $data['latitude'], (float) $data['longitude'], $data['address']['line1'] ?? null, $data['address']['postcode'] ?? null, $data['address']['locality'] ?? null, isset($data['address']['country']) ? strtoupper((string) $data['address']['country']) : null, $data['website'] ?? null, $data['phone'] ?? null, array_values(array_filter((array) ($data['taxonomy']['hierarchy'] ?? []), 'is_string')), isset($data['basic_category']) ? (string) $data['basic_category'] : null, isset($data['confidence']) ? (float) $data['confidence'] : null, isset($data['operating_status']) ? (string) $data['operating_status'] : null, $snapshot);
    }
}
