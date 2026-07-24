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

test.describe("Forum E2E Real Journey", () => {
  test("Complete multi-user forum and moderator workflow", async ({ browser }) => {
    const uniqueSuffix = Math.random().toString(36).substring(7);
    const threadTitle = `Wątek E2E ${uniqueSuffix}`;
    const threadBody = `Treść pierwszego posta w wątku E2E ${uniqueSuffix}`;
    const replyText = `Odpowiedź Boba E2E ${uniqueSuffix}`;
    const postSecret = `TAJNY_MARKER_${uniqueSuffix}`;

    // 1. Create separate contexts
    const aliceCtx = await browser.newContext();
    const bobCtx = await browser.newContext();
    const modCtx = await browser.newContext();

    const alicePage = await aliceCtx.newPage();
    const bobPage = await bobCtx.newPage();
    const modPage = await modCtx.newPage();

    // 2. Log in each user separately
    await loginAs(alicePage, `alice_${uniqueSuffix}@example.com`, "Alice");
    await loginAs(bobPage, `bob_${uniqueSuffix}@example.com`, "Bob");
    await loginAs(modPage, `mod_${uniqueSuffix}@example.com`, "Moderator", ["ROLE_MODERATOR"]);

    // 3. Alice creates a thread
    await alicePage.goto("/forum");
    await alicePage.locator("a[href^='/forum/']").first().click();
    await alicePage.getByRole("button", { name: "Nowy wątek" }).click();
    await alicePage.locator("#title").fill(threadTitle);
    await alicePage.locator("#body").fill(threadBody);
    await alicePage.getByRole("button", { name: "Utwórz wątek" }).click();

    // Verify thread title is visible
    await expect(alicePage.getByText(threadTitle)).toBeVisible();

    // Go to thread details
    await alicePage.locator("a", { hasText: threadTitle }).first().click();
    await expect(alicePage).toHaveURL(/\/forum\/watek\/[0-9a-f-]+$/);
    const threadUrl = alicePage.url();
    const threadId = threadUrl.split("/").pop();

    // 4. Bob opens the same thread and replies
    await bobPage.goto(threadUrl);
    await expect(bobPage.getByText(threadBody)).toBeVisible();
    await bobPage.locator("textarea").fill(`${replyText} ${postSecret}`);
    await bobPage.getByRole("button", { name: "Wyślij" }).click();

    // Verify Bob's reply is visible
    await expect(bobPage.getByText(replyText)).toBeVisible();

    const bobsPostCard = bobPage.locator('[data-slot="card"]', { hasText: replyText });
    await expect(bobsPostCard).toBeVisible();

    // 5. Alice attempts to edit Bob's post (Edit button must NOT be visible)
    await alicePage.goto(`/forum/watek/${threadId}`);
    await expect(alicePage.locator('[data-slot="card"]', { hasText: replyText }).getByRole("button", { name: "Edytuj" })).not.toBeVisible();

    // Alice reports Bob's post
    const reportBtn = alicePage.locator('[data-slot="card"]', { hasText: replyText }).getByRole("button", { name: "Zgłoś" });
    await reportBtn.click();
    await expect(alicePage.getByRole("heading", { name: "Zgłoś naruszenie regulaminu" })).toBeVisible();
    await alicePage.locator("#reason-select").click();
    await alicePage.getByRole("option", { name: "Spam lub reklama" }).click();
    await alicePage.locator("#details-textarea").fill("To jest spam!");
    await alicePage.getByRole("button", { name: "Wyślij zgłoszenie" }).click();
    await expect(alicePage.getByText("Zgłoszenie wysłane")).toBeVisible();

    // Let's grab Bob's reported post ID
    const urlParts = alicePage.url().split("/");
    const tId = urlParts[urlParts.length - 1];

    // 6. Moderator sees the exact report in the queue
    await modPage.goto("/moderator/queue");
    await expect(modPage.getByRole("heading", { name: "Panel Moderatorów" })).toBeVisible();
    
    const reportRow = modPage.locator("div.p-5", { hasText: replyText });
    const reportLink = reportRow.getByRole("link", { name: "Szczegóły" });
    await expect(reportLink).toBeVisible();
    await reportLink.click();
    await expect(modPage).toHaveURL(/\/moderator\/case\/[0-9a-f-]+$/);

    // Moderator claims the case
    await expect(modPage.getByRole("heading", { name: "Zgłoszenie naruszenia" })).toBeVisible();
    await modPage.getByRole("button", { name: "Rozpocznij analizę (Claim)" }).click();

    // 7. Moderator hides the reported post.
    await modPage.locator("#moderator-action-select").click();
    await modPage.getByRole("option", { name: "Ukryj treść (HIDE)" }).click();
    await modPage.locator("#moderator-reason-textarea").fill("Hiding Bob's post");
    await modPage.getByRole("button", { name: "Zatwierdź decyzję" }).click();
    await expect(modPage.getByRole("heading", { name: "Decyzja zapisana" })).toBeVisible();

    // 8. The post's unique secret disappears from the public thread and feed.
    await bobPage.goto(`/forum/watek/${threadId}`);
    await expect(bobPage.getByText(postSecret)).not.toBeVisible();

    await bobPage.goto("/spolecznosc");
    await expect(bobPage.getByText(postSecret)).not.toBeVisible();

    // 9. Close contexts
    await aliceCtx.close();
    await bobCtx.close();
    await modCtx.close();
  });
});
