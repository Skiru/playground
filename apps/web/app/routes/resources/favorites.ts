import type { Route } from "./+types/favorites"

const baseUrl = process.env.API_BASE_URL ?? "http://api"

export async function loader({ request }: Route.LoaderArgs) {
  const cookie = request.headers.get("cookie") || ""
  const url = new URL(request.url)
  const page = url.searchParams.get("page") || "1"

  const forwardHeaders = new Headers()
  if (cookie) forwardHeaders.set("cookie", cookie)

  const res = await fetch(`${baseUrl}/api/v1/me/favorites?page=${page}`, {
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
  if (cookie) forwardHeaders.set("cookie", cookie)
  if (csrfToken) forwardHeaders.set("x-csrf-token", csrfToken)

  if (request.method === "POST") {
    const { placeId } = await request.json()
    const res = await fetch(`${baseUrl}/api/v1/places/${placeId}/favorite`, {
      method: "PUT",
      headers: forwardHeaders,
    })

    const data = await res.json().catch(() => ({}))
    return Response.json(data, { status: res.status })
  }

  if (request.method === "DELETE") {
    const url = new URL(request.url)
    const placeId = url.searchParams.get("placeId")

    const res = await fetch(`${baseUrl}/api/v1/places/${placeId}/favorite`, {
      method: "DELETE",
      headers: forwardHeaders,
    })

    return new Response(null, { status: res.status })
  }

  return Response.json({ detail: "Method not allowed" }, { status: 405 })
}
