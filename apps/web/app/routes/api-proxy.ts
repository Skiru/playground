import type { Route } from "./+types/api-proxy"
import { proxyApiRequest } from "~/lib/api-proxy.server"

function getApiPath(params: Route.LoaderArgs["params"]): string {
  const splat = params["*"] ?? ""
  return `/api/${splat}`
}

export function loader({ request, params }: Route.LoaderArgs) {
  return proxyApiRequest(request, getApiPath(params))
}

export function action({ request, params }: Route.ActionArgs) {
  return proxyApiRequest(request, getApiPath(params))
}
