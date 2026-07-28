<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Domain;

final class PlaceNormalizer
{
    public function normalize(ProviderPlace $place): NormalizedPlace
    {
        $name = preg_replace('/\s+/u', ' ', trim(normalizer_normalize($place->name, \Normalizer::FORM_KC) ?: $place->name)) ?? trim($place->name);
        $websiteHost = null;
        if (null !== $place->website && '' !== trim($place->website)) {
            $url = str_contains($place->website, '://') ? $place->website : 'https://'.$place->website;
            $websiteHost = parse_url($url, \PHP_URL_HOST);
            $websiteHost = \is_string($websiteHost) ? preg_replace('/^www\./i', '', mb_strtolower(rtrim($websiteHost, '.'))) : null;
        }
        $phone = null === $place->phone ? null : preg_replace('/(?!^\+)\D+/', '', trim($place->phone));
        $categories = array_values(array_unique(array_map([$this, 'comparison'], array_filter([$place->basicCategory, ...$place->categories]))));

        return new NormalizedPlace($name, $this->comparison($name), $websiteHost ?: null, $phone ?: null, $categories);
    }

    public function comparison(string $value): string
    {
        $value = mb_strtolower(normalizer_normalize($value, \Normalizer::FORM_KD) ?: $value);
        $value = preg_replace('/\p{Mn}+/u', '', $value) ?? $value;

        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value);
    }
}
