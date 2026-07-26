<?php

declare(strict_types=1);

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function uuidV4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
    $hex = bin2hex($bytes);

    return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
}

function parseDatabaseUrl(string $databaseUrl): array
{
    $parts = parse_url($databaseUrl);
    if (false === $parts) {
        throw new RuntimeException('Unable to parse DATABASE_URL.');
    }

    return [
        'host' => $parts['host'] ?? 'database',
        'port' => (int) ($parts['port'] ?? 5432),
        'user' => $parts['user'] ?? '',
        'password' => $parts['pass'] ?? '',
        'dbname' => ltrim((string) ($parts['path'] ?? ''), '/'),
    ];
}

function pgConnection(): PgSql\Connection
{
    $parsed = parseDatabaseUrl((string) getenv('DATABASE_URL'));
    $connString = sprintf(
        'host=%s port=%d dbname=%s user=%s password=%s',
        $parsed['host'],
        $parsed['port'],
        $parsed['dbname'],
        $parsed['user'],
        $parsed['password'],
    );

    $connection = pg_connect($connString);
    if (false === $connection) {
        throw new RuntimeException('Unable to connect to PostgreSQL.');
    }

    return $connection;
}

function pgOne(PgSql\Connection $connection, string $sql, array $params = []): string
{
    $result = pg_query_params($connection, $sql, $params);
    if (false === $result) {
        throw new RuntimeException(pg_last_error($connection));
    }
    $row = pg_fetch_row($result);
    if (false === $row) {
        throw new RuntimeException('Expected one database row.');
    }

    return (string) $row[0];
}

function pgRow(PgSql\Connection $connection, string $sql, array $params = []): array
{
    $result = pg_query_params($connection, $sql, $params);
    if (false === $result) {
        throw new RuntimeException(pg_last_error($connection));
    }
    $row = pg_fetch_assoc($result);
    if (false === $row) {
        throw new RuntimeException('Expected one database row.');
    }

    return $row;
}

function apiRequest(string $method, string $path, ?array $body = null, ?string $cookieFile = null, array $headers = [], string $baseUrl = 'http://127.0.0.1'): array
{
    $startedAt = microtime(true);
    $ch = curl_init();
    $httpHeaders = [];
    foreach ($headers as $name => $value) {
        $httpHeaders[] = $name.': '.$value;
    }

    $payload = null;
    if (null !== $body) {
        $payload = json_encode($body, \JSON_THROW_ON_ERROR);
        if (!isset($headers['Content-Type'])) {
            $httpHeaders[] = 'Content-Type: application/json';
        }
    }

    curl_setopt_array($ch, [
        \CURLOPT_URL => rtrim($baseUrl, '/').$path,
        \CURLOPT_CUSTOMREQUEST => $method,
        \CURLOPT_RETURNTRANSFER => true,
        \CURLOPT_HEADER => true,
        \CURLOPT_TIMEOUT => 30,
        \CURLOPT_HTTPHEADER => $httpHeaders,
    ]);

    if (null !== $cookieFile) {
        curl_setopt($ch, \CURLOPT_COOKIEFILE, $cookieFile);
        curl_setopt($ch, \CURLOPT_COOKIEJAR, $cookieFile);
    }

    if (null !== $payload) {
        curl_setopt($ch, \CURLOPT_POSTFIELDS, $payload);
    }

    $response = curl_exec($ch);
    if (false === $response) {
        $error = curl_error($ch);
        throw new RuntimeException('Curl request failed: '.$error);
    }

    $status = (int) curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, \CURLINFO_HEADER_SIZE);

    $bodyText = substr($response, $headerSize);
    $json = json_decode($bodyText, true);

    return [
        'status' => $status,
        'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
        'body' => $bodyText,
        'json' => is_array($json) ? $json : null,
    ];
}

function loginDev(string $email, string $displayName, array $roles = ['ROLE_USER']): array
{
    $cookieFile = tempnam(sys_get_temp_dir(), 'c5d-cookie-');
    if (false === $cookieFile) {
        throw new RuntimeException('Unable to create cookie file.');
    }

    $response = apiRequest('POST', '/api/v1/dev-auth/login', [
        'email' => $email,
        'displayName' => $displayName,
        'roles' => $roles,
    ], $cookieFile, ['Content-Type' => 'application/json']);

    assertTrue(200 === $response['status'], 'Dev login failed for '.$email);
    assertTrue(isset($response['json']['csrfToken']), 'Missing csrfToken for '.$email);

    return [
        'cookieFile' => $cookieFile,
        'csrfToken' => (string) $response['json']['csrfToken'],
        'userId' => (string) $response['json']['user']['id'],
    ];
}

