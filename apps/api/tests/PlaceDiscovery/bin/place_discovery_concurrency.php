<?php

declare(strict_types=1);

use App\Kernel;
use App\PlaceDiscovery\Application\ConcurrentCandidateModification;
use App\PlaceDiscovery\Application\PlaceDiscoveryService;
use App\PlaceDiscovery\Domain\FamilyDiscoveryProfile;
use App\PlaceDiscovery\Domain\OvertureOperatingStatus;
use App\PlaceDiscovery\Domain\PlaceNormalizer;
use App\PlaceDiscovery\Domain\ProviderPlace;
use App\PlaceDiscovery\Domain\ProviderSourceRecord;
use App\PlaceDiscovery\Domain\SourceLicenseResolution;
use App\PlaceDiscovery\Domain\SourceProvenanceFingerprint;

require dirname(__DIR__, 3).'/vendor/autoload.php';

if (!extension_loaded('pgsql') || !extension_loaded('pcntl')) {
    throw new RuntimeException('pgsql and pcntl extensions are required.');
}

function connection(): PgSql\Connection
{
    $url = parse_url((string) getenv('DATABASE_URL'));
    if (false === $url || !isset($url['host'], $url['path'], $url['user'], $url['pass'])) {
        throw new RuntimeException('DATABASE_URL is invalid.');
    }
    $database = ltrim($url['path'], '/').('_test' === substr(ltrim($url['path'], '/'), -5) ? '' : '_test');
    $connection = pg_connect(sprintf('host=%s port=%d dbname=%s user=%s password=%s', $url['host'], $url['port'] ?? 5432, $database, $url['user'], $url['pass']), \PGSQL_CONNECT_FORCE_NEW);
    if (false === $connection) {
        throw new RuntimeException('Unable to open independent PostgreSQL connection.');
    }

    return $connection;
}

/** @param list<bool|float|int|string|null> $parameters */
function execute(PgSql\Connection $connection, string $sql, array $parameters = []): PgSql\Result
{
    $result = [] === $parameters ? pg_query($connection, $sql) : pg_query_params($connection, $sql, $parameters);
    if (false === $result) {
        throw new RuntimeException(pg_last_error($connection));
    }

    return $result;
}

/** @return list<mixed> */
function race(callable $left, callable $right): array
{
    global $main;
    if (isset($main) && $main instanceof PgSql\Connection) {
        pg_close($main);
        unset($main);
    }
    $children = [];
    foreach ([$left, $right] as $action) {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, 0);
        if (false === $pair) {
            throw new RuntimeException('Unable to create race barrier.');
        }
        $pid = pcntl_fork();
        if (-1 === $pid) {
            throw new RuntimeException('Unable to fork race worker.');
        }
        if (0 === $pid) {
            fclose($pair[0]);
            fread($pair[1], 1);
            try {
                fwrite($pair[1], json_encode(['ok' => true, 'result' => $action(connection())], \JSON_THROW_ON_ERROR)."\n");
                exit(0);
            } catch (Throwable $exception) {
                fwrite($pair[1], json_encode(['ok' => false, 'error' => $exception->getMessage()], \JSON_THROW_ON_ERROR)."\n");
                exit(1);
            }
        }
        fclose($pair[1]);
        $children[] = [$pid, $pair[0]];
    }
    foreach ($children as [, $socket]) {
        fwrite($socket, '1');
    }
    $results = [];
    foreach ($children as [$pid, $socket]) {
        $payload = json_decode((string) fgets($socket), true, 16, \JSON_THROW_ON_ERROR);
        pcntl_waitpid($pid, $status);
        if (!$payload['ok'] || 0 !== pcntl_wexitstatus($status)) {
            throw new RuntimeException('Race worker failed: '.($payload['error'] ?? 'unknown'));
        }
        $results[] = $payload['result'];
    }

    return $results;
}

function candidateInsert(PgSql\Connection $connection, string $id, string $externalId): bool
{
    $result = execute($connection, <<<'SQL'
INSERT INTO place_candidates (id, source, external_id, source_release, source_payload_hash, source_snapshot, name, normalized_name, latitude, longitude, source_categories, discovery_score, discovery_reasons, status, first_seen_at, last_seen_at, created_at, updated_at)
VALUES ($1, 'overture', $2, '2099-02-01.0', repeat('a',64), '{"id":"race"}'::jsonb, 'Race Park', 'race park', 50, 20, '[]'::jsonb, 50, '[]'::jsonb, 'PENDING', now(), now(), now(), now())
ON CONFLICT (source, external_id) DO NOTHING RETURNING id
SQL, [$id, $externalId]);

    return 1 === pg_num_rows($result);
}

