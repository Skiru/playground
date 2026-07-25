<?php

declare(strict_types=1);

namespace App\Community\Application\Pagination;

use App\Shared\Application\Exception\ApiException;
use Symfony\Component\Uid\Uuid;

final class CursorCodec
{
    public const TYPE_STRING = 'string';
    public const TYPE_INTEGER = 'integer';
    public const TYPE_UUID = 'uuid';
    public const TYPE_TIMESTAMP = 'timestamp';
    public const TYPE_ENUM = 'enum';

    /**
     * @param array<string, array{type: string, required: bool, nullable: bool, expected?: mixed, values?: list<string>}> $schema
     *
     * @return array<string, mixed>|null
     */
    public static function decode(?string $cursor, array $schema): ?array
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
        foreach (array_keys($data) as $key) {
            if (!\array_key_exists($key, $schema)) {
                self::invalid();
            }
        }

        foreach ($schema as $key => $descriptor) {
            if (!\array_key_exists($key, $data)) {
                if (true === $descriptor['required']) {
                    self::invalid();
                }

                continue;
            }

            $value = $data[$key];
            if (null === $value) {
                if (false === $descriptor['nullable']) {
                    self::invalid();
                }

                if (\array_key_exists('expected', $descriptor) && null !== $descriptor['expected']) {
                    self::invalid('Cursor does not match the active filters.');
                }

                continue;
            }

            switch ($descriptor['type']) {
                case self::TYPE_STRING:
                    if (!\is_string($value)) {
                        self::invalid();
                    }

                    break;

                case self::TYPE_INTEGER:
                    if (!\is_int($value)) {
                        self::invalid();
                    }

                    break;

                case self::TYPE_UUID:
                    if (!\is_string($value) || !Uuid::isValid($value)) {
                        self::invalid();
                    }

                    break;

                case self::TYPE_TIMESTAMP:
                    if (!\is_string($value)) {
                        self::invalid();
                    }
                    $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
                    $errors = \DateTimeImmutable::getLastErrors();
                    if (false === $date || (false !== $errors && (0 !== $errors['warning_count'] || 0 !== $errors['error_count'])) || $date->format('Y-m-d H:i:s') !== $value) {
                        self::invalid();
                    }

                    break;

                case self::TYPE_ENUM:
                    if (!\is_string($value) || !\in_array($value, $descriptor['values'] ?? [], true)) {
                        self::invalid();
                    }

                    break;

                default:
                    self::invalid();
            }

            if (\array_key_exists('expected', $descriptor) && $value !== $descriptor['expected']) {
                self::invalid('Cursor does not match the active filters.');
            }
        }

        return $data;
    }

    /** @return array{type: string, required: bool, nullable: bool, expected?: mixed} */
    public static function stringField(bool $required = true, bool $nullable = false, mixed $expected = null, bool $hasExpected = false): array
    {
        return self::field(self::TYPE_STRING, $required, $nullable, $expected, $hasExpected);
    }

    /** @return array{type: string, required: bool, nullable: bool, expected?: mixed} */
    public static function integerField(bool $required = true, bool $nullable = false, mixed $expected = null, bool $hasExpected = false): array
    {
        return self::field(self::TYPE_INTEGER, $required, $nullable, $expected, $hasExpected);
    }

    /** @return array{type: string, required: bool, nullable: bool, expected?: mixed} */
    public static function uuidField(bool $required = true, bool $nullable = false, mixed $expected = null, bool $hasExpected = false): array
    {
        return self::field(self::TYPE_UUID, $required, $nullable, $expected, $hasExpected);
    }

    /** @return array{type: string, required: bool, nullable: bool, expected?: mixed} */
    public static function timestampField(bool $required = true, bool $nullable = false, mixed $expected = null, bool $hasExpected = false): array
    {
        return self::field(self::TYPE_TIMESTAMP, $required, $nullable, $expected, $hasExpected);
    }

    /**
     * @param list<string> $values
     *
     * @return array{type: string, required: bool, nullable: bool, values: list<string>, expected?: mixed}
     */
    public static function enumField(array $values, bool $required = true, bool $nullable = false, mixed $expected = null, bool $hasExpected = false): array
    {
        $descriptor = self::field(self::TYPE_ENUM, $required, $nullable, $expected, $hasExpected);
        $descriptor['values'] = $values;

        return $descriptor;
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

    /** @return array{type: string, required: bool, nullable: bool, expected?: mixed} */
    private static function field(string $type, bool $required, bool $nullable, mixed $expected, bool $hasExpected): array
    {
        $descriptor = [
            'type' => $type,
            'required' => $required,
            'nullable' => $nullable,
        ];

        if ($hasExpected) {
            $descriptor['expected'] = $expected;
        }

        return $descriptor;
    }
}
