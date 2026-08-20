import { hardenedFetch } from "../../lib/hardened-fetch.server"
import { parseJsonBodyGuarded } from "../../lib/request-body-guard.server"
import type { Route } from "./+types/visits"

export async function loader({ request }: Route.LoaderArgs) {
  const url = new URL(request.url)
  const page = url.searchParams.get("page") || "1"
  return hardenedFetch(request, `/api/v1/me/visits?page=${page}`)
}

export async function action({ request }: Route.ActionArgs) {
  if (request.method === "POST") {
    const parsed = await parseJsonBodyGuarded<{ placeId?: string; visitedOn?: string; note?: string }>(request, 8192)
    if (!parsed.ok) {
      return parsed.response
    }
    const { placeId, visitedOn, note } = parsed.data
    if (!placeId) {
      return Response.json({ detail: "Missing placeId" }, { status: 400 })
    }
    return hardenedFetch(request, `/api/v1/places/${placeId}/visits`, {
      method: "POST",
      body: JSON.stringify({ visitedOn, note }),
    })
  }

  return Response.json({ detail: "Method not allowed" }, { status: 405 })
}
