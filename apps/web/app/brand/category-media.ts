import type { AssetInfo } from "./brand.schema"

export const realCategorySlugs = ["bawialnie", "parki", "kawiarnie", "muzea", "sport", "natura"] as const

export type RealCategorySlug = (typeof realCategorySlugs)[number]

const categoryMedia: Record<RealCategorySlug, AssetInfo> = {
  bawialnie: { path: "/brand/categories/playrooms.svg", alt: "Bawialnie i sale zabaw" },
  parki: { path: "/brand/categories/parks.svg", alt: "Parki rodzinne" },
  kawiarnie: { path: "/brand/categories/cafes.svg", alt: "Kawiarnie rodzinne" },
  muzea: { path: "/brand/categories/museums.svg", alt: "Muzea i edukacja" },
  sport: { path: "/brand/categories/outdoor.svg", alt: "Sport i rekreacja" },
  natura: { path: "/brand/categories/outdoor.svg", alt: "Natura i wypoczynek na zewnątrz" },
}

const aliases: Record<string, RealCategorySlug> = {
  playrooms: "bawialnie",
  parks: "parki",
  cafes: "kawiarnie",
  museums: "muzea",
  outdoor: "sport",
}

export const genericCategoryMedia: AssetInfo = {
  path: "/brand/categories/generic.svg",
  alt: "Rodzinne miejsce",
}

export function resolveCategoryMedia(slug?: string): AssetInfo {
  if (!slug) return genericCategoryMedia
  const canonicalSlug = aliases[slug] ?? slug
  return categoryMedia[canonicalSlug as RealCategorySlug] ?? genericCategoryMedia
}
