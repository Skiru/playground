import { hardenedFetch } from "../../lib/hardened-fetch.server"
import { parseJsonBodyGuarded } from "../../lib/request-body-guard.server"
import type { Route } from "./+types/auth-google"

export async function action({ request }: Route.ActionArgs) {
  if (request.method !== "POST") {
    return Response.json({ detail: "Method not allowed" }, { status: 405 })
  }

  const parsed = await parseJsonBodyGuarded<{ credential?: string }>(request, 8192)
  if (!parsed.ok) {
    return parsed.response
  }

  const { credential } = parsed.data
  if (!credential || typeof credential !== "string" || !credential.trim()) {
    return Response.json(
      { title: "Bad Request", detail: "Missing credential.", status: 400, code: "MISSING_CREDENTIAL" },
      { status: 400 }
    )
  }

  return hardenedFetch(request, "/api/v1/auth/google", {
    method: "POST",
    body: JSON.stringify({ credential: credential.trim() }),
  })
}
