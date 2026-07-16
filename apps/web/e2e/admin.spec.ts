import { expect, test } from "@playwright/test";

const API = process.env.API_BASE_URL_BROWSER ?? "http://127.0.0.1:8080";
const WEB = process.env.WEB_BASE_URL ?? "http://127.0.0.1:3000";

test("real admin create, edit, invalid publish, valid publish, visibility and unpublish", async ({ page }, testInfo) => {
  const run = process.env.GITHUB_RUN_ID ?? String(process.pid);
  const slug = `playwright-${testInfo.project.name}-${run}-${testInfo.retry}`;
  const name = `Playwright ${slug}`;
  await login(page);
  await page.goto(`${API}/admin/places/new`);
  await page.getByLabel("Nazwa").fill(name);
  await page.getByLabel("Slug").fill(slug);
  await page.getByLabel("Krótki opis").fill("Playwright short description");
  await page.getByLabel("Opis", { exact: true }).fill("Playwright complete description");
  await page.getByLabel("Adres").fill("Testowa 20");
  await page.getByLabel("Kod pocztowy").fill("00-020");
  await page.getByLabel("Szerokość geograficzna").fill("52.24");
  await page.getByLabel("Długość geograficzna").fill("21.03");
  await page.getByLabel("Wewnątrz").check();
  await page.getByRole("button", { name: "Utwórz draft" }).click();
  await page.getByRole("link", { name }).click();

  await page.getByRole("button", { name: "publish", exact: true }).click();
  await expect(page.getByRole("alert")).toContainText("incomplete");
  await page.getByRole("row").filter({ hasText: name }).getByRole("link", { name: "edit" }).click();
  await page.getByLabel(/Kategorie \(slugi/).fill("bawialnie,parki");
  await page.getByLabel(/Amenities/).fill("parking,wifi");
  await page.getByLabel(/Strefy wieku/).fill("Maluchy|6|36|\nDzieci|37|120|");
  await page.getByLabel(/Godziny tygodniowe/).fill("1|1|09:00|12:00|0\n1|2|13:00|18:00|0");
  await page.getByRole("button", { name: "Zapisz cały agregat" }).click();
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