function authHeaders(array $auth, array $headers = []): array
{
    return array_merge([
        'X-CSRF-Token' => (string) $auth['csrfToken'],
        'Content-Type' => 'application/json',
    ], $headers);
}

function createThread(array $auth, string $categoryId, string $title, string $body): array
{
    $response = apiRequest('POST', '/api/v1/forum/categories/'.$categoryId.'/threads', [
        'title' => $title,
        'body' => $body,
    ], (string) $auth['cookieFile'], authHeaders($auth));

    assertTrue(201 === $response['status'], 'Thread creation failed: '.$response['body']);

    return $response['json'];
}

function reportThread(array $auth, string $threadId, string $reason = 'SPAM'): string
{
    $response = apiRequest('POST', '/api/v1/content-reports', [
        'targetId' => $threadId,
        'targetType' => 'FORUM_THREAD',
        'reason' => $reason,
    ], (string) $auth['cookieFile'], authHeaders($auth));

    assertTrue(201 === $response['status'], 'Thread report failed: '.$response['body']);

    return (string) $response['json']['id'];
}

function claimCase(array $auth, string $reportId): void
{
    $response = apiRequest('POST', '/api/v1/moderation/case/'.$reportId.'/claim', null, (string) $auth['cookieFile'], [
        'X-CSRF-Token' => (string) $auth['csrfToken'],
    ]);

    assertTrue(200 === $response['status'], 'Claim failed: '.$response['body']);
}

function beginLock(PgSql\Connection $connection, string $sql, array $params): void
{
    assertTrue(false !== pg_query($connection, 'BEGIN'), 'Unable to begin lock transaction.');
    $result = pg_query_params($connection, $sql, $params);
    assertTrue(false !== $result, 'Unable to acquire lock: '.pg_last_error($connection));
}

function releaseLock(PgSql\Connection $connection): void
{
    assertTrue(false !== pg_query($connection, 'COMMIT'), 'Unable to commit lock transaction.');
}

function startWorker(array $payload): array
{
    $payloadFile = tempnam(sys_get_temp_dir(), 'c5d-payload-');
    if (false === $payloadFile) {
        throw new RuntimeException('Unable to create payload file.');
    }
    file_put_contents($payloadFile, json_encode($payload, \JSON_THROW_ON_ERROR));

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $command = ['php', __DIR__.'/c5d_delete_moderation_concurrency_worker.php', $payloadFile];
    $process = proc_open($command, $descriptorSpec, $pipes, __DIR__);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start worker process.');
    }

    fclose($pipes[0]);

    return [
        'process' => $process,
        'stdout' => $pipes[1],
        'stderr' => $pipes[2],
        'payloadFile' => $payloadFile,
    ];
}

function finishWorker(array $worker): array
{
    $stdout = stream_get_contents($worker['stdout']);
    $stderr = stream_get_contents($worker['stderr']);
    fclose($worker['stdout']);
    fclose($worker['stderr']);
    $exitCode = proc_close($worker['process']);
    @unlink($worker['payloadFile']);

    assertTrue(0 === $exitCode, 'Worker exited with '.$exitCode.': '.$stderr);
    assertTrue(false !== $stdout && '' !== trim($stdout), 'Worker produced no stdout.');

    return json_decode(trim($stdout), true, 512, \JSON_THROW_ON_ERROR);
}

function assertAllowedLoser(array $result, array $allowedCodes): void
{
    assertTrue(
        in_array($result['status'], [404, 409], true),
        'Expected controlled 404/409 loser outcome, got '.$result['status'].' with body '.$result['body']
    );
    $code = $result['json']['code'] ?? null;
    assertTrue(in_array($code, $allowedCodes, true), 'Unexpected loser code: '.var_export($code, true));
}

