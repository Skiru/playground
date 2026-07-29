<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Domain;

final readonly class SourceLicenseResolution implements \JsonSerializable
{
    private const IDENTITY_FIELDS = ['property', 'dataset', 'record_id', 'provider', 'resource', 'version'];

    /** @param array<string, string|null> $sourceIdentity */
    private function __construct(public string $fingerprint, public string $license, public string $reviewer, public string $reviewedAt, public string $sourceRelease, public array $sourceIdentity)
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $fingerprint) || '' === trim($license) || \strlen($license) > 255 || \strlen($reviewer) > 160 || \strlen($sourceRelease) > 40) {
            throw new \DomainException('Reviewed source license resolution is invalid.');
        }
        foreach ($sourceIdentity as $value) {
            if (null !== $value && \strlen($value) > 255) {
                throw new \DomainException('Reviewed source identity exceeds its bound.');
            }
        }
        new \DateTimeImmutable($reviewedAt);
    }

    /** @param array<string, mixed> $source */
    public static function review(array $source, string $license, string $reviewer, string $reviewedAt, string $sourceRelease): self
    {
        $identity = [];
        foreach (self::IDENTITY_FIELDS as $field) {
            $value = $source[$field] ?? null;
            $identity[$field] = \is_string($value) ? $value : null;
        }

        return new self(SourceProvenanceFingerprint::fromArray($source), $license, $reviewer, $reviewedAt, $sourceRelease, $identity);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(string $fingerprint, array $data): self
    {
        $identity = $data['source_identity'] ?? null;
        if (!\is_array($identity)) {
            throw new \DomainException('Reviewed source identity is missing.');
        }
        foreach (self::IDENTITY_FIELDS as $field) {
            if (!\array_key_exists($field, $identity) || (null !== $identity[$field] && !\is_string($identity[$field]))) {
                throw new \DomainException('Reviewed source identity is invalid.');
            }
        }
        foreach (['license', 'reviewer', 'reviewed_at', 'source_release'] as $field) {
            if (!isset($data[$field]) || !\is_string($data[$field])) {
                throw new \DomainException('Reviewed source license metadata is incomplete.');
            }
        }
        if (!hash_equals($fingerprint, SourceProvenanceFingerprint::fromArray($identity))) {
            throw new \DomainException('Reviewed source identity does not match its fingerprint.');
        }

        return new self($fingerprint, $data['license'], $data['reviewer'], $data['reviewed_at'], $data['source_release'], $identity);
    }

    /** @return array{license: string, reviewer: string, reviewed_at: string, source_release: string, source_identity: array<string, string|null>} */
    public function jsonSerialize(): array
    {
        return ['license' => $this->license, 'reviewer' => $this->reviewer, 'reviewed_at' => $this->reviewedAt, 'source_release' => $this->sourceRelease, 'source_identity' => $this->sourceIdentity];
    }
}
