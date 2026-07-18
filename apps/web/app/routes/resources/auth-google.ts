import { hardenedFetch } from "../../lib/hardened-fetch.server"
import type { Route } from "./+types/auth-google"

export async function action({ request }: Route.ActionArgs) {
  if (request.method !== "POST") {
    return Response.json({ detail: "Method not allowed" }, { status: 405 })
  }

  const body = await request.json()
  const { credential } = body

  if (!credential) {
    return Response.json({ detail: "Missing credential" }, { status: 400 })
  }

  return hardenedFetch(request, "/api/v1/auth/google", {
    method: "POST",
    body: JSON.stringify({ credential }),
  })
}
