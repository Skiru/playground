import { expect, test, type Page } from "@playwright/test";

async function loginAs(page: Page, email: string, displayName: string, roles: string[] = ["ROLE_USER"]) {
  await page.goto("/", { waitUntil: "domcontentloaded" });
  await page.waitForLoadState("networkidle");
  const ok = await page.evaluate(async (data: { email: string; displayName: string; roles: string[] }) => {
    const res = await fetch("/resources/auth/dev-login", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(data),
    });
    return res.ok;
  }, { email, displayName, roles });
  expect(ok).toBeTruthy();
  await page.goto("/");
}

test.describe("Community Moderation E2E", () => {
  test("Alice is blocked and moderator resolves a claimed case without changing content", async ({ page }) => {
    const uniqueSuffix = Math.random().toString(36).slice(2);
    const threadTitle = `Moderation case ${uniqueSuffix}`;

    // 1. Alice tries to access moderator queue and gets block page
    await loginAs(page, `alice_mod_${uniqueSuffix}@example.com`, "Alice");
    await page.goto("/moderator/queue");
    await expect(page.getByRole("heading", { name: "Brak uprawnień" })).toBeVisible();

    // 2. Alice creates a thread, and Bob reports it through the real UI.
    await page.goto("/forum");
    await page.locator("a.group[href^='/forum/']").first().click();
    await page.getByRole("button", { name: "Nowy wątek" }).click();
    await page.locator("#title").fill(threadTitle);
    await page.locator("#body").fill(`Treść sprawy moderacyjnej ${uniqueSuffix}`);
    await page.getByRole("button", { name: "Utwórz wątek" }).click();
    const threadLink1 = page.locator("a", { hasText: threadTitle });
    await expect(threadLink1).toBeVisible();
    await threadLink1.click();
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
    await page.locator("#moderator-action-select").click();
    await page.getByRole("option", { name: /RESOLVE/ }).click();
    await page.locator("#moderator-reason-textarea").fill("Zgłoszenie przeanalizowane, treść pozostaje bez zmian.");
    await page.getByRole("button", { name: "Zatwierdź decyzję" }).click();
    await expect(page.getByText("Decyzja zapisana")).toBeVisible();
    await expect(page.getByText("Sprawa została już zamknięta.")).toBeVisible({ timeout: 10_000 });
    await page.reload();
    await expect(page.getByText("RESOLVED", { exact: true })).toBeVisible();

    await page.goto(threadUrl);
    await expect(page.getByText(`Treść sprawy moderacyjnej ${uniqueSuffix}`, { exact: true })).toBeVisible();
  });

  test("two independent moderators race to claim and only one owns the case", async ({ browser }) => {
    test.setTimeout(60_000);
    const suffix = Math.random().toString(36).slice(2);
    const authorContext = await browser.newContext();
    const firstModeratorContext = await browser.newContext();
    const secondModeratorContext = await browser.newContext();
    const authorPage = await authorContext.newPage();
    const firstModeratorPage = await firstModeratorContext.newPage();
    const secondModeratorPage = await secondModeratorContext.newPage();
    const title = `Claim race ${suffix}`;

    await loginAs(authorPage, `race_author_${suffix}@example.com`, "Race Author");
    await authorPage.goto("/forum");
    await authorPage.locator("a.group[href^='/forum/']").first().click();
    await authorPage.getByRole("button", { name: "Nowy wątek" }).click();
    await authorPage.locator("#title").fill(title);
    await authorPage.locator("#body").fill(`Claim race body ${suffix}`);
    await authorPage.getByRole("button", { name: "Utwórz wątek" }).click();
    const threadLink2 = authorPage.locator("a", { hasText: title });
    await expect(threadLink2).toBeVisible();
    await threadLink2.click();
    await expect(authorPage).toHaveURL(/\/forum\/watek\/[0-9a-f-]+$/);
    const threadUrl = authorPage.url();

    await loginAs(authorPage, `race_reporter_${suffix}@example.com`, "Race Reporter");
    await authorPage.goto(threadUrl);
    await authorPage.getByRole("button", { name: "Zgłoś wątek" }).click();
    await authorPage.locator("#reason-select").click();
    await authorPage.getByRole("option", { name: "Spam lub reklama" }).click();
    await authorPage.getByRole("button", { name: "Wyślij zgłoszenie" }).click();
    await expect(authorPage.getByText("Zgłoszenie wysłane")).toBeVisible();

    await loginAs(firstModeratorPage, `race_mod_1_${suffix}@example.com`, "Race Moderator One", ["ROLE_MODERATOR"]);
    await firstModeratorPage.goto("/moderator/queue");
    await firstModeratorPage.locator("div.p-5", { hasText: title }).getByRole("link", { name: "Szczegóły" }).click();
    await expect(firstModeratorPage).toHaveURL(/\/moderator\/case\/[0-9a-f-]+$/);
    const caseUrl = firstModeratorPage.url();
    await loginAs(secondModeratorPage, `race_mod_2_${suffix}@example.com`, "Race Moderator Two", ["ROLE_MODERATOR"]);
    await secondModeratorPage.goto(caseUrl);

    const firstClaim = firstModeratorPage.getByRole("button", { name: "Rozpocznij analizę (Claim)" });
    const secondClaim = secondModeratorPage.getByRole("button", { name: "Rozpocznij analizę (Claim)" });
    await Promise.all([expect(firstClaim).toBeVisible(), expect(secondClaim).toBeVisible()]);

    await Promise.all([
      firstClaim.click(),
      secondClaim.click(),
    ]);
    await expect.poll(async () => {
      const firstOwns = await firstModeratorPage.locator("#moderator-reason-textarea").isVisible();
      const secondOwns = await secondModeratorPage.locator("#moderator-reason-textarea").isVisible();
      return Number(firstOwns) + Number(secondOwns);
    }).toBe(1);
    const firstOwns = await firstModeratorPage.locator("#moderator-reason-textarea").isVisible();
    const secondOwns = await secondModeratorPage.locator("#moderator-reason-textarea").isVisible();
    const losingPage = firstOwns ? secondModeratorPage : firstModeratorPage;
    await losingPage.reload();
    await expect(losingPage.getByText("Ta sprawa jest przypisana do innego moderatora.")).toBeVisible();

    await Promise.all([authorContext.close(), firstModeratorContext.close(), secondModeratorContext.close()]);
  });

  test("moderation retries reuse the same idempotency key", async ({ page }) => {
    const uniqueSuffix = Math.random().toString(36).slice(2);
    const threadTitle = `Retry idempotency ${uniqueSuffix}`;
    const observedKeys: string[] = [];
    let moderationAttempts = 0;

    await loginAs(page, `retry-author_${uniqueSuffix}@example.com`, "Retry Author");
    await page.goto("/forum");
    await page.locator("a.group[href^='/forum/']").first().click();
    await page.getByRole("button", { name: "Nowy wątek" }).click();
    await page.locator("#title").fill(threadTitle);
    await page.locator("#body").fill(`Retry body ${uniqueSuffix}`);
    await page.getByRole("button", { name: "Utwórz wątek" }).click();
    const threadLink3 = page.locator("a", { hasText: threadTitle });
    await expect(threadLink3).toBeVisible();
    await threadLink3.click();
    await expect(page).toHaveURL(/\/forum\/watek\/[0-9a-f-]+$/);
    const threadUrl = page.url();

    await loginAs(page, `retry-reporter_${uniqueSuffix}@example.com`, "Retry Reporter");
    await page.goto(threadUrl);
    await page.getByRole("button", { name: "Zgłoś wątek" }).click();
    await page.locator("#reason-select").click();
    await page.getByRole("option", { name: "Spam lub reklama" }).click();
    await page.getByRole("button", { name: "Wyślij zgłoszenie" }).click();
    await expect(page.getByText("Zgłoszenie wysłane")).toBeVisible();

    await loginAs(page, `retry-moderator_${uniqueSuffix}@example.com`, "Retry Moderator", ["ROLE_MODERATOR"]);
    await page.goto("/moderator/queue");
    await page.locator("div.p-5", { hasText: threadTitle }).getByRole("link", { name: "Szczegóły" }).click();
    await page.getByRole("button", { name: "Rozpocznij analizę (Claim)" }).click();

    await page.route("**/api/v1/moderation/action", async (route) => {
      observedKeys.push(route.request().headers()["idempotency-key"] ?? "");
      moderationAttempts += 1;
      if (moderationAttempts === 1) {
        await route.abort("failed");
        return;
      }

      await route.continue();
    });

    await page.locator("#moderator-action-select").click();
    await page.getByRole("option", { name: /RESOLVE/ }).click();
    await page.locator("#moderator-reason-textarea").fill("Retry the exact same moderation request.");
    await page.getByRole("button", { name: "Zatwierdź decyzję" }).click();
    await expect(page.getByText("Wystąpił błąd")).toBeVisible();

    await page.getByRole("button", { name: "Zatwierdź decyzję" }).click();
    await expect(page.getByText("Decyzja zapisana")).toBeVisible();

    expect(observedKeys).toHaveLength(2);
    expect(observedKeys[0]).not.toBe("");
    expect(observedKeys[0]).toBe(observedKeys[1]);
  });
});
