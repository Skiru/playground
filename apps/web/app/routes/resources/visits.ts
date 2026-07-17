import type { Route } from "./+types/visits"

const baseUrl = process.env.API_BASE_URL ?? "http://api"

export async function loader({ request }: Route.LoaderArgs) {
  const cookie = request.headers.get("cookie") || ""
  const url = new URL(request.url)
  const page = url.searchParams.get("page") || "1"

  const forwardHeaders = new Headers()
  if (cookie) forwardHeaders.set("cookie", cookie)

  const res = await fetch(`${baseUrl}/api/v1/me/visits?page=${page}`, {
    headers: forwardHeaders,
  })

  if (!res.ok) {
    return Response.json(
      { items: [], pagination: { page: 1, pageSize: 20, totalItems: 0, totalPages: 1 } },
      { status: res.status }
    )
  }

  const data = await res.json()
  return Response.json(data)
}

export async function action({ request }: Route.ActionArgs) {
  const cookie = request.headers.get("cookie") || ""
  const csrfToken = request.headers.get("x-csrf-token") || ""

  const forwardHeaders = new Headers()
  forwardHeaders.set("content-type", "application/json")
  if (cookie) forwardHeaders.set("cookie", cookie)
  if (csrfToken) forwardHeaders.set("x-csrf-token", csrfToken)

  if (request.method === "POST") {
    const { placeId, visitedOn, note } = await request.json()
    const res = await fetch(`${baseUrl}/api/v1/places/${placeId}/visits`, {
      method: "POST",
      headers: forwardHeaders,
      body: JSON.stringify({ visitedOn, note }),
    })

    const data = await res.json().catch(() => ({}))
    return Response.json(data, { status: res.status })
  }

  return Response.json({ detail: "Method not allowed" }, { status: 405 })
}
