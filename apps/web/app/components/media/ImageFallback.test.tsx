import { describe, expect, it } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { AppImage } from "./AppImage";
import { PlaceImage } from "./PlaceImage";
import React, { StrictMode } from "react";

describe("AppImage and PlaceImage fallbacks", () => {
  // 1. no src
  it("renders fallback immediately if src is null/undefined", () => {
    render(<AppImage src={undefined} fallback="/brand/place-placeholder.svg" alt="Test image" />);
    const img = screen.getByRole("img") as HTMLImageElement;
    expect(img.src).toContain("/brand/place-placeholder.svg");
  });

  // 2. valid primary
  it("renders primary src if provided", () => {
    render(<AppImage src="/real-image.jpg" fallback="/brand/place-placeholder.svg" alt="Test image" />);
    const img = screen.getByRole("img") as HTMLImageElement;
    expect(img.src).toContain("/real-image.jpg");
  });

  // 3. primary error
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

  // 4. fallback error
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

  // 5. src change after primary error
  it("re-evaluates and switches back to primary if src changes after a primary error", () => {
    const { container, rerender } = render(
      <AppImage
        src="/broken-image.jpg"
        fallback="/brand/place-placeholder.svg"
        alt="Test image"
      />
    );
    const img = container.querySelector("img") as HTMLImageElement;
    
    // Simulate primary error -> switches to fallback
    fireEvent.error(img);
    expect(img.src).toContain("/brand/place-placeholder.svg");

    // Change src prop
    rerender(
      <AppImage
        src="/new-valid-image.jpg"
        fallback="/brand/place-placeholder.svg"
        alt="Test image"
      />
    );

    const updatedImg = container.querySelector("img") as HTMLImageElement;
    expect(updatedImg.src).toContain("/new-valid-image.jpg");
  });

  // 6. fallback change while src unchanged
  it("resets status and retries primary if fallback changes while src is unchanged", () => {
    const { container, rerender } = render(
      <AppImage
        src="/broken-image.jpg"
        fallback="/brand/place-placeholder.svg"
        alt="Test image"
      />
    );
    const img = container.querySelector("img") as HTMLImageElement;
    
    // Simulate primary error -> switches to fallback
    fireEvent.error(img);
    expect(img.src).toContain("/brand/place-placeholder.svg");

    // Change fallback prop while src is unchanged
    rerender(
      <AppImage
        src="/broken-image.jpg"
        fallback="/brand/new-fallback.svg"
        alt="Test image"
      />
    );

    // It should reset to PRIMARY
    const resetImg = container.querySelector("img") as HTMLImageElement;
    expect(resetImg.src).toContain("/broken-image.jpg");
  });

  // 7. srcSet change while src unchanged
  it("resets status to primary if srcSet changes while src is unchanged", () => {
    const { container, rerender } = render(
      <AppImage
        src="/broken-image.jpg"
        srcSet="/broken-1.jpg 100w"
        fallback="/brand/place-placeholder.svg"
        alt="Test image"
      />
    );
    const img = container.querySelector("img") as HTMLImageElement;
    
    // Simulate primary error -> switches to fallback
    fireEvent.error(img);
    expect(img.src).toContain("/brand/place-placeholder.svg");

    // Change srcSet prop while src is unchanged
    rerender(
      <AppImage
        src="/broken-image.jpg"
        srcSet="/broken-2.jpg 200w"
        fallback="/brand/place-placeholder.svg"
        alt="Test image"
      />
    );

    const resetImg = container.querySelector("img") as HTMLImageElement;
    expect(resetImg.src).toContain("/broken-image.jpg");
    expect(resetImg.srcset || resetImg.getAttribute("srcset")).toContain("/broken-2.jpg 200w");
  });

  // 8. recovery from FINAL after new src
  it("recovers from FINAL status back to primary after receiving a new src", () => {
    const { container, rerender } = render(
      <AppImage
        src="/broken-image.jpg"
        fallback="/broken-fallback.jpg"
        alt="Test image"
      />
    );
    const img = container.querySelector("img") as HTMLImageElement;
    
    // Primary fails -> fallback
    fireEvent.error(img);
    // Fallback fails -> final
    fireEvent.error(img);

    expect(container.querySelector("div")).not.toBeNull();
    expect(container.querySelector("img")).toBeNull();

    // Rerender with new src
    rerender(
      <AppImage
        src="/recovered-image.jpg"
        fallback="/broken-fallback.jpg"
        alt="Test image"
      />
    );

    const newImg = container.querySelector("img") as HTMLImageElement;
    expect(newImg).not.toBeNull();
    expect(newImg.src).toContain("/recovered-image.jpg");
  });

  // 9. no infinite error loop
  it("never loops infinitely on error because the final state does not render img", () => {
    const { container } = render(
      <AppImage
        src="/broken-image.jpg"
        fallback="/broken-fallback.jpg"
        alt="Test image"
      />
    );
    const img = container.querySelector("img") as HTMLImageElement;
    
    // Error on primary -> state becomes FALLBACK
    fireEvent.error(img);
    
    // Error on fallback -> state becomes FINAL
    fireEvent.error(img);

    // After state becomes FINAL, no img is rendered
    expect(container.querySelector("img")).toBeNull();
  });

  // 10. StrictMode render
  it("renders correctly in StrictMode without warnings or bugs", () => {
    const { container } = render(
      <StrictMode>
        <AppImage
          src="/real-image.jpg"
          fallback="/brand/place-placeholder.svg"
          alt="Test image"
        />
      </StrictMode>
    );
    const img = container.querySelector("img") as HTMLImageElement;
    expect(img.src).toContain("/real-image.jpg");
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
