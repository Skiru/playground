import type { GetPlaceBySlugResponse } from "@family-places/api-client"
import { Link } from "react-router"
import { MapPin, Baby, ShieldCheck, Compass, Check, ArrowLeft, ExternalLink, Navigation, Clock, Phone, Globe } from "lucide-react"
import * as React from "react"

import { AppShell } from "../components/layout/AppShell"
import { PageContainer } from "../components/layout/PageContainer"
import { FavoriteButton } from "~/components/places/FavoriteButton"
import { VisitButton } from "~/components/places/VisitButton"
import { loadPlace } from "../lib/api.server"
import { fetchSession } from "../lib/api-session.server"
import { content } from "../content"
import { brand } from "../brand/default-brand"
import type { Route } from "./+types/place-detail"
import { Button } from "~/components/ui/button"
import { AppImage } from "../components/media/AppImage"
import { PlaceImage } from "../components/media/PlaceImage"
import { Card, CardContent } from "~/components/ui/card"
import { Badge } from "~/components/ui/badge"
import { Separator } from "~/components/ui/separator"

// Newly extracted community components
import { ReviewSection } from "~/components/community/ReviewSection"
import { PlaceDiscussionSection } from "~/components/community/PlaceDiscussionSection"

export async function loader({ request, params }: Route.LoaderArgs) {
  if (!params.slug) throw new Response("Not found", { status: 404 })
  const { data: session } = await fetchSession(request.headers)
  return { place: await loadPlace(params.slug), session }
}

export function meta({ loaderData }: Route.MetaArgs) {
  return [
    {
      title: loaderData
        ? content.metadata.placeDetailTitleSuffix(loaderData.place.name)
        : `Miejsce | ${content.common.siteTitle}`,
    },
    {
      name: "description",
      content: loaderData?.place.short_description ?? content.metadata.placeDetailDescriptionFallback,
    },
  ]
}

