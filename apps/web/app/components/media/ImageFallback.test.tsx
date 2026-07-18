import { describe, expect, it } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { AppImage } from "./AppImage";
import { PlaceImage } from "./PlaceImage";
import React from "react";

describe("AppImage and PlaceImage fallbacks", () => {
  it("renders fallback immediately if src is null/undefined", () => {
    render(<AppImage src={undefined} fallback="/brand/place-placeholder.svg" alt="Test image" />);
    const img = screen.getByRole("img") as HTMLImageElement;
    expect(img.src).toContain("/brand/place-placeholder.svg");
  });

  it("renders primary src if provided", () => {
    render(<AppImage src="/real-image.jpg" fallback="/brand/place-placeholder.svg" alt="Test image" />);
    const img = screen.getByRole("img") as HTMLImageElement;
    expect(img.src).toContain("/real-image.jpg");
  });

  it("switches to fallback and removes srcSet if primary image fails", () => {
    const { container } = render(
      <AppImage
        src="/broken-image.jpg"
        srcSet="/broken-image.jpg 100w"
        fallback="/brand/place-placeholder.svg"
        alt="Test image"
      />
    );
    const img = container.querySelector("img") as HTMLImageElement;
    
    // Simulate error on the primary image
    fireEvent.error(img);

    expect(img.src).toContain("/brand/place-placeholder.svg");
    expect(img.srcset || img.getAttribute("srcset")).toBeFalsy();
  });

  it("switches to safe final CSS fallback if fallback image also fails", () => {
    const { container } = render(
      <AppImage
        src="/broken-image.jpg"
        fallback="/broken-fallback.jpg"
        alt="Test image"
      />
    );
    const img = container.querySelector("img") as HTMLImageElement;
    
    // Primary fails -> switches to fallback
    fireEvent.error(img);
    
    // Fallback fails -> switches to final CSS/SVG-inline fallback
    fireEvent.error(img);

    const finalFallback = container.querySelector("div");
    expect(finalFallback).not.toBeNull();
    expect(container.querySelector("img")).toBeNull();
  });

  it("PlaceImage selects appropriate category fallback", () => {
    const { container } = render(<PlaceImage placeName="Park" categorySlug="parks" />);
    const img = container.querySelector("img") as HTMLImageElement;
    expect(img.src).toContain("/brand/categories/parks.svg");
    expect(img.alt).toBe(""); // Empty alt for decorative fallback when name is nearby
  });

  it("PlaceImage uses generic fallback if category is unknown", () => {
    const { container } = render(<PlaceImage placeName="Somewhere" categorySlug="unknown-category" />);
    const img = container.querySelector("img") as HTMLImageElement;
    expect(img.src).toContain("/brand/place-placeholder.svg");
  });
});
