import { logout } from "../../lib/api-session.server"
import type { Route } from "./+types/auth-logout"

export async function action({ request }: Route.ActionArgs) {
  if (request.method !== "POST") {
    return Response.json({ detail: "Method not allowed" }, { status: 405 })
  }

  const csrfToken = request.headers.get("x-csrf-token") || ""
  const { status, setCookie } = await logout(csrfToken, request.headers)

  const headers = new Headers()
  headers.set("Cache-Control", "no-store")
  if (setCookie) {
    headers.set("Set-Cookie", setCookie)
  }

  return new Response(null, { status, headers })
}
