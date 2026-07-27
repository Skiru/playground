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

test.describe("C3R Personalization and Security E2E", () => {
  test("complete user journey: login, reload session, favorite, visit, edit, delete, logout", async ({ page }, testInfo) => {
    const uniqueSuffix = `${testInfo.project.name}-${Date.now()}`.replace(/[^a-zA-Z0-9-]/g, "-").toLowerCase();

    // 1. Authenticate deterministically with an isolated test user.
    await loginAs(page, `c3r_${uniqueSuffix}@example.com`, "Demo User");

    // 2. Verify successful login and session menu button in the header.
    const userMenuButton = page.getByTestId("user-menu-button").filter({ visible: true });
    await expect(userMenuButton).toBeVisible();

    // 3. Reload page to verify session persistence.
    await page.reload();
    await expect(userMenuButton).toBeVisible();

    // 4. Navigate to place list and details.
    await page.goto("/miejsca?city=warszawa");
    await expect(page.locator(".place-card").first()).toBeVisible();

    // Get the name of the first place
    const firstPlaceLink = page.locator(".place-card").first().getByRole("link", { name: /Zobacz miejsce:/ });
    const firstPlaceName = await page.locator(".place-card").first().getByRole("heading").textContent();
    
    // Toggle favorite on the list card
    const favButton = page.locator(".place-card").first().locator('button[aria-pressed]');
    await expect(favButton).toBeEnabled();
    if (await favButton.getAttribute("aria-pressed") === "true") {
      const removeFavoriteRequest = page.waitForResponse((response) => {
        return response.url().includes("/resources/favorites") && response.request().method() === "DELETE";
      });
      await favButton.click();
      await removeFavoriteRequest;
      await expect(favButton).toHaveAttribute("aria-pressed", "false");
    }
    await favButton.click();
    
    // Verify button state changes to "Usuń z ulubionych" (aria-pressed=true)
    await expect(page.locator(".place-card").first().locator('button[aria-pressed="true"]')).toBeVisible();
    await page.reload();
    await expect(page.locator(".place-card").first().locator('button[aria-pressed="true"]')).toBeVisible();

    // Go to My Favorites page
    await page.goto("/konto/ulubione");
    await expect(page.getByRole("heading", { name: "Ulubione miejsca" })).toBeVisible();
    await expect(page.getByText(firstPlaceName || "").first()).toBeVisible();

    // Record a visit to this place
    await page.goto("/miejsca?city=warszawa");
    await page.locator(".place-card").first().getByRole("link", { name: /Zobacz miejsce:/ }).click();

    // Click "Byliśmy tutaj" button to open the visit form
    await page.getByRole("button", { name: "Byliśmy tutaj" }).first().click();
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
    await page.getByLabel("Edytuj wizytę").first().click();
    await expect(page.getByRole("heading", { name: "Edytuj wizytę" }).first()).toBeVisible();
    await page.locator("#note").fill("Zaktualizowana notatka: super zabawa!");
    await page.getByRole("button", { name: "Zapisz zmiany" }).first().click();

    // Verify update success
    await expect(page.getByText("Wizyta zaktualizowana pomyślnie!").first()).toBeVisible();
    await expect(page.getByText("Zaktualizowana notatka: super zabawa!").first()).toBeVisible();

    // Delete the visit
    await page.getByLabel("Usuń wizytę").first().click();
    await expect(page.getByRole("heading", { name: "Czy na pewno chcesz usunąć tę wizytę?" }).first()).toBeVisible();
    await page.getByRole("button", { name: "Usuń trwale" }).first().click();

    // Verify deletion success
    await expect(page.getByText("Wizyta została usunięta z historii.")).toBeVisible();

    // Clean up favorite state to prevent fixture leaks across runs or projects
    await page.goto("/konto/ulubione");
    const removeFavBtn = page.getByLabel("Usuń z ulubionych").first();
    await expect(removeFavBtn).toBeVisible();
    await removeFavBtn.click();
    await expect(page.getByText("Usunięto z ulubionych.")).toBeVisible();

    // 7. Logout and verify unauthenticated state
    await page.goto("/");
    await page.getByTestId("user-menu-button").filter({ visible: true }).click();
    await page.getByRole("menuitem", { name: "Wyloguj się" }).click();
    
    // Verify logout success toast and original button
    await expect(page.getByText("Wylogowano pomyślnie.")).toBeVisible();
    await expect(page.getByRole("button", { name: "Zaloguj się" }).first()).toBeVisible();
  });
});
