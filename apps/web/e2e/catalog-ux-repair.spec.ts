import { expect, test, type Locator, type Page } from "@playwright/test"

async function expectAboveMobileNavigation(page: Page, content: Locator) {
  await content.evaluate((element) => element.scrollIntoView({ block: "end" }))
  const navigation = page.getByRole("navigation", { name: "Nawigacja mobilna" })
  const [contentBox, navigationBox] = await Promise.all([content.boundingBox(), navigation.boundingBox()])
  expect(contentBox).not.toBeNull()
  expect(navigationBox).not.toBeNull()
  expect(contentBox!.y + contentBox!.height).toBeLessThanOrEqual(navigationBox!.y)
}

async function loginAs(page: Page) {
  await page.goto("/")
  const ok = await page.evaluate(async () => {
    const response = await fetch("/resources/auth/dev-login", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ email: "c6a-r1-visual@example.test", displayName: "C6A R1", roles: ["ROLE_USER"] }),
    })
    return response.ok
  })
  expect(ok).toBeTruthy()
}

test("catalog card, navigation, and URL-backed views expose correct semantics", async ({ page }) => {
  await page.goto("/miejsca?city=warszawa&category=bawialnie")
  const card = page.locator(".place-card").first()
  const placeName = (await card.getByRole("heading").textContent())!.trim()
  const cardLink = card.getByRole("link", { name: new RegExp(placeName) })
  await expect(cardLink).toContainText("Zobacz miejsce")
  await expect(card.getByRole("button", { name: /ulubionych/ })).toBeVisible()
  await expect(card.locator("a button, button a")).toHaveCount(0)
  const navigationName = (page.viewportSize()?.width ?? 1280) < 768 ? "Nawigacja mobilna" : "Nawigacja główna"
  await expect(page.getByRole("navigation", { name: navigationName }).getByRole("link", { name: "Miejsca" })).toHaveAttribute("aria-current", "page")

  await page.getByRole("link", { name: /Mapa \(/ }).click()
  await expect(page).toHaveURL(/view=map/)
  await expect(page).toHaveURL(/city=warszawa/)
  await expect(page).toHaveURL(/category=bawialnie/)
  await expect(page.getByRole("region", { name: "Interaktywna mapa" })).toBeVisible()
  await page.goBack()
  await expect(page).not.toHaveURL(/view=map/)
  await page.goForward()
  await expect(page).toHaveURL(/view=map/)
})

for (const width of [390, 320]) {
  test(`mobile content clears bottom navigation at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 })

    await page.goto("/miejsca?city=warszawa&view=list")
    const lastCardLink = page.locator(".place-card").last().getByRole("link", { name: /Zobacz miejsce:/ })
    await lastCardLink.focus()
    await expect(lastCardLink).toBeFocused()
    await expectAboveMobileNavigation(page, lastCardLink)

    await page.goto("/miejsca?city=warszawa&view=map")
    const showListLink = page.getByRole("link", { name: /Pokaż listę/ })
    await expect(showListLink).toBeVisible({ timeout: 15000 })
    await expectAboveMobileNavigation(page, showListLink)

    await page.goto("/miejsca/demo-1-demo-bawialnia-mokotow")
    await expectAboveMobileNavigation(page, page.getByRole("button", { name: "Nawiguj" }))

    await loginAs(page)
    await page.goto("/miejsca/demo-1-demo-bawialnia-mokotow")
    await page.getByRole("button", { name: "Napisz komentarz" }).click()
    await expectAboveMobileNavigation(page, page.getByRole("button", { name: "Wyślij" }))
  })
}
