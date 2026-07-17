import type { Route } from "./+types/visits-by-id"

const baseUrl = process.env.API_BASE_URL ?? "http://api"

export async function action({ request, params }: Route.ActionArgs) {
  const cookie = request.headers.get("cookie") || ""
  const csrfToken = request.headers.get("x-csrf-token") || ""
  const { visitId } = params

  const forwardHeaders = new Headers()
  forwardHeaders.set("content-type", "application/json")
  if (cookie) forwardHeaders.set("cookie", cookie)
  if (csrfToken) forwardHeaders.set("x-csrf-token", csrfToken)

  if (request.method === "PATCH") {
    const { visitedOn, note } = await request.json()
    const res = await fetch(`${baseUrl}/api/v1/me/visits/${visitId}`, {
      method: "PATCH",
      headers: forwardHeaders,
      body: JSON.stringify({ visitedOn, note }),
    })

    const data = await res.json().catch(() => ({}))
    return Response.json(data, { status: res.status })
  }

  if (request.method === "DELETE") {
    const res = await fetch(`${baseUrl}/api/v1/me/visits/${visitId}`, {
      method: "DELETE",
      headers: forwardHeaders,
    })

    return new Response(null, { status: res.status })
  }

  return Response.json({ detail: "Method not allowed" }, { status: 405 })
}
