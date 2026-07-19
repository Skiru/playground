import type { GetCategoriesResponse, GetCitiesResponse, SearchPlacesResponse } from "@family-places/api-client"
import { Link } from "react-router"
import { MapPin, Baby, Search, ArrowRight, ShieldCheck, Heart, Sparkles, Compass } from "lucide-react"

import { AppShell } from "../components/layout/AppShell"
import { PageContainer } from "../components/layout/PageContainer"
import { loadCategories, loadCities, loadPlaces } from "../lib/api.server"
import { content } from "../content"
import { brand } from "../brand/default-brand"
import type { Route } from "./+types/home"
import { Button } from "~/components/ui/button"
import { Card, CardContent } from "~/components/ui/card"
import { Input } from "~/components/ui/input"
import { Label } from "~/components/ui/label"
import { Badge } from "~/components/ui/badge"
import { AppImage } from "../components/media/AppImage"
import { PlaceImage } from "../components/media/PlaceImage"

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
      <div className="relative overflow-hidden bg-radial from-amber-50/20 via-background to-background pb-16">
        {/* Decorative background shape */}
        <div className="absolute top-0 right-1/4 -z-10 h-96 w-96 rounded-full bg-primary/5 blur-3xl" />
        <div className="absolute bottom-10 left-10 -z-10 h-72 w-72 rounded-full bg-accent/5 blur-3xl" />

        <PageContainer>
          {/* Hero Section */}
          <div className="text-center py-12 md:py-20 max-w-4xl mx-auto">
            <Badge variant="secondary" className="mb-4 bg-primary/10 text-primary hover:bg-primary/15 border-transparent py-1 px-3 text-xs tracking-wider uppercase font-mono font-bold">
              <Sparkles className="mr-1.5 size-3 text-accent" />
              {content.home.eyebrow}
            </Badge>
            <h1 className="font-serif text-4xl sm:text-5xl md:text-6xl tracking-tight text-foreground font-medium leading-none mb-6">
              {content.home.heading}
            </h1>
            <p className="text-lg sm:text-xl text-muted-foreground leading-relaxed max-w-2xl mx-auto mb-10">
              {content.home.lede}
            </p>

            {/* Quick Search Card */}
            <Card className="shadow-lg border-muted/60 p-2 max-w-3xl mx-auto bg-card/90 backdrop-blur-sm">
              <CardContent className="p-0">
                <form className="grid grid-cols-1 md:grid-cols-[1.5fr_1fr_1fr_auto] gap-2 items-end" action="/miejsca" method="get">
                  {/* Query */}
                  <div className="text-left p-2 grid gap-1.5">
                    <Label htmlFor="q" className="font-mono text-xs uppercase tracking-wider text-muted-foreground font-bold flex items-center">
                      <Search className="mr-1.5 size-3.5 text-primary" />
                      {content.home.queryLabel}
                    </Label>
                    <Input
                      id="q"
                      name="q"
                      placeholder={content.home.queryPlaceholder}
                      className="border-none bg-muted/40 focus-visible:ring-0 focus-visible:bg-muted/60 placeholder:text-muted-foreground/70"
                    />
                  </div>

                  {/* City Select */}
                  <div className="text-left p-2 grid gap-1.5 border-t md:border-t-0 md:border-l border-muted/50">
                    <Label htmlFor="city" className="font-mono text-xs uppercase tracking-wider text-muted-foreground font-bold flex items-center">
                      <MapPin className="mr-1.5 size-3.5 text-primary" />
                      {content.home.cityLabel}
                    </Label>
                    <select
                      id="city"
                      name="city"
                      defaultValue="warszawa"
                      className="flex h-9 w-full rounded-md border-none bg-muted/40 px-3 py-1 text-sm transition-colors focus:outline-none focus:bg-muted/60 text-foreground"
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
                    <Label htmlFor="ageMonths" className="font-mono text-xs uppercase tracking-wider text-muted-foreground font-bold flex items-center">
                      <Baby className="mr-1.5 size-3.5 text-primary" />
                      {content.home.ageLabel}
                    </Label>
                    <select
                      id="ageMonths"
                      name="ageMonths"
                      defaultValue=""
                      className="flex h-9 w-full rounded-md border-none bg-muted/40 px-3 py-1 text-sm transition-colors focus:outline-none focus:bg-muted/60 text-foreground"
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
                    <Button type="submit" size="lg" className="w-full md:w-auto font-bold bg-primary hover:bg-primary/95 text-white">
                      {content.home.showPlacesButton}
                      <ArrowRight className="ml-1.5 size-4" />
                    </Button>
                  </div>
                </form>
              </CardContent>
            </Card>
          </div>

          {/* Popular Categories Grid */}
          <section className="py-12 border-t border-muted/50" aria-labelledby="categories-heading">
            <div className="flex items-center justify-between mb-8">
              <div>
                <p className="font-mono text-xs uppercase tracking-wider text-accent font-bold mb-1">
                  {content.home.popularHeading}
                </p>
                <h2 id="categories-heading" className="text-2xl sm:text-3xl font-serif font-medium">
                  {content.home.selectCategoryType}
                </h2>
              </div>
              <Button variant="ghost" asChild className="text-primary font-bold hover:bg-primary/5">
                <Link to="/miejsca" className="flex items-center gap-1.5">
                  {content.home.allPlaces}
                  <ArrowRight className="size-4" />
                </Link>
              </Button>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-6">
              {categories.map((category, index) => {
                const categoryImg = brand.categoryImageMapping[category.slug] || brand.placePlaceholder
                return (
                  <Link
                    key={category.id}
                    to={`/miejsca?city=warszawa&category=${category.slug}`}
                    className="group relative flex flex-col justify-end overflow-hidden rounded-xl border bg-card aspect-[4/3] p-6 shadow-sm hover:shadow-md transition-all duration-300 hover:-translate-y-1"
                  >
                    <div className="absolute inset-0 bg-gradient-to-t from-black/85 via-black/40 to-transparent z-10 transition-opacity group-hover:from-black/90" />
                    <AppImage
                      src={categoryImg.path}
                      fallback={brand.placePlaceholder.path}
                      alt={categoryImg.alt}
                      className="absolute inset-0 h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
                    />
                    <div className="relative z-20 text-white">
                      <span className="font-mono text-xs tracking-widest text-primary-foreground/85 block mb-1">
                        0{index + 1}
                      </span>
                      <h3 className="text-xl font-bold tracking-tight">
                        {category.name}
                      </h3>
                    </div>
                  </Link>
                )
              })}
            </div>
          </section>

          {/* Trust and Verification Highlights */}
          <section className="py-12 border-t border-muted/50">
            <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
              <div className="flex gap-4 items-start">
                <div className="p-3 rounded-lg bg-primary/10 text-primary">
                  <ShieldCheck className="size-6" />
                </div>
                <div>
                  <h3 className="font-serif text-lg font-bold mb-1">{content.home.trustTitle1}</h3>
                  <p className="text-sm text-muted-foreground leading-relaxed">
                    {content.home.trustDesc1}
                  </p>
                </div>
              </div>
              <div className="flex gap-4 items-start">
                <div className="p-3 rounded-lg bg-primary/10 text-primary">
                  <Compass className="size-6" />
                </div>
                <div>
                  <h3 className="font-serif text-lg font-bold mb-1">{content.home.trustTitle2}</h3>
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
                  <h3 className="font-serif text-lg font-bold mb-1">{content.home.trustTitle3}</h3>
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
              <div className="flex items-center justify-between mb-8">
                <div>
                  <p className="font-mono text-xs uppercase tracking-wider text-accent font-bold mb-1">
                    {content.home.featuredEyebrow}
                  </p>
                  <h2 id="featured-heading" className="text-2xl sm:text-3xl font-serif font-medium">
                    {content.home.featuredHeading}
                  </h2>
                </div>
                <Button variant="ghost" asChild className="text-primary font-bold hover:bg-primary/5">
                  <Link to="/miejsca" className="flex items-center gap-1.5">
                    {content.home.exploreMap}
                    <ArrowRight className="size-4" />
                  </Link>
                </Button>
              </div>
              <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                {featuredPlaces.map((place) => (
                  <Card key={place.id} className="group overflow-hidden rounded-xl border bg-card shadow-sm hover:shadow-md transition-all duration-300">
                    <div className="relative aspect-video overflow-hidden bg-muted">
                      <PlaceImage
                        mainPhotoUrl={place.main_photo?.thumbnail}
                        srcSet={place.main_photo ? `${place.main_photo.thumbnail_mini} 150w, ${place.main_photo.thumbnail} 400w, ${place.main_photo.card} 800w` : undefined}
                        sizes="(max-width: 768px) 100vw, (max-width: 1024px) 33vw, 384px"
                        placeName={place.name}
                        categorySlug={place.categories[0]?.slug}
                        className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
                      />
                      <Badge className="absolute top-3 left-3 bg-primary text-white font-mono text-xs py-1 px-2.5 font-bold rounded-md">
                        {place.indoor ? content.places.indoor : content.places.outdoor}
                      </Badge>
                      {place.free_entry && (
                        <Badge className="absolute top-3 right-3 bg-accent text-white font-mono text-xs py-1 px-2.5 font-bold rounded-md">
                          {content.places.freeEntry}
                        </Badge>
                      )}
                    </div>
                    <CardContent className="p-5 flex flex-col h-[180px] justify-between">
                      <div>
                        <p className="font-mono text-xs text-muted-foreground uppercase tracking-wider mb-1.5">
                          {place.city}
                        </p>
                        <h3 className="font-serif text-lg font-bold line-clamp-1 group-hover:text-primary transition-colors mb-2">
                          <Link to={`/miejsca/${place.slug}`}>
                            {place.name}
                          </Link>
                        </h3>
                        <p className="text-xs text-muted-foreground line-clamp-3 leading-relaxed mb-4">
                          {place.short_description}
                        </p>
                      </div>
                      <div className="flex flex-wrap gap-1 mt-auto">
                        {place.categories?.slice(0, 2).map((category) => (
                          <Badge key={category.slug} variant="secondary" className="text-2xs rounded-full py-0 px-2">
                            {category.name}
                          </Badge>
                        ))}
                      </div>
                    </CardContent>
                  </Card>
                ))}
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
