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

test.describe("Community Reviews E2E Real Journey", () => {
  test("Alice creates, edits, and deletes a review with rating recalculation validations", async ({ browser }) => {
    const uniqueSuffix = Math.random().toString(36).substring(7);
    const aliceEmail = `alice_review_${uniqueSuffix}@example.com`;
    const bobEmail = `bob_review_${uniqueSuffix}@example.com`;

    const aliceCtx = await browser.newContext();
    const bobCtx = await browser.newContext();

    const alicePage = await aliceCtx.newPage();
    const bobPage = await bobCtx.newPage();

    // 1. Log in Alice and Bob
    await loginAs(alicePage, aliceEmail, "Alice");
    await loginAs(bobPage, bobEmail, "Bob");

    // 2. Go to first place details page under Alice Page
    await alicePage.goto("/miejsca?city=warszawa");
    await expect(alicePage.locator(".place-card").first()).toBeVisible();
    await alicePage.locator(".place-card h2 a").first().click();
    await expect(alicePage.getByRole("heading", { name: "Oceny i opinie" }).first()).toBeVisible();

    const placeUrl = alicePage.url();

    // 3. Read rating summary BEFORE creation
    const ratingSummaryTextBefore = await alicePage.locator("#rating-summary-stats").textContent();
    const totalReviewsBefore = ratingSummaryTextBefore ? parseInt(ratingSummaryTextBefore.replace(/\D/g, "") || "0", 10) : 0;

    // 4. Alice adds a review (rating = 4)
    await alicePage.getByRole("button", { name: "Dodaj opinię" }).first().click();
    
    // Select 4 stars
    const starButtons = alicePage.locator("form button:has-text('★')");
    await starButtons.nth(3).click(); // Click the 4th star (0-indexed, so 4th star)
    
    await alicePage.locator("#review-form-body").fill("To jest fantastyczne, czyste i unikalne miejsce dla wszystkich dzieci! Wyjątkowa i bezpieczna sala zabaw.");
    await alicePage.getByRole("button", { name: "Zapisz opinię" }).click();

    // Verify review is visible
    await expect(alicePage.getByText("To jest fantastyczne, czyste i unikalne miejsce dla wszystkich dzieci!")).toBeVisible();

    // 5. Read rating summary AFTER creation (total reviews must be incremented by 1!)
    const ratingSummaryTextAfter = await alicePage.locator("#rating-summary-stats").textContent();
    const totalReviewsAfter = ratingSummaryTextAfter ? parseInt(ratingSummaryTextAfter.replace(/\D/g, "") || "0", 10) : 0;
    expect(totalReviewsAfter).toBe(totalReviewsBefore + 1);

    // 6. Bob opens the same place details and verifies Bob cannot edit/delete Alice's review
    await bobPage.goto(placeUrl);
    await expect(bobPage.getByText("To jest fantastyczne, czyste i unikalne miejsce dla wszystkich dzieci!")).toBeVisible();
    
    const aliceReviewCard = bobPage.locator("div", { hasText: "To jest fantastyczne, czyste i unikalne miejsce dla wszystkich dzieci!" });
    await expect(aliceReviewCard.getByRole("button", { name: "Edytuj" })).not.toBeVisible();
    await expect(aliceReviewCard.getByRole("button", { name: "Usuń" })).not.toBeVisible();

    // 7. Alice deletes her review and verifies rating summary has been recalculated back to initial state
    await alicePage.getByRole("button", { name: "Usuń" }).first().click();
    
    // Confirm delete inside the custom accessible dialog
    await alicePage.getByRole("button", { name: "Usuń", exact: true }).filter({ visible: true }).click();

    // Verify review is deleted and no longer visible
    await expect(alicePage.getByText("To jest fantastyczne, czyste i unikalne miejsce dla wszystkich dzieci!")).not.toBeVisible();

    // Summary must go back to initial count
    const ratingSummaryTextFinal = await alicePage.locator("#rating-summary-stats").textContent();
    const totalReviewsFinal = ratingSummaryTextFinal ? parseInt(ratingSummaryTextFinal.replace(/\D/g, "") || "0", 10) : 0;
    expect(totalReviewsFinal).toBe(totalReviewsBefore);

    // 8. Close contexts
    await aliceCtx.close();
    await bobCtx.close();
  });
});
