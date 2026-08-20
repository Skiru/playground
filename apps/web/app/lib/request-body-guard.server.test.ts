import { describe, expect, test } from "vitest"
import { parseJsonBodyGuarded } from "./request-body-guard.server"

describe("request body size guard and JSON parsing", () => {
  test("valid small JSON request parses successfully", async () => {
    const request = new Request("http://localhost/resources/auth/google", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ credential: "valid-google-token" }),
    })

    const result = await parseJsonBodyGuarded<{ credential: string }>(request)
    expect(result.ok).toBe(true)
    if (result.ok) {
      expect(result.data.credential).toBe("valid-google-token")
    }
  })

  test("rejects request with invalid Content-Type (415)", async () => {
    const request = new Request("http://localhost/resources/auth/google", {
      method: "POST",
      headers: { "Content-Type": "text/plain" },
      body: "credential=abc",
    })

    const result = await parseJsonBodyGuarded(request)
    expect(result.ok).toBe(false)
    if (!result.ok) {
      expect(result.response.status).toBe(415)
    }
  })

  test("rejects empty body (400)", async () => {
    const request = new Request("http://localhost/resources/auth/google", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: "   ",
    })

    const result = await parseJsonBodyGuarded(request)
    expect(result.ok).toBe(false)
    if (!result.ok) {
      expect(result.response.status).toBe(400)
    }
  })

  test("rejects malformed JSON (400)", async () => {
    const request = new Request("http://localhost/resources/auth/google", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: '{"credential": "abc",}',
    })

    const result = await parseJsonBodyGuarded(request)
    expect(result.ok).toBe(false)
    if (!result.ok) {
      expect(result.response.status).toBe(400)
    }
  })

  test("rejects request with content-length header exceeding limit (413)", async () => {
    const request = new Request("http://localhost/resources/auth/google", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Content-Length": "20000",
      },
      body: JSON.stringify({ credential: "abc" }),
    })

    const result = await parseJsonBodyGuarded(request, 8192)
    expect(result.ok).toBe(false)
    if (!result.ok) {
      expect(result.response.status).toBe(413)
    }
  })

  test("rejects request with body size exceeding limit (413)", async () => {
    const largePayload = { data: "a".repeat(10000) }
    const request = new Request("http://localhost/resources/auth/google", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(largePayload),
    })

    const result = await parseJsonBodyGuarded(request, 8192)
    expect(result.ok).toBe(false)
    if (!result.ok) {
      expect(result.response.status).toBe(413)
    }
  })
})
