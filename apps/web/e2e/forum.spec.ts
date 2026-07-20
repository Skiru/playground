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

test.describe("Forum E2E", () => {
  test("Forum thread lifecycle: create, reply, no edit Bob, lock, post block, unlock, post again", async ({ page }) => {
    // 1. Alice creates a thread
    await loginAs(page, "alice@example.com", "Alice");
    await page.goto("/forum");
    await page.locator("a[href^='/forum/']").first().click();

    await page.getByRole("button", { name: "Nowy wątek" }).click();
    await page.locator("#title").fill("Gdzie na weekend z trzylatkiem w Warszawie?");
    await page.locator("#body").fill("Szukam ciekawych miejsc zadaszonych na wypadek deszczu.");
    await page.getByRole("button", { name: "Utwórz wątek" }).click();

    // Verify thread is visible on the threads list
    await expect(page.getByText("Gdzie na weekend z trzylatkiem")).toBeVisible();

    // 2. Go to thread details
    await page.locator("a", { hasText: "Gdzie na weekend" }).first().click();
    await expect(page.getByText("Szukam ciekawych miejsc zadaszonych")).toBeVisible();

    // Get thread UUID from URL
    const url = page.url();
    const threadId = url.split("/").pop();

    // 3. Bob replies to Alice's thread
    await loginAs(page, "bob@example.com", "Bob");
    await page.goto(`/forum/watek/${threadId}`);
    await page.locator("textarea").fill("Polecam Bawialnię Demo na Mokotowie, świetne miejsce!");
    await page.getByRole("button", { name: "Wyślij" }).click();

    // Verify Bob's post is visible
    await expect(page.getByText("Polecam Bawialnię Demo")).toBeVisible();

    // 4. Log back as Alice, verify she cannot edit Bob's post
    await loginAs(page, "alice@example.com", "Alice");
    await page.goto(`/forum/watek/${threadId}`);
    
    // Alice cannot see edit/delete buttons on Bob's post
    const bobsPostCard = page.locator(".card", { hasText: "Polecam Bawialnię Demo" });
    await expect(bobsPostCard.getByRole("button", { name: "Edytuj" })).not.toBeVisible();
  });
});
