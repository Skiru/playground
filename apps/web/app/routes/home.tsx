import type { GetCategoriesResponse, GetCitiesResponse, SearchPlacesResponse } from "@family-places/api-client"
import { Link } from "react-router"
import { MapPin, Baby, Search, ArrowRight, ShieldCheck, Heart, Sparkles, Compass } from "lucide-react"

import { AppShell } from "../components/layout/AppShell"
import { PageContainer } from "../components/layout/PageContainer"
import { loadCategories, loadCities, loadPlaces } from "../lib/api.server"
import { content } from "../content"
import { brand } from "../brand/default-brand"
import { resolveCategoryMedia } from "../brand/category-media"
import type { Route } from "./+types/home"
import { Button } from "~/components/ui/button"
import { Card, CardContent } from "~/components/ui/card"
import { Input } from "~/components/ui/input"
import { Label } from "~/components/ui/label"
import { Badge } from "~/components/ui/badge"
import { AppImage } from "../components/media/AppImage"
import { PlaceCard } from "~/components/places/PlaceCard"

export function meta() {
  return [
    { title: content.metadata.homeTitle },
    {
      name: "description",
      content: content.metadata.homeDescription,
    },
  ]
}

export async function loader() {
  const [cities, categories, featuredPlaces] = await Promise.all([
    loadCities(),
    loadCategories(),
    loadPlaces({ pageSize: 3 }),
  ])
  return {
    cities: cities.items,
    categories: categories.items,
    featuredPlaces: featuredPlaces.items,
  }
}

