import {
  getCategories,
  getCities,
  getAmenities,
  getMapPlaces,
  getPlaceBySlug,
  searchPlaces,
  type GetMapPlacesData,
  type SearchPlacesData,
} from "@family-places/api-client";

const baseUrl = process.env.API_BASE_URL ?? "http://api";

function upstreamError(result: { error?: unknown; response?: Response }, fallback: string): never {
  const detail =
    result.error && typeof result.error === "object" && "detail" in result.error
      ? String(result.error.detail)
      : fallback;

  throw new Response(detail, { status: result.response?.status ?? 502 });
}

export async function loadCities() {
  const result = await getCities({ baseUrl });
  if (!result.data) upstreamError(result, "Nie udało się pobrać listy miast.");
  return result.data;
}

export async function loadCategories() {
  const result = await getCategories({ baseUrl });
  if (!result.data) upstreamError(result, "Nie udało się pobrać listy kategorii.");
  return result.data;
}

export async function loadAmenities() {
  const result = await getAmenities({ baseUrl });
  if (!result.data) upstreamError(result, "Nie udało się pobrać listy udogodnień.");
  return result.data;
}

export async function loadPlaces(query: NonNullable<SearchPlacesData["query"]>) {
  const result = await searchPlaces({ baseUrl, query });
  if (!result.data) upstreamError(result, "Nie udało się pobrać miejsc.");
  return result.data;
}

export async function loadPlace(slug: string) {
  const result = await getPlaceBySlug({ baseUrl, path: { slug } });
  if (!result.data) upstreamError(result, "Nie znaleziono miejsca.");
  return result.data;
}

export async function loadMapPlaces(query: GetMapPlacesData["query"]) {
  const result = await getMapPlaces({ baseUrl, query });
  if (!result.data) upstreamError(result, "Nie udało się pobrać danych mapy.");
  return result.data;
}
