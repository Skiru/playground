import { expect, test } from "@playwright/test";

const requiredAssets = [
  "/brand/wordmark.svg",
  "/brand/compact-mark.svg",
  "/brand/default-og.svg",
  "/brand/hero-placeholder.svg",
  "/brand/place-placeholder.svg",
  "/brand/map-unavailable.svg",
  "/brand/no-results.svg",
  "/brand/avatar-placeholder.svg",
  "/brand/categories/parks.svg",
  "/brand/categories/cafes.svg",
  "/brand/categories/playrooms.svg",
  "/brand/categories/museums.svg",
  "/brand/categories/outdoor.svg",
  "/brand/categories/generic.svg"
];

test.describe("Real Playwright Image Fallbacks", () => {
  // Scenario 1: All required brand assets return 200
  test("All required /brand/* assets return 200", async ({ request }) => {
    for (const asset of requiredAssets) {
      const response = await request.get(asset);
      expect(response.status()).toBe(200);
    }
  });

  // Scenario 2, 3, 4, 6, 7: Primary image 404 transitions to local placeholder
  test("Primary 404 transitions to local placeholder on Home, Results, and Details", async ({ page }) => {
    // Disable browser cache to ensure onError always triggers
    const session = await page.context().newCDPSession(page);
    await session.send("Network.setCacheDisabled", { cacheDisabled: true });

    // Intercept any real media URL and return 404
    await page.route("**/media/**", (route) => {
      route.fulfill({
        status: 404,
        contentType: "text/plain",
        body: "Not Found"
      });
    });

    // Visit Home page
    await page.goto("/");
    await page.waitForLoadState("networkidle");

    // Any place-card image should fall back to local placeholder
    let placeCardImages = await page.locator("img").all();
    for (const img of placeCardImages) {
      const src = await img.getAttribute("src");
      if (src && !src.includes("/brand/wordmark.svg") && !src.includes("/brand/compact-mark.svg")) {
        expect(src).toContain("/brand/place-placeholder.svg");
      }
    }

    // Visit Results page
    await page.goto("/miejsca?city=warszawa");
    await page.waitForLoadState("networkidle");

    const resultsImages = await page.locator(".place-card img").all();
    for (const img of resultsImages) {
      const src = await img.getAttribute("src");
      expect(src).toContain("/brand/place-placeholder.svg");
    }

    // Visit Details page
    await page.goto("/miejsca/demo-1-demo-bawialnia-mokotow");
    await page.waitForLoadState("networkidle");

    const detailImages = await page.locator("img").all();
    for (const img of detailImages) {
      const src = await img.getAttribute("src");
      if (src && !src.includes("/brand/wordmark.svg") && !src.includes("/brand/compact-mark.svg")) {
        expect(src).toContain("/brand/place-placeholder.svg");
      }
    }
  });

  // Scenario 8, 9, 10: Placeholder fallback transitions work gracefully on Details page
  test("Image fallbacks prevent any broken images from showing on Details page", async ({ page }) => {
    // Disable browser network cache
    const session = await page.context().newCDPSession(page);
    await session.send("Network.setCacheDisabled", { cacheDisabled: true });

    // Intercept media and return 404
    await page.route("**/media/**", (route) => {
      route.fulfill({
        status: 404,
        contentType: "text/plain",
        body: "Not Found"
      });
    });

    // Navigate to Details page to test fallback transitions
    await page.goto("/miejsca/demo-1-demo-bawialnia-mokotow");
    await page.waitForLoadState("networkidle");

    // Give React and the browser some time to process the transitions
    await page.waitForTimeout(1000);

    // Primary has failed and transitioned to local placeholder (errorCount is 1)
    const img = page.locator(".relative.rounded-2xl.overflow-hidden img").first();
    await expect(img).toBeVisible();
    await expect(img).toHaveAttribute("src", "/brand/place-placeholder.svg");

    const brokenSrcs = await page.evaluate(() => {
      const imgs = Array.from(document.querySelectorAll("img"));
      return imgs.filter(imgEl => imgEl.complete && imgEl.naturalWidth === 0 && imgEl.style.display !== "none").map(imgEl => imgEl.src);
    });
    console.log("BROKEN IMAGE SRCS ARE:", brokenSrcs);

    // No visible image has naturalWidth === 0 after fallback (if it has completed loading)
    const visibleImages = await page.locator("img").all();
    for (const imgEl of visibleImages) {
      if (await imgEl.isVisible()) {
        const isComplete = await imgEl.evaluate((el: HTMLImageElement) => el.complete);
        if (isComplete) {
          const naturalWidth = await imgEl.evaluate((el: HTMLImageElement) => el.naturalWidth);
          expect(naturalWidth).toBeGreaterThan(0);
        }
      }
    }

    // No broken-image icon should be visible
    const brokenImagesCount = await page.evaluate(() => {
      const imgs = Array.from(document.querySelectorAll("img"));
      return imgs.filter(imgEl => imgEl.complete && imgEl.naturalWidth === 0 && imgEl.style.display !== "none").length;
    });
    expect(brokenImagesCount).toBe(0);
  });

  // Scenario 5: Favorites page without photos
  test("Favorites page without photos falls back to local placeholder", async ({ page }) => {
    // Disable browser cache
    const session = await page.context().newCDPSession(page);
    await session.send("Network.setCacheDisabled", { cacheDisabled: true });

    // Log in
    await page.goto("/");
    await page.getByRole("button", { name: "Zaloguj się" }).filter({ visible: true }).first().click();
    await page.getByRole("button", { name: "Bypass Login (Fake User)" }).first().click();

    // Add a favorite first
    await page.goto("/miejsca?city=warszawa");
    const favButton = page.locator(".place-card").first().locator('button[title="Dodaj do ulubionych"]');
    await expect(favButton).toBeEnabled();
    await favButton.click();

    // Intercept any real media URL and return 404
    await page.route("**/media/**", (route) => {
      route.fulfill({
        status: 404,
        contentType: "text/plain",
        body: "Not Found"
      });
    });

    await page.goto("/konto/ulubione");
    await page.waitForLoadState("networkidle");

    // Confirm cards are present
    const cardLocators = page.locator(".group.overflow-hidden.bg-card");
    await expect(cardLocators.first()).toBeVisible();

    // Verify favorite cards render fallbacks gracefully
    const favoriteImages = await page.locator("img").all();
    let checkedAtLeastOne = false;
    for (const img of favoriteImages) {
      const src = await img.getAttribute("src");
      if (src && !src.includes("/brand/wordmark.svg") && !src.includes("/brand/compact-mark.svg") && !src.includes("avatar")) {
        expect(src).toContain("/brand/place-placeholder.svg");
        checkedAtLeastOne = true;
      }
    }
    expect(checkedAtLeastOne).toBe(true);
  });
});
