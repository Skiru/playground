import { expect, test, type Page } from "@playwright/test";

async function loginAs(page: Page, email: string, displayName: string) {
  await page.goto("/");
  await page.evaluate(async (data: { email: string; displayName: string }) => {
    const response = await fetch("/resources/auth/dev-login", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ ...data, roles: ["ROLE_USER"] }),
    });
    return response.ok;
  }, { email, displayName });
  await page.goto("/");
}

test.describe("Community Feed E2E Real Journey", () => {
  test("Feed excludes hidden, inactive, and unpublished items", async ({ page }) => {
    const uniqueSuffix = Math.random().toString(36).slice(2);
    const threadTitle = `Feed visible thread ${uniqueSuffix}`;
    await loginAs(page, `feed_${uniqueSuffix}@example.com`, "FeedUser");
    await page.goto("/forum");
    await page.locator("a[href^='/forum/']").first().click();
    await page.getByRole("button", { name: "Nowy wątek" }).click();
    await page.locator("#title").fill(threadTitle);
    await page.locator("#body").fill(`Visible feed body ${uniqueSuffix}`);
    await page.getByRole("button", { name: "Utwórz wątek" }).click();
    await expect(page.getByText(threadTitle)).toBeVisible();

    // Navigate to feed page
    await page.goto("/spolecznosc");
    await expect(page.getByRole("heading", { name: "Aktywność społeczności" })).toBeVisible();

    // 1. Assert that the feed loads cards successfully
    await expect(page.getByText(threadTitle, { exact: true })).toBeVisible();

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
