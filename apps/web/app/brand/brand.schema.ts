export interface AssetInfo {
  path: string;
  alt: string;
  width?: number;
  height?: number;
  aspectRatio?: string;
}

export interface BrandManifest {
  wordmark: AssetInfo;
  compactMark: AssetInfo;
  favicon: string;
  defaultOpenGraphImage: AssetInfo;
  homepageHeroImage: AssetInfo;
  placePlaceholder: AssetInfo;
  mapUnavailableIllustration: AssetInfo;
  noResultsIllustration: AssetInfo;
  userAvatarPlaceholder: AssetInfo;
  categoryImageMapping: Record<string, AssetInfo>;
}
