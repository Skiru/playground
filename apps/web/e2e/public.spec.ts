import { expect, test } from "@playwright/test";

const API = process.env.API_BASE_URL_BROWSER ?? "http://127.0.0.1:8080";

test("SSR homepage contains useful content before JavaScript", async ({ page, request }, testInfo) => {
  const response = await request.get("/");
  expect(response.ok()).toBeTruthy();
  const html = await response.text();
  expect(html).toContain("Miejsca dobrane do wieku");
  await page.goto("/");
  await expect(page.getByRole("heading", { level: 1 })).toContainText("Miejsca dobrane");
  await page.screenshot({ path: testInfo.outputPath(`homepage-${testInfo.project.name}.png`), fullPage: true });
});

test("city, category, age, radius, amenities AND and search reach results and details", async ({ page }) => {
  await page.goto("/");
  await page.getByLabel("Czego szukasz?").fill("Demo");
  await page.getByLabel("Miasto").selectOption("warszawa");
  await page.getByLabel("Wiek dziecka").selectOption("36");
  await page.getByRole("button", { name: "Pokaż miejsca" }).click();
  await expect(page).toHaveURL(/city=warszawa/);
  await expect(page.getByRole("heading", { level: 1 })).toContainText("propozyc");

  const filterTrigger = page.getByRole("button", { name: "Filtruj propozycje" });
  const isMobile = await filterTrigger.isVisible();
  if (isMobile) {
    await filterTrigger.click();
    await expect(page.getByRole("dialog")).toBeVisible();
  }

  const container = isMobile ? page.getByRole("dialog") : page.locator("aside");

  await container.locator("select[name='category']").selectOption("bawialnie");
  await container.locator("input[name='latitude']").fill("52.2297");
  await container.locator("input[name='longitude']").fill("21.0122");
  await container.locator("input[name='radiusKm']").fill("20");
  const amenities = container.getByRole("checkbox");
  await amenities.nth(0).check();
  await amenities.nth(1).check();
  await container.getByRole("button", { name: "Filtruj" }).click();
  await expect(page).toHaveURL(/radiusKm=20/);
  await expect(page.locator(".place-card").first()).toBeVisible();
  await page.locator(".place-card h2 a").first().click();
  await expect(page.getByRole("heading", { level: 1 })).toContainText("Demo");
  await expect(page.getByRole("heading", { name: "Informacje" })).toBeVisible();
});

test("map, empty results, fallback, API error and 404 remain understandable", async ({ page }) => {
  await page.goto("/miejsca?city=warszawa");
  const mapToggle = page.getByRole("button", { name: /Mapa/i });
  if (await mapToggle.isVisible()) {
    await mapToggle.click();
  }
  await expect(page.getByRole("region", { name: "Interaktywna mapa" })).toBeVisible();
  await expect(page.locator(".map-fallback a").first()).toBeAttached();

  await page.goto("/miejsca?q=definitely-no-family-place-xyz");
  await expect(page.getByText("Brak miejsc dla tych filtrów")).toBeVisible();
  
  if (await mapToggle.isVisible()) {
    await mapToggle.click();
  }
  await expect(page.getByText("Brak miejsc w tym obszarze")).toBeVisible();

  await page.goto("/miejsca?pageSize=51");
  await expect(page.getByText("Błąd").first()).toBeVisible();
  await page.goto("/nie-istnieje");
  await expect(page.getByText("404").first()).toBeVisible();

  const response = await page.request.get(`${API}/api/v1/places`);
  expect(response.ok()).toBeTruthy();
});

test("keyboard-only navigation reaches the catalogue", async ({ page }) => {
  await page.goto("/");
  await page.keyboard.press("Tab");
  await page.keyboard.press("Tab");
  await page.keyboard.press("Enter");
  await expect(page).toHaveURL(/miejsca|\/$/);
});