function assertPublicBodyAbsent(array $threadData, string $categorySlug): void
{
    $threadResponse = apiRequest('GET', '/api/v1/forum/threads/'.$threadData['id']);
    assertTrue(in_array($threadResponse['status'], [200, 404], true), 'Unexpected public thread status: '.$threadResponse['status']);
    assertTrue(!str_contains($threadResponse['body'], (string) $threadData['firstPost']['body']), 'Deleted thread body leaked in public thread detail.');

    $listingResponse = apiRequest('GET', '/api/v1/forum/categories/'.$categorySlug.'/threads?limit=20');
    assertTrue(200 === $listingResponse['status'], 'Thread listing failed.');
    $listingBody = $listingResponse['body'];
    assertTrue(!str_contains($listingBody, (string) $threadData['firstPost']['body']), 'Deleted thread body leaked in listing.');

    $feedResponse = apiRequest('GET', '/api/v1/community/feed?limit=20');
    assertTrue(200 === $feedResponse['status'], 'Community feed failed.');
    assertTrue(!str_contains($feedResponse['body'], (string) $threadData['firstPost']['body']), 'Deleted thread body leaked in feed.');
}

function scenarioA(PgSql\Connection $connection, string $categoryId, string $categorySlug): array
{
    $author = loginDev('c5d-race-author-a@example.com', 'Race Author A');
    $reporter = loginDev('c5d-race-reporter-a@example.com', 'Race Reporter A');
    $moderator = loginDev('c5d-race-moderator-a@example.com', 'Race Moderator A', ['ROLE_MODERATOR']);
    $thread = createThread($author, $categoryId, 'C5D concurrency remove/delete A', 'Concurrent delete versus moderator remove body A');
    $reportId = reportThread($reporter, (string) $thread['id']);
    claimCase($moderator, $reportId);

    beginLock($connection, 'SELECT id FROM forum_threads WHERE id = $1 FOR UPDATE', [(string) $thread['id']]);
    $deleteWorker = startWorker([
        'baseUrl' => 'http://127.0.0.1',
        'method' => 'DELETE',
        'path' => '/api/v1/me/forum-threads/'.$thread['id'],
        'body' => null,
        'cookieFile' => $author['cookieFile'],
        'headers' => ['X-CSRF-Token' => $author['csrfToken']],
    ]);
    $moderateWorker = startWorker([
        'baseUrl' => 'http://127.0.0.1',
        'method' => 'POST',
        'path' => '/api/v1/moderation/action',
        'body' => [
            'reportId' => $reportId,
            'action' => 'REMOVE',
            'reason' => 'Moderator remove races with author delete.',
        ],
        'cookieFile' => $moderator['cookieFile'],
        'headers' => [
            'Content-Type' => 'application/json',
            'X-CSRF-Token' => $moderator['csrfToken'],
            'Idempotency-Key' => uuidV4(),
        ],
    ]);

    usleep(300000);
    releaseLock($connection);

    $deleteResult = finishWorker($deleteWorker);
    $moderateResult = finishWorker($moderateWorker);

    assertTrue(!in_array($deleteResult['status'], [500], true), 'Delete request leaked 500.');
    assertTrue(!in_array($moderateResult['status'], [500], true), 'Moderation request leaked 500.');

    if (204 === $deleteResult['status']) {
        assertTrue(200 === $moderateResult['status'] || in_array($moderateResult['status'], [404, 409], true), 'Unexpected moderation outcome after author delete.');
        if (200 !== $moderateResult['status']) {
            assertAllowedLoser($moderateResult, ['MISSING_PUBLIC_RESOURCE', 'MODERATION_CONFLICT', 'MODERATION_STATE_CONFLICT']);
        }
    } else {
        assertAllowedLoser($deleteResult, ['MISSING_PUBLIC_RESOURCE', 'CONCURRENCY_CONFLICT']);
        assertTrue(200 === $moderateResult['status'], 'Moderator remove should succeed when author delete loses.');
    }

    $threadStatus = pgOne($connection, 'SELECT status FROM forum_threads WHERE id = $1', [(string) $thread['id']]);
    $firstPostStatus = pgOne($connection, 'SELECT status FROM forum_posts WHERE id = $1', [(string) $thread['firstPost']['id']]);
    assertTrue(in_array($threadStatus, ['DELETED_BY_AUTHOR', 'REMOVED_BY_MODERATOR'], true), 'Unexpected final thread status for scenario A: '.$threadStatus);
    assertTrue(in_array($firstPostStatus, ['DELETED_BY_AUTHOR', 'PUBLISHED', 'HIDDEN'], true), 'Unexpected initial post status for scenario A: '.$firstPostStatus);
    assertPublicBodyAbsent($thread, $categorySlug);

    return ['delete' => $deleteResult, 'moderate' => $moderateResult, 'threadStatus' => $threadStatus, 'firstPostStatus' => $firstPostStatus];
}

