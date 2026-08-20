import { hardenedFetch } from "../../lib/hardened-fetch.server"
import { parseJsonBodyGuarded } from "../../lib/request-body-guard.server"
import type { Route } from "./+types/visits-by-id"

export async function action({ request, params }: Route.ActionArgs) {
  const { visitId } = params

  if (request.method === "PATCH") {
    const parsed = await parseJsonBodyGuarded<{ visitedOn?: string; note?: string }>(request, 8192)
    if (!parsed.ok) {
      return parsed.response
    }
    const { visitedOn, note } = parsed.data
    return hardenedFetch(request, `/api/v1/me/visits/${visitId}`, {
      method: "PATCH",
      body: JSON.stringify({ visitedOn, note }),
    })
  }

  if (request.method === "DELETE") {
    return hardenedFetch(request, `/api/v1/me/visits/${visitId}`, {
      method: "DELETE",
    })
  }

  return Response.json({ detail: "Method not allowed" }, { status: 405 })
}
