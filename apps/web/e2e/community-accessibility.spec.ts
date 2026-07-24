import { createRequire } from "node:module";
import { expect, test, type Page } from "@playwright/test";

const require = createRequire(import.meta.url);
const axePath = require.resolve("axe-core/axe.min.js");

async function loginAs(page: Page, email: string, displayName: string, roles: string[] = ["ROLE_USER"]) {
  await page.goto("/");
  await page.evaluate(async (data) => {
    const res = await fetch("/resources/auth/dev-login", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(data),
    });
    return res.ok;
  }, { email, displayName, roles });
  await page.goto("/");
}

async function assertAccessible(page: Page) {
  await page.addScriptTag({ path: axePath });
  const violations = await page.evaluate(async () => {
    const result = await (window as any).axe.run({
      runOnly: {
        type: "tag",
        values: ["wcag2a", "wcag2aa", "best-practice"]
      }
    });
    
    // Filter to only serious and critical violations
    return result.violations
      .filter((v: any) => v.impact === "critical" || v.impact === "serious")
      .map((v: any) => ({
        id: v.id,
        impact: v.impact,
        description: v.description,
        help: v.help,
        nodes: v.nodes.map((node: any) => node.target.join(" "))
      }));
  });
  expect(violations).toEqual([]);
}

test.describe("Community Accessibility Axe Scan Real Journey", () => {
  test("Run actual Axe scans across all required community pages and dialogs", async ({ page }) => {
    const uniqueSuffix = Math.random().toString(36).substring(7);

    // 1. Log in as Moderator so we can access queue and cases
    await loginAs(page, `mod_axe_${uniqueSuffix}@example.com`, "Moderator", ["ROLE_MODERATOR"]);

    // 2. Go to first place details page to get review/comment sections
    await page.goto("/miejsca?city=warszawa");
    await expect(page.locator(".place-card").first()).toBeVisible();
    await page.locator(".place-card h2 a").first().click();
    await expect(page.getByRole("heading", { name: "Oceny i opinie" }).first()).toBeVisible();

    const placeUrl = page.url();

    // Scan Place Review and Discussion Sections
    await assertAccessible(page);

    // Open Report dialog and scan
    const reportBtn = page.getByRole("button", { name: "Zgłoś" }).first();
    if (await reportBtn.isVisible()) {
      await reportBtn.click();
      await expect(page.getByRole("heading", { name: "Zgłoś naruszenie regulaminu" })).toBeVisible();
      await assertAccessible(page);
      // Close report dialog
      await page.keyboard.press("Escape");
    }

    // 3. Community Feed
    await page.goto("/spolecznosc");
    await expect(page.getByRole("heading", { name: "Aktywność społeczności" })).toBeVisible();
    await assertAccessible(page);

    // 4. Forum Categories
    await page.goto("/forum");
    await expect(page.getByRole("heading", { name: "Kategorie forum" })).toBeVisible();
    await assertAccessible(page);

    // 5. Forum Thread List (first category)
    await page.locator("a[href^='/forum/']").first().click();
    const categoryUrl = page.url();
    await assertAccessible(page);

    // Open Create Thread dialog and scan
    const newThreadBtn = page.getByRole("button", { name: "Nowy wątek" });
    if (await newThreadBtn.isVisible()) {
      await newThreadBtn.click();
      await expect(page.getByRole("heading", { name: "Utwórz nowy wątek" })).toBeVisible();
      await assertAccessible(page);
      // Close dialog
      await page.keyboard.press("Escape");
    }

    // 6. Moderator Queue
    await page.goto("/moderator/queue");
    await expect(page.getByRole("heading", { name: "Panel Moderatorów" })).toBeVisible();
    await assertAccessible(page);

    // 7. Moderation Case Detail (first case)
    const reportLink = page.locator("a[href^='/moderator/case/']").first();
    if (await reportLink.isVisible()) {
      await reportLink.click();
      await expect(page.getByRole("heading", { name: "Zgłoszenie naruszenia" })).toBeVisible();
      await assertAccessible(page);
    }
  });
});
