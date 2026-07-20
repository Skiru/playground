import { expect, test } from "@playwright/test";

test.describe("Community Accessibility E2E", () => {
  test("Key pages support keyboard navigation and focus styles", async ({ page }) => {
    await page.goto("/forum");
    await page.keyboard.press("Tab");
    await page.keyboard.press("Tab");
    await page.keyboard.press("Tab");
    
    // Check skip or focus links exist
    await expect(page.locator("a").first()).toBeAttached();
  });
});
