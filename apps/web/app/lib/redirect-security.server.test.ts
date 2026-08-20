import { describe, expect, test } from "vitest"
import { isSafeRedirectUrl, sanitizeRedirect } from "./redirect-security.server"

describe("Redirect security and Open Redirect prevention", () => {
  test("allows relative local routes", () => {
    expect(isSafeRedirectUrl("/konto")).toBe(true)
    expect(isSafeRedirectUrl("/miejsca?city=warszawa&category=bawialnie")).toBe(true)
    expect(isSafeRedirectUrl("/forum/watek/123#post-456")).toBe(true)
  })

  test("rejects open redirect vectors", () => {
    expect(isSafeRedirectUrl("//evil.com")).toBe(false)
    expect(isSafeRedirectUrl("/\\evil.com")).toBe(false)
    expect(isSafeRedirectUrl("\\\\evil.com")).toBe(false)
    expect(isSafeRedirectUrl("https://evil-attacker.test/phishing")).toBe(false)
    expect(isSafeRedirectUrl("javascript:alert(1)")).toBe(false)
    expect(isSafeRedirectUrl("data:text/html,<script>alert(1)</script>")).toBe(false)
  })

  test("allows explicitly allowed external origins", () => {
    const allowed = ["https://app.familyplaces.pl"]
    expect(isSafeRedirectUrl("https://app.familyplaces.pl/login", allowed)).toBe(true)
    expect(isSafeRedirectUrl("https://untrusted.test/login", allowed)).toBe(false)
  })

  test("sanitizes unsafe redirects to fallback route", () => {
    expect(sanitizeRedirect("//evil.com")).toBe("/")
    expect(sanitizeRedirect("https://evil.com", "/login")).toBe("/login")
    expect(sanitizeRedirect("/konto")).toBe("/konto")
  })
})
