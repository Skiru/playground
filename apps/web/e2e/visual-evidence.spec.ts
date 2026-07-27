import path from "node:path"
import { expect, test, type Page } from "@playwright/test"

const evidenceDir = process.env.C6A_R1_EVIDENCE_DIR ?? path.resolve("test-results")
const placePath = "/miejsca/demo-1-demo-bawialnia-mokotow"

async function capture(page: Page, name: string, fullPage = true) {
  await page.screenshot({ path: path.join(evidenceDir, `${name}.png`), fullPage })
}

async function loginAs(page: Page, email: string, displayName: string) {
  await page.goto("/")
  const ok = await page.evaluate(async ({ email, displayName }) => {
    const response = await fetch("/resources/auth/dev-login", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ email, displayName, roles: ["ROLE_USER"] }),
    })
    return response.ok
  }, { email, displayName })
  expect(ok).toBeTruthy()
}

test("capture catalog repair evidence", async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1000 })
  await page.goto("/miejsca?city=warszawa&view=list")
  await expect(page.locator(".place-card").first()).toBeVisible()
  await capture(page, "catalog-desktop-list")

  await page.goto("/miejsca?city=warszawa&view=map")
  await expect(page.getByRole("region", { name: "Interaktywna mapa" })).toBeVisible()
  await capture(page, "catalog-desktop-map")

  await page.setViewportSize({ width: 1024, height: 900 })
  await page.goto("/miejsca?city=warszawa&view=list")
  await capture(page, "catalog-tablet-list")

  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto("/miejsca?city=warszawa&view=list")
  await capture(page, "catalog-mobile-list")
  await page.goto("/miejsca?city=warszawa&view=map")
  await capture(page, "catalog-mobile-map")
  await page.getByRole("button", { name: /^Filtry/ }).click()
  await expect(page.getByRole("dialog")).toBeVisible()
  await capture(page, "catalog-mobile-filters", false)
})

test("capture deterministic review and discussion evidence", async ({ browser }, testInfo) => {
  const aliceContext = await browser.newContext({ viewport: { width: 1440, height: 1000 } })
  const bobContext = await browser.newContext({ viewport: { width: 1440, height: 1000 } })
  const alice = await aliceContext.newPage()
  const bob = await bobContext.newPage()
  const shortComment = "Czy przy wejściu jest miejsce na wózek?"
  const longComment = "Byliśmy tutaj w sobotę z dwójką dzieci.\n\nNajmłodsze spokojnie bawiło się w strefie malucha, a starsze miało dość miejsca na ruch. Obsługa jasno wyjaśniła zasady i pomogła znaleźć przewijak. To czytelny, dłuższy komentarz testowy sprawdzający naturalne zawijanie polskiego tekstu."
  const reply = "Tak, wózki można zostawić w oznaczonej strefie obok szatni."
  const review = "Bardzo przyjazne rodzinom miejsce, czytelne zasady i świetnie przygotowana strefa zabawy."

  const runId = testInfo.project.name.replace(/[^a-z0-9]+/gi, "-").toLowerCase()
  await loginAs(alice, `c6a-r1-alicja-${runId}@example.test`, "Alicja Testowa")
  await alice.goto(placePath)
  await alice.getByRole("button", { name: "Dodaj opinię" }).click()
  await alice.getByRole("button", { name: "5 gwiazdek" }).click()
  await alice.locator("#review-form-body").fill(review)
  await alice.getByRole("button", { name: "Zapisz opinię" }).click()
  await expect(alice.getByRole("paragraph").filter({ hasText: review })).toBeVisible()

  for (const body of [shortComment, longComment]) {
    await alice.getByRole("button", { name: "Napisz komentarz" }).click()
    await alice.getByLabel("Treść komentarza").fill(body)
    await alice.getByRole("button", { name: "Wyślij" }).click()
    await expect(alice.getByRole("paragraph").filter({ hasText: body })).toBeVisible()
  }

  await loginAs(bob, `c6a-r1-bartosz-${runId}@example.test`, "Bartosz Testowy")
  await bob.goto(placePath)
  const shortThread = bob.locator("article", { has: bob.getByText(shortComment, { exact: true }) }).last()
  await shortThread.getByRole("button", { name: "Odpowiedz" }).click()
  await shortThread.getByLabel("Treść komentarza").fill(reply)
  await shortThread.getByRole("button", { name: "Wyślij" }).click()
  await expect(shortThread.getByLabel("Treść komentarza")).not.toBeVisible()
  await expect(shortThread.locator("p", { hasText: reply })).toBeVisible()
  await bob.evaluate(() => window.scrollTo(0, 0))
  await capture(bob, "place-community-desktop")

  await bob.getByRole("button", { name: "Napisz komentarz" }).click()
  await capture(bob, "place-composer-desktop")
  await bob.getByRole("button", { name: "Anuluj" }).click()

  await bob.setViewportSize({ width: 390, height: 844 })
  await bob.goto(placePath)
  await expect(bob.getByText(longComment, { exact: true })).toBeVisible()
  await expect(bob.getByText(review, { exact: true })).toBeVisible()
  await capture(bob, "place-comments-mobile")
  await bob.getByRole("button", { name: "Napisz komentarz" }).click()
  await capture(bob, "place-composer-mobile")

  await aliceContext.close()
  await bobContext.close()
})
