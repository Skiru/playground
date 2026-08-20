import { afterEach, beforeEach, describe, expect, test, vi } from "vitest"
import { proxyApiRequest } from "./api-proxy.server"

describe("same-origin API proxy destination validation and headers contract", () => {
  beforeEach(() => {
    vi.stubEnv("API_BASE_URL", "https://api.example.test")
    vi.spyOn(global, "fetch").mockResolvedValue(new Response("ok", { status: 200 }))
  })

  afterEach(() => {
    vi.restoreAllMocks()
    vi.unstubAllEnvs()
  })

  test("forwards a valid API path and only the incoming query", async () => {
    const response = await proxyApiRequest(new Request("http://localhost/?page=2"), "/api/v1/forum?ignored=true")

    expect(response.status).toBe(403)
    expect(fetch).not.toHaveBeenCalled()

    const valid = await proxyApiRequest(new Request("http://localhost/?page=2"), "/api/v1/forum")
    expect(valid.status).toBe(200)
    expect(fetch).toHaveBeenCalledWith("https://api.example.test/api/v1/forum?page=2", expect.anything())
  })

  test.each([
    "/api/v1/../../admin",
    "/api/v1/%2e%2e/%2e%2e/admin",
    "/api/v1/%2E%2e/admin",
    "/api/v1/%252e%252e/admin",
    "/api/v1/%2fadmin",
    "/api/v1/%5Cadmin",
    "/api/v1/%ZZ/admin",
    "//evil.example/admin",
    "https://evil.example/api/v1/admin",
  ])("rejects normalized or authority-changing path %s", async (path) => {
    const response = await proxyApiRequest(new Request("http://localhost/", { headers: { Cookie: "secret" } }), path)

    expect(response.status).toBe(403)
    expect(fetch).not.toHaveBeenCalled()
  })

  test("forwards allowlisted request headers including Origin and Referer", async () => {
    const req = new Request("http://localhost/api/v1/auth/google", {
      method: "POST",
      headers: {
        "Origin": "http://localhost:3000",
        "Referer": "http://localhost:3000/login",
        "Cookie": "FAMILYPLACESSESSID=123",
        "Content-Type": "application/json",
        "Accept": "application/json",
        "X-CSRF-Token": "csrf-123",
        "X-Correlation-ID": "corr-123",
        "Idempotency-Key": "01a01a00-0000-7000-8000-000000000000",
        "X-Untrusted-Header": "should-not-forward",
      },
      body: JSON.stringify({ credential: "abc" }),
    })

    await proxyApiRequest(req, "/api/v1/auth/google")

    expect(fetch).toHaveBeenCalled()
    const callArgs = vi.mocked(fetch).mock.calls[0]
    const initHeaders = new Headers(callArgs[1]?.headers)

    expect(initHeaders.get("origin")).toBe("http://localhost:3000")
    expect(initHeaders.get("referer")).toBe("http://localhost:3000/login")
    expect(initHeaders.get("cookie")).toBe("FAMILYPLACESSESSID=123")
    expect(initHeaders.get("content-type")).toBe("application/json")
    expect(initHeaders.get("x-csrf-token")).toBe("csrf-123")
    expect(initHeaders.has("x-untrusted-header")).toBe(false)
  })

  test("forwards allowlisted response headers and strips hop-by-hop headers", async () => {
    const mockUpstreamResponse = new Response("ok", {
      status: 200,
      headers: {
        "Content-Type": "application/json",
        "Cache-Control": "private, no-store",
        "Vary": "Cookie",
        "Set-Cookie": "FAMILYPLACESSESSID=abc",
        "Retry-After": "60",
        "ETag": 'W/"123"',
        "Last-Modified": "Wed, 19 Aug 2026 12:00:00 GMT",
        "Location": "/login",
        "WWW-Authenticate": 'Bearer realm="api"',
        "Content-Disposition": 'attachment; filename="file.txt"',
        "RateLimit-Limit": "100",
        "RateLimit-Remaining": "99",
        "Connection": "keep-alive",
        "Transfer-Encoding": "chunked",
        "Proxy-Authenticate": "Basic",
      },
    })

    vi.spyOn(global, "fetch").mockResolvedValue(mockUpstreamResponse)

    const response = await proxyApiRequest(new Request("http://localhost/"), "/api/v1/forum")

    expect(response.headers.get("Content-Type")).toBe("application/json")
    expect(response.headers.get("Cache-Control")).toBe("private, no-store")
    expect(response.headers.get("Retry-After")).toBe("60")
    expect(response.headers.get("ETag")).toBe('W/"123"')
    expect(response.headers.get("Last-Modified")).toBe("Wed, 19 Aug 2026 12:00:00 GMT")
    expect(response.headers.get("Location")).toBe("/login")
    expect(response.headers.get("WWW-Authenticate")).toBe('Bearer realm="api"')
    expect(response.headers.get("Content-Disposition")).toBe('attachment; filename="file.txt"')
    expect(response.headers.get("RateLimit-Limit")).toBe("100")
    expect(response.headers.get("RateLimit-Remaining")).toBe("99")

    // Hop-by-hop and length-managed headers stripped
    expect(response.headers.has("Connection")).toBe(false)
    expect(response.headers.has("Content-Length")).toBe(false)
    expect(response.headers.has("Transfer-Encoding")).toBe(false)
    expect(response.headers.has("Proxy-Authenticate")).toBe(false)
  })

  test("enforces max body size limit for proxy POST requests", async () => {
    const largeBody = new Uint8Array(1048577) // 1MB + 1 byte
    const req = new Request("http://localhost/api/v1/forum", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Content-Length": "1048577",
      },
      body: largeBody,
    })

    const response = await proxyApiRequest(req, "/api/v1/forum")
    expect(response.status).toBe(413)
    expect(fetch).not.toHaveBeenCalled()
  })
})