function deleteLicenseRaceAudits(PgSql\Connection $connection): void
{
    execute($connection, 'ALTER TABLE place_candidate_audit_events DISABLE TRIGGER place_candidate_audit_no_update');
    try {
        execute($connection, "DELETE FROM place_candidate_audit_events WHERE candidate_id='00000000-0000-7000-8000-000000009915'");
    } finally {
        execute($connection, 'ALTER TABLE place_candidate_audit_events ENABLE TRIGGER place_candidate_audit_no_update');
    }
}

/** @return list<ProviderSourceRecord> */
function maximumRaceSources(): array
{
    $sources = [];
    for ($index = 0; $index < 32; ++$index) {
        $suffix = str_pad((string) $index, 2, '0', \STR_PAD_LEFT);
        $sources[] = new ProviderSourceRecord(str_pad('/p/'.$suffix, 50, 'p'), str_pad('Dataset-'.$suffix, 50, 'd'), null, str_pad('record-'.$suffix, 50, 'r'), provider: str_pad('Provider-'.$suffix, 50, 'p'), resource: str_pad('resource-'.$suffix, 50, 'r'), version: str_pad('version-'.$suffix, 50, 'v'));
    }

    return $sources;
}

function maximumRaceLicense(int $index): string
{
    return str_pad('Reviewed-'.$index.'-', 255, 'L');
}

$main = connection();
$externalIds = ['race-candidate', 'race-source-link', 'race-approval', 'race-import-approval', 'race-license-refresh'];
deleteLicenseRaceAudits($main);
execute($main, 'DELETE FROM place_source_links WHERE external_id = ANY($1::varchar[])', ['{'.implode(',', $externalIds).'}']);
execute($main, 'DELETE FROM place_candidates WHERE external_id = ANY($1::varchar[])', ['{'.implode(',', $externalIds).'}']);

