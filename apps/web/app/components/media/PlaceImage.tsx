import React from "react";
import { AppImage, type AppImageProps } from "./AppImage";
import { brand } from "../../brand/default-brand";

export interface PlaceImageProps extends Omit<AppImageProps, 'fallback'> {
  mainPhotoUrl?: string;
  placeName: string;
  categorySlug?: string;
  fallback?: string;
}

export const PlaceImage: React.FC<PlaceImageProps> = ({
  mainPhotoUrl,
  placeName,
  categorySlug,
  fallback,
  alt,
  ...props
}) => {
  // Determine fallback image based on category mapping or general placeholder
  const mappedCategory = categorySlug ? brand.categoryImageMapping[categorySlug as keyof typeof brand.categoryImageMapping] : undefined;
  const categoryFallback = mappedCategory ? mappedCategory.path : undefined;
  const chosenFallback = fallback || categoryFallback || brand.placePlaceholder.path;

  // Alt attribute strategy:
  // - Real photo: use photo alt or place name
  // - Illustrative fallback: empty alt since place name is adjacent in most UI contexts
  const isFallbackActive = !mainPhotoUrl;
  const chosenAlt = alt !== undefined 
    ? alt 
    : (isFallbackActive ? "" : placeName);

  return (
    <AppImage
      src={mainPhotoUrl}
      fallback={chosenFallback}
      alt={chosenAlt}
      {...props}
    />
  );
};