function scenarioB(PgSql\Connection $connection, string $categoryId, string $categorySlug): array
{
    $author = loginDev('c5d-race-author-b@example.com', 'Race Author B');
    $thread = createThread($author, $categoryId, 'C5D concurrency delete initial B', 'Concurrent delete with direct initial-post delete body B');

    beginLock($connection, 'SELECT id FROM forum_threads WHERE id = $1 FOR UPDATE', [(string) $thread['id']]);
    $threadWorker = startWorker([
        'baseUrl' => 'http://127.0.0.1',
        'method' => 'DELETE',
        'path' => '/api/v1/me/forum-threads/'.$thread['id'],
        'body' => null,
        'cookieFile' => $author['cookieFile'],
        'headers' => ['X-CSRF-Token' => $author['csrfToken']],
    ]);
    $postWorker = startWorker([
        'baseUrl' => 'http://127.0.0.1',
        'method' => 'DELETE',
        'path' => '/api/v1/me/forum-posts/'.$thread['firstPost']['id'],
        'body' => null,
        'cookieFile' => $author['cookieFile'],
        'headers' => ['X-CSRF-Token' => $author['csrfToken']],
    ]);

    usleep(300000);
    releaseLock($connection);

    $threadResult = finishWorker($threadWorker);
    $postResult = finishWorker($postWorker);

    assertTrue(204 === $threadResult['status'], 'Author thread delete should succeed in scenario B.');
    assertTrue(in_array($postResult['status'], [404, 409], true), 'Initial post delete should fail with controlled 404/409 outcome.');
    assertTrue(
        in_array($postResult['json']['code'] ?? null, ['INITIAL_POST_DELETE_REQUIRES_THREAD_DELETE', 'MISSING_PUBLIC_RESOURCE'], true),
        'Unexpected initial post delete code: '.var_export($postResult['json']['code'] ?? null, true)
    );

    $threadStatus = pgOne($connection, 'SELECT status FROM forum_threads WHERE id = $1', [(string) $thread['id']]);
    $firstPostStatus = pgOne($connection, 'SELECT status FROM forum_posts WHERE id = $1', [(string) $thread['firstPost']['id']]);
    assertTrue('DELETED_BY_AUTHOR' === $threadStatus, 'Scenario B thread was not author-deleted.');
    assertTrue('DELETED_BY_AUTHOR' === $firstPostStatus, 'Scenario B initial post was not author-deleted.');
    assertPublicBodyAbsent($thread, $categorySlug);

    return ['threadDelete' => $threadResult, 'postDelete' => $postResult, 'threadStatus' => $threadStatus, 'firstPostStatus' => $firstPostStatus];
}

function scenarioC(PgSql\Connection $connection, string $categoryId): array
{
    $author = loginDev('c5d-race-author-c@example.com', 'Race Author C');
    $thread = createThread($author, $categoryId, 'C5D repeated author delete C', 'Repeated author delete body C');

    beginLock($connection, 'SELECT id FROM forum_threads WHERE id = $1 FOR UPDATE', [(string) $thread['id']]);
    $firstDelete = startWorker([
        'baseUrl' => 'http://127.0.0.1',
        'method' => 'DELETE',
        'path' => '/api/v1/me/forum-threads/'.$thread['id'],
        'body' => null,
        'cookieFile' => $author['cookieFile'],
        'headers' => ['X-CSRF-Token' => $author['csrfToken']],
    ]);
    $secondDelete = startWorker([
        'baseUrl' => 'http://127.0.0.1',
        'method' => 'DELETE',
        'path' => '/api/v1/me/forum-threads/'.$thread['id'],
        'body' => null,
        'cookieFile' => $author['cookieFile'],
        'headers' => ['X-CSRF-Token' => $author['csrfToken']],
    ]);

    usleep(300000);
    releaseLock($connection);

    $firstResult = finishWorker($firstDelete);
    $secondResult = finishWorker($secondDelete);
    $statuses = [$firstResult['status'], $secondResult['status']];
    sort($statuses);
    assertTrue($statuses === [204, 404], 'Repeated author delete must yield one 204 and one 404.');

    $loser = 404 === $firstResult['status'] ? $firstResult : $secondResult;
    assertAllowedLoser($loser, ['MISSING_PUBLIC_RESOURCE']);
    assertTrue('DELETED_BY_AUTHOR' === pgOne($connection, 'SELECT status FROM forum_threads WHERE id = $1', [(string) $thread['id']]), 'Scenario C final thread status mismatch.');

    return ['first' => $firstResult, 'second' => $secondResult];
}

