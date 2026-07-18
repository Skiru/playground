<?php

declare(strict_types=1);

namespace App\Places\Domain;

use App\Places\Domain\ValueObject\Coordinates;
use App\Places\Domain\ValueObject\PlaceName;
use App\Places\Domain\ValueObject\PlaceSlug;
use Symfony\Component\Uid\Uuid;

final class Place
{
    private Uuid $id;
    private string $name;
    private string $slug;
    private float $latitude;
    private float $longitude;
    private PlaceStatus $status = PlaceStatus::DRAFT;
    private VerificationStatus $verificationStatus = VerificationStatus::UNVERIFIED;
    private int $version = 1;
    /** @var list<Category> */
    private array $categories = [];
    /** @var list<Amenity> */
    private array $amenities = [];
    /** @var list<PlaceAgeZone> */
    private array $ageZones = [];
    /** @var list<WeeklyOpeningInterval> */
    private array $weeklyOpeningHours = [];
    /** @var list<SpecialOpeningDay> */
    private array $specialOpeningDays = [];
    /** @var list<ExternalPlaceReference> */
    private array $externalReferences = [];
    /** @var list<PlacePhoto> */
    private array $photos = [];
    private ?string $addressLine2 = null;
    private ?string $priceDescription = null;
    private ?string $websiteUrl = null;
    private ?string $phone = null;
    private ?\DateTimeImmutable $verifiedAt = null;
    private ?\DateTimeImmutable $publishedAt = null;
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        PlaceName $name,
        PlaceSlug $slug,
        private string $shortDescription,
        private string $description,
        private string $addressLine1,
        private string $postalCode,
        private City $city,
        private string $countryCode,
        Coordinates $coordinates,
        private string $timezone,
        private Category $primaryCategory,
        private bool $indoor,
        private bool $outdoor,
        private bool $freeEntry,
        private readonly \DateTimeImmutable $createdAt,
        ?string $addressLine2 = null,
        ?string $priceDescription = null,
        ?string $websiteUrl = null,
        ?string $phone = null,
        private OpeningHoursMode $openingHoursMode = OpeningHoursMode::UNKNOWN,
    ) {
        $this->id = Uuid::v7();
        $this->name = $name->value;
        $this->slug = $slug->value;
        $this->latitude = $coordinates->latitude;
        $this->longitude = $coordinates->longitude;
        $this->categories[] = $primaryCategory;
        $this->addressLine2 = $addressLine2;
        $this->priceDescription = $priceDescription;
        $this->websiteUrl = $websiteUrl;
        $this->phone = $phone;
        $this->updatedAt = $createdAt;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function markPersisted(int $version): void
    {
        if ($version <= $this->version) {
            throw new \LogicException('Persisted version must increase.');
        }
        $this->version = $version;
    }

    public static function reconstitute(
        Uuid $id,
        int $version,
        PlaceName $name,
        PlaceSlug $slug,
        string $shortDescription,
        string $description,
        string $addressLine1,
        string $postalCode,
        City $city,
        string $countryCode,
        Coordinates $coordinates,
        string $timezone,
        Category $primaryCategory,
        bool $indoor,
        bool $outdoor,
        bool $freeEntry,
        \DateTimeImmutable $createdAt,
        PlaceStatus $status,
        VerificationStatus $verificationStatus,
        \DateTimeImmutable $updatedAt,
        ?\DateTimeImmutable $publishedAt,
        ?\DateTimeImmutable $verifiedAt,
        ?string $addressLine2 = null,
        ?string $priceDescription = null,
        ?string $websiteUrl = null,
        ?string $phone = null,
        OpeningHoursMode $openingHoursMode = OpeningHoursMode::UNKNOWN,
    ): self {
        $place = new self($name, $slug, $shortDescription, $description, $addressLine1, $postalCode, $city, $countryCode, $coordinates, $timezone, $primaryCategory, $indoor, $outdoor, $freeEntry, $createdAt, $addressLine2, $priceDescription, $websiteUrl, $phone, $openingHoursMode);
        $place->id = $id;
        $place->version = $version;
        $place->status = $status;
        $place->verificationStatus = $verificationStatus;
        $place->updatedAt = $updatedAt;
        $place->publishedAt = $publishedAt;
        $place->verifiedAt = $verifiedAt;

        return $place;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function status(): PlaceStatus
    {
        return $this->status;
    }

    public function verificationStatus(): VerificationStatus
    {
        return $this->verificationStatus;
    }

    public function publishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function shortDescription(): string
    {
        return $this->shortDescription;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function addressLine1(): string
    {
        return $this->addressLine1;
    }

    public function addressLine2(): ?string
    {
        return $this->addressLine2;
    }

    public function postalCode(): string
    {
        return $this->postalCode;
    }

    public function city(): City
    {
        return $this->city;
    }

    public function countryCode(): string
    {
        return $this->countryCode;
    }

    public function coordinates(): Coordinates
    {
        return new Coordinates($this->latitude, $this->longitude);
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    public function primaryCategory(): Category
    {
        return $this->primaryCategory;
    }

    /** @return list<Category> */
    public function categories(): array
    {
        return $this->categories;
    }

    /** @return list<Amenity> */
    public function amenities(): array
    {
        return $this->amenities;
    }

    public function indoor(): bool
    {
        return $this->indoor;
    }

    public function outdoor(): bool
    {
        return $this->outdoor;
    }

    public function freeEntry(): bool
    {
        return $this->freeEntry;
    }

    public function priceDescription(): ?string
    {
        return $this->priceDescription;
    }

    public function websiteUrl(): ?string
    {
        return $this->websiteUrl;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function verifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return list<PlaceAgeZone> */
    public function ageZones(): array
    {
        return $this->ageZones;
    }

    public function addCategory(Category $category): void
    {
        foreach ($this->categories as $existing) {
            if ($existing->id()->equals($category->id())) {
                return;
            }
        }
        $this->categories[] = $category;
    }

    /** @param list<Category> $categories */
    public function replaceCategories(array $categories, Category $primaryCategory, \DateTimeImmutable $now): void
    {
        if ([] === $categories || !array_any($categories, static fn (Category $category): bool => $category->id()->equals($primaryCategory->id()))) {
            throw new \InvalidArgumentException('Categories must include the primary category.');
        }
        $this->categories = $categories;
        $this->primaryCategory = $primaryCategory;
        $this->updatedAt = $now;
    }

    public function addAmenity(Amenity $amenity): void
    {
        $this->amenities[] = $amenity;
    }

    /** @param list<Amenity> $amenities */
    public function replaceAmenities(array $amenities, \DateTimeImmutable $now): void
    {
        $this->amenities = $amenities;
        $this->updatedAt = $now;
    }

    public function addAgeZone(PlaceAgeZone $ageZone): void
    {
        $this->ageZones[] = $ageZone;
    }

    /** @param list<PlaceAgeZone> $ageZones */
    public function replaceAgeZones(array $ageZones, \DateTimeImmutable $now): void
    {
        $this->ageZones = $ageZones;
        $this->updatedAt = $now;
    }

    /** @return list<PlacePhoto> */
    public function photos(): array
    {
        usort($this->photos, static function (PlacePhoto $a, PlacePhoto $b): int {
            if ($a->displayOrder() !== $b->displayOrder()) {
                return $a->displayOrder() <=> $b->displayOrder();
            }

            return $a->id()->toRfc4122() <=> $b->id()->toRfc4122();
        });

        return $this->photos;
    }

    /** @param list<PlacePhoto> $photos */
    public function replacePhotos(array $photos, \DateTimeImmutable $now): void
    {
        $this->photos = $photos;
        $this->updatedAt = $now;
    }

    public function addPhoto(PlacePhoto $photo, \DateTimeImmutable $now): void
    {
        $this->photos[] = $photo;
        $this->updatedAt = $now;
    }

    /** @return list<WeeklyOpeningInterval> */
    public function weeklyOpeningHours(): array
    {
        return $this->weeklyOpeningHours;
    }

    public function openingHoursMode(): OpeningHoursMode
    {
        return $this->openingHoursMode;
    }

    /**
     * @param list<WeeklyOpeningInterval> $weeklyIntervals
     * @param list<SpecialOpeningDay>     $specialDays
     */
    public function replaceOpeningSchedule(OpeningHoursMode $mode, array $weeklyIntervals, array $specialDays, \DateTimeImmutable $now): void
    {
        if (OpeningHoursMode::SCHEDULED !== $mode && [] !== $weeklyIntervals) {
            throw new \InvalidArgumentException('Weekly intervals require scheduled opening hours mode.');
        }
        $this->replaceWeeklyOpeningHours($weeklyIntervals, $now);
        $this->replaceSpecialOpeningDays($specialDays, $now);
        $this->openingHoursMode = $mode;
    }

    /** @param list<WeeklyOpeningInterval> $intervals */
    public function replaceWeeklyOpeningHours(array $intervals, \DateTimeImmutable $now): void
    {
        self::assertIntervalsDoNotOverlap($intervals);
        $this->weeklyOpeningHours = $intervals;
        $this->updatedAt = $now;
    }

    /** @return list<SpecialOpeningDay> */
    public function specialOpeningDays(): array
    {
        return $this->specialOpeningDays;
    }

    /** @param list<SpecialOpeningDay> $days */
    public function replaceSpecialOpeningDays(array $days, \DateTimeImmutable $now): void
    {
        $dates = [];
        foreach ($days as $day) {
            $day->assertValid();
            $date = $day->localDate()->format('Y-m-d');
            if (isset($dates[$date])) {
                throw new \InvalidArgumentException('Special opening dates must be unique.');
            }
            $dates[$date] = true;
        }
        $this->assertSpecialIntervalsDoNotOverlap($days);
        $this->specialOpeningDays = $days;
        $this->updatedAt = $now;
    }

    /** @return list<ExternalPlaceReference> */
    public function externalReferences(): array
    {
        return $this->externalReferences;
    }

    /** @param list<ExternalPlaceReference> $references */
    public function replaceExternalReferences(array $references, \DateTimeImmutable $now): void
    {
        $keys = [];
        foreach ($references as $reference) {
            $key = $reference->provider()."\0".$reference->externalId();
            if (isset($keys[$key])) {
                throw new \InvalidArgumentException('External provider and identifier must be unique.');
            }
            $keys[$key] = true;
        }
        $this->externalReferences = $references;
        $this->updatedAt = $now;
    }

    public function updateCoreDetails(
        PlaceName $name,
        PlaceSlug $slug,
        string $shortDescription,
        string $description,
        string $addressLine1,
        ?string $addressLine2,
        string $postalCode,
        City $city,
        string $countryCode,
        Coordinates $coordinates,
        string $timezone,
        bool $indoor,
        bool $outdoor,
        bool $freeEntry,
        ?string $priceDescription,
        ?string $websiteUrl,
        ?string $phone,
        VerificationStatus $verificationStatus,
        \DateTimeImmutable $now,
    ): void {
        if (PlaceStatus::ARCHIVED === $this->status) {
            throw new \DomainException('An archived place cannot be edited.');
        }
        if (2 !== \strlen($countryCode) || false === timezone_open($timezone)) {
            throw new \InvalidArgumentException('Invalid country code or timezone.');
        }
        $this->name = $name->value;
        $this->slug = $slug->value;
        $this->shortDescription = trim($shortDescription);
        $this->description = trim($description);
        $this->addressLine1 = trim($addressLine1);
        $this->addressLine2 = $addressLine2;
        $this->postalCode = trim($postalCode);
        $this->city = $city;
        $this->countryCode = strtoupper($countryCode);
        $this->latitude = $coordinates->latitude;
        $this->longitude = $coordinates->longitude;
        $this->timezone = $timezone;
        $this->indoor = $indoor;
        $this->outdoor = $outdoor;
        $this->freeEntry = $freeEntry;
        $this->priceDescription = $priceDescription;
        $this->websiteUrl = $websiteUrl;
        $this->phone = $phone;
        $this->verificationStatus = $verificationStatus;
        $this->updatedAt = $now;
    }

    /** @return list<string> */
    public function publicationProblems(): array
    {
        $problems = [];
        if ('' === trim($this->name)) {
            $problems[] = 'name';
        }
        if ('' === trim($this->slug)) {
            $problems[] = 'slug';
        }
        if ('' === trim($this->shortDescription)) {
            $problems[] = 'shortDescription';
        }
        if ('' === trim($this->description)) {
            $problems[] = 'description';
        }
        if ('' === trim($this->addressLine1) || '' === trim($this->postalCode) || 2 !== \strlen($this->countryCode)) {
            $problems[] = 'address';
        }
        if ([] === $this->categories) {
            $problems[] = 'categories';
        }
        if ([] === $this->ageZones) {
            $problems[] = 'ageZones';
        }
        if (!$this->indoor && !$this->outdoor) {
            $problems[] = 'indoorOrOutdoor';
        }
        if (false === timezone_open($this->timezone)) {
            $problems[] = 'timezone';
        }

        return $problems;
    }

    public function submitForReview(\DateTimeImmutable $now): void
    {
        if (PlaceStatus::DRAFT !== $this->status) {
            throw new \DomainException('Only a draft can be submitted.');
        }
        $this->status = PlaceStatus::PENDING_REVIEW;
        $this->updatedAt = $now;
    }

    public function publish(\DateTimeImmutable $now): void
    {
        $problems = $this->publicationProblems();
        if ([] !== $problems) {
            throw new \DomainException('Place is incomplete: '.implode(', ', $problems));
        }
        if (PlaceStatus::PENDING_REVIEW !== $this->status && PlaceStatus::NEEDS_REVERIFICATION !== $this->status) {
            throw new \DomainException('Place is not ready for publication.');
        }
        $this->status = PlaceStatus::PUBLISHED;
        $this->verificationStatus = VerificationStatus::ADMIN_VERIFIED;
        $this->verifiedAt = $now;
        $this->publishedAt = $now;
        $this->updatedAt = $now;
    }

    public function unpublish(\DateTimeImmutable $now): void
    {
        $this->assertStatus(PlaceStatus::PUBLISHED);
        $this->status = PlaceStatus::DRAFT;
        $this->publishedAt = null;
        $this->updatedAt = $now;
    }

    public function markNeedsReverification(\DateTimeImmutable $now): void
    {
        $this->assertStatus(PlaceStatus::PUBLISHED);
        $this->status = PlaceStatus::NEEDS_REVERIFICATION;
        $this->updatedAt = $now;
    }

    public function markTemporarilyClosed(\DateTimeImmutable $now): void
    {
        $this->assertStatus(PlaceStatus::PUBLISHED);
        $this->status = PlaceStatus::TEMPORARILY_CLOSED;
        $this->updatedAt = $now;
    }

    public function archive(\DateTimeImmutable $now): void
    {
        if (PlaceStatus::ARCHIVED === $this->status) {
            throw new \DomainException('Place is already archived.');
        }
        $this->status = PlaceStatus::ARCHIVED;
        $this->publishedAt = null;
        $this->updatedAt = $now;
    }

    public function reopen(\DateTimeImmutable $now): void
    {
        $this->assertStatus(PlaceStatus::TEMPORARILY_CLOSED);
        $this->status = PlaceStatus::PUBLISHED;
        $this->updatedAt = $now;
    }

    private function assertStatus(PlaceStatus $required): void
    {
        if ($required !== $this->status) {
            throw new \DomainException(\sprintf('Transition from %s is not allowed.', $this->status->value));
        }
    }

    /** @param list<WeeklyOpeningInterval> $intervals */
    private static function assertIntervalsDoNotOverlap(array $intervals): void
    {
        $byDay = [];
        $segments = [];
        foreach ($intervals as $interval) {
            $byDay[$interval->weekday()][] = $interval;
            $start = (($interval->weekday() - 1) * 1440) + self::minuteOfDay($interval->opensAt());
            $end = (($interval->weekday() - 1) * 1440) + self::minuteOfDay($interval->closesAt()) + ($interval->closesNextDay() ? 1440 : 0);
            if ($end <= 10080) {
                $segments[] = [$start, $end];
            } else {
                $segments[] = [$start, 10080];
                $segments[] = [0, $end - 10080];
            }
        }
        foreach ($byDay as $dayIntervals) {
            usort($dayIntervals, static fn (WeeklyOpeningInterval $a, WeeklyOpeningInterval $b): int => $a->sequence() <=> $b->sequence());
            foreach ($dayIntervals as $index => $interval) {
                if ($interval->sequence() !== $index + 1) {
                    throw new \InvalidArgumentException('Opening interval sequence must be contiguous.');
                }
            }
        }
        usort($segments, static fn (array $left, array $right): int => $left[0] <=> $right[0]);
        foreach ($segments as $index => $segment) {
            if ($index > 0 && $segments[$index - 1][1] > $segment[0]) {
                throw new \InvalidArgumentException('Weekly opening intervals cannot overlap across day or week boundaries.');
            }
        }
    }

    /** @param list<SpecialOpeningDay> $days */
    private function assertSpecialIntervalsDoNotOverlap(array $days): void
    {
        $timezone = new \DateTimeZone($this->timezone);
        $ranges = [];
        foreach ($days as $day) {
            if (SpecialOpeningDayMode::CLOSED === $day->mode()) {
                continue;
            }
            $date = $day->localDate()->format('Y-m-d');
            if (SpecialOpeningDayMode::OPEN_24_HOURS === $day->mode()) {
                $ranges[] = [$this->localDateTime($date, '00:00', $timezone), $this->localDateTime((new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d'), '00:00', $timezone)];
                continue;
            }
            foreach ($day->intervals() as $interval) {
                $nextDate = (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
                $ranges[] = [
                    $this->localDateTime($date, $interval->opensAt()->format('H:i'), $timezone),
                    $this->localDateTime($interval->closesNextDay() ? $nextDate : $date, $interval->closesAt()->format('H:i'), $timezone),
                ];
            }
        }
        usort($ranges, static fn (array $left, array $right): int => $left[0] <=> $right[0]);
        foreach ($ranges as $index => $range) {
            if ($range[1] <= $range[0]) {
                throw new \InvalidArgumentException('Special opening interval must have a positive real duration.');
            }
            if ($index > 0 && $ranges[$index - 1][1] > $range[0]) {
                throw new \InvalidArgumentException('Special opening intervals cannot overlap across dates.');
            }
        }
    }

    private function localDateTime(string $date, string $time, \DateTimeZone $timezone): \DateTimeImmutable
    {
        $value = new \DateTimeImmutable($date.' '.$time, $timezone);
        if ($value->format('Y-m-d H:i') !== $date.' '.$time) {
            throw new \InvalidArgumentException('Opening interval uses a nonexistent local time.');
        }

        return $value;
    }

    private static function minuteOfDay(\DateTimeImmutable $time): int
    {
        return ((int) $time->format('H') * 60) + (int) $time->format('i');
    }
}
