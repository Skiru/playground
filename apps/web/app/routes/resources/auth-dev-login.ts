import { hardenedFetch } from "../../lib/hardened-fetch.server"
import type { Route } from "./+types/auth-dev-login"

export async function action({ request }: Route.ActionArgs) {
  if (request.method !== "POST") {
    return Response.json({ detail: "Method not allowed" }, { status: 405 })
  }

  let body: string | undefined = undefined
  try {
    const text = await request.text()
    if (text) {
      body = text
    }
  } catch {
    // Ignored fallback
  }

  return hardenedFetch(request, "/api/v1/dev-auth/login", {
    method: "POST",
    body,
  })
}