function scenarioD(PgSql\Connection $connection, string $categoryId): array
{
    $author = loginDev('c5d-race-author-d@example.com', 'Race Author D');
    $reporter = loginDev('c5d-race-reporter-d@example.com', 'Race Reporter D');
    $moderator = loginDev('c5d-race-moderator-d@example.com', 'Race Moderator D', ['ROLE_MODERATOR']);
    $thread = createThread($author, $categoryId, 'C5D repeated moderation D', 'Repeated moderation body D');
    $reportId = reportThread($reporter, (string) $thread['id']);
    claimCase($moderator, $reportId);
    $idempotencyKey = uuidV4();

    beginLock($connection, 'SELECT id FROM content_reports WHERE id = $1 FOR UPDATE', [$reportId]);
    $firstModeration = startWorker([
        'baseUrl' => 'http://127.0.0.1',
        'method' => 'POST',
        'path' => '/api/v1/moderation/action',
        'body' => [
            'reportId' => $reportId,
            'action' => 'RESOLVE_REPORT',
            'reason' => 'First resolution keeps content public.',
        ],
        'cookieFile' => $moderator['cookieFile'],
        'headers' => [
            'Content-Type' => 'application/json',
            'X-CSRF-Token' => $moderator['csrfToken'],
            'Idempotency-Key' => $idempotencyKey,
        ],
    ]);

    usleep(200000);
    $secondModeration = startWorker([
        'baseUrl' => 'http://127.0.0.1',
        'method' => 'POST',
        'path' => '/api/v1/moderation/action',
        'body' => [
            'reportId' => $reportId,
            'action' => 'RESOLVE_REPORT',
            'reason' => 'First resolution keeps content public.',
        ],
        'cookieFile' => $moderator['cookieFile'],
        'headers' => [
            'Content-Type' => 'application/json',
            'X-CSRF-Token' => $moderator['csrfToken'],
            'Idempotency-Key' => $idempotencyKey,
        ],
    ]);

    usleep(300000);
    releaseLock($connection);

    $firstResult = finishWorker($firstModeration);
    $secondResult = finishWorker($secondModeration);
    $statuses = [$firstResult['status'], $secondResult['status']];
    sort($statuses);
    $validReplay = $statuses === [200, 200];
    $validConflict = $statuses === [200, 409];
    assertTrue($validReplay || $validConflict, 'Repeated moderator action must yield idempotent 200/200 replay or controlled 200/409 conflict, got '.json_encode([$firstResult, $secondResult], \JSON_THROW_ON_ERROR));

    if ($validConflict) {
        $loser = 409 === $firstResult['status'] ? $firstResult : $secondResult;
        assertAllowedLoser($loser, ['IDEMPOTENCY_KEY_REUSE']);
    }
    assertTrue('RESOLVED' === pgOne($connection, 'SELECT status FROM content_reports WHERE id = $1', [$reportId]), 'Scenario D report was not resolved.');
    assertTrue('PUBLISHED' === pgOne($connection, 'SELECT status FROM forum_threads WHERE id = $1', [(string) $thread['id']]), 'Scenario D thread content should remain public.');
    assertTrue('1' === pgOne($connection, 'SELECT COUNT(*) FROM moderation_actions WHERE report_id = $1', [$reportId]), 'Scenario D moderation audit duplicated.');
    assertTrue('1' === pgOne($connection, 'SELECT COUNT(*) FROM moderation_idempotency_keys WHERE idempotency_key = $1', [$idempotencyKey]), 'Scenario D idempotency key duplicated.');

    return ['first' => $firstResult, 'second' => $secondResult, 'reportId' => $reportId];
}