try {
    $insertResults = race(
        static fn (PgSql\Connection $db): bool => candidateInsert($db, '00000000-0000-7000-8000-000000009910', 'race-candidate'),
        static fn (PgSql\Connection $db): bool => candidateInsert($db, '00000000-0000-7000-8000-000000009911', 'race-candidate'),
    );
    $main = connection();
    if (1 !== array_sum(array_map('intval', $insertResults)) || '1' !== pg_fetch_result(execute($main, "SELECT count(*) FROM place_candidates WHERE external_id='race-candidate'"), 0, 0)) {
        throw new RuntimeException('Candidate insert race did not converge to one row.');
    }

    candidateInsert($main, '00000000-0000-7000-8000-000000009912', 'race-source-link');
    $linkInsert = static function (PgSql\Connection $db): bool {
        $result = execute($db, "INSERT INTO place_source_links (id,place_id,source,external_id,source_release,first_linked_at,last_seen_at,last_payload_hash) VALUES (gen_random_uuid(),'00000000-0000-7000-8000-000000000410','overture','race-source-link','2099-02-01.0',now(),now(),repeat('a',64)) ON CONFLICT DO NOTHING RETURNING id");

        return 1 === pg_num_rows($result);
    };
    $linkResults = race($linkInsert, $linkInsert);
    $main = connection();
    if (1 !== array_sum(array_map('intval', $linkResults))) {
        throw new RuntimeException('Source-link insert race did not converge.');
    }

    candidateInsert($main, '00000000-0000-7000-8000-000000009913', 'race-approval');
    $approve = static function (PgSql\Connection $db): bool {
        execute($db, 'BEGIN');
        $status = pg_fetch_result(execute($db, "SELECT status FROM place_candidates WHERE external_id='race-approval' FOR UPDATE"), 0, 0);
        $won = false;
        if ('PENDING' === $status) {
            execute($db, "INSERT INTO place_source_links (id,place_id,source,external_id,source_release,first_linked_at,last_seen_at,last_payload_hash) VALUES (gen_random_uuid(),'00000000-0000-7000-8000-000000000411','overture','race-approval','2099-02-01.0',now(),now(),repeat('a',64)) ON CONFLICT (source,external_id) DO NOTHING");
            execute($db, "UPDATE place_candidates SET status='APPROVED', approved_place_id='00000000-0000-7000-8000-000000000411' WHERE external_id='race-approval'");
            $won = true;
        }
        execute($db, 'COMMIT');

        return $won;
    };
    $approvalResults = race($approve, $approve);
    $main = connection();
    if (1 !== array_sum(array_map('intval', $approvalResults))) {
        throw new RuntimeException('Approval race did not select one winner.');
    }

    candidateInsert($main, '00000000-0000-7000-8000-000000009914', 'race-import-approval');
    $import = static function (PgSql\Connection $db): bool {
        execute($db, 'BEGIN');
        execute($db, "SELECT id FROM place_candidates WHERE external_id='race-import-approval' FOR UPDATE");
        execute($db, "UPDATE place_candidates SET source_release='2099-03-01.0', last_seen_at=now() WHERE external_id='race-import-approval'");
        execute($db, 'COMMIT');

        return true;
    };
    $approveImport = static function (PgSql\Connection $db): bool {
        execute($db, 'BEGIN');
        execute($db, "SELECT id FROM place_candidates WHERE external_id='race-import-approval' FOR UPDATE");
        execute($db, "INSERT INTO place_source_links (id,place_id,source,external_id,source_release,first_linked_at,last_seen_at,last_payload_hash) VALUES (gen_random_uuid(),'00000000-0000-7000-8000-000000000412','overture','race-import-approval','2099-02-01.0',now(),now(),repeat('a',64)) ON CONFLICT (source,external_id) DO NOTHING");
        execute($db, "UPDATE place_candidates SET status='APPROVED', approved_place_id='00000000-0000-7000-8000-000000000412' WHERE external_id='race-import-approval' AND status='PENDING'");
        execute($db, 'COMMIT');

        return true;
    };
    race($import, $approveImport);
    $main = connection();
    $state = pg_fetch_assoc(execute($main, "SELECT status,source_release FROM place_candidates WHERE external_id='race-import-approval'"));
    if (false === $state || 'APPROVED' !== $state['status'] || '2099-03-01.0' !== $state['source_release']) {
        throw new RuntimeException('Import/approval race lost terminal state or source refresh.');
    }

    $raceSources = maximumRaceSources();
    $rawProvenance = json_encode($raceSources, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    $resolutions = [];
    foreach (array_slice($raceSources, 0, 31) as $index => $raceSource) {
        $source = $raceSource->jsonSerialize();
        $resolutions[SourceProvenanceFingerprint::fromArray($source)] = SourceLicenseResolution::review($source, maximumRaceLicense($index), 'race-admin@example.test', '2099-05-01T00:00:00+00:00', '2099-05-01.0');
    }
    $encodedResolutions = json_encode((object) $resolutions, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    execute($main, <<<'SQL'
INSERT INTO place_candidates (id, discovery_run_id, source, external_id, source_release, source_record_version, source_payload_hash, source_snapshot, source_provenance, source_license_resolutions, source_license_review_required, name, normalized_name, address_line1, postal_code, locality, country_code, latitude, longitude, source_categories, suggested_place_category_id, suggested_city_id, city_selection_source, indoor, outdoor, free_entry, confidence, operating_status, discovery_score, discovery_reasons, status, approved_place_id, first_seen_at, last_seen_at, created_at, updated_at)
VALUES ('00000000-0000-7000-8000-000000009915', '00000000-0000-7000-8000-000000000901', 'overture', 'race-license-refresh', '2099-05-01.0', '1', repeat('b',64), '{"id":"race-license-refresh","name":"Race License Park"}'::jsonb, $1::jsonb, $2::jsonb, true, 'Race License Park', 'race license park', 'Race 1', '35-001', 'Rzeszów', 'PL', 50.04, 22.0, '["playground"]'::jsonb, '00000000-0000-7000-8000-000000000201', '00000000-0000-7000-8000-000000000105', 'AUTO', true, false, true, 0.9, 'open', 90, '[]'::jsonb, 'APPROVED', '00000000-0000-7000-8000-000000000414', now(), now(), now(), now())
SQL, [$rawProvenance, $encodedResolutions]);
    execute($main, "INSERT INTO place_source_links (id,place_id,source,external_id,source_release,first_linked_at,last_seen_at,last_payload_hash,source_provenance) VALUES (gen_random_uuid(),'00000000-0000-7000-8000-000000000414','overture','race-license-refresh','2099-04-01.0',now(),now(),repeat('a',64),'[{\"property\":\"\",\"dataset\":\"Overture\",\"license\":\"Old-Compliant-1.0\"}]'::jsonb)");
    $refreshLicense = static function (PgSql\Connection $db) use ($raceSources): string {
        $kernel = new Kernel('test', false);
        $kernel->boot();
        try {
            $container = $kernel->getContainer()->get('test.service_container');
            $service = $container->get(PlaceDiscoveryService::class);
            $normalizer = $container->get(PlaceNormalizer::class);
            $profile = $container->get(FamilyDiscoveryProfile::class);
            $source = new ProviderPlace('race-license-refresh', '2099-05-08.0', '2', 'Race License Park', 50.04, 22.0, 'Race 1', '35-001', 'Rzeszów', 'PL', null, null, ['playground'], 'playground', 0.9, OvertureOperatingStatus::OPEN->value, ['id' => 'race-license-refresh', 'name' => 'Race License Park'], $raceSources);

            return $service->import('00000000-0000-7000-8000-000000000901', $source, $normalizer->normalize($source), $profile->classify($source, $normalizer->normalize($source)));
        } finally {
            $kernel->shutdown();
        }
    };
    $resolveLicense = static function (PgSql\Connection $db) use ($raceSources): bool {
        $kernel = new Kernel('test', false);
        $kernel->boot();
        try {
            $kernel->getContainer()->get('test.service_container')->get(PlaceDiscoveryService::class)->resolveUnlicensedProvenance('00000000-0000-7000-8000-000000009915', 1, SourceProvenanceFingerprint::fromArray($raceSources[31]->jsonSerialize()), maximumRaceLicense(31), 'race-admin@example.test');

            return true;
        } catch (ConcurrentCandidateModification) {
            return false;
        } finally {
            $kernel->shutdown();
        }
    };
    $licenseRaceResults = race($refreshLicense, $resolveLicense);
    $main = connection();
    $candidateState = pg_fetch_assoc(execute($main, "SELECT version,source_release,source_license_review_required FROM place_candidates WHERE external_id='race-license-refresh'"));
    $linkState = pg_fetch_assoc(execute($main, "SELECT source_release,octet_length(source_provenance::text) AS provenance_bytes,jsonb_array_length(source_provenance) AS provenance_count FROM place_source_links WHERE external_id='race-license-refresh'"));
    if (false === $candidateState || false === $linkState) {
        throw new RuntimeException('Refresh/license-resolution race lost its candidate or source link.');
    }
    $refreshAudits = (int) pg_fetch_result(execute($main, "SELECT count(*) FROM place_candidate_audit_events WHERE candidate_id='00000000-0000-7000-8000-000000009915' AND action='SOURCE_REFRESHED'"), 0, 0);
    $resolutionAudits = (int) pg_fetch_result(execute($main, "SELECT count(*) FROM place_candidate_audit_events WHERE candidate_id='00000000-0000-7000-8000-000000009915' AND action='SOURCE_LICENSE_RESOLVED'"), 0, 0);
    $resolutionWon = (bool) $licenseRaceResults[1];
    $validResolvedState = $resolutionWon && '3' === $candidateState['version'] && 'f' === $candidateState['source_license_review_required'] && '2099-05-08.0' === $linkState['source_release'] && '32' === $linkState['provenance_count'] && (int) $linkState['provenance_bytes'] > PlaceDiscoveryService::RAW_SOURCE_PROVENANCE_MAX_BYTES && (int) $linkState['provenance_bytes'] <= PlaceDiscoveryService::EFFECTIVE_SOURCE_PROVENANCE_MAX_BYTES && 1 === $resolutionAudits;
    $validRefreshState = !$resolutionWon && '2' === $candidateState['version'] && 't' === $candidateState['source_license_review_required'] && '2099-04-01.0' === $linkState['source_release'] && 0 === $resolutionAudits;
    if (1 !== $refreshAudits || (!$validResolvedState && !$validRefreshState)) {
        throw new RuntimeException('Refresh/license-resolution race produced an invalid state or lost an audit event.');
    }

    $lockKey = 'place-discovery:operation:overture:2099-04-01.0:00000000-0000-7000-8000-000000000900';
    $lockAction = static function (PgSql\Connection $db) use ($lockKey): bool {
        $acquired = 't' === pg_fetch_result(execute($db, 'SELECT pg_try_advisory_lock(hashtext($1))', [$lockKey]), 0, 0);
        if ($acquired) {
            usleep(500000);
            execute($db, 'SELECT pg_advisory_unlock(hashtext($1))', [$lockKey]);
        }

        return $acquired;
    };
    $lockResults = race($lockAction, $lockAction);
    $main = connection();
    if (1 !== array_sum(array_map('intval', $lockResults))) {
        throw new RuntimeException('Equivalent CLI/worker lock race admitted more than one owner.');
    }

    echo json_encode(['candidate_insert' => 'PASS', 'source_link_insert' => 'PASS', 'approval_approval' => 'PASS', 'import_approval' => 'PASS', 'refresh_license_resolution' => 'PASS', 'cli_async_lock' => 'PASS'], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT)."\n";
} finally {
    $cleanup = connection();
    deleteLicenseRaceAudits($cleanup);
    execute($cleanup, 'DELETE FROM place_source_links WHERE external_id = ANY($1::varchar[])', ['{'.implode(',', $externalIds).'}']);
    execute($cleanup, 'DELETE FROM place_candidates WHERE external_id = ANY($1::varchar[])', ['{'.implode(',', $externalIds).'}']);
}
