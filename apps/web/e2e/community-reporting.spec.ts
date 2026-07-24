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

test.describe("Community Reporting E2E Real Journey", () => {
  test("Report creation, duplicate handling, and validation states", async ({ browser }) => {
    const uniqueSuffix = Math.random().toString(36).substring(7);
    const aliceEmail = `alice_rep_${uniqueSuffix}@example.com`;
    const bobEmail = `bob_rep_${uniqueSuffix}@example.com`;
    const moderatorEmail = `mod_rep_${uniqueSuffix}@example.com`;

    const aliceCtx = await browser.newContext();
    const bobCtx = await browser.newContext();
    const moderatorCtx = await browser.newContext();

    const alicePage = await aliceCtx.newPage();
    const bobPage = await bobCtx.newPage();
    const moderatorPage = await moderatorCtx.newPage();

    // 1. Log in users
    await loginAs(alicePage, aliceEmail, "Alice");
    await loginAs(bobPage, bobEmail, "Bob");
    await loginAs(moderatorPage, moderatorEmail, "Moderator", ["ROLE_MODERATOR"]);

    // 2. Bob creates a thread to be reported
    await bobPage.goto("/forum");
    await bobPage.locator("a[href^='/forum/']").first().click();
    await bobPage.getByRole("button", { name: "Nowy wątek" }).click();
    await bobPage.locator("#title").fill(`Reporting Thread ${uniqueSuffix}`);
    await bobPage.locator("#body").fill(`This is the content to be reported ${uniqueSuffix}`);
    await bobPage.getByRole("button", { name: "Utwórz wątek" }).click();

    await expect(bobPage.getByText(`Reporting Thread ${uniqueSuffix}`)).toBeVisible();
    await bobPage.locator("a", { hasText: `Reporting Thread ${uniqueSuffix}` }).first().click();
    await expect(bobPage).toHaveURL(/\/forum\/watek\/[0-9a-f-]+$/);
    const threadUrl = bobPage.url();
    // 3. Alice reports Bob's thread
    await alicePage.goto(threadUrl);
    await expect(alicePage.getByText(`This is the content to be reported ${uniqueSuffix}`, { exact: true })).toBeVisible();

    // Alice clicks report button
    const reportBtn = alicePage.getByRole("button", { name: "Zgłoś" }).first();
    await reportBtn.click();
    await expect(alicePage.getByRole("heading", { name: "Zgłoś naruszenie regulaminu" })).toBeVisible();

    // Choose reason and submit
    await alicePage.locator("#reason-select").click();
    await alicePage.getByRole("option", { name: "Spam lub reklama" }).click();
    await alicePage.locator("#details-textarea").fill("This is a reported thread E2E!");
    await alicePage.getByRole("button", { name: "Wyślij zgłoszenie" }).click();

    // Assert success dialog is shown
    await expect(alicePage.getByText("Zgłoszenie wysłane")).toBeVisible();

    // 4. Row exists in moderator queue
    await moderatorPage.goto("/moderator/queue");
    await expect(moderatorPage.getByText(`Reporting Thread ${uniqueSuffix}`)).toBeVisible();

    // 5. Duplicate report returns 409
    // Alice attempts to report Bob's thread again - she shouldn't even be able to open dialog since we show reported state or we can call API directly
    await alicePage.goto(threadUrl);
    await alicePage.getByRole("button", { name: "Zgłoś" }).first().click();
    await expect(alicePage.getByRole("heading", { name: "Zgłoś naruszenie regulaminu" })).toBeVisible();
    await alicePage.locator("#reason-select").click();
    await alicePage.getByRole("option", { name: "Spam lub reklama" }).click();
    await alicePage.locator("#details-textarea").fill("Reporting again!");
    await alicePage.getByRole("button", { name: "Wyślij zgłoszenie" }).click();

    // It should display duplicate error message in dialog
    await expect(alicePage.getByText("Ta treść została już przez Ciebie zgłoszona i jest weryfikowana.")).toBeVisible();

    // 6. Close contexts
    await aliceCtx.close();
    await bobCtx.close();
    await moderatorCtx.close();
  });
});
