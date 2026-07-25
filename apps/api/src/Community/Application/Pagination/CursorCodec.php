<?php

declare(strict_types=1);

namespace App\Community\Application\Pagination;

use App\Shared\Application\Exception\ApiException;
use Symfony\Component\Uid\Uuid;

final class CursorCodec
{
    /**
     * @param list<string>         $requiredKeys
     * @param array<string, mixed> $expectedValues
     *
     * @return array<string, mixed>|null
     */
    public static function decode(?string $cursor, array $requiredKeys, array $expectedValues = []): ?array
    {
        if (null === $cursor || '' === $cursor) {
            return null;
        }

        $json = base64_decode($cursor, true);
        if (false === $json) {
            self::invalid();
        }
        try {
            $data = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::invalid();
        }
        if (!\is_array($data) || array_is_list($data)) {
            self::invalid();
        }
        foreach ($requiredKeys as $key) {
            if (!\array_key_exists($key, $data)) {
                self::invalid();
            }
        }
        $allowedKeys = array_values(array_unique([...$requiredKeys, ...array_keys($expectedValues)]));
        $actualKeys = array_keys($data);
        sort($allowedKeys);
        sort($actualKeys);
        if ($allowedKeys !== $actualKeys) {
            self::invalid();
        }
        foreach ($expectedValues as $key => $expectedValue) {
            if (!\array_key_exists($key, $data) || $data[$key] !== $expectedValue) {
                self::invalid('Cursor does not match the active filters.');
            }
        }
        foreach ($data as $key => $value) {
            if ('id' === $key || str_ends_with($key, 'Id')) {
                if (null !== $value && (!\is_string($value) || !Uuid::isValid($value))) {
                    self::invalid();
                }
            }
            if (str_ends_with($key, 'At') && null !== $value) {
                if (!\is_string($value)) {
                    self::invalid();
                }
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
                $errors = \DateTimeImmutable::getLastErrors();
                if (false === $date || (false !== $errors && (0 !== $errors['warning_count'] || 0 !== $errors['error_count'])) || $date->format('Y-m-d H:i:s') !== $value) {
                    self::invalid();
                }
            }
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    public static function encode(array $data): string
    {
        return base64_encode(json_encode($data, \JSON_THROW_ON_ERROR));
    }

    private static function invalid(string $message = 'Invalid pagination cursor.'): never
    {
        throw new ApiException(400, $message, 'INVALID_CURSOR');
    }
}
