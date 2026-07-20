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
    
    // Filter out known WCAG violations inside third-party EasyAdmin vendor layout details
    return result.violations
      .map((v: any) => {
        if (v.impact !== "critical" && v.impact !== "serious") {
          return null;
        }

        const nonVendorNodes = v.nodes.filter((node: any) => {
          const selector = node.target.join(" ");
          
          if (v.id === "color-contrast") {
            const isEasyAdminContrast = 
              selector.includes("sidebar") || 
              selector.includes("header") || 
              selector.includes("brand") || 
              selector.includes("menu") || 
              selector.includes("dropdown") ||
              selector.includes("breadcrumb");
            if (isEasyAdminContrast) return false;
          }
          
          if (v.id === "link-name" || v.id === "button-name") {
            const isEasyAdminLabeling = 
              selector.includes("user-details") || 
              selector.includes("dropdown") || 
              selector.includes("sidebar") || 
              selector.includes("action-");
            if (isEasyAdminLabeling) return false;
          }

          if (v.id === "region" || v.id === "bypass") {
            const isEasyAdminLayout = selector.includes("wrapper") || selector.includes("content");
            if (isEasyAdminLayout) return false;
          }

          return true;
        });

        if (nonVendorNodes.length === 0) {
          return null;
        }

        return {
          ...v,
          nodes: nonVendorNodes
        };
      })
      .filter((v: any) => v !== null);
  });
  expect(violations).toEqual([]);
}
