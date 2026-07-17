import { hardenedFetch } from "../../lib/hardened-fetch.server"
import type { Route } from "./+types/place-state"

export async function loader({ request }: Route.LoaderArgs) {
  const url = new URL(request.url)
  const placeIds = [...url.searchParams.getAll("placeIds"), ...url.searchParams.getAll("placeIds[]")]

  const queryParams = new URLSearchParams()
  placeIds.forEach((id) => queryParams.append("placeIds[]", id))

  return hardenedFetch(request, `/api/v1/me/place-state?${queryParams.toString()}`)
}
