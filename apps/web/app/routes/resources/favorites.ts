import { hardenedFetch } from "../../lib/hardened-fetch.server"
import type { Route } from "./+types/favorites"

export async function loader({ request }: Route.LoaderArgs) {
  const url = new URL(request.url)
  const page = url.searchParams.get("page") || "1"
  return hardenedFetch(request, `/api/v1/me/favorites?page=${page}`)
}

export async function action({ request }: Route.ActionArgs) {
  if (request.method === "POST") {
    const { placeId } = await request.json()
    return hardenedFetch(request, `/api/v1/places/${placeId}/favorite`, {
      method: "PUT",
    })
  }

  if (request.method === "DELETE") {
    const url = new URL(request.url)
    const placeId = url.searchParams.get("placeId")
    return hardenedFetch(request, `/api/v1/places/${placeId}/favorite`, {
      method: "DELETE",
    })
  }

  return Response.json({ detail: "Method not allowed" }, { status: 405 })
}
