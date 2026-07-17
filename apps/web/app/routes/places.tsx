import type { SearchPlacesData, SearchPlacesResponse, GetCitiesResponse, GetCategoriesResponse, GetAmenitiesResponse } from "@family-places/api-client"
import { useState } from "react"
import { Link, useSearchParams } from "react-router"
import { Search, SlidersHorizontal, Map as MapIcon, List, X, Compass, ArrowRight } from "lucide-react"

import { MapExplorer } from "../components/MapExplorer"
import { AppShell } from "../components/layout/AppShell"
import { PageContainer } from "../components/layout/PageContainer"
import { loadAmenities, loadCategories, loadCities, loadMapPlaces, loadPlaces } from "../lib/api.server"
import { content } from "../content"
import { brand } from "../brand/default-brand"
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
      <div className="border-b pb-4">
        <p className="font-mono text-xs uppercase tracking-wider text-accent font-bold mb-1">
          {content.places.resultsEyebrow}
        </p>
        <h1 className="font-serif text-2xl sm:text-3xl font-medium text-foreground">
          {content.places.resultsHeadingPlural(places.pagination.totalItems)}
        </h1>
      </div>

      {places.items.length ? (
        <ol className="flex flex-col gap-6">
          {places.items.map((place) => (
            <li key={place.id}>
              <Card className="group overflow-hidden bg-card border hover:border-primary/50 hover:shadow-md transition-all duration-300">
                <CardContent className="p-6 flex flex-col md:flex-row gap-6">
                  {/* Aspect video thumbnail placeholder */}
                  <div className="relative w-full md:w-48 aspect-video md:aspect-[4/3] rounded-lg overflow-hidden bg-muted flex-shrink-0">
                    <img
                      src={brand.placePlaceholder.path}
                      alt={brand.placePlaceholder.alt}
                      className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
                    />
                    <Badge className="absolute top-2 left-2 bg-primary text-white font-mono text-2xs py-0.5 px-2 font-bold rounded">
                      {place.indoor ? content.places.indoor : place.outdoor ? content.places.outdoor : "miejscówka"}
                    </Badge>
                  </div>

                  {/* Place Details */}
                  <div className="flex-1 flex flex-col justify-between">
                    <div>
                      <p className="font-mono text-2xs text-muted-foreground uppercase tracking-wider mb-1">
                        {place.city}
                        {content.places.placeMetaSeparator}
                        {place.indoor ? content.places.indoor : content.places.outdoor}
                      </p>
                      <h2 className="font-serif text-xl font-bold tracking-tight mb-2 group-hover:text-primary transition-colors">
                        <Link to={`/miejsca/${place.slug}`}>
                          {place.name}
                        </Link>
                      </h2>
                      <p className="text-sm text-muted-foreground line-clamp-2 leading-relaxed mb-4">
                        {place.short_description}
                      </p>
                    </div>

                    <div className="flex flex-wrap items-center justify-between gap-4 mt-auto">
                      <div className="flex flex-wrap gap-1.5">
                        {place.categories?.map((cat) => (
                          <Badge key={cat.slug} variant="secondary" className="text-2xs rounded-full py-0 px-2">
                            {cat.name}
                          </Badge>
                        ))}
                        {place.free_entry && (
                          <Badge className="text-2xs bg-accent/10 text-accent hover:bg-accent/15 border-transparent rounded-full py-0 px-2 font-semibold">
                            {content.places.freeEntry}
                          </Badge>
                        )}
                      </div>
                      <Button variant="ghost" size="sm" asChild className="text-primary font-bold group-hover:translate-x-1 transition-transform">
                        <Link to={`/miejsca/${place.slug}`}>
                          {content.places.detailsLabel}
                          <ArrowRight className="ml-1 size-3.5" />
                        </Link>
                      </Button>
                    </div>
                  </div>
                </CardContent>
              </Card>
            </li>
          ))}
        </ol>
      ) : (
        <Card className="border-dashed p-12 text-center bg-muted/20">
          <CardContent className="flex flex-col items-center justify-center p-0">
            <Compass className="size-12 text-muted-foreground/60 mb-4 animate-pulse" />
            <p className="text-base text-muted-foreground max-w-sm mb-4">
              {content.places.noResults}
            </p>
            <Button variant="outline" size="sm" asChild>
              <Link to="/miejsca">{content.places.clearFilters}</Link>
            </Button>
          </CardContent>
        </Card>
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
        <Label htmlFor="q" className="font-mono text-xs uppercase tracking-wider text-muted-foreground font-bold">
          {content.places.formSearch}
        </Label>
        <div className="relative">
          <Search className="absolute left-3 top-2.5 size-4 text-muted-foreground" />
          <Input
            id="q"
            name="q"
            defaultValue={filters.q}
            placeholder={content.places.searchPlaceholder}
            className="pl-9"
          />
        </div>
      </div>

      {/* City Select */}
      <div className="grid gap-2">
        <Label htmlFor="city" className="font-mono text-xs uppercase tracking-wider text-muted-foreground font-bold">
          {content.places.formCity}
        </Label>
        <select
          id="city"
          name="city"
          defaultValue={filters.city ?? ""}
          className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
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
        <Label htmlFor="category" className="font-mono text-xs uppercase tracking-wider text-muted-foreground font-bold">
          {content.places.formCategory}
        </Label>
        <select
          id="category"
          name="category"
          defaultValue={filters.category ?? ""}
          className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
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
        <Label htmlFor="ageMonths" className="font-mono text-xs uppercase tracking-wider text-muted-foreground font-bold">
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
        />
      </div>

      {/* Location radius fields */}
      <div className="grid gap-4 border-t border-b py-4 border-muted/50 my-1">
        <div className="grid grid-cols-2 gap-2">
          <div className="grid gap-1.5">
            <Label htmlFor="latitude" className="font-mono text-3xs uppercase tracking-wider text-muted-foreground">
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
              className="h-8 text-xs"
            />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="longitude" className="font-mono text-3xs uppercase tracking-wider text-muted-foreground">
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
              className="h-8 text-xs"
            />
          </div>
        </div>
        <div className="grid gap-2">
          <Label htmlFor="radiusKm" className="font-mono text-xs uppercase tracking-wider text-muted-foreground font-bold">
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
          />
        </div>
      </div>

      {/* Toggles */}
      <div className="flex flex-col gap-3">
        <label className="flex items-center gap-3 cursor-pointer text-sm">
          <input
            type="checkbox"
            name="indoor"
            value="true"
            defaultChecked={filters.indoor === "true"}
            className="rounded border-input text-primary focus:ring-primary size-4"
          />
          <span className="font-semibold">{content.places.formIndoor}</span>
        </label>
      </div>

      {/* Amenities fieldset */}
      <div className="grid gap-3">
        <Label className="font-mono text-xs uppercase tracking-wider text-muted-foreground font-bold">
          {content.places.formAmenitiesHeader}
        </Label>
        <div className="flex flex-col gap-2.5 max-h-48 overflow-y-auto pr-1">
          {amenities.slice(0, 8).map((amenity) => {
            const params = new URLSearchParams(resourceQuery)
            const isChecked = [...params.getAll("amenities"), ...params.getAll("amenities[]")].includes(amenity.slug)
            return (
              <label key={amenity.id} className="flex items-center gap-3 cursor-pointer text-sm">
                <input
                  type="checkbox"
                  name="amenities[]"
                  value={amenity.slug}
                  defaultChecked={isChecked}
                  className="rounded border-input text-primary focus:ring-primary size-4"
                />
                <span>{amenity.name}</span>
              </label>
            )
          })}
        </div>
      </div>

      <Button type="submit" className="w-full font-bold bg-primary hover:bg-primary/95 text-white mt-2">
        {content.places.filterButton}
      </Button>
    </div>
  )
}

