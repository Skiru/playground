import type { Route } from "./+types/place-state"

const baseUrl = process.env.API_BASE_URL ?? "http://api"

export async function loader({ request }: Route.LoaderArgs) {
  const cookie = request.headers.get("cookie") || ""
  const url = new URL(request.url)
  const placeIds = [...url.searchParams.getAll("placeIds"), ...url.searchParams.getAll("placeIds[]")]

  const forwardHeaders = new Headers()
  if (cookie) forwardHeaders.set("cookie", cookie)

  const queryParams = new URLSearchParams()
  placeIds.forEach((id) => queryParams.append("placeIds[]", id))

  const res = await fetch(`${baseUrl}/api/v1/me/place-state?${queryParams.toString()}`, {
    headers: forwardHeaders,
  })

  if (!res.ok) {
    return Response.json({}, { status: res.status })
  }

  const data = await res.json()
  return Response.json(data)
}
