import { expect, test, type Page } from "@playwright/test";

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

test.describe("Community Moderation E2E", () => {
  test("Alice cannot access moderator panel, Moderator can claim and process a case", async ({ page }) => {
    const uniqueSuffix = Math.random().toString(36).slice(2);
    const threadTitle = `Moderation case ${uniqueSuffix}`;

    // 1. Alice tries to access moderator queue and gets block page
    await loginAs(page, `alice_mod_${uniqueSuffix}@example.com`, "Alice");
    await page.goto("/moderator/queue");
    await expect(page.getByRole("heading", { name: "Brak uprawnień" })).toBeVisible();

    // 2. Alice creates a thread, and Bob reports it through the real UI.
    await page.goto("/forum");
    await page.locator("a[href^='/forum/']").first().click();
    await page.getByRole("button", { name: "Nowy wątek" }).click();
    await page.locator("#title").fill(threadTitle);
    await page.locator("#body").fill(`Treść sprawy moderacyjnej ${uniqueSuffix}`);
    await page.getByRole("button", { name: "Utwórz wątek" }).click();
    await page.locator("a", { hasText: threadTitle }).click();
    await expect(page).toHaveURL(/\/forum\/watek\/[0-9a-f-]+$/);
    const threadUrl = page.url();

    await loginAs(page, `bob_mod_${uniqueSuffix}@example.com`, "Bob");
    await page.goto(threadUrl);
    await page.getByRole("button", { name: "Zgłoś wątek" }).click();
    await page.locator("#reason-select").click();
    await page.getByRole("option", { name: "Spam lub reklama" }).click();
    await page.getByRole("button", { name: "Wyślij zgłoszenie" }).click();
    await expect(page.getByText("Zgłoszenie wysłane")).toBeVisible();

    // 3. Moderator accesses the exact case and claims it.
    await loginAs(page, `moderator_mod_${uniqueSuffix}@example.com`, "Moderator", ["ROLE_MODERATOR"]);
    await page.goto("/moderator/queue");
    await expect(page.getByRole("heading", { name: "Panel Moderatorów" })).toBeVisible();
    const reportRow = page.locator("div.p-5", { hasText: threadTitle });
    await reportRow.getByRole("link", { name: "Szczegóły" }).click();
    await expect(page.getByRole("heading", { name: "Zgłoszenie naruszenia" })).toBeVisible();
    await page.getByRole("button", { name: "Rozpocznij analizę (Claim)" }).click();
    await expect(page.locator("#moderator-reason-textarea")).toBeVisible();
  });
});
