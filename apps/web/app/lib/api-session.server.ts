const baseUrl = process.env.API_BASE_URL ?? "http://api"

interface SessionUser {
  id: string
  displayName: string
  initials: string
  roles: string[]
}

export interface SessionResponse {
  authenticated: boolean
  user: SessionUser | null
  csrfToken: string | null
}

export async function fetchSession(headers: Headers): Promise<{ data: SessionResponse; setCookie: string | null }> {
  const cookie = headers.get("cookie") || ""
  const correlationId = headers.get("x-correlation-id") || ""

  const forwardHeaders = new Headers()
  if (cookie) forwardHeaders.set("cookie", cookie)
  if (correlationId) forwardHeaders.set("x-correlation-id", correlationId)

  try {
    const res = await fetch(`${baseUrl}/api/v1/session`, {
      method: "GET",
      headers: forwardHeaders,
    })

    if (!res.ok) {
      return {
        data: { authenticated: false, user: null, csrfToken: null },
        setCookie: null,
      }
    }

    const data = (await res.json()) as SessionResponse
    const setCookie = res.headers.get("set-cookie")

    return { data, setCookie }
  } catch {
    return {
      data: { authenticated: false, user: null, csrfToken: null },
      setCookie: null,
    }
  }
}

export async function loginWithGoogle(
  idToken: string,
  headers: Headers
): Promise<{ data: unknown; status: number; setCookie: string | null }> {
  const cookie = headers.get("cookie") || ""
  const correlationId = headers.get("x-correlation-id") || ""

  const forwardHeaders = new Headers()
  forwardHeaders.set("content-type", "application/json")
  if (cookie) forwardHeaders.set("cookie", cookie)
  if (correlationId) forwardHeaders.set("x-correlation-id", correlationId)

  try {
    const res = await fetch(`${baseUrl}/api/v1/auth/google`, {
      method: "POST",
      headers: forwardHeaders,
      body: JSON.stringify({ credential: idToken }),
    })

    const setCookie = res.headers.get("set-cookie")
    const status = res.status

    if (!res.ok) {
      const errorData = await res.json().catch(() => ({}))
      return { data: errorData, status, setCookie }
    }

    const data = await res.json()
    return { data, status, setCookie }
  } catch (err: unknown) {
    return {
      data: { title: "BFF error", detail: err instanceof Error ? err.message : String(err) },
      status: 502,
      setCookie: null,
    }
  }
}

export async function logout(
  csrfToken: string,
  headers: Headers
): Promise<{ status: number; setCookie: string | null }> {
  const cookie = headers.get("cookie") || ""
  const correlationId = headers.get("x-correlation-id") || ""

  const forwardHeaders = new Headers()
  forwardHeaders.set("x-csrf-token", csrfToken)
  if (cookie) forwardHeaders.set("cookie", cookie)
  if (correlationId) forwardHeaders.set("x-correlation-id", correlationId)

  try {
    const res = await fetch(`${baseUrl}/api/v1/logout`, {
      method: "POST",
      headers: forwardHeaders,
    })

    const setCookie = res.headers.get("set-cookie")
    return { status: res.status, setCookie }
  } catch {
    return { status: 502, setCookie: null }
  }
}
