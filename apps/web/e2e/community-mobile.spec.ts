import { expect, test, devices, type Page } from "@playwright/test";

test.use({ ...devices["iPhone 14"] });

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

test.describe("Community Mobile Viewport E2E Real Journey", () => {
  test("Mobile forum post, reply, and reporting journey", async ({ page }) => {
    const uniqueSuffix = Math.random().toString(36).substring(7);
    const userEmail = `mobile_user_${uniqueSuffix}@example.com`;
    const threadTitle = `Mobile Thread ${uniqueSuffix}`;
    const threadBody = `Mobile thread body details ${uniqueSuffix}`;
    const replyBody = `Mobile reply details ${uniqueSuffix}`;

    // 1. Log in mobile user
    await loginAs(page, userEmail, "MobileUser");

    // 2. Open Forum via Mobile menu toggler
    await page.goto("/");
    await expect(page.getByRole("button", { name: "Toggle Menu" })).toBeVisible();
    await page.getByRole("button", { name: "Toggle Menu" }).click();
    
    await expect(page.getByRole("link", { name: "Forum" })).toBeVisible();
    await page.getByRole("link", { name: "Forum" }).click();

    // 3. Create Thread
    await expect(page.locator("a[href^='/forum/']").first()).toBeVisible();
    await page.locator("a[href^='/forum/']").first().click();
    await page.getByRole("button", { name: "Nowy wątek" }).click();
    await page.locator("#title").fill(threadTitle);
    await page.locator("#body").fill(threadBody);
    await page.getByRole("button", { name: "Utwórz wątek" }).click();

    // 4. Open Thread and Submit Reply
    await expect(page.getByText(threadTitle)).toBeVisible();
    await page.locator("a", { hasText: threadTitle }).first().click();
    await expect(page).toHaveURL(/\/forum\/watek\/[0-9a-f-]+$/);
    const threadUrl = page.url();
    await expect(page.getByText(threadBody)).toBeVisible();

    await page.locator("textarea").fill(replyBody);
    await page.getByRole("button", { name: "Wyślij" }).click();
    await expect(page.getByText(replyBody)).toBeVisible();

    // 5. A different mobile user reports the reply.
    await loginAs(page, `mobile_reporter_${uniqueSuffix}@example.com`, "MobileReporter");
    await page.goto(threadUrl);
    const replyCard = page.locator('[data-slot="card"]', { hasText: replyBody });
    const reportBtn = replyCard.getByRole("button", { name: "Zgłoś" });
    await reportBtn.click();
    await expect(page.getByRole("heading", { name: "Zgłoś naruszenie regulaminu" })).toBeVisible();

    await page.locator("#reason-select").click();
    await page.getByRole("option", { name: "Spam lub reklama" }).click();
    await page.locator("#details-textarea").fill("Reported from mobile!");
    await page.getByRole("button", { name: "Wyślij zgłoszenie" }).click();
    await expect(page.getByText("Zgłoszenie wysłane")).toBeVisible();

    // 6. Validate responsive controls and no horizontal overflow
    const overflow = await page.evaluate(() => {
      return document.documentElement.scrollWidth > window.innerWidth;
    });
    expect(overflow).toBe(false);
  });
});
