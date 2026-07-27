import React from "react";
import { AppImage, type AppImageProps } from "./AppImage";
import { resolveCategoryMedia } from "../../brand/category-media";

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
  const categoryFallback = resolveCategoryMedia(categorySlug);
  const chosenFallback = fallback || categoryFallback.path;

  const isFallbackActive = !mainPhotoUrl;
  const chosenAlt = alt !== undefined 
    ? alt 
    : (isFallbackActive ? `${categoryFallback.alt}: ${placeName}` : placeName);

  return (
    <AppImage
      src={mainPhotoUrl}
      fallback={chosenFallback}
      alt={chosenAlt}
      {...props}
    />
  );
};