export function HomeView({
  cities,
  categories,
  featuredPlaces,
}: {
  cities: GetCitiesResponse["items"]
  categories: GetCategoriesResponse["items"]
  featuredPlaces: SearchPlacesResponse["items"]
}) {
  return (
    <AppShell>
      <div className="relative overflow-hidden pb-10 sm:pb-16">
        <div className="absolute inset-x-0 top-0 -z-10 h-[34rem] bg-gradient-to-b from-secondary/55 via-background to-background" />

        <PageContainer>
          <div className="mx-auto max-w-4xl py-8 text-center sm:py-12 lg:py-16">
            <Badge variant="secondary" className="mb-4 border-transparent px-3 py-1 text-xs font-bold uppercase tracking-wider text-primary">
              <Sparkles className="mr-1.5 size-3.5 text-accent" />
              {content.home.eyebrow}
            </Badge>
            <h1 className="mx-auto mb-5 max-w-3xl text-4xl font-extrabold leading-[1.08] tracking-[-0.035em] text-foreground sm:text-5xl lg:text-6xl">
              {content.home.heading}
            </h1>
            <p className="mx-auto mb-8 max-w-2xl text-base leading-relaxed text-muted-foreground sm:text-lg">
              {content.home.lede}
            </p>

            <Card className="mx-auto max-w-3xl border-border/90 bg-card/95 p-2 shadow-[var(--shadow-card)] backdrop-blur-sm">
              <CardContent className="p-0">
                <form className="grid grid-cols-1 items-end gap-2 md:grid-cols-[1.5fr_1fr_1fr_auto]" action="/miejsca" method="get">
                  {/* Query */}
                  <div className="text-left p-2 grid gap-1.5">
                    <Label htmlFor="q" className="flex items-center text-sm font-bold text-foreground">
                      <Search className="mr-1.5 size-3.5 text-primary" />
                      {content.home.queryLabel}
                    </Label>
                    <Input
                      id="q"
                      name="q"
                      placeholder={content.home.queryPlaceholder}
                      className="h-11 border-none bg-muted/55 text-base focus-visible:bg-muted/75"
                    />
                  </div>

                  {/* City Select */}
                  <div className="text-left p-2 grid gap-1.5 border-t md:border-t-0 md:border-l border-muted/50">
                    <Label htmlFor="city" className="flex items-center text-sm font-bold text-foreground">
                      <MapPin className="mr-1.5 size-3.5 text-primary" />
                      {content.home.cityLabel}
                    </Label>
                    <select
                      id="city"
                      name="city"
                      defaultValue="warszawa"
                      className="flex h-11 w-full rounded-[var(--radius-control)] border-none bg-muted/55 px-3 text-base text-foreground focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/40"
                    >
                      {cities.map((city) => (
                        <option key={city.id} value={city.slug}>
                          {city.name}
                        </option>
                      ))}
                    </select>
                  </div>

                  {/* Age Select */}
                  <div className="text-left p-2 grid gap-1.5 border-t md:border-t-0 md:border-l border-muted/50">
                    <Label htmlFor="ageMonths" className="flex items-center text-sm font-bold text-foreground">
                      <Baby className="mr-1.5 size-3.5 text-primary" />
                      {content.home.ageLabel}
                    </Label>
                    <select
                      id="ageMonths"
                      name="ageMonths"
                      defaultValue=""
                      className="flex h-11 w-full rounded-[var(--radius-control)] border-none bg-muted/55 px-3 text-base text-foreground focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/40"
                    >
                      <option value="">{content.home.anyOption}</option>
                      <option value="12">{content.home.ageOptionUnder2}</option>
                      <option value="36">{content.home.ageOption3to5}</option>
                      <option value="84">{content.home.ageOption6to9}</option>
                      <option value="120">{content.home.ageOption10Plus}</option>
                    </select>
                  </div>

                  {/* Submit */}
                  <div className="p-2 w-full md:w-auto">
                    <Button type="submit" size="lg" className="w-full font-bold md:w-auto">
                      {content.home.showPlacesButton}
                      <ArrowRight className="ml-1.5 size-4" />
                    </Button>
                  </div>
                </form>
              </CardContent>
            </Card>
          </div>

          <section className="border-t py-10 sm:py-14" aria-labelledby="categories-heading">
            <div className="mb-7 flex items-end justify-between gap-4">
              <div>
                <p className="mb-1 text-xs font-extrabold uppercase tracking-[0.12em] text-accent">
                  {content.home.popularHeading}
                </p>
                <h2 id="categories-heading" className="text-2xl font-extrabold tracking-tight sm:text-3xl">
                  {content.home.selectCategoryType}
                </h2>
              </div>
              <Button variant="ghost" asChild className="text-primary">
                <Link to="/miejsca" className="flex items-center gap-1.5">
                  {content.home.allPlaces}
                  <ArrowRight className="size-4" />
                </Link>
              </Button>
            </div>
            <div className="grid grid-cols-2 gap-3 sm:gap-5 lg:grid-cols-3">
              {categories.map((category, index) => {
                const categoryImg = resolveCategoryMedia(category.slug)
                return (
                  <Link
                    key={category.id}
                    to={`/miejsca?city=warszawa&category=${category.slug}`}
                    className="group relative flex aspect-square flex-col justify-end overflow-hidden rounded-[var(--radius-card)] border bg-card p-4 shadow-sm transition-[transform,box-shadow] hover:-translate-y-0.5 hover:shadow-[var(--shadow-card)] sm:aspect-[4/3] sm:p-6"
                  >
                    <div className="absolute inset-0 bg-gradient-to-t from-black/85 via-black/40 to-transparent z-10 transition-opacity group-hover:from-black/90" />
                    <AppImage
                      src={categoryImg.path}
                      fallback={brand.placePlaceholder.path}
                      alt={categoryImg.alt}
                      className="absolute inset-0 h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
                    />
                    <div className="relative z-20 text-white">
                      <span className="mb-1 block text-xs font-bold tracking-widest text-white/85">
                        0{index + 1}
                      </span>
                      <h3 className="text-base font-bold tracking-tight sm:text-xl">
                        {category.name}
                      </h3>
                    </div>
                  </Link>
                )
              })}
            </div>
          </section>

          <section className="border-t py-10 sm:py-14">
            <div className="grid grid-cols-1 gap-5 md:grid-cols-3">
              <div className="flex gap-4 items-start">
                <div className="p-3 rounded-lg bg-primary/10 text-primary">
                  <ShieldCheck className="size-6" />
                </div>
                <div>
                  <h3 className="mb-1 text-lg font-bold">{content.home.trustTitle1}</h3>
                  <p className="text-sm leading-relaxed text-muted-foreground">
                    {content.home.trustDesc1}
                  </p>
                </div>
              </div>
              <div className="flex gap-4 items-start">
                <div className="p-3 rounded-lg bg-primary/10 text-primary">
                  <Compass className="size-6" />
                </div>
                <div>
                  <h3 className="mb-1 text-lg font-bold">{content.home.trustTitle2}</h3>
                  <p className="text-sm text-muted-foreground leading-relaxed">
                    {content.home.trustDesc2}
                  </p>
                </div>
              </div>
              <div className="flex gap-4 items-start">
                <div className="p-3 rounded-lg bg-primary/10 text-primary">
                  <Heart className="size-6" />
                </div>
                <div>
                  <h3 className="mb-1 text-lg font-bold">{content.home.trustTitle3}</h3>
                  <p className="text-sm text-muted-foreground leading-relaxed">
                    {content.home.trustDesc3}
                  </p>
                </div>
              </div>
            </div>
          </section>

          {/* Featured Places */}
          {featuredPlaces && featuredPlaces.length > 0 && (
            <section className="py-12 border-t border-muted/50" aria-labelledby="featured-heading">
              <div className="mb-8 flex items-end justify-between gap-4">
                <div>
                  <p className="mb-1 text-xs font-extrabold uppercase tracking-[0.12em] text-accent">
                    {content.home.featuredEyebrow}
                  </p>
                  <h2 id="featured-heading" className="text-2xl font-extrabold tracking-tight sm:text-3xl">
                    {content.home.featuredHeading}
                  </h2>
                </div>
                <Button variant="ghost" asChild className="text-primary">
                  <Link to="/miejsca" className="flex items-center gap-1.5">
                    {content.home.exploreMap}
                    <ArrowRight className="size-4" />
                  </Link>
                </Button>
              </div>
              <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                {featuredPlaces.map((place) => <PlaceCard key={place.id} place={place} headingLevel={3} />)}
              </div>
            </section>
          )}
        </PageContainer>
      </div>
    </AppShell>
  )
}

export default function Home({ loaderData }: Route.ComponentProps) {
  return (
    <HomeView
      cities={loaderData.cities}
      categories={loaderData.categories}
      featuredPlaces={loaderData.featuredPlaces}
    />
  )
}
