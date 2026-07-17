import { fetchSession } from "../../lib/api-session.server"
import type { Route } from "./+types/session"

export async function loader({ request }: Route.LoaderArgs) {
  const { data, setCookie } = await fetchSession(request.headers)

  const headers = new Headers()
  headers.set("Cache-Control", "no-store")
  if (setCookie) {
    headers.set("Set-Cookie", setCookie)
  }

  return Response.json(data, { headers })
}
