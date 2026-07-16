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
    /** @var list<Category> */
    private array $categories = [];
    /** @var list<Amenity> */
    private array $amenities = [];
    /** @var list<PlaceAgeZone> */
    private array $ageZones = [];
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

    public function addAmenity(Amenity $amenity): void
    {
        $this->amenities[] = $amenity;
    }

    public function addAgeZone(PlaceAgeZone $ageZone): void
    {
        $this->ageZones[] = $ageZone;
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
        $this->status = PlaceStatus::DRAFT;
        $this->publishedAt = null;
        $this->updatedAt = $now;
    }

    public function markNeedsReverification(\DateTimeImmutable $now): void
    {
        $this->status = PlaceStatus::NEEDS_REVERIFICATION;
        $this->updatedAt = $now;
    }

    public function markTemporarilyClosed(\DateTimeImmutable $now): void
    {
        $this->status = PlaceStatus::TEMPORARILY_CLOSED;
        $this->updatedAt = $now;
    }

    public function archive(\DateTimeImmutable $now): void
    {
        $this->status = PlaceStatus::ARCHIVED;
        $this->publishedAt = null;
        $this->updatedAt = $now;
    }
}
