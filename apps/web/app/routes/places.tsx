import type { SearchPlacesData, SearchPlacesResponse, GetCitiesResponse, GetCategoriesResponse, GetAmenitiesResponse } from "@family-places/api-client"
import { useState } from "react"
import { Link, useSearchParams } from "react-router"
import { Search, SlidersHorizontal, Map as MapIcon, List, X } from "lucide-react"

import { MapExplorer } from "../components/MapExplorer"
import { AppShell } from "../components/layout/AppShell"
import { PageContainer } from "../components/layout/PageContainer"
import { loadAmenities, loadCategories, loadCities, loadMapPlaces, loadPlaces } from "../lib/api.server"
import { content } from "../content"
import type { Route } from "./+types/places"
import { Button } from "~/components/ui/button"
import { Card, CardContent } from "~/components/ui/card"
import { Input } from "~/components/ui/input"
import { Label } from "~/components/ui/label"
import { Badge } from "~/components/ui/badge"
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "~/components/ui/sheet"
import { PlaceCard } from "~/components/places/PlaceCard"
import { StatePanel } from "~/components/states/StatePanel"

type SearchQuery = NonNullable<SearchPlacesData["query"]>

function numberParam(params: URLSearchParams, name: string): number | undefined {
  const raw = params.get(name)
  if (!raw) return undefined
  const value = Number(raw)
  if (!Number.isFinite(value)) throw new Response(`${name} must be numeric.`, { status: 400 })
  return value
}

function searchQuery(params: URLSearchParams): SearchQuery {
  const query: SearchQuery = {}
  for (const name of ["city", "category", "q", "sort"] as const) {
    const value = params.get(name)
    if (value) query[name] = value
  }
  for (const name of ["ageMonths", "latitude", "longitude", "radiusKm", "page", "pageSize"] as const) {
    const value = numberParam(params, name)
    if (value !== undefined) query[name] = value
  }
  for (const name of ["indoor", "outdoor", "freeEntry", "openNow"] as const) {
    if (params.get(name) === "true") query[name] = true
  }
  const amenities = [...params.getAll("amenities"), ...params.getAll("amenities[]")].slice(0, 10)
  if (amenities.length) query.amenities = amenities
  return query
}

export async function loader({ request }: Route.LoaderArgs) {
  const url = new URL(request.url)
  const query = searchQuery(url.searchParams)
  const [places, cities, categories, amenities] = await Promise.all([
    loadPlaces(query),
    loadCities(),
    loadCategories(),
    loadAmenities(),
  ])
  const anchor = places.items[0]
  const map = anchor
    ? await loadMapPlaces({
        ...query,
        west: anchor.longitude - 0.3,
        south: anchor.latitude - 0.2,
        east: anchor.longitude + 0.3,
        north: anchor.latitude + 0.2,
        zoom: 10,
      })
    : { type: "FeatureCollection" as const, features: [], truncated: false }
  const resourceParams = new URLSearchParams(url.searchParams)
  resourceParams.delete("page")
  resourceParams.delete("pageSize")
  resourceParams.delete("sort")
  const page = places.pagination.page
  const pageUrl = (target: number) => {
    const next = new URL(url)
    next.searchParams.set("page", String(target))
    return `${next.pathname}${next.search}`
  }

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
  }
}

export function meta() {
  return [
    { title: content.metadata.catalogTitle },
    { name: "description", content: content.metadata.catalogDescription },
  ]
}

