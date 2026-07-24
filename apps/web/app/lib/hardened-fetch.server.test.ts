import { describe, test, expect, vi, beforeEach, afterEach, type MockInstance } from "vitest"
import { hardenedFetch } from "./hardened-fetch.server"

describe("hardenedFetch and typed body policy", () => {
  let fetchSpy: MockInstance<typeof fetch>

  beforeEach(() => {
    vi.stubEnv("API_BASE_URL", "http://test-api")
    fetchSpy = vi.spyOn(global, "fetch").mockImplementation(() =>
      Promise.resolve(
        new Response(JSON.stringify({ success: true }), {
          status: 200,
          headers: { "Content-Type": "application/json" },
        })
      )
    )
  })

  afterEach(() => {
    vi.restoreAllMocks()
    vi.unstubAllEnvs()
  })

  test("blocks non-allowlisted endpoint with 403", async () => {
    const incoming = new Request("http://localhost/")
    const response = await hardenedFetch(incoming, "/api/v1/invalid-endpoint")
    expect(response.status).toBe(403)
    const body = await response.json()
    expect(body.code).toBe("BFF_FORBIDDEN")
    expect(fetchSpy).not.toHaveBeenCalled()
  })

  test("allows allowlisted endpoint and calls fetch with correlation id and cookies", async () => {
    const incoming = new Request("http://localhost/", {
      headers: {
        "cookie": "session=abc",
        "x-correlation-id": "corr-123",
      },
    })
    const response = await hardenedFetch(incoming, "/api/v1/me/favorites")
    expect(response.status).toBe(200)
    expect(fetchSpy).toHaveBeenCalledOnce()
    
    const [url, init] = fetchSpy.mock.calls[0]!
    expect(url).toBe("http://test-api/api/v1/me/favorites")
    const sentHeaders = init?.headers as Headers
    expect(sentHeaders.get("cookie")).toBe("session=abc")
    expect(sentHeaders.get("x-correlation-id")).toBe("corr-123")
  })

  test("injects Content-Type: application/json for valid JSON object string", async () => {
    const incoming = new Request("http://localhost/")
    const payload = JSON.stringify({ foo: "bar" })
    await hardenedFetch(incoming, "/api/v1/me/favorites", {
      method: "POST",
      body: payload,
    })
    
    const [, init] = fetchSpy.mock.calls[0]!
    const sentHeaders = init?.headers as Headers
    expect(sentHeaders.get("Content-Type")).toBe("application/json")
  })

  test("injects Content-Type: application/json for valid JSON array string", async () => {
    const incoming = new Request("http://localhost/")
    const payload = JSON.stringify([{ foo: "bar" }])
    await hardenedFetch(incoming, "/api/v1/me/favorites", {
      method: "POST",
      body: payload,
    })
    
    const [, init] = fetchSpy.mock.calls[0]!
    const sentHeaders = init?.headers as Headers
    expect(sentHeaders.get("Content-Type")).toBe("application/json")
  })

  test("does NOT inject Content-Type: application/json for plain text", async () => {
    const incoming = new Request("http://localhost/")
    await hardenedFetch(incoming, "/api/v1/me/favorites", {
      method: "POST",
      body: "plain text message, not json",
    })
    
    const [, init] = fetchSpy.mock.calls[0]!
    const sentHeaders = init?.headers as Headers
    expect(sentHeaders.get("Content-Type")).toBeNull()
  })

  test("does NOT inject Content-Type: application/json for FormData", async () => {
    const incoming = new Request("http://localhost/")
    const formData = new FormData()
    formData.append("key", "value")
    await hardenedFetch(incoming, "/api/v1/me/favorites", {
      method: "POST",
      body: formData,
    })
    
    const [, init] = fetchSpy.mock.calls[0]!
    const sentHeaders = init?.headers as Headers
    expect(sentHeaders.get("Content-Type")).toBeNull()
  })

  test("does NOT inject Content-Type: application/json for URLSearchParams", async () => {
    const incoming = new Request("http://localhost/")
    const params = new URLSearchParams()
    params.append("key", "value")
    await hardenedFetch(incoming, "/api/v1/me/favorites", {
      method: "POST",
      body: params,
    })
    
    const [, init] = fetchSpy.mock.calls[0]!
    const sentHeaders = init?.headers as Headers
    expect(sentHeaders.get("Content-Type")).toBeNull()
  })

  test("does NOT inject Content-Type: application/json for Blob", async () => {
    const incoming = new Request("http://localhost/")
    const blob = new Blob(["hello"], { type: "text/plain" })
    await hardenedFetch(incoming, "/api/v1/me/favorites", {
      method: "POST",
      body: blob,
    })
    
    const [, init] = fetchSpy.mock.calls[0]!
    const sentHeaders = init?.headers as Headers
    expect(sentHeaders.get("Content-Type")).toBeNull()
  })

  test("does NOT inject Content-Type: application/json for ArrayBuffer", async () => {
    const incoming = new Request("http://localhost/")
    const buffer = new ArrayBuffer(8)
    await hardenedFetch(incoming, "/api/v1/me/favorites", {
      method: "POST",
      body: buffer,
    })
    
    const [, init] = fetchSpy.mock.calls[0]!
    const sentHeaders = init?.headers as Headers
    expect(sentHeaders.get("Content-Type")).toBeNull()
  })

  test("does NOT inject Content-Type: application/json for typed arrays", async () => {
    const incoming = new Request("http://localhost/")
    const typedArray = new Uint8Array([1, 2, 3])
    await hardenedFetch(incoming, "/api/v1/me/favorites", {
      method: "POST",
      body: typedArray,
    })
    
    const [, init] = fetchSpy.mock.calls[0]!
    const sentHeaders = init?.headers as Headers
    expect(sentHeaders.get("Content-Type")).toBeNull()
  })

  test("preserves an explicitly supplied Content-Type", async () => {
    const incoming = new Request("http://localhost/")
    await hardenedFetch(incoming, "/api/v1/me/favorites", {
      method: "POST",
      body: JSON.stringify({ foo: "bar" }),
      headers: {
        "Content-Type": "text/custom-json",
      },
    })
    
    const [, init] = fetchSpy.mock.calls[0]!
    const sentHeaders = init?.headers as Headers
    expect(sentHeaders.get("Content-Type")).toBe("text/custom-json")
  })
})
