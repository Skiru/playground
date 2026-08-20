export function isSafeRedirectUrl(urlStr: string, allowedOrigins: string[] = []): boolean {
  if (!urlStr || typeof urlStr !== "string") return false
  const trimmed = urlStr.trim()

  // Disallow protocol-relative URLs (//evil.com) and backslashes (/\\evil.com)
  if (trimmed.startsWith("//") || trimmed.startsWith("/\\") || trimmed.startsWith("\\\\")) {
    return false
  }

  // Relative path starting with single slash
  if (trimmed.startsWith("/")) {
    return true
  }

  try {
    const parsed = new URL(trimmed)
    if (parsed.protocol !== "http:" && parsed.protocol !== "https:") {
      return false
    }

    const host = parsed.host.toLowerCase()
    for (const allowed of allowedOrigins) {
      if (!allowed) continue
      try {
        const allowedParsed = new URL(allowed)
        if (allowedParsed.host.toLowerCase() === host) {
          return true
        }
      } catch {
        if (allowed.toLowerCase() === host) {
          return true
        }
      }
    }
  } catch {
    return false
  }

  return false
}

export function sanitizeRedirect(urlStr: string, defaultFallback = "/"): string {
  if (isSafeRedirectUrl(urlStr)) {
    return urlStr.trim()
  }
  return defaultFallback
}
