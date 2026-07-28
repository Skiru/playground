<?php

declare(strict_types=1);

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

$main = connection();
$externalIds = ['race-candidate', 'race-source-link', 'race-approval', 'race-import-approval'];
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
        $result = execute($db, "INSERT INTO place_source_links (id,place_id,source,external_id,source_release,first_linked_at,last_seen_at,last_payload_hash) VALUES (gen_random_uuid(),'00000000-0000-7000-8000-000000000410','overture','race-source-link','2099-02-01.0',now(),now(),repeat('a',64)) ON CONFLICT (source,external_id) DO NOTHING RETURNING id");

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

    echo json_encode(['candidate_insert' => 'PASS', 'source_link_insert' => 'PASS', 'approval_approval' => 'PASS', 'import_approval' => 'PASS', 'cli_async_lock' => 'PASS'], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT)."\n";
} finally {
    $cleanup = connection();
    execute($cleanup, 'DELETE FROM place_source_links WHERE external_id = ANY($1::varchar[])', ['{'.implode(',', $externalIds).'}']);
    execute($cleanup, 'DELETE FROM place_candidates WHERE external_id = ANY($1::varchar[])', ['{'.implode(',', $externalIds).'}']);
}
