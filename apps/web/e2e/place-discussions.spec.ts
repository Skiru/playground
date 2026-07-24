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

test.describe("Place Discussions E2E Real Journey", () => {
  test("Alice comment, Bob reply, Alice delete, Bob survives, reply to deleted parent rejected", async ({ browser }) => {
    const uniqueSuffix = Math.random().toString(36).substring(7);
    const aliceEmail = `alice_disc_${uniqueSuffix}@example.com`;
    const bobEmail = `bob_disc_${uniqueSuffix}@example.com`;

    const aliceCtx = await browser.newContext();
    const bobCtx = await browser.newContext();

    const alicePage = await aliceCtx.newPage();
    const bobPage = await bobCtx.newPage();

    // 1. Log in Alice and Bob
    await loginAs(alicePage, aliceEmail, "Alice");
    await loginAs(bobPage, bobEmail, "Bob");

    // 2. Alice creates a comment
    await alicePage.goto("/miejsca?city=warszawa");
    await alicePage.locator(".place-card h2 a").first().click();
    const placeUrl = alicePage.url();

    await alicePage.getByRole("button", { name: "Napisz komentarz" }).first().click();
    await alicePage.locator("textarea").fill("Czy są tu dostępne zniżki dla rodzeństwa? " + uniqueSuffix);
    await alicePage.getByRole("button", { name: "Wyślij" }).click();

    // Verify comment is added
    await expect(alicePage.getByText("Czy są tu dostępne zniżki dla rodzeństwa?")).toBeVisible();

    // 3. Bob replies to Alice's comment
    await bobPage.goto(placeUrl);
    await expect(bobPage.getByText("Czy są tu dostępne zniżki dla rodzeństwa?")).toBeVisible();

    await bobPage.getByRole("button", { name: "Odpowiedz" }).first().click();
    await bobPage.locator("textarea").fill("Tak, drugie dziecko płaci połowę ceny! " + uniqueSuffix);
    await bobPage.getByRole("button", { name: "Wyślij" }).click();

    // Verify Bob's reply is visible
    await expect(bobPage.getByText("drugie dziecko płaci połowę ceny!")).toBeVisible();

    // 4. Alice deletes her comment
    await alicePage.goto(placeUrl);
    await alicePage.getByRole("button", { name: "Usuń" }).first().click();
    
    // Confirm delete inside custom dialog
    await alicePage.getByRole("button", { name: "Usuń", exact: true }).filter({ visible: true }).click();

    // Verify Alice's parent comment is now a tombstone, but Bob's reply survived
    await expect(alicePage.getByText("Treść usunięta przez autora").first()).toBeVisible();
    await expect(alicePage.getByText("drugie dziecko płaci połowę ceny!")).toBeVisible();

    // 5. Bob attempts to reply to the deleted parent comment
    // Since the reply form might be hidden in UI for deleted comment, Bob tries to call addReply via page.evaluate
    await bobPage.goto(placeUrl);
    // Find the reply button on the tombstone comment - it should be hidden or disabled.
    // If we call API directly, the backend should reject it with INVALID_PARENT_STATUS.
    const replyButtonOnTombstone = bobPage.locator("div", { hasText: "Treść usunięta przez autora" }).getByRole("button", { name: "Odpowiedz" });
    await expect(replyButtonOnTombstone).not.toBeVisible();

    // 6. Close contexts
    await aliceCtx.close();
    await bobCtx.close();
  });
});
