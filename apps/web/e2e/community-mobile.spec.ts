import { expect, test, devices } from "@playwright/test";

test.use({ ...devices["iPhone 14"] });

test.describe("Community Mobile Viewport E2E", () => {
  test("Essential journeys render correctly in mobile layout", async ({ page }) => {
    await page.goto("/");
    await expect(page.getByRole("button", { name: "Toggle Menu" })).toBeVisible();
    await page.getByRole("button", { name: "Toggle Menu" }).click();
    
    // Check navigation menu
    await expect(page.getByRole("link", { name: "Forum" })).toBeVisible();
    await expect(page.getByRole("link", { name: "Społeczność" })).toBeVisible();
  });
});
