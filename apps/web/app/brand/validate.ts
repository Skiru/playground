import { defaultBrand } from "./default-brand";
import { plContent } from "../content";

export function validateBrandConfig(): void {
  // 1. Validate Brand Assets Manifest
  const requiredAssets = [
    defaultBrand.wordmark,
    defaultBrand.compactMark,
    defaultBrand.homepageHeroImage,
    defaultBrand.placePlaceholder,
    defaultBrand.mapUnavailableIllustration,
    defaultBrand.noResultsIllustration,
    defaultBrand.userAvatarPlaceholder,
  ];

  for (const asset of requiredAssets) {
    if (!asset) {
      throw new Error("Validation Error: Missing a required semantic brand asset in manifest.");
    }
    if (!asset.path || typeof asset.path !== "string" || !asset.path.startsWith("/")) {
      throw new Error(`Validation Error: Invalid asset path '${asset?.path}'. It must be a non-empty absolute URL-path starting with '/'.`);
    }
    if (asset.alt === undefined || typeof asset.alt !== "string") {
      throw new Error(`Validation Error: Asset with path '${asset.path}' is missing alt text metadata.`);
    }
  }

  // 2. Validate Content Catalog
  if (!plContent) {
    throw new Error("Validation Error: Content Catalog is empty or not loaded.");
  }
  if (!plContent.common?.siteTitle) {
    throw new Error("Validation Error: Content Catalog common.siteTitle is missing.");
  }
  if (!plContent.home?.heading) {
    throw new Error("Validation Error: Content Catalog home.heading is missing.");
  }
  if (!plContent.places?.noResults) {
    throw new Error("Validation Error: Content Catalog places.noResults is missing.");
  }
  if (typeof plContent.places.resultsHeadingPlural !== "function") {
    throw new Error("Validation Error: Content Catalog resultsHeadingPlural must be a function.");
  }

  // 3. Confirm Favicon
  if (!defaultBrand.favicon || typeof defaultBrand.favicon !== "string" || !defaultBrand.favicon.endsWith(".ico")) {
    throw new Error("Validation Error: Brand favicon must be a valid .ico file path.");
  }

  console.log("✓ Brand configuration, asset manifest, and content catalog validated successfully.");
}

// Automatically execute the validation when this module is loaded at build time.
validateBrandConfig();
