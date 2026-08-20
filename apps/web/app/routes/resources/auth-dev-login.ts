import { hardenedFetch } from "../../lib/hardened-fetch.server"
import { parseJsonBodyGuarded } from "../../lib/request-body-guard.server"
import type { Route } from "./+types/auth-dev-login"

export async function action({ request }: Route.ActionArgs) {
  if (request.method !== "POST") {
    return Response.json({ detail: "Method not allowed" }, { status: 405 })
  }

  const parsed = await parseJsonBodyGuarded<{ email?: string; displayName?: string; roles?: string[] }>(request, 8192)
  if (!parsed.ok) {
    return parsed.response
  }

  return hardenedFetch(request, "/api/v1/dev-auth/login", {
    method: "POST",
    body: JSON.stringify(parsed.data),
  })
}
