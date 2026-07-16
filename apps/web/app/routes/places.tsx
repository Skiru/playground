import type { SearchPlacesData, SearchPlacesResponse } from "@family-places/api-client";

import { MapExplorer } from "../components/MapExplorer";
import { SiteHeader } from "../components/SiteHeader";
import { loadAmenities, loadCategories, loadCities, loadMapPlaces, loadPlaces } from "../lib/api.server";
import type { Route } from "./+types/places";

type SearchQuery = NonNullable<SearchPlacesData["query"]>;

function numberParam(params: URLSearchParams, name: string): number | undefined {
  const raw = params.get(name);
  if (!raw) return undefined;
  const value = Number(raw);
  if (!Number.isFinite(value)) throw new Response(`${name} must be numeric.`, { status: 400 });
  return value;
}

function searchQuery(params: URLSearchParams): SearchQuery {
  const query: SearchQuery = {};
  for (const name of ["city", "category", "q", "sort"] as const) {
    const value = params.get(name);
    if (value) query[name] = value;
  }
  for (const name of ["ageMonths", "latitude", "longitude", "radiusKm", "page", "pageSize"] as const) {
    const value = numberParam(params, name);
    if (value !== undefined) query[name] = value;
  }
  for (const name of ["indoor", "outdoor", "freeEntry", "openNow"] as const) {
    if (params.get(name) === "true") query[name] = true;
  }
  const amenities = [...params.getAll("amenities"), ...params.getAll("amenities[]")].slice(0, 10);
  if (amenities.length) query.amenities = amenities;
  return query;
}

export async function loader({ request }: Route.LoaderArgs) {
  const url = new URL(request.url);
  const query = searchQuery(url.searchParams);
  const [places, cities, categories, amenities] = await Promise.all([loadPlaces(query), loadCities(), loadCategories(), loadAmenities()]);
  const anchor = places.items[0];
  const map = anchor
    ? await loadMapPlaces({ ...query, west: anchor.longitude - 0.3, south: anchor.latitude - 0.2, east: anchor.longitude + 0.3, north: anchor.latitude + 0.2, zoom: 10 })
    : { type: "FeatureCollection" as const, features: [], truncated: false };
  const resourceParams = new URLSearchParams(url.searchParams);
  resourceParams.delete("page");
  resourceParams.delete("pageSize");
  resourceParams.delete("sort");
  const page = places.pagination.page;
  const pageUrl = (target: number) => {
    const next = new URL(url);
    next.searchParams.set("page", String(target));
    return `${next.pathname}${next.search}`;
  };

  return {
    places,
    map,
    filters: Object.fromEntries(url.searchParams),
    resourceQuery: resourceParams.toString(),
    mapStyleUrl: process.env.MAP_STYLE_URL ?? "",
    mapAttribution: process.env.MAP_ATTRIBUTION ?? "",
    cities: cities.items,
    categories: categories.items,
    amenities: amenities.items,
    previousPageUrl: page > 1 ? pageUrl(page - 1) : null,
    nextPageUrl: page < places.pagination.totalPages ? pageUrl(page + 1) : null,
  };
}

export function meta() {
  return [{ title: "Katalog miejsc | FamilyPlaces" }, { name: "description", content: "Znajdź rodzinne miejsca według miasta, wieku i udogodnień." }];
}

export function PlacesView({ places, previousPageUrl, nextPageUrl }: { places: SearchPlacesResponse; previousPageUrl?: string | null; nextPageUrl?: string | null }) {
  return (
    <section className="results" aria-labelledby="results-heading">
      <div className="section-heading">
        <p className="eyebrow">Znalezione miejsca</p>
        <h1 id="results-heading">{places.pagination.totalItems} propozycji dla rodziny</h1>
      </div>
      {places.items.length ? (
        <ol className="place-grid">
          {places.items.map((place) => (
            <li key={place.id}>
              <article className="place-card">
                <p className="place-meta">{place.city} · {place.indoor ? "wewnątrz" : "na zewnątrz"}</p>
                <h2><a href={`/miejsca/${place.slug}`}>{place.name}</a></h2>
                <p>{place.short_description}</p>
                <div className="tag-row">
                  {place.categories?.map((category) => <span key={category.slug}>{category.name}</span>)}
                  {place.free_entry ? <span>bezpłatnie</span> : null}
                </div>
              </article>
            </li>
          ))}
        </ol>
      ) : <p className="empty-state">Brak miejsc dla tych filtrów. Zmień kryteria i spróbuj ponownie.</p>}
      {previousPageUrl || nextPageUrl ? (
        <nav className="pagination" aria-label="Strony wyników">
          {previousPageUrl ? <a href={previousPageUrl}>← Poprzednia</a> : <span />}
          <span>Strona {places.pagination.page} z {places.pagination.totalPages}</span>
          {nextPageUrl ? <a href={nextPageUrl}>Następna →</a> : <span />}
        </nav>
      ) : null}
    </section>
  );
}

export default function Places({ loaderData }: Route.ComponentProps) {
  return (
    <main className="shell">
      <SiteHeader />
      <form className="filter-bar" method="get">
        <label>Szukaj <input name="q" defaultValue={loaderData.filters.q} /></label>
        <label>Miasto <select name="city" defaultValue={loaderData.filters.city ?? ""}><option value="">Wszystkie</option>{loaderData.cities.map((city) => <option key={city.id} value={city.slug}>{city.name}</option>)}</select></label>
        <label>Kategoria <select name="category" defaultValue={loaderData.filters.category ?? ""}><option value="">Wszystkie</option>{loaderData.categories.map((category) => <option key={category.id} value={category.slug}>{category.name}</option>)}</select></label>
        <label>Wiek w miesiącach <input name="ageMonths" type="number" min="0" max="216" defaultValue={loaderData.filters.ageMonths} /></label>
        <label>Latitude <input name="latitude" type="number" min="-90" max="90" step="any" defaultValue={loaderData.filters.latitude} /></label>
        <label>Longitude <input name="longitude" type="number" min="-180" max="180" step="any" defaultValue={loaderData.filters.longitude} /></label>
        <label>Promień km <input name="radiusKm" type="number" min="1" max="100" step="1" defaultValue={loaderData.filters.radiusKm} /></label>
        <label className="check"><input name="indoor" type="checkbox" value="true" defaultChecked={loaderData.filters.indoor === "true"} /> wewnątrz</label>
        <fieldset><legend>Udogodnienia (wszystkie wybrane)</legend>{loaderData.amenities.slice(0, 8).map((amenity) => { const params = new URLSearchParams(loaderData.resourceQuery); return <label className="check" key={amenity.id}><input name="amenities[]" type="checkbox" value={amenity.slug} defaultChecked={[...params.getAll("amenities"), ...params.getAll("amenities[]")].includes(amenity.slug)} /> {amenity.name}</label>; })}</fieldset>
        <button type="submit">Filtruj</button>
      </form>
      <div className="results-layout">
        <PlacesView places={loaderData.places} previousPageUrl={loaderData.previousPageUrl} nextPageUrl={loaderData.nextPageUrl} />
        <MapExplorer initialFeatures={loaderData.map.features} styleUrl={loaderData.mapStyleUrl} attribution={loaderData.mapAttribution} filterQuery={loaderData.resourceQuery} />
      </div>
    </main>
  );
}
