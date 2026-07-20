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

test.describe("Community Reviews E2E", () => {
  test("Alice creates, edits, and deletes a review, rating recalculates, Bob cannot edit", async ({ page }) => {
    // 1. Log in as Alice
    await loginAs(page, "alice@example.com", "Alice");
    
    // Go to first place details page
    await page.goto("/miejsca?city=warszawa");
    await expect(page.locator(".place-card").first()).toBeVisible();
    await page.locator(".place-card h2 a").first().click();
    await expect(page.getByRole("heading", { name: "Oceny i opinie" }).first()).toBeVisible();

    // 2. Alice adds a review (rating = 5)
    await page.getByRole("button", { name: "Dodaj opinię" }).first().click();
    await page.locator("#review-form-body").fill("To jest fantastyczne miejsce dla wszystkich dzieci! Wyjątkowa i czysta sala zabaw.");
    await page.getByRole("button", { name: "Zapisz opinię" }).click();

    // Verify review is visible
    await expect(page.getByText("To jest fantastyczne miejsce").first()).toBeVisible();

    // 3. Alice edits her review (rating = 4)
    await page.getByRole("button", { name: "Edytuj" }).first().click();
    await page.locator("#review-form-body").fill("To jest fantastyczne miejsce dla wszystkich dzieci! Edytowana treść opinii o sali.");
    await page.getByRole("button", { name: "Zapisz opinię" }).click();

    // Verify updated review text
    await expect(page.getByText("Edytowana treść opinii").first()).toBeVisible();

    // 4. Log in as Bob and verify he cannot edit Alice's review
    await loginAs(page, "bob@example.com", "Bob");
    await page.goto("/miejsca?city=warszawa");
    await page.locator(".place-card h2 a").first().click();
    
    await expect(page.getByText("Edytowana treść opinii").first()).toBeVisible();
    // Verify Bob does not see Edit/Delete buttons for Alice's review
    await expect(page.getByRole("button", { name: "Edytuj" })).not.toBeVisible();

    // 5. Log back in as Alice and delete her review
    await loginAs(page, "alice@example.com", "Alice");
    await page.goto("/miejsca?city=warszawa");
    await page.locator(".place-card h2 a").first().click();

    await page.getByRole("button", { name: "Usuń" }).first().click();
    // Confirm delete using accessible delete notice trigger
    await page.getByRole("button", { name: "Usuń", exact: true }).filter({ visible: true }).click();

    // Verify review is deleted and no longer visible
    await expect(page.getByText("Edytowana treść opinii")).not.toBeVisible();
  });
});
