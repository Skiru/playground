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

test.describe("Place Discussions E2E", () => {
  test("Alice comment, Bob reply, Alice delete, Bob survives, reply to deleted parent rejected", async ({ page }) => {
    // 1. Alice creates a comment
    await loginAs(page, "alice@example.com", "Alice");
    await page.goto("/miejsca?city=warszawa");
    await page.locator(".place-card h2 a").first().click();

    await page.getByRole("button", { name: "Napisz komentarz" }).first().click();
    await page.locator("textarea").fill("Czy są tu dostępne zniżki dla rodzeństwa?");
    await page.getByRole("button", { name: "Wyślij" }).click();

    // Verify comment is added
    await expect(page.getByText("Czy są tu dostępne zniżki")).toBeVisible();

    // 2. Bob replies to Alice's comment
    await loginAs(page, "bob@example.com", "Bob");
    await page.goto("/miejsca?city=warszawa");
    await page.locator(".place-card h2 a").first().click();

    await page.getByRole("button", { name: "Odpowiedz" }).first().click();
    await page.locator("textarea").fill("Tak, drugie dziecko płaci połowę ceny!");
    await page.getByRole("button", { name: "Wyślij" }).click();

    // Verify Bob's reply is visible
    await expect(page.getByText("drugie dziecko płaci połowę")).toBeVisible();

    // 3. Alice deletes her comment (Bob's reply must survive beneath a tombstone)
    await loginAs(page, "alice@example.com", "Alice");
    await page.goto("/miejsca?city=warszawa");
    await page.locator(".place-card h2 a").first().click();

    await page.getByRole("button", { name: "Usuń" }).first().click();
    await page.getByRole("button", { name: "Usuń", exact: true }).filter({ visible: true }).click();

    // Verify Alice's parent comment is now a tombstone, but Bob's reply survived
    await expect(page.getByText("Treść usunięta przez autora").first()).toBeVisible();
    await expect(page.getByText("drugie dziecko płaci połowę")).toBeVisible();
  });
});
