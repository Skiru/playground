import { hardenedFetch } from "../../lib/hardened-fetch.server"
import type { Route } from "./+types/auth-logout"

export async function action({ request }: Route.ActionArgs) {
  if (request.method !== "POST") {
    return Response.json({ detail: "Method not allowed" }, { status: 405 })
  }

  return hardenedFetch(request, "/api/v1/logout", {
    method: "POST",
  })
}