export function PlaceDetailView({ place }: { place: GetPlaceBySlugResponse }) {
  return (
    <article className="flex min-w-0 flex-col gap-6 pb-10 sm:gap-8 sm:pb-16">
      {/* Breadcrumbs */}
      <nav aria-label="Breadcrumb" className="flex min-w-0 flex-wrap items-center gap-2 text-sm text-muted-foreground">
        <Link to="/" className="hover:text-primary transition-colors">Główna</Link>
        <span className="text-muted-foreground/50">/</span>
        <Link to="/miejsca" className="hover:text-primary transition-colors">Katalog</Link>
        <span className="text-muted-foreground/50">/</span>
        <span className="hidden sm:inline">{place.city_name}</span>
        <span className="hidden text-muted-foreground/50 sm:inline">/</span>
        <span className="hidden min-w-0 truncate font-semibold text-foreground sm:inline">{place.name}</span>
      </nav>

      <div className="relative aspect-[4/3] overflow-hidden rounded-[var(--radius-card)] border bg-muted shadow-sm sm:aspect-[16/9] lg:aspect-[3/1]">
        <PlaceImage
          mainPhotoUrl={place.main_photo?.hero}
          srcSet={place.main_photo ? `${place.main_photo.card} 800w, ${place.main_photo.hero} 1200w, ${place.main_photo.original_max} 1920w` : undefined}
          sizes="(max-width: 768px) 100vw, (max-width: 1200px) 90vw, 1200px"
          placeName={place.name}
          categorySlug={place.categories[0]?.slug}
          loading="eager"
          fetchPriority="high"
          decoding="async"
          data-testid="place-hero-image"
          className="h-full w-full object-cover"
        />
      </div>

      <header className="flex flex-col gap-4 border-b pb-6 lg:flex-row lg:items-end lg:justify-between">
        <div className="min-w-0 max-w-4xl">
          <div className="mb-3 flex flex-wrap items-center gap-2">
            {place.verification_status === "admin_verified" ? (
              <Badge variant="secondary" className="gap-1.5 text-primary">
                <ShieldCheck className="size-4" aria-hidden="true" />
                {content.places.verifiedPlace}
              </Badge>
            ) : null}
            {place.categories.map((category) => <Badge key={category.slug} variant="outline">{category.name}</Badge>)}
          </div>
          <h1 className="text-3xl font-extrabold leading-tight tracking-[-0.03em] sm:text-4xl lg:text-5xl">{place.name}</h1>
          <p className="mt-3 flex items-center gap-2 text-base font-semibold text-muted-foreground">
            <MapPin className="size-5 text-primary" aria-hidden="true" />
            {place.city_name}
          </p>
          <p className="mt-3 max-w-3xl text-base leading-relaxed text-muted-foreground sm:text-lg">{place.short_description}</p>
        </div>
      </header>

      {/* Place Action Bar */}
      <div className="flex scroll-mt-24 flex-col gap-3 rounded-[var(--radius-card)] border bg-card p-3 shadow-sm sm:flex-row sm:items-center sm:justify-between sm:p-4">
        <div>
          <Button variant="outline" size="sm" asChild className="w-full font-semibold sm:w-auto">
            <Link to="/miejsca" className="flex items-center gap-1.5">
              <ArrowLeft className="size-3.5" />
              {content.common.backToCatalog}
            </Link>
          </Button>
        </div>
        <div className="grid grid-cols-[minmax(0,1fr)_auto] gap-2 sm:flex sm:items-center">
          <VisitButton placeId={place.id} />
          <FavoriteButton placeId={place.id} />
          <Button type="button" size="sm" variant="outline" className="col-span-2 gap-1.5 font-semibold sm:col-span-1" onClick={() => window.open(`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(place.name + " " + place.address_line1 + " " + place.city_name)}`, "_blank", "noopener,noreferrer")}>
            <Navigation className="size-3.5" />
            Nawiguj
            <ExternalLink className="size-3" />
          </Button>
        </div>
      </div>

      {/* Detail Grid */}
      <div className="grid min-w-0 grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1.5fr)_minmax(280px,0.8fr)] lg:gap-8">
        {/* Main Content */}
        <div className="flex min-w-0 flex-col gap-6 sm:gap-8">
          <Card className="order-3 border bg-card shadow-sm">
            <CardContent className="p-6 sm:p-8 flex flex-col gap-4">
              <h2 className="border-b pb-3 text-xl font-bold text-foreground sm:text-2xl">
                {content.places.aboutPlace}
              </h2>
              <p className="whitespace-pre-line text-base leading-relaxed text-muted-foreground">
                {place.description || "Brak szczegółowego opisu dla tego miejsca."}
              </p>
            </CardContent>
          </Card>

          {/* Suitability */}
          <Card className="order-1 border bg-card shadow-sm">
            <CardContent className="p-6 flex flex-col gap-4">
              <h2 className="flex items-center text-xl font-bold text-foreground">
                <Baby className="size-5 mr-2 text-primary" />
                {content.places.suitabilityHeading}
              </h2>
              <p className="text-sm text-muted-foreground">
                {content.places.suitabilitySub}
              </p>
              {place.ageZones && place.ageZones.length > 0 ? (
                <div className="flex flex-wrap gap-2">
                  {place.ageZones.map((zone, index) => (
                    <Badge key={index} variant="secondary" className="border-primary/20 px-3 py-1 text-sm font-bold text-primary">
                      {zone.label}
                    </Badge>
                  ))}
                </div>
              ) : (
                <p className="text-sm italic text-muted-foreground">
                  {content.places.noConfirmedInformation}
                </p>
              )}
            </CardContent>
          </Card>

          {/* Family Amenities */}
          <Card className="order-2 border bg-card shadow-sm">
            <CardContent className="p-6 flex flex-col gap-4">
              <h2 className="border-b pb-3 text-xl font-bold text-foreground">
                {content.places.amenitiesHeading}
              </h2>
              {place.amenities && place.amenities.length > 0 ? (
                <ul className="grid grid-cols-1 sm:grid-cols-2 gap-3" aria-label="Udogodnienia rodzinne">
                  {place.amenities.map((amenity) => (
                    <li key={amenity.slug} className="flex min-h-10 items-center gap-2.5 text-base text-muted-foreground">
                      <span className="flex shrink-0 rounded-full bg-secondary p-1 text-primary">
                        <Check className="size-3.5" />
                      </span>
                      <span>{amenity.name}</span>
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="text-sm text-muted-foreground italic">
                  Brak przypisanych udogodnień.
                </p>
              )}
            </CardContent>
          </Card>

          {/* Photo Gallery */}
          {place.photos && place.photos.length > 0 && (
            <Card className="order-0 border bg-card shadow-sm">
              <CardContent className="p-6 flex flex-col gap-4">
                <h2 className="border-b pb-3 text-xl font-bold text-foreground sm:text-2xl">
                  Galeria zdjęć
                </h2>
                <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4" aria-label="Galeria zdjęć">
                  {place.photos.map((photo) => (
                    <figure key={photo.id} tabIndex={0} className="group relative flex flex-col gap-2 rounded-[var(--radius-media)] border bg-muted p-2 transition-colors hover:border-primary/50 focus-visible:outline-2 focus-visible:outline-primary">
                      <div className="aspect-square overflow-hidden rounded-md">
                        <AppImage
                          src={photo.variants?.thumbnail}
                          srcSet={photo.variants ? `${photo.variants.thumbnail_mini} 150w, ${photo.variants.thumbnail} 400w, ${photo.variants.card} 800w` : undefined}
                          sizes="(max-width: 640px) 50vw, (max-width: 1024px) 33vw, 200px"
                          fallback={brand.placePlaceholder.path}
                          alt={photo.alt_text || `Zdjęcie przedstawiające ${place.name}`}
                          loading="lazy"
                          decoding="async"
                          className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
                        />
                      </div>
                      <figcaption className="min-h-[2.75rem] line-clamp-2 px-1 text-sm font-medium leading-snug text-muted-foreground">
                        {photo.caption || photo.alt_text || "Zdjęcie z galerii"}
                      </figcaption>
                    </figure>
                  ))}
                </div>
              </CardContent>
            </Card>
          )}

          {/* Reviews Section */}
          <Card className="order-4 border bg-card shadow-sm">
            <CardContent className="p-6 sm:p-8 flex flex-col gap-6">
              <ReviewSection placeId={place.id} />
            </CardContent>
          </Card>

          {/* Dyskusja (Place Comments & Replies) */}
          <Card className="order-5 border bg-card shadow-sm">
            <CardContent className="p-6 sm:p-8 flex flex-col gap-6">
              <PlaceDiscussionSection placeId={place.id} />
            </CardContent>
          </Card>
        </div>

        {/* Sidebar details */}
        <aside className="flex min-w-0 flex-col gap-6">
          {/* Place Summary */}
          <Card className="border bg-card/80 shadow-sm backdrop-blur-sm">
            <CardContent className="p-6 flex flex-col gap-5">
              <h2 className="text-xl font-bold text-foreground">
                {content.places.infoHeading}
              </h2>
              <Separator />

              <dl className="flex flex-col gap-4 text-sm">
                <div>
                  <dt className="mb-1 text-sm font-bold text-muted-foreground">
                    {content.places.addressLabel}
                  </dt>
                  <dd className="text-foreground flex items-start gap-1.5">
                    <MapPin className="size-4 text-primary flex-shrink-0 mt-0.5" />
                    <span>
                      {place.address_line1}
                      <br />
                      {place.postal_code} {place.city_name}
                    </span>
                  </dd>
                </div>

                <div>
                  <dt className="mb-1 text-sm font-bold text-muted-foreground">
                    {content.places.spaceLabel}
                  </dt>
                  <dd className="text-foreground flex items-center gap-1.5">
                    <Compass className="size-4 text-primary" />
                    <span>
                      {place.indoor ? content.places.indoor : ""}
                      {place.indoor && place.outdoor ? content.places.spaceAnd : ""}
                      {place.outdoor ? content.places.outdoor : ""}
                    </span>
                  </dd>
                </div>

                <div>
                  <dt className="mb-1 text-sm font-bold text-muted-foreground">
                    {content.places.entryLabel}
                  </dt>
                  <dd className="text-foreground">
                    <Badge variant="outline" className={place.free_entry ? "bg-accent/10 border-transparent text-accent font-semibold" : "bg-primary/10 border-transparent text-primary font-semibold"}>
                      {place.free_entry ? content.places.freeEntryLabel : content.places.paidEntryLabel}
                    </Badge>
                  </dd>
                </div>
              </dl>
              {(place.website_url || place.phone) ? (
                <div className="flex flex-col gap-2 border-t pt-4">
                  {place.website_url ? <Button variant="outline" asChild><a href={place.website_url} target="_blank" rel="noreferrer"><Globe className="size-4" />Strona miejsca<ExternalLink className="size-3.5" /></a></Button> : null}
                  {place.phone ? <Button variant="outline" asChild><a href={`tel:${place.phone}`}><Phone className="size-4" />{place.phone}</a></Button> : null}
                </div>
              ) : null}
            </CardContent>
          </Card>

          {/* Opening Hours Card */}
          <Card className="border bg-card/80 shadow-sm backdrop-blur-sm">
            <CardContent className="p-6 flex flex-col gap-4">
              <h2 className="flex items-center gap-2 text-lg font-bold text-foreground">
                <Clock className="size-4.5 text-primary" />
                {content.places.openingHoursHeading}
              </h2>
              <Separator />
              {place.openingSchedule && place.openingSchedule.some(day => !day.closed) ? (
                <dl className="grid min-w-0 grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)] gap-2 text-sm text-muted-foreground">
                  {place.openingSchedule.map((day) => {
                    const dayNames = ["Poniedziałek", "Wtorek", "Środa", "Czwartek", "Piątek", "Sobota", "Niedziela"];
                    const name = dayNames[day.dayOfWeek - 1] || `Dzień ${day.dayOfWeek}`;
                    return (
                      <React.Fragment key={day.dayOfWeek}>
                        <dt className="font-semibold">{name}:</dt>
                        <dd className="min-w-0 break-words text-foreground">
                          {day.closed ? (
                            content.places.closedLabel
                          ) : (
                            day.periods.map((p, i) => (
                              <span key={i}>
                                {i > 0 ? ", " : ""}
                                {p.opensAt} - {p.closesAt}
                                {p.closesNextDay ? " (następnego dnia)" : ""}
                              </span>
                            ))
                          )}
                        </dd>
                      </React.Fragment>
                    );
                  })}
                </dl>
              ) : (
                <p className="text-xs text-muted-foreground italic">
                  {content.places.noConfirmedInformation}
                </p>
              )}

              {place.specialOpeningDays && place.specialOpeningDays.length > 0 && (
                <>
                  <Separator className="my-2" />
                  <h3 className="text-sm font-bold uppercase tracking-wider text-muted-foreground">
                    Wyjątki / Dni specjalne
                  </h3>
                  <ul className="flex flex-col gap-2 text-xs text-muted-foreground">
                    {place.specialOpeningDays.map((special, i) => (
                      <li key={i} className="flex flex-col gap-0.5">
                        <span className="font-semibold text-foreground">{special.date}</span>
                        <span>
                          {special.mode === "closed" && "Zamknięte"}
                          {special.mode === "open_24_hours" && "Otwarte całą dobę"}
                          {special.mode === "custom" && special.periods.map((p, pi) => (
                            <span key={pi}>
                              {pi > 0 ? ", " : ""}
                              {p.opensAt} - {p.closesAt}
                              {p.closesNextDay ? " (następnego dnia)" : ""}
                            </span>
                          ))}
                          {special.note && <span className="block text-sm italic text-muted-foreground">({special.note})</span>}
                        </span>
                      </li>
                    ))}
                  </ul>
                </>
              )}
            </CardContent>
          </Card>
        </aside>
      </div>
    </article>
  )
}

export default function PlaceDetail({ loaderData }: Route.ComponentProps) {
  return (
    <AppShell>
      <PageContainer className="py-6">
        <PlaceDetailView place={loaderData.place} />
      </PageContainer>
    </AppShell>
  )
}
