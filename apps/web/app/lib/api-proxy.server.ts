function buildUpstreamUrl(request: Request, apiPath: string): string {
  const baseUrl = process.env.API_BASE_URL ?? "http://api"
  const url = new URL(request.url)
  const query = url.search || ""

  return `${baseUrl}${apiPath}${query}`
}

function shouldForwardHeader(name: string): boolean {
  switch (name.toLowerCase()) {
    case "accept":
    case "content-type":
    case "cookie":
    case "idempotency-key":
    case "x-correlation-id":
    case "x-csrf-token":
    case "x-request-id":
      return true
    default:
      return false
  }
}
export async function proxyApiRequest(request: Request, apiPath: string): Promise<Response> {
  if (!apiPath.startsWith("/api/v1/")) {
    return Response.json({ detail: "Destination path is not allowed." }, { status: 403 })
  }

  const headers = new Headers()
  for (const [name, value] of request.headers.entries()) {
    if (shouldForwardHeader(name)) {
      headers.set(name, value)
    }
  }

  const method = request.method.toUpperCase()
  const init: RequestInit = {
    method,
    headers,
  }

  if (method !== "GET" && method !== "HEAD") {
    init.body = await request.arrayBuffer()
  }

  const upstream = await fetch(buildUpstreamUrl(request, apiPath), init)
  const responseHeaders = new Headers()

  const contentType = upstream.headers.get("content-type")
  if (contentType) {
    responseHeaders.set("Content-Type", contentType)
  }

  const cacheControl = upstream.headers.get("cache-control")
  if (cacheControl) {
    responseHeaders.set("Cache-Control", cacheControl)
  }

  const vary = upstream.headers.get("vary")
  if (vary) {
    responseHeaders.set("Vary", vary)
  }

  const setCookie = upstream.headers.get("set-cookie")
  if (setCookie) {
    responseHeaders.set("Set-Cookie", setCookie)
  }

  return new Response(upstream.body, {
    status: upstream.status,
    headers: responseHeaders,
  })
}