function scenarioE(PgSql\Connection $connection, string $categoryId): array
{
    $suffix = (string) random_int(10000, 99999);
    $author = loginDev('c5d-post-author-'.$suffix.'@example.com', 'Post Author '.$suffix);
    $firstReplyAuthor = loginDev('c5d-post-replier-a-'.$suffix.'@example.com', 'Post Replier A '.$suffix);
    $secondReplyAuthor = loginDev('c5d-post-replier-b-'.$suffix.'@example.com', 'Post Replier B '.$suffix);
    $thread = createThread($author, $categoryId, 'C5D simultaneous posts '.$suffix, 'Concurrent normal post thread '.$suffix);

    $firstWorker = startWorker([
        'baseUrl' => 'http://127.0.0.1',
        'method' => 'POST',
        'path' => '/api/v1/forum/threads/'.$thread['id'].'/posts',
        'body' => ['body' => 'Concurrent post A '.$suffix, 'replyToPostId' => $thread['firstPost']['id']],
        'cookieFile' => $firstReplyAuthor['cookieFile'],
        'headers' => authHeaders($firstReplyAuthor),
    ]);
    $secondWorker = startWorker([
        'baseUrl' => 'http://127.0.0.1',
        'method' => 'POST',
        'path' => '/api/v1/forum/threads/'.$thread['id'].'/posts',
        'body' => ['body' => 'Concurrent post B '.$suffix, 'replyToPostId' => $thread['firstPost']['id']],
        'cookieFile' => $secondReplyAuthor['cookieFile'],
        'headers' => authHeaders($secondReplyAuthor),
    ]);

    $firstResult = finishWorker($firstWorker);
    $secondResult = finishWorker($secondWorker);
    $statuses = [$firstResult['status'], $secondResult['status']];
    sort($statuses);
    assertTrue(in_array($statuses, [[201, 201], [201, 409]], true), 'Concurrent normal posts must produce 201/201 or controlled 201/409, got '.json_encode($statuses, \JSON_THROW_ON_ERROR));
    assertTrue(!in_array(500, [$firstResult['status'], $secondResult['status']], true), 'Concurrent normal post leaked HTTP 500.');

    $postCount = (int) pgOne($connection, 'SELECT COUNT(*) FROM forum_posts WHERE thread_id = $1 AND is_initial = false AND status = \'PUBLISHED\'', [(string) $thread['id']]);
    assertTrue($postCount === ($statuses === [201, 201] ? 2 : 1), 'Unexpected persisted post count after concurrent post race.');
    $lastActivity = pgOne($connection, 'SELECT last_activity_at FROM forum_threads WHERE id = $1', [(string) $thread['id']]);
    assertTrue('' !== $lastActivity, 'Thread activity timestamp was lost after concurrent post race.');

    return ['first' => $firstResult, 'second' => $secondResult, 'publishedReplies' => $postCount, 'lastActivityAt' => $lastActivity];
}

function postRaceSetup(string $suffix, string $categoryId, string $action): array
{
    $author = loginDev('c5d-post-race-author-'.$action.'-'.$suffix.'@example.com', 'Post Race Author '.$action);
    $replyAuthor = loginDev('c5d-post-race-replier-'.$action.'-'.$suffix.'@example.com', 'Post Race Replier '.$action);
    $reporter = loginDev('c5d-post-race-reporter-'.$action.'-'.$suffix.'@example.com', 'Post Race Reporter '.$action);
    $moderator = loginDev('c5d-post-race-moderator-'.$action.'-'.$suffix.'@example.com', 'Post Race Moderator '.$action, ['ROLE_MODERATOR']);
    $thread = createThread($author, $categoryId, 'C5D POST race '.$action.' '.$suffix, 'Sanitized POST race thread '.$action.' '.$suffix);
    $reportId = reportThread($reporter, (string) $thread['id']);
    claimCase($moderator, $reportId);

    return compact('author', 'replyAuthor', 'moderator', 'thread', 'reportId');
}

function postRaceRequest(array $auth, string $threadId, string $body): array
{
    return ['baseUrl' => 'http://127.0.0.1', 'method' => 'POST', 'path' => '/api/v1/forum/threads/'.$threadId.'/posts', 'body' => ['body' => $body], 'cookieFile' => $auth['cookieFile'], 'headers' => authHeaders($auth)];
}

