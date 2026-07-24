import { createRequire } from "node:module";
import { expect, test, type Page } from "@playwright/test";

const require = createRequire(import.meta.url);
const axePath = require.resolve("axe-core/axe.min.js");

async function loginAs(page: Page, email: string, displayName: string, roles: string[] = ["ROLE_USER"]) {
  await page.goto("/");
  await page.evaluate(async (data: { email: string; displayName: string; roles: string[] }) => {
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
    type AxeNode = { target: string[] };
    type AxeViolation = {
      id: string;
      impact: string | null;
      description: string;
      help: string;
      nodes: AxeNode[];
    };
    const axeWindow = window as typeof window & {
      axe: { run: (options: object) => Promise<{ violations: AxeViolation[] }> };
    };
    const result = await axeWindow.axe.run({
      runOnly: {
        type: "tag",
        values: ["wcag2a", "wcag2aa", "best-practice"]
      }
    });
    
    // Filter to only serious and critical violations
    return result.violations
      .filter((violation) => violation.impact === "critical" || violation.impact === "serious")
      .map((violation) => ({
        id: violation.id,
        impact: violation.impact,
        description: violation.description,
        help: violation.help,
        nodes: violation.nodes.map((node) => node.target.join(" "))
      }));
  });
  expect(violations).toEqual([]);
}

test.describe("Community Accessibility Axe Scan Real Journey", () => {
  test("Run actual Axe scans across all required community pages and dialogs", async ({ page }) => {
    const uniqueSuffix = Math.random().toString(36).substring(7);

    // 1. Create reportable community content with an ordinary user.
    await loginAs(page, `author_axe_${uniqueSuffix}@example.com`, "AxeAuthor");
    await page.goto("/miejsca?city=warszawa");
    await expect(page.locator(".place-card").first()).toBeVisible();
    await page.locator(".place-card h2 a").first().click();
    await expect(page).toHaveURL(/\/miejsca\/[^/?#]+$/);
    await expect(page.getByRole("heading", { name: "Opinie i oceny rodziców" }).first()).toBeVisible();
    const placeUrl = page.url();
    await page.getByRole("button", { name: "Dodaj opinię" }).click();
    await page.locator("form button:has-text('★')").nth(3).click();
    await page.locator("#review-form-body").fill(`Opinia do testu dostępności zgłoszenia ${uniqueSuffix}.`);
    await page.getByRole("button", { name: "Zapisz opinię" }).click();
    await expect(page.getByText(`Opinia do testu dostępności zgłoszenia ${uniqueSuffix}.`, { exact: true })).toBeVisible();

    // 2. Log in as moderator and scan the rendered place community UI.
    await loginAs(page, `mod_axe_${uniqueSuffix}@example.com`, "Moderator", ["ROLE_MODERATOR"]);
    await page.goto(placeUrl);
    await expect(page.getByText(`Opinia do testu dostępności zgłoszenia ${uniqueSuffix}.`, { exact: true })).toBeVisible();

    // Scan Place Review and Discussion Sections
    await assertAccessible(page);

    // Open Report dialog and scan
    const reportBtn = page.getByRole("button", { name: "Zgłoś" }).first();
    await expect(reportBtn).toBeVisible();
    await reportBtn.click();
    await expect(page.getByRole("heading", { name: "Zgłoś naruszenie regulaminu" })).toBeVisible();
    await assertAccessible(page);
    await page.locator("#reason-select").click();
    await page.getByRole("option", { name: "Spam lub reklama" }).click();
    await page.getByRole("button", { name: "Wyślij zgłoszenie" }).click();
    await expect(page.getByText("Zgłoszenie wysłane")).toBeVisible();

    // 3. Community Feed
    await page.goto("/spolecznosc");
    await expect(page.getByRole("heading", { name: "Aktywność społeczności" })).toBeVisible();
    await assertAccessible(page);

    // 4. Forum Categories
    await page.goto("/forum");
    await expect(page.getByRole("heading", { name: "Forum Społeczności" })).toBeVisible();
    await assertAccessible(page);

    // 5. Forum Thread List (first category)
    await page.locator("a[href^='/forum/']").first().click();
    await expect(page).toHaveURL(/\/forum\/[^/]+$/);
    await assertAccessible(page);

    // Open Create Thread dialog and scan
    const newThreadBtn = page.getByRole("button", { name: "Nowy wątek" });
    await expect(newThreadBtn).toBeVisible();
    await newThreadBtn.click();
    await expect(page.getByRole("heading", { name: "Utwórz nowy wątek" })).toBeVisible();
    await assertAccessible(page);
    await page.keyboard.press("Escape");

    // 6. Moderator Queue
    await page.goto("/moderator/queue");
    await expect(page.getByRole("heading", { name: "Panel Moderatorów" })).toBeVisible();
    await assertAccessible(page);

    // 7. Moderation Case Detail (first case)
    const reportLink = page.locator("a[href^='/moderator/case/']").first();
    await expect(reportLink).toBeVisible();
    await reportLink.click();
    await expect(page.getByRole("heading", { name: "Zgłoszenie naruszenia" })).toBeVisible();
    await assertAccessible(page);
  });
});
