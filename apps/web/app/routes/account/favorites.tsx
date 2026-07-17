import { redirect, Link } from "react-router"
import { fetchSession } from "../../lib/api-session.server"
import type { Route } from "./+types/favorites"
import { AppShell } from "../../components/layout/AppShell"
import { PageContainer } from "../../components/layout/PageContainer"
import { Card, CardContent } from "~/components/ui/card"
import { Button } from "~/components/ui/button"
import { Badge } from "~/components/ui/badge"
import { Heart, Trash2, ArrowLeft, ArrowRight } from "lucide-react"
import { brand } from "~/brand/default-brand"
import { toast } from "sonner"
import * as React from "react"

const baseUrl = process.env.API_BASE_URL ?? "http://api"

interface PlaceCategory {
  name: string
  slug: string
}

interface PlaceDetail {
  id: string
  slug: string
  name: string
  city_name: string
  short_description: string
  categories?: PlaceCategory[]
  favoriteId?: string
}

interface FavoriteItem {
  id: string
  placeId: string
  createdAt: string
}

export async function loader({ request }: Route.LoaderArgs) {
  const { data } = await fetchSession(request.headers)
  if (!data.authenticated) {
    return redirect("/?loginRequired=true")
  }

  const forwardHeaders = new Headers()
  const cookie = request.headers.get("cookie") || ""
  if (cookie) forwardHeaders.set("cookie", cookie)

  const url = new URL(request.url)
  const page = url.searchParams.get("page") || "1"

  const favsRes = await fetch(`${baseUrl}/api/v1/me/favorites?page=${page}`, {
    headers: forwardHeaders,
  })

  if (!favsRes.ok) {
    return { session: data, favoritesList: { items: [], pagination: { page: 1, pageSize: 20, totalItems: 0, totalPages: 1 } }, places: [] }
  }

  const favoritesList = await favsRes.json()

  // Fetch place details for each favorite
  const places = await Promise.all(
    favoritesList.items.map(async (fav: FavoriteItem) => {
      try {
        const pRes = await fetch(`${baseUrl}/api/v1/places/${fav.placeId}`, {
          headers: forwardHeaders,
        })
        if (pRes.ok) {
          const place = await pRes.json()
          return { ...place, favoriteId: fav.id } as PlaceDetail
        }
      } catch {
        // Ignored
      }
      return null
    })
  )

  return {
    session: data,
    favoritesList,
    places: places.filter((p): p is PlaceDetail => p !== null),
  }
}

export default function AccountFavorites({ loaderData }: Route.ComponentProps) {
  const { session, places: initialPlaces } = loaderData
  const [places, setPlaces] = React.useState<PlaceDetail[]>(initialPlaces)

  const handleRemoveFavorite = async (placeId: string) => {
    try {
      const res = await fetch(`/resources/favorites?placeId=${placeId}`, {
        method: "DELETE",
        headers: {
          "X-CSRF-Token": session.csrfToken || "",
        },
      })

      if (res.ok) {
        setPlaces((prev) => prev.filter((p) => p.id !== placeId))
        toast.info("Usunięto z ulubionych.")
      } else {
        toast.error("Nie udało się usunąć z ulubionych.")
      }
    } catch {
      toast.error("Wystąpił błąd sieci.")
    }
  }

  return (
    <AppShell>
      <PageContainer className="py-10 max-w-4xl">
        <div className="flex flex-col gap-6">
          <nav aria-label="Breadcrumb" className="text-2xs font-mono uppercase tracking-wider text-muted-foreground flex items-center gap-2">
            <Link to="/" className="hover:text-primary transition-colors">Główna</Link>
            <span className="text-muted-foreground/50">/</span>
            <Link to="/konto" className="hover:text-primary transition-colors">Moje konto</Link>
            <span className="text-muted-foreground/50">/</span>
            <span className="text-foreground font-semibold">Ulubione</span>
          </nav>

          <div className="flex items-center justify-between border-b pb-4">
            <div>
              <h1 className="font-serif text-3xl font-medium text-foreground">
                Ulubione miejsca
              </h1>
              <p className="text-sm text-muted-foreground mt-1">
                Twoja prywatna lista zapisanych miejsc przyjaznych rodzinie.
              </p>
            </div>
            <Button variant="outline" size="sm" asChild className="font-semibold text-xs">
              <Link to="/konto" className="flex items-center gap-1.5">
                <ArrowLeft className="size-3.5" />
                Powrót
              </Link>
            </Button>
          </div>

          {places.length > 0 ? (
            <div className="flex flex-col gap-4">
              {places.map((place) => (
                <Card key={place.id} className="group overflow-hidden bg-card border hover:border-primary/50 hover:shadow-md transition-all duration-300">
                  <CardContent className="p-5 flex flex-col sm:flex-row gap-5">
                    <div className="relative w-full sm:w-36 aspect-video sm:aspect-square rounded-lg overflow-hidden bg-muted flex-shrink-0">
                      <img
                        src={brand.placePlaceholder.path}
                        alt={brand.placePlaceholder.alt}
                        className="h-full w-full object-cover"
                      />
                    </div>
                    <div className="flex-1 flex flex-col justify-between">
                      <div>
                        <div className="flex items-start justify-between gap-4">
                          <div>
                            <p className="font-mono text-3xs text-muted-foreground uppercase tracking-wider mb-0.5">
                              {place.city_name}
                            </p>
                            <h2 className="font-serif text-lg font-bold group-hover:text-primary transition-colors">
                              <Link to={`/miejsca/${place.slug}`}>
                                {place.name}
                              </Link>
                            </h2>
                          </div>
                          <Button
                            variant="ghost"
                            size="icon"
                            className="text-muted-foreground hover:text-destructive hover:bg-destructive/10 size-8 rounded-full"
                            onClick={() => handleRemoveFavorite(place.id)}
                            aria-label="Usuń z ulubionych"
                          >
                            <Trash2 className="size-4" />
                          </Button>
                        </div>
                        <p className="text-xs text-muted-foreground line-clamp-2 leading-relaxed mt-2">
                          {place.short_description}
                        </p>
                      </div>

                      <div className="flex items-center justify-between gap-4 mt-4">
                        <div className="flex flex-wrap gap-1">
                          {place.categories?.slice(0, 2).map((cat) => (
                            <Badge key={cat.slug} variant="secondary" className="text-3xs py-0 px-2 rounded-full">
                              {cat.name}
                            </Badge>
                          ))}
                        </div>
                        <Button variant="ghost" size="sm" asChild className="text-xs font-bold text-primary group-hover:translate-x-1 transition-transform">
                          <Link to={`/miejsca/${place.slug}`}>
                            Przejdź do miejsca
                            <ArrowRight className="ml-1 size-3.5" />
                          </Link>
                        </Button>
                      </div>
                    </div>
                  </CardContent>
                </Card>
              ))}
            </div>
          ) : (
            <Card className="border-dashed p-12 text-center bg-muted/20">
              <CardContent className="flex flex-col items-center justify-center p-0">
                <Heart className="size-12 text-muted-foreground/60 mb-4" />
                <p className="text-base text-muted-foreground max-w-sm mb-4">
                  Nie masz jeszcze żadnych zapisanych ulubionych miejsc.
                </p>
                <Button variant="outline" size="sm" asChild>
                  <Link to="/miejsca">Odkrywaj katalog</Link>
                </Button>
              </CardContent>
            </Card>
          )}
        </div>
      </PageContainer>
    </AppShell>
  )
}
