import { hardenedFetch } from "../../lib/hardened-fetch.server"
import type { Route } from "./+types/session"

export async function loader({ request }: Route.LoaderArgs) {
  return hardenedFetch(request, "/api/v1/session")
}
