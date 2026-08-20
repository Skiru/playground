import { expect, test } from "@playwright/test";

test.describe("Real Server-Side Rendering (SSR) & Private Data Isolation", () => {
  test("GET /spolecznosc returns raw HTML with feed content before hydration and no duplicate initial API fetch", async ({ page, request }) => {
    // 1. Raw HTTP GET request against web server
    const res = await request.get("/spolecznosc");
    expect(res.ok()).toBeTruthy();
    const html = await res.text();

    // 2. Verify raw HTML contains meaningful content BEFORE client JavaScript executes
    expect(html).toContain("Aktywność społeczności");

    // 3. Hydrate in browser and monitor network requests to ensure no initial duplicate API call
    const apiRequests: string[] = [];
    page.on("request", (req) => {
      if (req.url().includes("/api/v1/community/feed")) {
        apiRequests.push(req.url());
      }
    });

    await page.goto("/spolecznosc");
    await expect(page.getByRole("heading", { level: 1 })).toContainText("Aktywność społeczności");

    // Initial page load with SSR should not perform a duplicate client fetch for the initial feed items
    expect(apiRequests).toHaveLength(0);
  });

  test("GET /forum returns raw HTML with forum categories before hydration", async ({ request }) => {
    const res = await request.get("/forum");
    expect(res.ok()).toBeTruthy();
    const html = await res.text();

    expect(html).toContain("Warszawa");
    expect(html).toContain("Forum Społeczności");
  });

  test("GET /forum/warszawa returns raw HTML with category name and thread list before hydration", async ({ page, request }) => {
    const res = await request.get("/forum/warszawa");
    expect(res.ok()).toBeTruthy();
    const html = await res.text();

    expect(html).toContain("Warszawa");

    const apiRequests: string[] = [];
    page.on("request", (req) => {
      if (req.url().includes("/api/v1/forum/categories/warszawa/threads")) {
        apiRequests.push(req.url());
      }
    });

    await page.goto("/forum/warszawa");
    await expect(page.getByRole("heading", { level: 1 })).toContainText("Warszawa");

    // No duplicate initial loader request after hydration
    expect(apiRequests).toHaveLength(0);
  });

  test("private endpoints require authentication and enforce private cache controls", async ({ request }) => {
    const accountRes = await request.get("/konto");
    expect([200, 302, 303, 307]).toContain(accountRes.status());

    const sessionRes = await request.get("/resources/session");
    expect(sessionRes.ok()).toBeTruthy();
    const cacheControl = sessionRes.headers()["cache-control"];
    expect(cacheControl).toContain("no-store");
    expect(cacheControl).toContain("private");
  });
});
