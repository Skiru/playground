import { expect, test } from "@playwright/test";

test.describe("Community Feed E2E Real Journey", () => {
  test("Feed excludes hidden, inactive, and unpublished items", async ({ page }) => {
    // Navigate to feed page
    await page.goto("/spolecznosc");
    await expect(page.getByRole("heading", { name: "Aktywność społeczności" })).toBeVisible();

    // 1. Assert that the feed loads cards successfully
    const firstCard = page.locator(".card").first();
    await expect(firstCard).toBeVisible();

    // 2. Assert that secret markers of hidden/containment-violated content DO NOT exist in the DOM
    const secretMarkers = [
      "SECRET_MARKER_HIDDEN_THREAD",
      "SECRET_MARKER_POST_IN_INACTIVE_CAT",
      "SECRET_MARKER_REVIEW_UNPUBLISHED_PLACE",
      "SECRET_MARKER_COMMENT_UNPUBLISHED_PLACE"
    ];

    for (const marker of secretMarkers) {
      await expect(page.getByText(marker)).not.toBeVisible();
    }
  });
});
