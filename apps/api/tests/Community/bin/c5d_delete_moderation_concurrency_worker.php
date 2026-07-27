<?php

declare(strict_types=1);

if ($argc < 2) {
    fwrite(\STDERR, "Missing payload file.\n");
    exit(2);
}

$payloadPath = $argv[1];
$payload = json_decode((string) file_get_contents($payloadPath), true, 512, \JSON_THROW_ON_ERROR);

$ch = curl_init();
$startedAt = microtime(true);
$headers = [];
foreach ($payload['headers'] ?? [] as $name => $value) {
    $headers[] = $name.': '.$value;
}

$body = null;
if (array_key_exists('body', $payload) && null !== $payload['body']) {
    $body = json_encode($payload['body'], \JSON_THROW_ON_ERROR);
}

curl_setopt_array($ch, [
    \CURLOPT_URL => rtrim((string) $payload['baseUrl'], '/').(string) $payload['path'],
    \CURLOPT_CUSTOMREQUEST => (string) $payload['method'],
    \CURLOPT_RETURNTRANSFER => true,
    \CURLOPT_HEADER => true,
    \CURLOPT_TIMEOUT => 30,
    \CURLOPT_HTTPHEADER => $headers,
    \CURLOPT_COOKIEFILE => (string) $payload['cookieFile'],
    \CURLOPT_COOKIEJAR => (string) $payload['cookieFile'],
]);

if (null !== $body) {
    curl_setopt($ch, \CURLOPT_POSTFIELDS, $body);
}

$response = curl_exec($ch);
$error = curl_error($ch);
$status = (int) curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
$headerSize = (int) curl_getinfo($ch, \CURLINFO_HEADER_SIZE);

if (false === $response) {
    echo json_encode([
        'status' => 0,
        'error' => $error,
        'body' => '',
        'json' => null,
    ], \JSON_THROW_ON_ERROR);
    exit(0);
}

$bodyText = substr($response, $headerSize);
$json = json_decode($bodyText, true);

echo json_encode([
    'status' => $status,
    'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
    'error' => '' !== $error ? $error : null,
    'body' => $bodyText,
    'json' => is_array($json) ? $json : null,
], \JSON_THROW_ON_ERROR);
