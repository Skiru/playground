// Canonical definitions of all API endpoint patterns allowed by BFF policy.
// Any endpoint not explicitly matching one of these patterns will be rejected with 403.

const ALLOWED_EXACT_PATHS = new Set([
  "/api/v1/session",
  "/api/v1/auth/google",
  "/api/v1/dev-auth/login",
  "/api/v1/logout",
  "/api/v1/health/live",
  "/api/v1/health/ready",
  "/api/v1/amenities",
  "/api/v1/categories",
  "/api/v1/cities",
  "/api/v1/places",
  "/api/v1/map/places",
  "/api/v1/community/feed",
  "/api/v1/forum",
  "/api/v1/forum/categories",
  "/api/v1/me/favorites",
  "/api/v1/me/visits",
  "/api/v1/me/place-state",
  "/api/v1/me/reviews",
  "/api/v1/content-reports",
  "/api/v1/moderation/queue",
  "/api/v1/moderation/action",
])

const ALLOWED_REGEX_PATTERNS = [
  /^\/api\/v1\/places\/[^/]+$/,
  /^\/api\/v1\/places\/[^/]+\/favorite$/,
  /^\/api\/v1\/places\/[^/]+\/visits$/,
  /^\/api\/v1\/places\/[^/]+\/comments$/,
  /^\/api\/v1\/places\/[^/]+\/reviews$/,
  /^\/api\/v1\/place-comments\/[^/]+\/replies$/,
  /^\/api\/v1\/forum\/categories\/[^/]+\/threads$/,
  /^\/api\/v1\/forum\/threads\/[^/]+$/,
  /^\/api\/v1\/forum\/threads\/[^/]+\/posts$/,
  /^\/api\/v1\/me\/visits\/[^/]+$/,
  /^\/api\/v1\/me\/place-comments\/[^/]+$/,
  /^\/api\/v1\/me\/reviews\/[^/]+$/,
  /^\/api\/v1\/me\/forum-posts\/[^/]+$/,
  /^\/api\/v1\/me\/forum-threads\/[^/]+$/,
  /^\/api\/v1\/moderation\/case\/[^/]+$/,
  /^\/api\/v1\/moderation\/case\/[^/]+\/claim$/,
]

export function isApiEndpointAllowed(rawPath: string): boolean {
  if (!rawPath || typeof rawPath !== "string") {
    return false
  }

  // Strip query string before matching
  const cleanPath = rawPath.split("?")[0].trim()

  // Disallow path traversal, backslashes, double slashes, and percent-encoded sequences
  if (
    cleanPath.includes("..") ||
    cleanPath.includes("//") ||
    cleanPath.includes("\\") ||
    /%[0-9a-f]{2}/i.test(cleanPath)
  ) {
    return false
  }

  if (ALLOWED_EXACT_PATHS.has(cleanPath)) {
    return true
  }

  return ALLOWED_REGEX_PATTERNS.some((pattern) => pattern.test(cleanPath))
}
