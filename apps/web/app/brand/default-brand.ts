import type { BrandManifest } from "./brand.schema";

export const defaultBrand: BrandManifest = {
  wordmark: {
    path: "/brand/wordmark.svg",
    alt: "FamilyPlaces",
    width: 200,
    height: 40,
  },
  compactMark: {
    path: "/brand/compact-mark.svg",
    alt: "FP Logo",
    width: 40,
    height: 40,
  },
  favicon: "/favicon.ico",
  defaultOpenGraphImage: {
    path: "/brand/default-og.png",
    alt: "FamilyPlaces - Miejsca przyjazne rodzinom",
    width: 1200,
    height: 630,
  },
  homepageHeroImage: {
    path: "/brand/hero-placeholder.webp",
    alt: "Odkrywaj rodzinne miejsca",
    aspectRatio: "16:9",
  },
  placePlaceholder: {
    path: "/brand/place-placeholder.webp",
    alt: "Brak zdjęcia miejsca",
    width: 400,
    height: 300,
  },
  mapUnavailableIllustration: {
    path: "/brand/map-unavailable.svg",
    alt: "Mapa tymczasowo niedostępna",
    width: 500,
    height: 400,
  },
  noResultsIllustration: {
    path: "/brand/no-results.svg",
    alt: "Brak wyników wyszukiwania",
    width: 500,
    height: 400,
  },
  userAvatarPlaceholder: {
    path: "/brand/avatar-placeholder.png",
    alt: "Użytkownik",
    width: 100,
    height: 100,
  },
  categoryImageMapping: {
    parks: {
      path: "/brand/categories/parks.webp",
      alt: "Parki i rekreacja",
    },
    cafes: {
      path: "/brand/categories/cafes.webp",
      alt: "Kawiarnie i restauracje",
    },
    playrooms: {
      path: "/brand/categories/playrooms.webp",
      alt: "Bawialnie i sale zabaw",
    },
  },
};

export const brand = defaultBrand;
