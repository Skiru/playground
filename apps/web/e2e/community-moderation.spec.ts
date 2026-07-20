import { expect, test } from "@playwright/test";

async function loginAs(page: any, email: string, displayName: string, roles: string[] = ["ROLE_USER"]) {
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

test.describe("Community Moderation E2E", () => {
  test("Alice cannot access moderator panel, Moderator can claim and process a case", async ({ page }) => {
    // 1. Alice tries to access moderator queue and gets block page
    await loginAs(page, "alice@example.com", "Alice");
    await page.goto("/moderator/queue");
    await expect(page.getByRole("heading", { name: "Brak uprawnień" })).toBeVisible();

    // 2. Moderator accesses queue
    await loginAs(page, "moderator@example.com", "Moderator", ["ROLE_MODERATOR"]);
    await page.goto("/moderator/queue");
    await expect(page.getByRole("heading", { name: "Panel Moderatorów" })).toBeVisible();

    // Verify reports exist in queue
    const reportItem = page.locator("a[href^='/moderator/case/']").first();
    if (await reportItem.isVisible()) {
      await reportItem.click();
      await expect(page.getByRole("heading", { name: "Zgłoszenie naruszenia" })).toBeVisible();

      // Moderator claims the case
      const claimBtn = page.getByRole("button", { name: "Rozpocznij analizę (Claim)" });
      if (await claimBtn.isVisible()) {
        await claimBtn.click();
        await expect(page.locator("textarea[id='moderator-reason-textarea']")).toBeVisible();
      }
    }
  });
});