export function PlacesView({
  places,
  previousPageUrl,
  nextPageUrl,
}: {
  places: SearchPlacesResponse
  previousPageUrl?: string | null
  nextPageUrl?: string | null
}) {
  return (
    <div className="flex flex-col gap-6">
      <div className="border-b pb-5">
        <p className="mb-1 text-xs font-extrabold uppercase tracking-[0.12em] text-accent">
          {content.places.resultsEyebrow}
        </p>
        <h1 className="text-2xl font-extrabold tracking-tight text-foreground sm:text-3xl">
          {content.places.resultsHeadingPlural(places.pagination.totalItems)}
        </h1>
      </div>

      {places.items.length ? (
        <ol className="flex flex-col gap-5">
          {places.items.map((place) => (
            <li key={place.id}>
              <PlaceCard place={place} layout="horizontal" showFavorite />
            </li>
          ))}
        </ol>
      ) : (
        <StatePanel
          title="Nie znaleźliśmy pasujących miejsc"
          description={content.places.noResults}
          action={<Button variant="outline" asChild><Link to="/miejsca">{content.places.clearFilters}</Link></Button>}
        />
      )}

      {/* Pagination */}
      {places.pagination.totalPages > 1 && (
        <nav className="flex items-center justify-between border-t pt-6" aria-label={content.places.paginationLabel}>
          <div className="w-24">
            {previousPageUrl ? (
              <Button variant="outline" size="sm" asChild>
                <Link to={previousPageUrl}>{content.places.previousPage}</Link>
              </Button>
            ) : null}
          </div>
          <p className="text-sm text-muted-foreground">
            {content.places.paginationPageInfo(places.pagination.page, places.pagination.totalPages)}
          </p>
          <div className="w-24 text-right">
            {nextPageUrl ? (
              <Button variant="outline" size="sm" asChild>
                <Link to={nextPageUrl}>{content.places.nextPage}</Link>
              </Button>
            ) : null}
          </div>
        </nav>
      )}
    </div>
  )
}

