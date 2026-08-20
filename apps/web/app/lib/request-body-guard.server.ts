export async function parseJsonBodyGuarded<T = unknown>(
  request: Request,
  maxSizeBytes = 16384
): Promise<{ ok: true; data: T } | { ok: false; response: Response }> {
  const contentType = request.headers.get("content-type") || ""
  if (!contentType.toLowerCase().includes("application/json")) {
    return {
      ok: false,
      response: Response.json(
        {
          title: "Unsupported Media Type",
          detail: "Content-Type must be application/json.",
          status: 415,
          code: "INVALID_CONTENT_TYPE",
        },
        { status: 415 }
      ),
    }
  }

  const contentLength = request.headers.get("content-length")
  if (contentLength && parseInt(contentLength, 10) > maxSizeBytes) {
    return {
      ok: false,
      response: Response.json(
        {
          title: "Payload Too Large",
          detail: "Request body exceeds allowed size limit.",
          status: 413,
          code: "BODY_TOO_LARGE",
        },
        { status: 413 }
      ),
    }
  }

  let buffer: ArrayBuffer
  try {
    buffer = await request.arrayBuffer()
  } catch {
    return {
      ok: false,
      response: Response.json(
        {
          title: "Bad Request",
          detail: "Failed to read request body.",
          status: 400,
          code: "BAD_REQUEST",
        },
        { status: 400 }
      ),
    }
  }

  if (buffer.byteLength > maxSizeBytes) {
    return {
      ok: false,
      response: Response.json(
        {
          title: "Payload Too Large",
          detail: "Request body exceeds allowed size limit.",
          status: 413,
          code: "BODY_TOO_LARGE",
        },
        { status: 413 }
      ),
    }
  }

  const text = new TextDecoder().decode(buffer)
  if (text.trim().length === 0) {
    return {
      ok: false,
      response: Response.json(
        {
          title: "Bad Request",
          detail: "Request body cannot be empty.",
          status: 400,
          code: "EMPTY_BODY",
        },
        { status: 400 }
      ),
    }
  }

  try {
    const data = JSON.parse(text) as T
    return { ok: true, data }
  } catch {
    return {
      ok: false,
      response: Response.json(
        {
          title: "Bad Request",
          detail: "Malformed JSON payload.",
          status: 400,
          code: "MALFORMED_JSON",
        },
        { status: 400 }
      ),
    }
  }
}