function moderationRaceRequest(array $moderator, string $reportId, string $action): array
{
    return ['baseUrl' => 'http://127.0.0.1', 'method' => 'POST', 'path' => '/api/v1/moderation/action', 'body' => ['reportId' => $reportId, 'action' => $action, 'reason' => 'C5D deterministic POST race '.$action], 'cookieFile' => $moderator['cookieFile'], 'headers' => ['Content-Type' => 'application/json', 'X-CSRF-Token' => $moderator['csrfToken'], 'Idempotency-Key' => uuidV4()]];
}

function assertPostRaceResponse(array $result, array $allowedStatuses): void
{
    assertTrue(in_array($result['status'], $allowedStatuses, true), 'Unexpected POST race response: '.json_encode($result, \JSON_THROW_ON_ERROR));
    assertTrue(500 !== $result['status'], 'POST race leaked HTTP 500.');
    $body = (string) ($result['body'] ?? '');
    assertTrue(!str_contains($body, 'RuntimeException') && !str_contains($body, 'LogicException') && !str_contains($body, 'DBAL'), 'POST race leaked an internal exception.');
}

function postRaceState(PgSql\Connection $connection, array $thread, string $categorySlug, string $replyBody, bool $replyMustBeAbsent, int $expectedAuditCount): array
{
    $threadId = (string) $thread['id'];
    $threadState = pgRow($connection, 'SELECT status, locked_at, version FROM forum_threads WHERE id = $1', [$threadId]);
    $postCount = (int) pgOne($connection, 'SELECT COUNT(*) FROM forum_posts WHERE thread_id = $1 AND is_initial = false', [$threadId]);
    $publishedReplyCount = (int) pgOne($connection, "SELECT COUNT(*) FROM forum_posts WHERE thread_id = $1 AND is_initial = false AND status = 'PUBLISHED'", [$threadId]);
    $auditCount = (int) pgOne($connection, 'SELECT COUNT(*) FROM moderation_actions WHERE target_id = $1', [$threadId]);
    $public = [];
    foreach (['detail' => '/api/v1/forum/threads/'.$threadId, 'posts' => '/api/v1/forum/threads/'.$threadId.'/posts', 'listing' => '/api/v1/forum/categories/'.$categorySlug.'/threads?limit=50', 'feed' => '/api/v1/community/feed?limit=50'] as $name => $path) {
        $response = apiRequest('GET', $path);
        assertTrue(500 !== $response['status'], 'Public '.$name.' read leaked HTTP 500.');
        $public[$name] = ['status' => $response['status'], 'containsRaceBody' => str_contains($response['body'], $replyBody)];
    }
    assertTrue($expectedAuditCount === $auditCount, 'Moderation audit record count was not deterministic.');
    if ($replyMustBeAbsent) {
        foreach ($public as $read) {
            assertTrue(false === $read['containsRaceBody'], 'Race content leaked through public output.');
        }
    }

    return ['thread' => $threadState, 'postCount' => $postCount, 'publishedReplyCount' => $publishedReplyCount, 'moderationAuditCount' => $auditCount, 'public' => $public];
}

function scenarioPostVsLock(PgSql\Connection $connection, string $categoryId, string $categorySlug): array
{
    $setup = postRaceSetup((string) random_int(10000, 99999), $categoryId, 'LOCK');
    $threadId = (string) $setup['thread']['id'];
    beginLock($connection, 'SELECT id FROM forum_threads WHERE id = $1 FOR UPDATE', [$threadId]);
    $post = startWorker(postRaceRequest($setup['replyAuthor'], $threadId, 'C5D POST race LOCK reply'));
    $moderation = startWorker(moderationRaceRequest($setup['moderator'], (string) $setup['reportId'], 'LOCK'));
    releaseLock($connection);
    $postResult = finishWorker($post);
    $moderationResult = finishWorker($moderation);
    assertPostRaceResponse($postResult, [201, 409]);
    assertPostRaceResponse($moderationResult, [200]);
    $state = postRaceState($connection, $setup['thread'], $categorySlug, 'C5D POST race LOCK reply', false, 1);
    assertTrue('PUBLISHED' === $state['thread']['status'] && null !== $state['thread']['locked_at'], 'Lock race final thread state is invalid.');

    return ['name' => 'POST_CREATE_VS_THREAD_LOCK', 'post' => $postResult, 'moderation' => $moderationResult, 'final' => $state];
}

