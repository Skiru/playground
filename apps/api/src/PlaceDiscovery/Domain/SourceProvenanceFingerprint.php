<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Domain;

final class SourceProvenanceFingerprint
{
    private const IDENTITY_FIELDS = ['property', 'dataset', 'record_id', 'provider', 'resource', 'version'];

    /** @param array<string, mixed> $source */
    public static function fromArray(array $source): string
    {
        $identity = [];
        foreach (self::IDENTITY_FIELDS as $field) {
            $value = $source[$field] ?? null;
            if (null !== $value && (!\is_string($value) || \strlen($value) > 255)) {
                throw new \DomainException('Source provenance identity is invalid.');
            }
            $identity[$field] = $value;
        }

        if (!\is_string($identity['property']) || !\is_string($identity['dataset']) || '' === trim($identity['dataset'])) {
            throw new \DomainException('Source provenance identity is incomplete.');
        }

        return hash('sha256', json_encode($identity, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }
}
