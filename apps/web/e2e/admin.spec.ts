import { expect, test } from "@playwright/test";

const API = process.env.API_BASE_URL_BROWSER ?? "http://127.0.0.1:8080";
const WEB = process.env.WEB_BASE_URL ?? "http://127.0.0.1:3000";

test("real admin create, edit, invalid publish, valid publish, visibility and unpublish", async ({ page }, testInfo) => {
  const run = process.env.GITHUB_RUN_ID ?? String(process.pid);
  const slug = `playwright-${testInfo.project.name}-${run}-${testInfo.retry}`;
  const name = `Playwright ${slug}`;
  await login(page);
  await page.goto(`${API}/admin/places/new`);
  await page.getByLabel("Nazwa", { exact: true }).fill(name);
  await page.getByLabel("Slug").fill(slug);
  await page.getByLabel("Krótki opis").fill("Playwright short description");
  await page.getByLabel("Opis", { exact: true }).fill("Playwright complete description");
  await page.getByLabel("Adres", { exact: true }).fill("Testowa 20");
  await page.getByLabel("Kod pocztowy").fill("00-020");
  await page.getByLabel("Szerokość geograficzna").fill("52.24");
  await page.getByLabel("Długość geograficzna").fill("21.03");
  await page.getByLabel("Wewnątrz").check();
  await page.getByLabel("Bawialnie").check();
  await page.getByLabel("Nazwa strefy").fill("Maluchy");
  await page.getByLabel("Wiek od (miesiące)").fill("6");
  await page.getByLabel("Wiek do (miesiące)").fill("36");
  await page.getByRole("button", { name: "Utwórz cały draft" }).click();
  await page.getByRole("link", { name }).click();

  await page.getByRole("button", { name: "publish", exact: true }).click();
  await expect(page.getByRole("alert")).toContainText("not ready");
  await page.getByRole("row").filter({ hasText: name }).getByRole("link", { name: "edit" }).click();
  await page.getByLabel("Parki rodzinne").check();
  await page.getByLabel("Parking").check();
  await page.getByLabel("Wifi").check();
  const ages = page.locator("#place_admin_form_ageZones");
  await ages.getByRole("button", { name: /Dodaj wiersz/ }).click();
  await ages.getByLabel("Nazwa strefy").nth(1).fill("Dzieci");
  await ages.getByLabel("Wiek od (miesiące)").nth(1).fill("37");
  await ages.getByLabel("Wiek do (miesiące)").nth(1).fill("120");
  await page.getByLabel("Tryb godzin otwarcia").selectOption("scheduled");
  const weekly = page.locator("#place_admin_form_weeklyOpeningHours");
  await weekly.getByRole("button", { name: /Dodaj wiersz/ }).click();
  await weekly.getByRole("button", { name: /Dodaj wiersz/ }).click();
  await weekly.getByLabel("Otwarcie").nth(0).fill("09:00");
  await weekly.getByLabel("Zamknięcie").nth(0).fill("13:00");
  await weekly.getByLabel("Otwarcie").nth(1).fill("12:00");
  await weekly.getByLabel("Zamknięcie").nth(1).fill("18:00");
  const special = page.locator("#place_admin_form_specialOpeningDays");
  await special.getByRole("button", { name: /Dodaj wiersz/ }).first().click();
  await special.getByLabel("Data").fill("2026-12-24");
  await special.getByLabel("Tryb dnia").selectOption("custom");
  const specialIntervals = special.locator("[data-prototype]").first();
  await specialIntervals.getByRole("button", { name: /Dodaj wiersz/ }).click();
  await specialIntervals.getByLabel("Otwarcie").fill("10:00");
  await specialIntervals.getByLabel("Zamknięcie").fill("14:00");
  await page.getByRole("button", { name: "Zapisz cały agregat" }).click();
  await expect(page.getByText(/overlaps another weekly interval/)).toBeVisible();
  await weekly.getByLabel("Otwarcie").nth(1).fill("13:00");
  await page.getByRole("button", { name: "Zapisz cały agregat" }).click();
  await expect(page.locator('input[name="version"]').first()).toHaveValue("2");
  await page.getByRole("button", { name: "submit", exact: true }).click();
  await page.getByRole("link", { name }).click();
  await page.getByRole("button", { name: "publish", exact: true }).click();

  expect((await page.request.get(`${API}/api/v1/places/${slug}`)).ok()).toBeTruthy();
  await page.goto(`${WEB}/miejsca/${slug}`);
  await expect(page.getByRole("heading", { level: 1 })).toContainText("Playwright");
  await page.goto(`${API}/admin/places`);
  await page.getByRole("link", { name }).click();
  await page.getByRole("button", { name: "unpublish", exact: true }).click();
  expect((await page.request.get(`${API}/api/v1/places/${slug}`)).status()).toBe(404);
});

async function login(page: import("@playwright/test").Page) {
  await page.goto(`${API}/admin/login`);
  await page.getByLabel("E-mail").fill("admin@example.test");
  await page.getByLabel("Hasło").fill("test-password");
  await page.getByRole("button", { name: "Zaloguj się" }).click();
  await expect(page).toHaveURL(`${API}/admin`);
}