export function FilterFields({
  cities,
  categories,
  amenities,
  filters,
  resourceQuery,
}: {
  cities: GetCitiesResponse["items"]
  categories: GetCategoriesResponse["items"]
  amenities: GetAmenitiesResponse["items"]
  filters: Record<string, string>
  resourceQuery: string
}) {
  return (
    <div className="flex flex-col gap-6 p-1">
      {/* Search Input */}
      <div className="grid gap-2">
        <Label htmlFor="q" className="text-sm font-bold">
          {content.places.formSearch}
        </Label>
        <div className="relative">
          <Search className="absolute left-3 top-2.5 size-4 text-muted-foreground" />
          <Input
            id="q"
            name="q"
            defaultValue={filters.q}
            placeholder={content.places.searchPlaceholder}
            className="h-11 pl-10 text-base"
          />
        </div>
      </div>

      {/* City Select */}
      <div className="grid gap-2">
        <Label htmlFor="city" className="text-sm font-bold">
          {content.places.formCity}
        </Label>
        <select
          id="city"
          name="city"
          defaultValue={filters.city ?? ""}
          className="flex h-11 w-full rounded-[var(--radius-control)] border border-input bg-background px-3 text-base shadow-xs focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/40"
        >
          <option value="">{content.places.allCitiesOption}</option>
          {cities.map((city) => (
            <option key={city.id} value={city.slug}>
              {city.name}
            </option>
          ))}
        </select>
      </div>

      {/* Category Select */}
      <div className="grid gap-2">
        <Label htmlFor="category" className="text-sm font-bold">
          {content.places.formCategory}
        </Label>
        <select
          id="category"
          name="category"
          defaultValue={filters.category ?? ""}
          className="flex h-11 w-full rounded-[var(--radius-control)] border border-input bg-background px-3 text-base shadow-xs focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/40"
        >
          <option value="">{content.places.allCategoriesOption}</option>
          {categories.map((category) => (
            <option key={category.id} value={category.slug}>
              {category.name}
            </option>
          ))}
        </select>
      </div>

      {/* Age Input */}
      <div className="grid gap-2">
        <Label htmlFor="ageMonths" className="text-sm font-bold">
          {content.places.formAge}
        </Label>
        <Input
          id="ageMonths"
          name="ageMonths"
          type="number"
          min="0"
          max="216"
          defaultValue={filters.ageMonths}
          placeholder={content.places.agePlaceholder}
          className="h-11 text-base"
        />
      </div>

      {/* Location radius fields */}
      <div className="grid gap-4 border-t border-b py-4 border-muted/50 my-1">
        <div className="grid grid-cols-2 gap-2">
          <div className="grid gap-1.5">
            <Label htmlFor="latitude" className="text-sm font-semibold text-muted-foreground">
              {content.places.formLat}
            </Label>
            <Input
              id="latitude"
              name="latitude"
              type="number"
              min="-90"
              max="90"
              step="any"
              defaultValue={filters.latitude}
              className="h-11 text-base"
            />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="longitude" className="text-sm font-semibold text-muted-foreground">
              {content.places.formLng}
            </Label>
            <Input
              id="longitude"
              name="longitude"
              type="number"
              min="-180"
              max="180"
              step="any"
              defaultValue={filters.longitude}
              className="h-11 text-base"
            />
          </div>
        </div>
        <div className="grid gap-2">
          <Label htmlFor="radiusKm" className="text-sm font-bold">
            {content.places.formRadius}
          </Label>
          <Input
            id="radiusKm"
            name="radiusKm"
            type="number"
            min="1"
            max="100"
            step="1"
            defaultValue={filters.radiusKm}
            placeholder={content.places.radiusPlaceholder}
            className="h-11 text-base"
          />
        </div>
      </div>

      {/* Toggles */}
      <div className="flex flex-col gap-3">
        <label className="flex min-h-11 cursor-pointer items-center gap-3 text-base">
          <input
            type="checkbox"
            name="indoor"
            value="true"
            defaultChecked={filters.indoor === "true"}
            className="size-5 rounded border-input text-primary focus:ring-primary"
          />
          <span className="font-semibold">{content.places.formIndoor}</span>
        </label>
      </div>

      {/* Amenities fieldset */}
      <div role="group" aria-label="Udogodnienia" className="grid gap-3">
        <Label className="text-sm font-bold">
          {content.places.formAmenitiesHeader}
        </Label>
        <div className="flex flex-col gap-2.5 max-h-48 overflow-y-auto pr-1">
          {amenities.slice(0, 8).map((amenity) => {
            const params = new URLSearchParams(resourceQuery)
            const isChecked = [...params.getAll("amenities"), ...params.getAll("amenities[]")].includes(amenity.slug)
            return (
              <label key={amenity.id} className="flex min-h-10 cursor-pointer items-center gap-3 text-sm">
                <input
                  type="checkbox"
                  name="amenities[]"
                  value={amenity.slug}
                  defaultChecked={isChecked}
                  className="size-5 rounded border-input text-primary focus:ring-primary"
                />
                <span>{amenity.name}</span>
              </label>
            )
          })}
        </div>
      </div>

      <Button type="submit" className="mt-2 w-full font-bold">
        {content.places.filterButton}
      </Button>
    </div>
  )
}

