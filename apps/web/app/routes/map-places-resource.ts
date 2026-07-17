import type { GetMapPlacesData } from "@family-places/api-client";

import { loadMapPlaces } from "../lib/api.server";
import type { Route } from "./+types/map-places-resource";

function requiredNumber(params: URLSearchParams, name: string): number {
  const raw = params.get(name);
  if (!raw) throw new Response(`${name} is required.`, { status: 400 });
  const value = Number(raw);
  if (!Number.isFinite(value)) throw new Response(`${name} must be numeric.`, { status: 400 });
  return value;
}

export async function loader({ request }: Route.LoaderArgs) {
  const params = new URL(request.url).searchParams;
  const query: GetMapPlacesData["query"] = {
    west: requiredNumber(params, "west"),
    south: requiredNumber(params, "south"),
    east: requiredNumber(params, "east"),
    north: requiredNumber(params, "north"),
    zoom: requiredNumber(params, "zoom"),
  };

  for (const name of ["city", "category", "q"] as const) {
    const value = params.get(name);
    if (value) query[name] = value;
  }
  const age = params.get("ageMonths");
  if (age) query.ageMonths = requiredNumber(params, "ageMonths");
  for (const name of ["latitude", "longitude", "radiusKm"] as const) {
    if (params.has(name)) query[name] = requiredNumber(params, name);
  }
  for (const name of ["indoor", "outdoor", "freeEntry", "openNow"] as const) {
    const value = params.get(name);
    if (value === "true" || value === "false") query[name] = value === "true";
  }
  const amenities = params.getAll("amenities");
  if (amenities.length) query.amenities = amenities;

  return Response.json(await loadMapPlaces(query));
}