export default function Places({ loaderData }: Route.ComponentProps) {
  const [viewMode, setViewMode] = useState<"list" | "map">("list")
  const [searchParams] = useSearchParams()

  // Generate active filter badges to display
  const activeFilters: Array<{ key: string; label: string; value: string }> = []
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

  const hasActiveFilters = activeFilters.length > 0

  return (
    <AppShell>
      <PageContainer className="py-6">
        {/* Toggle between list and map on mobile */}
        <div className="md:hidden flex w-full mb-4 border rounded-lg overflow-hidden bg-card shadow-xs">
          <Button
            variant={viewMode === "list" ? "default" : "ghost"}
            className="flex-1 rounded-none font-bold text-xs"
            onClick={() => setViewMode("list")}
          >
            <List className="mr-1.5 size-4" />
            Lista ({loaderData.places.pagination.totalItems})
          </Button>
          <Button
            variant={viewMode === "map" ? "default" : "ghost"}
            className="flex-1 rounded-none font-bold text-xs"
            onClick={() => setViewMode("map")}
          >
            <MapIcon className="mr-1.5 size-4" />
            Mapa ({loaderData.map.features.length})
          </Button>
        </div>

        {/* Search Toolbar (Active Filters) */}
        {hasActiveFilters && (
          <div className="flex flex-wrap gap-2 items-center mb-6 p-3 bg-muted/20 border rounded-lg">
            <span className="text-2xs uppercase tracking-wider text-muted-foreground font-mono font-bold">
              Aktywne filtry:
            </span>
            <div className="flex flex-wrap gap-1.5 flex-1">
              {activeFilters.map((filter) => {
                // Build a URL without this filter
                const nextParams = new URLSearchParams(searchParams)
                if (filter.key === "amenities[]") {
                  const items = nextParams.getAll("amenities[]").filter((x) => x !== filter.value)
                  nextParams.delete("amenities[]")
                  items.forEach((x) => nextParams.append("amenities[]", x))
                } else {
                  nextParams.delete(filter.key)
                }
                return (
                  <Badge
                    key={`${filter.key}-${filter.value}`}
                    variant="secondary"
                    className="gap-1 bg-background hover:bg-muted font-semibold border"
                  >
                    <span className="text-muted-foreground font-normal">{filter.label}:</span>
                    {filter.value}
                    <Link to={`/miejsca?${nextParams.toString()}`} aria-label={`Wyczyść filtr ${filter.label}`}>
                      <X className="size-3 text-muted-foreground hover:text-foreground cursor-pointer" />
                    </Link>
                  </Badge>
                )
              })}
            </div>
            <Button size="xs" variant="ghost" asChild className="text-xs font-bold hover:bg-transparent text-muted-foreground hover:text-foreground">
              <Link to="/miejsca">Wyczyść wszystko</Link>
            </Button>
          </div>
        )}

        <div className="grid grid-cols-1 lg:grid-cols-[280px_1fr] gap-8">
          {/* Desktop Filter Panel */}
          <aside className="hidden lg:block">
            <Card className="sticky top-20 border-muted/60 shadow-sm bg-card/50 backdrop-blur-sm">
              <CardContent className="p-5">
                <div className="flex items-center justify-between border-b pb-3 mb-4">
                  <h3 className="font-serif font-bold text-lg flex items-center">
                    <SlidersHorizontal className="mr-2 size-4 text-primary" />
                    Filtry
                  </h3>
                  {hasActiveFilters && (
                    <Button size="xs" variant="ghost" asChild className="text-2xs font-mono font-bold text-muted-foreground">
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
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-1 xl:grid-cols-[1.2fr_0.8fr] gap-8">
            {/* List View Column */}
            <div className={`${viewMode === "list" ? "block" : "hidden md:block"}`}>
              {/* Mobile Filter Sheet button inside list view */}
              <div className="lg:hidden mb-4 flex items-center justify-between">
                <Sheet>
                  <SheetTrigger asChild>
                    <Button variant="outline" size="sm" className="font-semibold text-xs gap-1.5">
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
                <span className="text-2xs font-mono text-muted-foreground">
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
            <div className={`relative ${viewMode === "map" ? "block" : "hidden md:block"}`}>
              <Card className="sticky top-20 border-muted/60 shadow-sm overflow-hidden bg-card">
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
