function buildUpstreamUrl(request: Request, apiPath: string): string {
  const configured = new URL(process.env.API_BASE_URL ?? "http://api")
  if (!['http:', 'https:'].includes(configured.protocol) || configured.username || configured.password) {
    throw new Error('Invalid API origin configuration')
  }

  if (!apiPath.startsWith('/') || apiPath.includes('#') || apiPath.includes('?') || /%(?![0-9a-f]{2})/i.test(apiPath)) {
    throw new Error('Destination path is not allowed.')
  }
  if (/[\\]/.test(apiPath) || /%(?:25|2f|5c|2e)/i.test(apiPath) || apiPath.split('/').includes('..') || apiPath.split('/').includes('.')) {
    throw new Error('Destination path is not allowed.')
  }

  const candidate = new URL(apiPath, configured.origin)
  const normalizedPath = candidate.pathname
  if (candidate.protocol !== configured.protocol || candidate.host !== configured.host ||
      !(normalizedPath === '/api/v1' || normalizedPath.startsWith('/api/v1/'))) {
    throw new Error('Destination path is not allowed.')
  }

  candidate.search = new URL(request.url).search
  return candidate.toString()
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
  let upstreamUrl: string
  try {
    upstreamUrl = buildUpstreamUrl(request, apiPath)
  } catch {
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

  const upstream = await fetch(upstreamUrl, init)
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