export default function Places({ loaderData }: Route.ComponentProps) {
  const [viewMode, setViewMode] = useState<"list" | "map">("list")
  const [searchParams] = useSearchParams()

  // Generate active filter badges to display
  const activeFilters: Array<{ key: string; label: string; value: string; paramValue?: string }> = []
  if (loaderData.filters.q) activeFilters.push({ key: "q", label: "Szukaj", value: loaderData.filters.q })
  if (loaderData.filters.city) {
    const cityName = loaderData.cities.find((c) => c.slug === loaderData.filters.city)?.name || loaderData.filters.city
    activeFilters.push({ key: "city", label: "Miasto", value: cityName })
  }
  if (loaderData.filters.category) {
    const catName = loaderData.categories.find((c) => c.slug === loaderData.filters.category)?.name || loaderData.filters.category
    activeFilters.push({ key: "category", label: "Kategoria", value: catName })
  }
  if (loaderData.filters.ageMonths) activeFilters.push({ key: "ageMonths", label: "Wiek", value: `${loaderData.filters.ageMonths} m-cy` })
  if (loaderData.filters.indoor === "true") activeFilters.push({ key: "indoor", label: "Przestrzeń", value: "wewnątrz" })
  if (loaderData.filters.outdoor === "true") activeFilters.push({ key: "outdoor", label: "Przestrzeń", value: "na zewnątrz" })
  if (loaderData.filters.freeEntry === "true") activeFilters.push({ key: "freeEntry", label: "Wstęp", value: "bezpłatny" })
  if (loaderData.filters.openNow === "true") activeFilters.push({ key: "openNow", label: "Godziny", value: "otwarte teraz" })
  if (loaderData.filters.radiusKm) activeFilters.push({ key: "radiusKm", label: "Promień", value: `${loaderData.filters.radiusKm} km` })
  if (loaderData.filters.sort) activeFilters.push({ key: "sort", label: "Sortowanie", value: loaderData.filters.sort })
  const selectedAmenities = [...searchParams.getAll("amenities"), ...searchParams.getAll("amenities[]")]
  selectedAmenities.forEach((slug) => {
    const name = loaderData.amenities.find((amenity) => amenity.slug === slug)?.name ?? slug
    activeFilters.push({ key: "amenities[]", label: "Udogodnienie", value: name, paramValue: slug })
  })

  const hasActiveFilters = activeFilters.length > 0

  return (
    <AppShell>
      <PageContainer className="py-6">
        {/* Toggle between list and map on mobile */}
        <div className="mb-5 flex w-full overflow-hidden rounded-[var(--radius-button)] border bg-card p-1 shadow-xs xl:hidden">
          <Button
            variant={viewMode === "list" ? "default" : "ghost"}
            className="flex-1 font-bold"
            onClick={() => setViewMode("list")}
            aria-pressed={viewMode === "list"}
          >
            <List className="mr-1.5 size-4" />
            Lista ({loaderData.places.pagination.totalItems})
          </Button>
          <Button
            variant={viewMode === "map" ? "default" : "ghost"}
            className="flex-1 font-bold"
            onClick={() => setViewMode("map")}
            aria-pressed={viewMode === "map"}
          >
            <MapIcon className="mr-1.5 size-4" />
            Mapa ({loaderData.map.features.length})
          </Button>
        </div>

        {/* Search Toolbar (Active Filters) */}
        {hasActiveFilters && (
          <div className="z-30 mb-6 flex flex-wrap items-center gap-2 rounded-[var(--radius-card)] border bg-background/95 p-3 shadow-sm backdrop-blur lg:sticky lg:top-[4.5rem]">
            <span className="text-sm font-bold text-muted-foreground">
              Aktywne filtry:
            </span>
            <div className="flex flex-wrap gap-1.5 flex-1">
              {activeFilters.map((filter) => {
                // Build a URL without this filter
                const nextParams = new URLSearchParams(searchParams)
                if (filter.key === "amenities[]") {
                  const items = nextParams.getAll("amenities[]").filter((x) => x !== filter.paramValue)
                  nextParams.delete("amenities[]")
                  items.forEach((x) => nextParams.append("amenities[]", x))
                  const plainItems = nextParams.getAll("amenities").filter((x) => x !== filter.paramValue)
                  nextParams.delete("amenities")
                  plainItems.forEach((x) => nextParams.append("amenities", x))
                } else {
                  nextParams.delete(filter.key)
                }
                return (
                  <Badge
                    key={`${filter.key}-${filter.value}`}
                    variant="secondary"
                    className="min-h-10 gap-1 border bg-card px-3 font-semibold hover:bg-muted"
                  >
                    <span className="text-muted-foreground font-normal">{filter.label}:</span>
                    {filter.value}
                    <Link to={`/miejsca?${nextParams.toString()}`} className="inline-flex size-8 items-center justify-center rounded-full" aria-label={`Wyczyść filtr ${filter.label}`}>
                      <X className="size-4 text-muted-foreground hover:text-foreground" />
                    </Link>
                  </Badge>
                )
              })}
            </div>
            <Button size="sm" variant="ghost" asChild className="font-bold text-muted-foreground hover:text-foreground">
              <Link to="/miejsca">Wyczyść wszystko</Link>
            </Button>
          </div>
        )}

        <div className="grid grid-cols-1 gap-6 lg:grid-cols-[280px_minmax(0,1fr)] xl:gap-8">
          {/* Desktop Filter Panel */}
          <aside className="hidden lg:block">
            <Card className="sticky top-[6rem] border-border/90 bg-card/80 shadow-sm backdrop-blur-sm">
              <CardContent className="p-5">
                <div className="flex items-center justify-between border-b pb-3 mb-4">
                  <h2 className="flex items-center text-lg font-bold">
                    <SlidersHorizontal className="mr-2 size-4 text-primary" />
                    Filtry
                  </h2>
                  {hasActiveFilters && (
                    <Button size="sm" variant="ghost" asChild className="font-bold text-muted-foreground">
                      <Link to="/miejsca">Reset</Link>
                    </Button>
                  )}
                </div>
                <form method="get" action="/miejsca">
                  <FilterFields
                    cities={loaderData.cities}
                    categories={loaderData.categories}
                    amenities={loaderData.amenities}
                    filters={loaderData.filters}
                    resourceQuery={loaderData.resourceQuery}
                  />
                </form>
              </CardContent>
            </Card>
          </aside>

          {/* Results Layout */}
          <div className="grid min-w-0 grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1.12fr)_minmax(320px,0.88fr)] xl:gap-8">
            {/* List View Column */}
            <div className={`${viewMode === "list" ? "block" : "hidden xl:block"}`}>
              {/* Mobile Filter Sheet button inside list view */}
              <div className="mb-4 flex items-center justify-between gap-3 lg:hidden">
                <Sheet>
                  <SheetTrigger asChild>
                    <Button variant="outline" size="sm" className="gap-1.5 font-semibold">
                      <SlidersHorizontal className="size-3.5" />
                      Filtruj propozycje
                    </Button>
                  </SheetTrigger>
                  <SheetContent side="left" className="w-[300px] overflow-y-auto p-6">
                    <SheetHeader className="text-left border-b pb-4 mb-4">
                      <SheetTitle className="text-lg font-bold flex items-center">
                        <SlidersHorizontal className="mr-2 size-4 text-primary" />
                        Filtry wyszukiwania
                      </SheetTitle>
                    </SheetHeader>
                    <form method="get" action="/miejsca">
                      <FilterFields
                        cities={loaderData.cities}
                        categories={loaderData.categories}
                        amenities={loaderData.amenities}
                        filters={loaderData.filters}
                        resourceQuery={loaderData.resourceQuery}
                      />
                    </form>
                  </SheetContent>
                </Sheet>
                <span className="text-sm text-muted-foreground">
                  Propozycje: {loaderData.places.pagination.totalItems}
                </span>
              </div>

              <PlacesView
                places={loaderData.places}
                previousPageUrl={loaderData.previousPageUrl}
                nextPageUrl={loaderData.nextPageUrl}
              />
            </div>

            {/* Map View Column */}
            <div className={`relative ${viewMode === "map" ? "block" : "hidden xl:block"}`}>
              <Card className="sticky top-[6rem] overflow-hidden border-border/90 bg-card py-0 shadow-sm">
                <CardContent className="p-0">
                  <MapExplorer
                    initialFeatures={loaderData.map.features}
                    styleUrl={loaderData.mapStyleUrl}
                    attribution={loaderData.mapAttribution}
                    filterQuery={loaderData.resourceQuery}
                  />
                </CardContent>
              </Card>
            </div>
          </div>
        </div>
      </PageContainer>
    </AppShell>
  )
}
