import { loginWithGoogle } from "../../lib/api-session.server"
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

  const { data, status, setCookie } = await loginWithGoogle(credential, request.headers)

  const headers = new Headers()
  headers.set("Cache-Control", "no-store")
  if (setCookie) {
    headers.set("Set-Cookie", setCookie)
  }

  return Response.json(data, { status, headers })
}
