import type { GetPlaceBySlugResponse } from "@family-places/api-client"
import { Link } from "react-router"
import { MapPin, Baby, ShieldCheck, Compass, Check, ArrowLeft, ExternalLink, Navigation, Clock } from "lucide-react"
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

export function PlaceDetailView({ place, session }: { place: GetPlaceBySlugResponse, session: any }) {
  return (
    <article className="flex flex-col gap-8 pb-16">
      {/* Breadcrumbs */}
      <nav aria-label="Breadcrumb" className="text-2xs font-mono uppercase tracking-wider text-muted-foreground flex items-center gap-2">
        <Link to="/" className="hover:text-primary transition-colors">Główna</Link>
        <span className="text-muted-foreground/50">/</span>
        <Link to="/miejsca" className="hover:text-primary transition-colors">Katalog</Link>
        <span className="text-muted-foreground/50">/</span>
        <span className="hover:text-primary transition-colors">{place.city_name}</span>
        <span className="text-muted-foreground/50">/</span>
        <span className="text-foreground font-semibold line-clamp-1">{place.name}</span>
      </nav>

      {/* Place Hero */}
      <div className="relative rounded-2xl overflow-hidden bg-muted aspect-video md:aspect-[3/1] border shadow-sm">
        <PlaceImage
          mainPhotoUrl={place.main_photo?.hero}
          srcSet={place.main_photo ? `${place.main_photo.card} 800w, ${place.main_photo.hero} 1200w, ${place.main_photo.original_max} 1920w` : undefined}
          sizes="(max-width: 768px) 100vw, (max-width: 1200px) 90vw, 1200px"
          placeName={place.name}
          categorySlug={place.categories[0]?.slug}
          loading="eager"
          fetchPriority="high"
          decoding="async"
          className="h-full w-full object-cover"
        />
        <div className="absolute inset-0 bg-gradient-to-t from-black/85 via-black/35 to-transparent z-10" />
        <div className="absolute bottom-6 left-6 right-6 z-20 text-white flex flex-col gap-2">
          <div className="flex flex-wrap items-center gap-2">
            <Badge className="bg-primary hover:bg-primary/95 text-white font-mono text-xs py-0.5 px-2.5 font-semibold rounded flex items-center">
              <ShieldCheck className="size-3.5 mr-1 text-primary-foreground" />
              {content.places.verifiedPlace}
            </Badge>
            <Badge className="bg-accent hover:bg-accent/95 text-white font-mono text-xs py-0.5 px-2.5 font-semibold rounded">
              {place.city_name}
            </Badge>
          </div>
          <h1 className="font-serif text-2xl sm:text-3xl md:text-4xl lg:text-5xl font-medium tracking-tight leading-tight">
            {place.name}
          </h1>
          <p className="text-sm sm:text-base text-white/90 leading-relaxed max-w-2xl line-clamp-2">
            {place.short_description}
          </p>
        </div>
      </div>

      {/* Place Action Bar */}
      <div className="flex flex-wrap items-center justify-between gap-4 p-4 border rounded-xl bg-card shadow-2xs scroll-mt-20">
        <div className="flex items-center gap-2">
          <Button variant="outline" size="sm" asChild className="font-semibold text-xs">
            <Link to="/miejsca" className="flex items-center gap-1.5">
              <ArrowLeft className="size-3.5" />
              {content.common.backToCatalog}
            </Link>
          </Button>
        </div>
        <div className="flex items-center gap-2">
          <VisitButton placeId={place.id} />
          <FavoriteButton placeId={place.id} />
          <Button size="sm" variant="outline" className="font-semibold text-xs gap-1.5" onClick={() => window.open(`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(place.name + " " + place.address_line1 + " " + place.city_name)}`, "_blank")}>
            <Navigation className="size-3.5" />
            Nawiguj
            <ExternalLink className="size-3" />
          </Button>
        </div>
      </div>

      {/* Detail Grid */}
      <div className="grid grid-cols-1 lg:grid-cols-[1.5fr_0.8fr] gap-8">
        {/* Main Content */}
        <div className="flex flex-col gap-8">
          <Card className="border shadow-2xs bg-card">
            <CardContent className="p-6 sm:p-8 flex flex-col gap-4">
              <h2 className="font-serif text-xl sm:text-2xl font-medium text-foreground pb-2 border-b">
                {content.places.aboutPlace}
              </h2>
              <p className="text-sm sm:text-base text-muted-foreground leading-relaxed whitespace-pre-line">
                {place.description || "Brak szczegółowego opisu dla tego miejsca."}
              </p>
            </CardContent>
          </Card>

          {/* Suitability */}
          <Card className="border shadow-2xs bg-card">
            <CardContent className="p-6 flex flex-col gap-4">
              <h2 className="font-serif text-lg font-bold text-foreground flex items-center">
                <Baby className="size-5 mr-2 text-primary" />
                {content.places.suitabilityHeading}
              </h2>
              <p className="text-xs text-muted-foreground">
                {content.places.suitabilitySub}
              </p>
              {place.ageZones && place.ageZones.length > 0 ? (
                <div className="flex flex-wrap gap-2">
                  {place.ageZones.map((zone, index) => (
                    <Badge key={index} variant="secondary" className="bg-primary/5 text-primary hover:bg-primary/10 border-primary/20 text-xs py-1 px-3 rounded-full font-bold">
                      {zone.label}
                    </Badge>
                  ))}
                </div>
              ) : (
                <p className="text-xs text-muted-foreground italic">
                  {content.places.noConfirmedInformation}
                </p>
              )}
            </CardContent>
          </Card>

          {/* Family Amenities */}
          <Card className="border shadow-2xs bg-card">
            <CardContent className="p-6 flex flex-col gap-4">
              <h2 className="font-serif text-xl font-medium text-foreground pb-2 border-b">
                {content.places.amenitiesHeading}
              </h2>
              {place.amenities && place.amenities.length > 0 ? (
                <ul className="grid grid-cols-1 sm:grid-cols-2 gap-3" aria-label="Udogodnienia rodzinne">
                  {place.amenities.map((amenity) => (
                    <li key={amenity.slug} className="flex items-center gap-2.5 text-sm text-muted-foreground">
                      <div className="rounded-full p-0.5 bg-primary/10 text-primary flex-shrink-0">
                        <Check className="size-3.5" />
                      </div>
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
            <Card className="border shadow-2xs bg-card">
              <CardContent className="p-6 flex flex-col gap-4">
                <h2 className="font-serif text-xl sm:text-2xl font-medium text-foreground pb-2 border-b">
                  Galeria zdjęć
                </h2>
                <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4" aria-label="Galeria zdjęć">
                  {place.photos.map((photo) => (
                    <figure key={photo.id} tabIndex={0} className="group relative flex flex-col gap-2 rounded-lg border bg-muted p-2 hover:border-primary/50 focus-visible:outline-2 focus-visible:outline-primary transition-all duration-300">
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
                      <figcaption className="text-3xs text-muted-foreground font-medium px-1 line-clamp-2 min-h-[2.5rem] leading-snug">
                        {photo.caption || photo.alt_text || "Zdjęcie z galerii"}
                      </figcaption>
                    </figure>
                  ))}
                </div>
              </CardContent>
            </Card>
          )}

          {/* Reviews Section */}
          <Card className="border shadow-2xs bg-card">
            <CardContent className="p-6 sm:p-8 flex flex-col gap-6">
              <ReviewSection placeId={place.id} />
            </CardContent>
          </Card>

          {/* Dyskusja (Place Comments & Replies) */}
          <Card className="border shadow-2xs bg-card mt-6">
            <CardContent className="p-6 sm:p-8 flex flex-col gap-6">
              <PlaceDiscussionSection placeId={place.id} />
            </CardContent>
          </Card>
        </div>

        {/* Sidebar details */}
        <div className="flex flex-col gap-6">
          {/* Place Summary */}
          <Card className="border shadow-2xs bg-card/60 backdrop-blur-sm">
            <CardContent className="p-6 flex flex-col gap-5">
              <h2 className="font-serif text-lg font-bold text-foreground">
                {content.places.infoHeading}
              </h2>
              <Separator />

              <dl className="flex flex-col gap-4 text-sm">
                <div>
                  <dt className="font-mono text-3xs uppercase tracking-wider text-muted-foreground font-bold mb-1">
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
                  <dt className="font-mono text-3xs uppercase tracking-wider text-muted-foreground font-bold mb-1">
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
                  <dt className="font-mono text-3xs uppercase tracking-wider text-muted-foreground font-bold mb-1">
                    {content.places.entryLabel}
                  </dt>
                  <dd className="text-foreground">
                    <Badge variant="outline" className={place.free_entry ? "bg-accent/10 border-transparent text-accent font-semibold" : "bg-primary/10 border-transparent text-primary font-semibold"}>
                      {place.free_entry ? content.places.freeEntryLabel : content.places.paidEntryLabel}
                    </Badge>
                  </dd>
                </div>
              </dl>
            </CardContent>
          </Card>

          {/* Opening Hours Card */}
          <Card className="border shadow-2xs bg-card/60 backdrop-blur-sm">
            <CardContent className="p-6 flex flex-col gap-4">
              <h2 className="font-serif text-base font-bold text-foreground flex items-center gap-1.5">
                <Clock className="size-4.5 text-primary" />
                {content.places.openingHoursHeading}
              </h2>
              <Separator />
              {place.openingSchedule && place.openingSchedule.some(day => !day.closed) ? (
                <dl className="grid grid-cols-[100px_1fr] gap-2 text-xs text-muted-foreground">
                  {place.openingSchedule.map((day) => {
                    const dayNames = ["Poniedziałek", "Wtorek", "Środa", "Czwartek", "Piątek", "Sobota", "Niedziela"];
                    const name = dayNames[day.dayOfWeek - 1] || `Dzień ${day.dayOfWeek}`;
                    return (
                      <React.Fragment key={day.dayOfWeek}>
                        <dt className="font-semibold">{name}:</dt>
                        <dd className="text-foreground font-mono">
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
                  <h3 className="font-mono text-3xs uppercase tracking-wider text-muted-foreground font-bold">
                    Wyjątki / Dni specjalne
                  </h3>
                  <ul className="flex flex-col gap-2 text-xs text-muted-foreground">
                    {place.specialOpeningDays.map((special, i) => (
                      <li key={i} className="flex flex-col gap-0.5">
                        <span className="font-semibold text-foreground">{special.date}</span>
                        <span className="font-mono">
                          {special.mode === "closed" && "Zamknięte"}
                          {special.mode === "open_24_hours" && "Otwarte całą dobę"}
                          {special.mode === "custom" && special.periods.map((p, pi) => (
                            <span key={pi}>
                              {pi > 0 ? ", " : ""}
                              {p.opensAt} - {p.closesAt}
                              {p.closesNextDay ? " (następnego dnia)" : ""}
                            </span>
                          ))}
                          {special.note && <span className="text-3xs italic text-muted-foreground block">({special.note})</span>}
                        </span>
                      </li>
                    ))}
                  </ul>
                </>
              )}
            </CardContent>
          </Card>
        </div>
      </div>
    </article>
  )
}

export default function PlaceDetail({ loaderData }: Route.ComponentProps) {
  return (
    <AppShell>
      <PageContainer className="py-6">
        <PlaceDetailView place={loaderData.place} session={loaderData.session} />
      </PageContainer>
    </AppShell>
  )
}