function scenarioPostVsRemove(PgSql\Connection $connection, string $categoryId, string $categorySlug): array
{
    $setup = postRaceSetup((string) random_int(10000, 99999), $categoryId, 'REMOVE');
    $threadId = (string) $setup['thread']['id'];
    beginLock($connection, 'SELECT id FROM forum_threads WHERE id = $1 FOR UPDATE', [$threadId]);
    $post = startWorker(postRaceRequest($setup['replyAuthor'], $threadId, 'C5D POST race REMOVE reply'));
    $moderation = startWorker(moderationRaceRequest($setup['moderator'], (string) $setup['reportId'], 'REMOVE'));
    releaseLock($connection);
    $postResult = finishWorker($post);
    $moderationResult = finishWorker($moderation);
    assertPostRaceResponse($postResult, [201, 409]);
    assertPostRaceResponse($moderationResult, [200]);
    $state = postRaceState($connection, $setup['thread'], $categorySlug, 'C5D POST race REMOVE reply', true, 1);
    assertTrue('REMOVED_BY_MODERATOR' === $state['thread']['status'], 'Remove race final thread state is invalid.');

    return ['name' => 'POST_CREATE_VS_MODERATOR_REMOVE', 'post' => $postResult, 'moderation' => $moderationResult, 'final' => $state];
}

function scenarioPostVsAuthorDelete(PgSql\Connection $connection, string $categoryId, string $categorySlug): array
{
    $setup = postRaceSetup((string) random_int(10000, 99999), $categoryId, 'DELETE');
    $threadId = (string) $setup['thread']['id'];
    beginLock($connection, 'SELECT id FROM forum_threads WHERE id = $1 FOR UPDATE', [$threadId]);
    $post = startWorker(postRaceRequest($setup['replyAuthor'], $threadId, 'C5D POST race DELETE reply'));
    $delete = startWorker(['baseUrl' => 'http://127.0.0.1', 'method' => 'DELETE', 'path' => '/api/v1/me/forum-threads/'.$threadId, 'body' => null, 'cookieFile' => $setup['author']['cookieFile'], 'headers' => ['X-CSRF-Token' => $setup['author']['csrfToken']]]);
    releaseLock($connection);
    $postResult = finishWorker($post);
    $deleteResult = finishWorker($delete);
    assertPostRaceResponse($postResult, [201, 404, 409]);
    assertPostRaceResponse($deleteResult, [204]);
    $state = postRaceState($connection, $setup['thread'], $categorySlug, 'C5D POST race DELETE reply', false, 0);
    assertTrue('DELETED_BY_AUTHOR' === $state['thread']['status'], 'Author delete race final thread state is invalid.');

    return ['name' => 'POST_CREATE_VS_AUTHOR_DELETE', 'post' => $postResult, 'delete' => $deleteResult, 'final' => $state];
}

function sanitizeRaceResult(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (array_key_exists('status', $value) && array_key_exists('body', $value)) {
        return ['status' => $value['status'], 'durationMs' => $value['durationMs'] ?? null, 'errorCode' => $value['json']['code'] ?? null, 'transportError' => $value['error'] ?? null];
    }
    $result = [];
    foreach ($value as $key => $item) {
        $result[$key] = sanitizeRaceResult($item);
    }

    return $result;
}

$connection = pgConnection();
$categoryId = pgOne($connection, 'SELECT id FROM forum_categories WHERE active = true ORDER BY display_order ASC, id ASC LIMIT 1');
$categorySlug = pgOne($connection, 'SELECT slug FROM forum_categories WHERE id = $1', [$categoryId]);

$results = getenv('C5D_ONLY_POST_RACES') ? [
    'postVsLock' => scenarioPostVsLock($connection, $categoryId, $categorySlug),
    'postVsRemove' => scenarioPostVsRemove($connection, $categoryId, $categorySlug),
    'postVsAuthorDelete' => scenarioPostVsAuthorDelete($connection, $categoryId, $categorySlug),
] : [
    'scenarioA' => scenarioA($connection, $categoryId, $categorySlug),
    'scenarioB' => scenarioB($connection, $categoryId, $categorySlug),
    'scenarioC' => scenarioC($connection, $categoryId),
    'scenarioD' => scenarioD($connection, $categoryId),
    'scenarioE' => scenarioE($connection, $categoryId),
];

echo json_encode([
    'status' => 'PASS',
    'results' => sanitizeRaceResult($results),
], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR).\PHP_EOL;
