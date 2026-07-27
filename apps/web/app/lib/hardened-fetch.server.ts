const uuidRegex = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

function isUuid(val: string): boolean {
  return uuidRegex.test(val)
}

function isAllowlisted(path: string): boolean {
  const cleanPath = path.split("?")[0]

  if (
    cleanPath === "/api/v1/me/favorites" ||
    cleanPath === "/api/v1/me/visits" ||
    cleanPath === "/api/v1/me/place-state" ||
    cleanPath === "/api/v1/auth/google" ||
    cleanPath === "/api/v1/dev-auth/login" ||
    cleanPath === "/api/v1/session" ||
    cleanPath === "/api/v1/logout"
  ) {
    return true
  }

  // Matches /api/v1/places/{placeId}/favorite
  const favMatch = cleanPath.match(/^\/api\/v1\/places\/([^/]+)\/favorite$/)
  if (favMatch && isUuid(favMatch[1])) {
    return true
  }

  // Matches /api/v1/places/{placeId}/visits
  const visitMatch = cleanPath.match(/^\/api\/v1\/places\/([^/]+)\/visits$/)
  if (visitMatch && isUuid(visitMatch[1])) {
    return true
  }

  // Matches /api/v1/me/visits/{visitId}
  const visitIdMatch = cleanPath.match(/^\/api\/v1\/me\/visits\/([^/]+)$/)
  if (visitIdMatch && isUuid(visitIdMatch[1])) {
    return true
  }

  return false
}

function isBodyJson(body: unknown): boolean {
  if (!body) return false

  // Never set JSON for FormData
  if (typeof FormData !== "undefined" && body instanceof FormData) {
    return false
  }

  // Never set JSON for URLSearchParams
  if (typeof URLSearchParams !== "undefined" && body instanceof URLSearchParams) {
    return false
  }

  // Never set JSON for Blob / File
  if (typeof Blob !== "undefined" && body instanceof Blob) {
    return false
  }

  // Never set JSON for ArrayBuffer
  if (typeof ArrayBuffer !== "undefined" && body instanceof ArrayBuffer) {
    return false
  }

  // Never set JSON for typed arrays (ArrayBufferView)
  if (typeof ArrayBuffer !== "undefined" && ArrayBuffer.isView && ArrayBuffer.isView(body)) {
    return false
  }

  // Never set JSON for ReadableStream
  if (typeof ReadableStream !== "undefined" && body instanceof ReadableStream) {
    return false
  }

  // If it's a string, check if it's valid JSON (object or array structure) and not plain text or multipart
  if (typeof body === "string") {
    const trimmed = body.trim()
    // Valid JSON objects or arrays start with { or [
    if (trimmed.startsWith("{") || trimmed.startsWith("[")) {
      try {
        JSON.parse(trimmed)
        return true
      } catch {
        return false
      }
    }
    // Plain text or primitive string is not considered JSON body
    return false
  }

  return false
}

export async function hardenedFetch(
  incomingRequest: Request,
  path: string,
  options: RequestInit = {}
): Promise<Response> {
  const incomingCorrId = incomingRequest.headers.get("x-correlation-id") || incomingRequest.headers.get("x-request-id")

  if (!isAllowlisted(path)) {
    return Response.json(
      {
        title: "Forbidden Endpoint",
        detail: "Destination path is not allowed by BFF policy.",
        status: 403,
        code: "BFF_FORBIDDEN",
        correlationId: incomingCorrId || undefined,
      },
      { status: 403 }
    )
  }

  const baseUrl = process.env.API_BASE_URL ?? "http://api"
  const url = `${baseUrl}${path}`

  // AbortController for 5 second timeout
  const controller = new AbortController()
  const timeoutId = setTimeout(() => {
    controller.abort()
  }, 5000)

  const headers = new Headers(options.headers)

  if (options.body && !headers.has("Content-Type") && isBodyJson(options.body)) {
    headers.set("Content-Type", "application/json")
  }

  // Forward incoming headers safely
  const incomingCookie = incomingRequest.headers.get("cookie")
  if (incomingCookie) {
    headers.set("cookie", incomingCookie)
  }

  if (incomingCorrId) {
    headers.set("x-correlation-id", incomingCorrId)
  }

  const incomingCsrf = incomingRequest.headers.get("x-csrf-token")
  if (incomingCsrf) {
    headers.set("x-csrf-token", incomingCsrf)
  }

  try {
    const res = await fetch(url, {
      ...options,
      headers,
      signal: controller.signal,
    })

    clearTimeout(timeoutId)

    const responseHeaders = new Headers()
    
    // Always preserve Cache-Control: no-store and Vary: Cookie for private endpoints
    responseHeaders.set("Cache-Control", "no-store, private")
    responseHeaders.set("Vary", "Cookie")

    // Forward Set-Cookie if any
    const setCookie = res.headers.get("set-cookie")
    if (setCookie) {
      responseHeaders.set("Set-Cookie", setCookie)
    }

    if (res.status === 204) {
      return new Response(null, { status: 204, headers: responseHeaders })
    }

    if (!res.ok) {
      const errorData = await res.json().catch(() => ({}))
      
      const safeProblem = {
        title: errorData.title || "Request Failed",
        detail: errorData.detail || "An error occurred during communication with the backend service.",
        status: res.status,
        code: errorData.code || "BFF_UPSTREAM_ERROR",
        correlationId: incomingCorrId || undefined,
      }
      return Response.json(safeProblem, { status: res.status, headers: responseHeaders })
    }

    const data = await res.json()
    return Response.json(data, { status: res.status, headers: responseHeaders })
  } catch (err: unknown) {
    clearTimeout(timeoutId)

    const status = err instanceof DOMException && err.name === "AbortError" ? 504 : 502
    const detail = status === 504 
      ? "Gateway Timeout: Backend service took too long to respond." 
      : "Bad Gateway: Failed to contact backend service."

    return Response.json(
      {
        title: status === 504 ? "Gateway Timeout" : "Bad Gateway",
        detail,
        status,
        code: status === 504 ? "BFF_TIMEOUT" : "BFF_BAD_GATEWAY",
        correlationId: incomingCorrId || undefined,
      },
      {
        status,
        headers: {
          "Cache-Control": "no-store, private",
          "Vary": "Cookie",
        },
      }
    )
  }
}
