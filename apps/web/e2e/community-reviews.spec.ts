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

test.describe("Community Reviews E2E Real Journey", () => {
  test("Alice creates, edits, and deletes a review with rating recalculation validations", async ({ browser }) => {
    const uniqueSuffix = Math.random().toString(36).substring(7);
    const aliceEmail = `alice_review_${uniqueSuffix}@example.com`;
    const bobEmail = `bob_review_${uniqueSuffix}@example.com`;
    const reviewText = `Fantastyczne i bezpieczne miejsce dla dzieci ${uniqueSuffix}.`;
    const editedReviewText = `Fantastyczne i bezpieczne miejsce po edycji ${uniqueSuffix}.`;

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
    const initialReviewsResponsePromise = alicePage.waitForResponse((response) =>
      response.url().includes("/api/v1/places/") && response.url().includes("/reviews") && response.request().method() === "GET"
    );
    await alicePage.locator(".place-card h2 a").first().click();
    const initialReviewsResponse = await initialReviewsResponsePromise;
    const initialReviewsPayload = await initialReviewsResponse.json() as { summary: { totalReviews: number } };
    await expect(alicePage.getByRole("heading", { name: "Opinie i oceny rodziców" }).first()).toBeVisible();

    const placeUrl = alicePage.url();

    // 3. Read rating summary BEFORE creation
    const totalReviewsBefore = initialReviewsPayload.summary.totalReviews;
    await expect(alicePage.getByTestId("total-reviews-count").filter({ visible: true })).toHaveText(String(totalReviewsBefore));

    // 4. Alice adds a review (rating = 4)
    await alicePage.getByRole("button", { name: "Dodaj opinię" }).first().click();

    // Select 4 stars
    const starButtons = alicePage.locator("form button:has-text('★')");
    await starButtons.nth(3).click(); // Click the 4th star (0-indexed, so 4th star)

    await alicePage.locator("#review-form-body").fill(reviewText);
    const refreshedReviewsResponsePromise = alicePage.waitForResponse((response) =>
      response.url().includes("/api/v1/places/") && response.url().includes("/reviews") && response.request().method() === "GET"
    );
    await alicePage.getByRole("button", { name: "Zapisz opinię" }).click();
    await refreshedReviewsResponsePromise;

    // Verify review is visible
    await expect(alicePage.getByText(reviewText, { exact: true })).toBeVisible();

    // 5. Read rating summary AFTER creation (total reviews must be incremented by 1!)
    await expect(alicePage.getByTestId("total-reviews-count").filter({ visible: true })).toHaveText(String(totalReviewsBefore + 1));

    // 6. Alice edits the review and reload confirms persistence.
    const aliceReview = alicePage.locator("div.pt-4", { has: alicePage.getByText(reviewText, { exact: true }) });
    await aliceReview.getByRole("button", { name: "Edytuj" }).click();
    await alicePage.locator("#review-form-body").fill(editedReviewText);
    await alicePage.getByRole("button", { name: "Zapisz opinię" }).click();
    await expect(alicePage.getByText(editedReviewText, { exact: true })).toBeVisible();
    await alicePage.reload();
    await expect(alicePage.getByText(editedReviewText, { exact: true })).toBeVisible();

    // 7. Bob opens the same place details and verifies Bob cannot edit/delete Alice's review.
    await bobPage.goto(placeUrl);
    await expect(bobPage.getByText(editedReviewText, { exact: true })).toBeVisible();

    const aliceReviewCard = bobPage.locator("div.pt-4", { has: bobPage.getByText(editedReviewText, { exact: true }) });
    await expect(aliceReviewCard.getByRole("button", { name: "Edytuj" })).not.toBeVisible();
    await expect(aliceReviewCard.getByRole("button", { name: "Usuń" })).not.toBeVisible();

    // 8. Alice deletes her review and verifies rating summary has been recalculated.
    const editedReview = alicePage.locator("div.pt-4", { has: alicePage.getByText(editedReviewText, { exact: true }) });
    await editedReview.getByRole("button", { name: "Usuń" }).click();

    // Confirm delete inside the custom accessible dialog
    await alicePage.getByRole("alert").getByRole("button", { name: "Usuń", exact: true }).click();

    // Verify review is deleted and no longer visible
    await expect(alicePage.getByText(editedReviewText, { exact: true })).not.toBeVisible();

    // Summary must go back to initial count
    await expect(alicePage.getByTestId("total-reviews-count").filter({ visible: true })).toHaveText(String(totalReviewsBefore));

    // 9. Close contexts
    await aliceCtx.close();
    await bobCtx.close();
  });
});
