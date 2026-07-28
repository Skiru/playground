<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Domain;

use App\PlaceDiscovery\Domain\Aggregate\CandidateStatus;

final class FamilyDiscoveryProfile
{
    /** Values verified against Overture schema's current overture_categories.csv. */
    private const CATEGORY_MAP = ['playground' => 'bawialnie', 'indoor_playcenter' => 'bawialnie', 'amusement_park' => 'bawialnie', 'water_park' => 'sport', 'zoo' => 'natura', 'aquarium' => 'natura', 'science_museum' => 'muzea', 'childrens_museum' => 'muzea', 'trampoline_park' => 'sport', 'swimming_pool' => 'sport', 'park' => 'parki'];
    private const BROAD = ['restaurant', 'cafe'];
    private const KEYWORDS = ['dziecko', 'dzieci', 'rodzin', 'maluch', 'bawial', 'kids', 'junior', 'family', 'play'];

    public function classify(ProviderPlace $place, NormalizedPlace $normalized): DiscoveryClassification
    {
        $score = 20;
        $reasons = ['valid_name_and_coordinates'];
        if (null !== $place->confidence) {
            $score += (int) round($place->confidence * 25);
            $reasons[] = 'overture_confidence:'.number_format($place->confidence, 2, '.', '');
        }
        $category = null;
        foreach ($normalized->categories as $sourceCategory) {
            if (isset(self::CATEGORY_MAP[$sourceCategory])) {
                $category = self::CATEGORY_MAP[$sourceCategory];
                $score += 35;
                $reasons[] = 'family_category:'.$sourceCategory;
                break;
            }
        }
        $keyword = $this->keyword($normalized->normalizedName);
        if (null !== $keyword) {
            $score += 15;
            $reasons[] = 'family_keyword:'.$keyword;
        }
        if (null !== $normalized->websiteHost) {
            $score += 3;
            $reasons[] = 'website_present';
        }
        if (null !== $normalized->phone) {
            $score += 2;
            $reasons[] = 'phone_present';
        }
        $basic = null === $place->basicCategory ? null : str_replace(['-', ' '], '_', mb_strtolower(trim($place->basicCategory)));
        $keywordEligible = null !== $keyword && null !== $basic && \in_array($basic, self::BROAD, true);
        if ('closed_permanently' === $place->operatingStatus) {
            return new DiscoveryClassification(CandidateStatus::STALE, min(100, $score), $category, [...$reasons, 'permanently_closed'], null !== $category || $keywordEligible);
        }
        if (null !== $basic && \in_array($basic, self::BROAD, true) && null === $keyword) {
            return new DiscoveryClassification(CandidateStatus::NEEDS_MAPPING, min(100, $score), null, [...$reasons, 'broad_category_without_family_signal'], false);
        }

        if (null !== $keyword && !$keywordEligible && null === $category) {
            $reasons[] = 'keyword_outside_supported_category';
        }

        return new DiscoveryClassification(null === $category ? CandidateStatus::NEEDS_MAPPING : CandidateStatus::PENDING, min(100, $score), $category, $reasons, null !== $category || $keywordEligible);
    }

    private function keyword(string $name): ?string
    {
        foreach (self::KEYWORDS as $keyword) {
            if (preg_match('/(?:^|\s)'.preg_quote($keyword, '/').'\p{L}*(?:$|\s)/u', $name)) {
                return $keyword;
            }
        }

        return null;
    }
}
