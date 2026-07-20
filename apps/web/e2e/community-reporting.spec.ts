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

test.describe("Community Reporting E2E", () => {
  test("Alice reports content, dialog renders states correctly", async ({ page }) => {
    await loginAs(page, "alice@example.com", "Alice");
    
    // Go to a place with reviews/comments
    await page.goto("/miejsca?city=warszawa");
    await page.locator(".place-card h2 a").first().click();

    // Find a report button (e.g. from comments or reviews)
    const reportBtn = page.getByRole("button", { name: "Zgłoś" }).first();
    if (await reportBtn.isVisible()) {
      await reportBtn.click();
      await expect(page.getByRole("heading", { name: "Zgłoś naruszenie regulaminu" })).toBeVisible();

      // Choose reason and submit
      await page.locator("#reason-select").click();
      await page.getByRole("option", { name: "Spam lub reklama" }).click();
      await page.locator("#details-textarea").fill("Spam details.");
      await page.getByRole("button", { name: "Wyślij zgłoszenie" }).click();

      // Check success state
      await expect(page.getByText("Zgłoszenie wysłane")).toBeVisible();
    }
  });
});
