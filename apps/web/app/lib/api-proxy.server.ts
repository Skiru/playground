import { isApiEndpointAllowed } from "./api-allowlist"

const ALLOWED_REQUEST_HEADERS = new Set([
  "accept",
  "content-type",
  "cookie",
  "idempotency-key",
  "x-correlation-id",
  "x-csrf-token",
  "x-request-id",
  "origin",
  "referer",
])

const ALLOWED_RESPONSE_HEADERS = new Set([
  "content-type",
  "cache-control",
  "vary",
  "set-cookie",
  "retry-after",
  "etag",
  "last-modified",
  "location",
  "www-authenticate",
  "content-disposition",
])

const MAX_PROXY_BODY_BYTES = 1048576 // 1MB

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
  if (candidate.protocol !== configured.protocol || candidate.host !== configured.host || !isApiEndpointAllowed(normalizedPath)) {
    throw new Error('Destination path is not allowed.')
  }

  candidate.search = new URL(request.url).search
  return candidate.toString()
}

function shouldForwardRequestHeader(name: string): boolean {
  return ALLOWED_REQUEST_HEADERS.has(name.toLowerCase())
}

function shouldForwardResponseHeader(name: string): boolean {
  const lower = name.toLowerCase()
  if (ALLOWED_RESPONSE_HEADERS.has(lower)) return true
  if (lower.startsWith("ratelimit-")) return true
  return false
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
    if (shouldForwardRequestHeader(name)) {
      headers.set(name, value)
    }
  }

  const method = request.method.toUpperCase()
  const init: RequestInit = {
    method,
    headers,
  }

  if (method !== "GET" && method !== "HEAD") {
    const contentLength = request.headers.get("content-length")
    if (contentLength && parseInt(contentLength, 10) > MAX_PROXY_BODY_BYTES) {
      return Response.json(
        { title: "Payload Too Large", detail: "Request body exceeds allowed limit.", status: 413, code: "BODY_TOO_LARGE" },
        { status: 413 }
      )
    }

    const bodyBuffer = await request.arrayBuffer()
    if (bodyBuffer.byteLength > MAX_PROXY_BODY_BYTES) {
      return Response.json(
        { title: "Payload Too Large", detail: "Request body exceeds allowed limit.", status: 413, code: "BODY_TOO_LARGE" },
        { status: 413 }
      )
    }

    init.body = bodyBuffer
  }

  const upstream = await fetch(upstreamUrl, init)
  const responseHeaders = new Headers()

  for (const [name, value] of upstream.headers.entries()) {
    if (shouldForwardResponseHeader(name)) {
      responseHeaders.set(name, value)
    }
  }

  return new Response(upstream.body, {
    status: upstream.status,
    headers: responseHeaders,
  })
}
