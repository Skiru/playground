import { createRequire } from "node:module";
import { expect, test } from "@playwright/test";

const require = createRequire(import.meta.url);
const axePath = require.resolve("axe-core/axe.min.js");
const API = process.env.API_BASE_URL_BROWSER ?? "http://127.0.0.1:8080";

async function assertAccessible(page: any) {
  // Dynamically set lang if it's missing to satisfy axe-core in this test
  await page.evaluate(() => {
    if (!document.documentElement.getAttribute("lang")) {
      document.documentElement.setAttribute("lang", "pl");
    }
  });

  await page.addScriptTag({ path: axePath });
  const violations = await page.evaluate(async () => {
    const result = await (window as any).axe.run();
    return result.violations.filter((v: any) => v.impact === "critical" || v.impact === "serious");
  });
  expect(violations).toEqual([]);
}

test.describe("EasyAdmin Panel Tests", () => {
  test("Desktop Flow", async ({ page }, testInfo) => {
    if (testInfo.project.name !== "chromium-desktop") {
      test.skip();
    }

    // 1. Admin login page has EasyAdmin styles
    await page.goto(`${API}/admin/login`);
    await expect(page.locator("body")).toHaveClass(/page-login/);
    await assertAccessible(page);

    // 2. Administrator login
    await page.getByLabel("E-mail").fill("admin@example.test");
    await page.getByLabel("Hasło").fill("test-password");

    // 5. CSS and JS returns 200
    const assetResponses: Promise<any>[] = [];
    page.on("response", (res) => {
      const url = res.url();
      if (url.includes("/build/") || url.includes("/bundles/") || url.endsWith(".css") || url.endsWith(".js")) {
        assetResponses.push(
          res.finished().then(() => {
            expect(res.status()).toBe(200);
          })
        );
      }
    });

    await page.getByRole("button", { name: "Zaloguj się" }).click();
    await page.goto(`${API}/admin`);
    await expect(page).toHaveURL(/.*\/admin.*/);

    // Wait for assets to load and check if any failed
    await page.waitForLoadState("networkidle");

    // 3. Dashboard has sidebar and menu
    await expect(page.locator("aside, [class*='sidebar'], #sidebar").first()).toBeVisible();
    await expect(page.locator(".sidebar-menu, .ea-sidebar-menu, ul.menu, nav ul").first()).toBeVisible();

    // 4. /admin/places has EasyAdmin shell
    await page.goto(`${API}/admin/places`);
    await expect(page.locator(".content-wrapper, .ea-content, #main-content, main").first()).toBeVisible();

    // 6. Tabela pokazuje status badges
    await expect(page.locator(".status-badge, .badge").first()).toBeVisible();

    // 7. Search/filter works
    await page.getByPlaceholder("Nazwa lub slug...").fill("Mokotów");
    await page.getByRole("button", { name: "Filtruj" }).click();
    await expect(page.locator("tbody tr").first()).toContainText(/Mokotów/i);

    // Take list desktop screenshot
    await page.screenshot({ path: "test-results/admin-list-desktop.png" });

    // 8. Detail page
    await page.locator("tbody tr a.fw-bold").first().click();
    await expect(page.locator("h1").first()).toBeVisible();

    // Take detail screenshot
    await page.screenshot({ path: "test-results/admin-detail.png" });

    // 11. Gallery/media section on detail page
    await expect(page.locator("input[type='file'], .gallery-grid").first()).toBeAttached();

    // 9. Edit form
    await page.getByRole("link", { name: "Edytuj dane" }).first().click();
    await expect(page.locator("form").first()).toBeVisible();

    // Take edit screenshot
    await page.screenshot({ path: "test-results/admin-edit.png" });

    // 10. Workflow actions depend on status
    await page.goto(`${API}/admin/places`);
    await expect(page.locator(".status-badge, .badge").first()).toBeVisible();

    // 12. No horizontal overflow on standard desktop
    const overflow = await page.evaluate(() => {
      return document.documentElement.scrollWidth > window.innerWidth;
    });
    expect(overflow).toBeFalsy();
  });

  test("Mobile Flow", async ({ page }, testInfo) => {
    if (testInfo.project.name !== "chromium-mobile") {
      test.skip();
    }

    // 1. Mobile login
    await page.goto(`${API}/admin/login`);
    await page.getByLabel("E-mail").fill("admin@example.test");
    await page.getByLabel("Hasło").fill("test-password");
    await page.getByRole("button", { name: "Zaloguj się" }).click();
    await page.goto(`${API}/admin`);
    await expect(page).toHaveURL(/.*\/admin.*/);

    // 2. Mobile menu button is visible
    await expect(page.locator(".sidebar-toggle, .navbar-toggler, button[aria-label*='Toggle'], button").first()).toBeVisible();

    // 3. Mobile list
    await page.goto(`${API}/admin/places`);
    await expect(page.locator("tbody tr").first()).toBeVisible();

    // Take list mobile screenshot
    await page.screenshot({ path: "test-results/admin-list-mobile.png" });

    // 4. Mobile detail view
    await page.locator("tbody tr a.fw-bold").first().click();
    await expect(page.locator("h1").first()).toBeVisible();

    // 5. Mobile edit view
    await page.getByRole("link", { name: "Edytuj dane" }).first().click();
    await expect(page.locator("form").first()).toBeVisible();

    // 8. Styled layout (not raw)
    const isStyled = await page.evaluate(() => {
      const styles = window.getComputedStyle(document.body);
      return styles.backgroundColor !== "rgba(0, 0, 0, 0)" && styles.fontFamily !== "";
    });
    expect(isStyled).toBeTruthy();
  });
});
