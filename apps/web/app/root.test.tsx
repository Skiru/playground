/* eslint-disable @typescript-eslint/no-explicit-any */
import { describe, expect, it, vi, beforeEach, afterEach } from "vitest"
import { loader } from "./root"
import { fetchSession } from "./lib/api-session.server"

vi.mock("./lib/api-session.server", () => ({
  fetchSession: vi.fn(),
}))

describe("root loader", () => {
  const originalEnv = process.env

  beforeEach(() => {
    process.env = { ...originalEnv }
    vi.mocked(fetchSession).mockResolvedValue({
      data: { authenticated: false, user: null, csrfToken: null },
      setCookie: null,
    })
  })

  afterEach(() => {
    process.env = originalEnv
  })

  it("returns default values when environment variables are not set", async () => {
    const result = await loader({
      request: new Request("http://localhost/"),
      params: {},
    } as any)

    expect(result.publicRuntimeConfig).toEqual({
      googleIdentityEnabled: false,
      googleClientId: null,
      devAuthEnabled: false,
    })
  })

  it("throws error when Google identity is enabled but Client ID is missing", async () => {
    process.env.GOOGLE_IDENTITY_ENABLED = "true"
    process.env.PUBLIC_GOOGLE_CLIENT_ID = ""

    await expect(
      loader({
        request: new Request("http://localhost/"),
        params: {},
      } as any)
    ).rejects.toThrow(/Configuration error/)
  })

  it("enables Google identity and dev auth under dev environment", async () => {
    process.env.GOOGLE_IDENTITY_ENABLED = "true"
    process.env.PUBLIC_GOOGLE_CLIENT_ID = "some-client-id"
    process.env.DEV_AUTH_ENABLED = "true"
    process.env.APP_ENV = "dev"

    const result = await loader({
      request: new Request("http://localhost/"),
      params: {},
    } as any)

    expect(result.publicRuntimeConfig).toEqual({
      googleIdentityEnabled: true,
      googleClientId: "some-client-id",
      devAuthEnabled: true,
    })
  })

  it("disables dev auth under production environment even if DEV_AUTH_ENABLED is true", async () => {
    process.env.GOOGLE_IDENTITY_ENABLED = "true"
    process.env.PUBLIC_GOOGLE_CLIENT_ID = "some-client-id"
    process.env.DEV_AUTH_ENABLED = "true"
    process.env.APP_ENV = "prod"

    const result = await loader({
      request: new Request("http://localhost/"),
      params: {},
    } as any)

    expect(result.publicRuntimeConfig).toEqual({
      googleIdentityEnabled: true,
      googleClientId: "some-client-id",
      devAuthEnabled: false,
    })
  })
})
