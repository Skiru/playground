import { hardenedFetch } from "../../lib/hardened-fetch.server"
import type { Route } from "./+types/visits-by-id"

export async function action({ request, params }: Route.ActionArgs) {
  const { visitId } = params

  if (request.method === "PATCH") {
    const { visitedOn, note } = await request.json()
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
