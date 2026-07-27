import type { SearchPlacesResponse } from "@family-places/api-client"
import { ArrowUpRight, Baby, MapPin, Star } from "lucide-react"
import { Link } from "react-router"

import { Badge } from "~/components/ui/badge"
import { Card, CardContent } from "~/components/ui/card"
import { FavoriteButton } from "~/components/places/FavoriteButton"
import { PlaceImage } from "~/components/media/PlaceImage"

type Place = SearchPlacesResponse["items"][number]

interface PlaceCardProps {
  place: Place
  layout?: "vertical" | "horizontal"
  showFavorite?: boolean
  headingLevel?: 2 | 3
}

function ageLabel(minMonths: number, maxMonths: number | null) {
  const years = (months: number) => months < 24 ? `${months} mies.` : `${Math.floor(months / 12)} lat`
  return maxMonths === null ? `od ${years(minMonths)}` : `${years(minMonths)} - ${years(maxMonths)}`
}

export function PlaceCard({ place, layout = "vertical", showFavorite = false, headingLevel = 2 }: PlaceCardProps) {
  const Heading = headingLevel === 2 ? "h2" : "h3"
  const hasReviews = place.total_reviews > 0

  return (
    <Card className="place-card group relative overflow-hidden border-border/90 bg-card py-0 transition-[border-color,box-shadow,transform] hover:-translate-y-0.5 hover:border-primary/35 hover:shadow-[var(--shadow-card)] focus-within:border-primary focus-within:shadow-[var(--shadow-card)]">
      <Link
        to={`/miejsca/${place.slug}`}
        className="absolute inset-0 z-10 rounded-[inherit]"
        aria-label={`Zobacz miejsce: ${place.name}`}
      >
        <span className="absolute bottom-5 right-5 flex items-center gap-1 text-sm font-bold text-primary sm:bottom-6 sm:right-6">
          Zobacz miejsce <ArrowUpRight className="size-4" aria-hidden="true" />
        </span>
      </Link>
      <CardContent className={`relative p-0 ${layout === "horizontal" ? "md:grid md:grid-cols-[minmax(190px,0.8fr)_minmax(0,1.25fr)]" : ""}`}>
        <div className={`relative overflow-hidden bg-muted ${layout === "horizontal" ? "aspect-[16/10] md:aspect-auto md:min-h-60" : "aspect-[16/10]"}`}>
          <PlaceImage
            mainPhotoUrl={place.main_photo?.card ?? place.main_photo?.thumbnail}
            srcSet={place.main_photo ? `${place.main_photo.thumbnail_mini} 150w, ${place.main_photo.thumbnail} 400w, ${place.main_photo.card} 800w` : undefined}
            sizes={layout === "horizontal" ? "(max-width: 768px) 100vw, 320px" : "(max-width: 768px) 100vw, 400px"}
            placeName={place.name}
            categorySlug={place.categories[0]?.slug}
            className="h-full w-full object-cover transition-transform duration-300 group-hover:scale-[1.02]"
          />
          <div className="absolute left-3 top-3 flex flex-wrap gap-2">
            <Badge className="border-0 bg-primary text-primary-foreground">
              {place.indoor && place.outdoor ? "wewnątrz i na zewnątrz" : place.indoor ? "wewnątrz" : "na zewnątrz"}
            </Badge>
            {place.free_entry ? <Badge className="border-0 bg-accent text-accent-foreground">bezpłatnie</Badge> : null}
          </div>
        </div>

        <div className="flex min-w-0 flex-col p-5 sm:p-6">
          <div className="mb-3 flex items-start justify-between gap-3">
            <div className="min-w-0">
              <p className="mb-2 flex items-center gap-1.5 text-sm font-semibold text-muted-foreground">
                <MapPin className="size-4 text-primary" aria-hidden="true" />
                {place.city}
              </p>
              <Heading className="text-xl font-bold leading-tight tracking-tight text-foreground sm:text-2xl">
                {place.name}
              </Heading>
            </div>
            {showFavorite ? <div className="relative z-20"><FavoriteButton placeId={place.id} /></div> : null}
          </div>

          <div className="mb-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-muted-foreground">
            <span className="flex items-center gap-1.5" aria-label={hasReviews ? `Ocena ${place.average_rating.toFixed(1)} na podstawie ${place.total_reviews} opinii` : "Brak opinii"}>
              <Star className={`size-4 ${hasReviews ? "fill-accent text-accent" : "text-muted-foreground"}`} aria-hidden="true" />
              {hasReviews ? <><strong className="text-foreground">{place.average_rating.toFixed(1)}</strong> ({place.total_reviews})</> : "Brak opinii"}
            </span>
            <span className="flex items-center gap-1.5">
              <Baby className="size-4 text-primary" aria-hidden="true" />
              {ageLabel(place.min_age_months, place.max_age_months)}
            </span>
          </div>

          <p className="mb-5 line-clamp-2 text-base leading-relaxed text-muted-foreground">{place.short_description}</p>

          <div className="mt-auto flex flex-wrap items-center justify-between gap-3">
            <div className="flex flex-wrap gap-2">
              {place.categories.slice(0, 2).map((category) => <Badge key={category.slug} variant="secondary" className="font-semibold text-primary">{category.name}</Badge>)}
              {place.amenities.slice(0, 2).map((amenity) => <Badge key={amenity.slug} variant="outline">{amenity.name}</Badge>)}
            </div>
            <span className="h-5 min-w-32" aria-hidden="true" />
          </div>
        </div>
      </CardContent>
    </Card>
  )
}
