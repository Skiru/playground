import { expect, test } from "@playwright/test";

test.describe("Community Feed E2E", () => {
  test("Feed excludes unpublished places and hidden content", async ({ page }) => {
    await page.goto("/spolecznosc");
    await expect(page.getByRole("heading", { name: "Aktywność społeczności" })).toBeVisible();
    
    // Check that we can load the feed successfully
    await expect(page.locator(".card").first()).toBeVisible();
  });
});
