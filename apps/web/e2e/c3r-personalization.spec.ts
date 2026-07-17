import { expect, test } from "@playwright/test";

test.describe("C3R Personalization and Security E2E", () => {
  test("complete user journey: login, reload session, favorite, visit, edit, delete, logout", async ({ page }) => {
    // 1. Visit home page
    await page.goto("/");
    await expect(page.getByRole("button", { name: "Zaloguj się" }).first()).toBeVisible();

    // 2. Click Zaloguj się to open Login Dialog
    await page.getByRole("button", { name: "Zaloguj się" }).first().click();
    await expect(page.getByRole("heading", { name: "Logowanie" }).first()).toBeVisible();

    // 3. Click Bypass Login to authenticate deterministically
    await page.getByRole("button", { name: "Bypass Login (Fake User)" }).first().click();

    // 4. Verify successful login and session menu button in the header (initials DU)
    const duButton = page.getByRole("button", { name: "DU", exact: true }).filter({ visible: true });
    await expect(duButton).toBeVisible();

    // 5. Reload page to verify session persistence
    await page.reload();
    await expect(duButton).toBeVisible();

    // 6. Navigate to place list and details
    await page.goto("/miejsca?city=warszawa");
    await expect(page.locator(".place-card").first()).toBeVisible();

    // Get the name of the first place
    const firstPlaceLink = page.locator(".place-card h2 a").first();
    const firstPlaceName = await firstPlaceLink.textContent();
    
    // Toggle favorite on the list card
    const favButton = page.locator(".place-card").first().locator('button[title="Dodaj do ulubionych"]');
    await expect(favButton).toBeEnabled();
    await favButton.click({ force: true });
    
    // Verify toast notification for success
    await expect(page.getByText("Dodano do ulubionych!")).toBeVisible();

    // Verify button state changes to "Usuń z ulubionych" (aria-pressed=true)
    await expect(page.locator(".place-card").first().locator('button[aria-pressed="true"]')).toBeVisible();

    // Go to My Favorites page
    await page.goto("/konto/ulubione");
    await expect(page.getByRole("heading", { name: "Ulubione miejsca" })).toBeVisible();
    await expect(page.getByText(firstPlaceName || "").first()).toBeVisible();

    // Record a visit to this place
    await page.goto("/miejsca?city=warszawa");
    await page.locator(".place-card h2 a").first().click({ force: true });

    // Click "Byliśmy tutaj" button to open the visit form
    await page.getByRole("button", { name: "Byliśmy tutaj" }).first().click({ force: true });
    await expect(page.getByRole("heading", { name: "Zapisz wizytę" })).toBeVisible();

    // Fill notes and save
    await page.getByLabel("Notatki z pobytu (opcjonalnie)").fill("Fantastyczny czas z dziećmi!");
    await page.getByRole("button", { name: "Zapisz wizytę" }).click();

    // Verify success toast
    await expect(page.getByText("Wizyta została zapisana w historii!")).toBeVisible();

    // Go to Visited History page
    await page.goto("/konto/odwiedzone");
    await expect(page.getByRole("heading", { name: "Historia wizyt" })).toBeVisible();
    await expect(page.getByText(firstPlaceName || "").first()).toBeVisible();
    await expect(page.getByText("Fantastyczny czas z dziećmi!").first()).toBeVisible();

    // Edit the visit
    await page.getByLabel("Edytuj wizytę").first().click({ force: true });
    await expect(page.getByRole("heading", { name: "Edytuj wizytę" }).first()).toBeVisible();
    await page.locator("#note").fill("Zaktualizowana notatka: super zabawa!");
    await page.getByRole("button", { name: "Zapisz zmiany" }).first().click();

    // Verify update success
    await expect(page.getByText("Wizyta zaktualizowana pomyślnie!").first()).toBeVisible();
    await expect(page.getByText("Zaktualizowana notatka: super zabawa!").first()).toBeVisible();

    // Delete the visit
    await page.getByLabel("Usuń wizytę").first().click({ force: true });
    await expect(page.getByRole("heading", { name: "Czy na pewno chcesz usunąć tę wizytę?" }).first()).toBeVisible();
    await page.getByRole("button", { name: "Usuń trwale" }).first().click();

    // Verify deletion success
    await expect(page.getByText("Wizyta została usunięta z historii.")).toBeVisible();

    // 7. Logout and verify unauthenticated state
    await page.goto("/");
    await duButton.click();
    await page.getByRole("menuitem", { name: "Wyloguj się" }).click();
    
    // Verify logout success toast and original button
    await expect(page.getByText("Wylogowano pomyślnie.")).toBeVisible();
    await expect(page.getByRole("button", { name: "Zaloguj się" }).first()).toBeVisible();
  });
});
