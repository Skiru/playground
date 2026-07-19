import { createRequire } from "node:module";
import { expect, test, type Page } from "@playwright/test";

const require = createRequire(import.meta.url);
const axePath = require.resolve("axe-core/axe.min.js");
const API = process.env.API_BASE_URL_BROWSER ?? "http://127.0.0.1:8080";

for (const [name, url] of [["homepage", "/"], ["results", "/miejsca?city=warszawa"], ["details", "/miejsca/demo-1-demo-bawialnia-mokotow"]] as const) {
  test(`${name} has no serious or critical axe violations`, async ({ page }) => {
    await page.goto(url);
    await assertAccessible(page);
  });
}

test("admin login and edit have no serious or critical axe violations", async ({ page }) => {
  await page.goto(`${API}/admin/login`);
  await assertAccessible(page);
  await page.getByLabel("E-mail").fill("admin@example.test");
  await page.getByLabel("Hasło").fill("test-password");
  await page.getByRole("button", { name: "Zaloguj się" }).click();
  await page.goto(`${API}/admin/places`);
  await page.getByRole("link", { name: /edit|edytuj/i }).first().click();
  await assertAccessible(page);
});

async function assertAccessible(page: Page) {
  await page.addScriptTag({ path: axePath });
  const violations = await page.evaluate(async () => {
    const result = await (window as any).axe.run();
    return result.violations.filter((violation: any) => violation.impact === "critical" || violation.impact === "serious");
  });
  expect(violations).toEqual([]);
}
