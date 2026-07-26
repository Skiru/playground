import { afterEach, beforeEach, describe, expect, test, vi } from "vitest"
import { proxyApiRequest } from "./api-proxy.server"

describe("same-origin API proxy destination validation", () => {
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
})
