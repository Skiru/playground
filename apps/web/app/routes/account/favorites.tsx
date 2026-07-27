import { redirect, Link } from "react-router"
import { fetchSession } from "../../lib/api-session.server"
import { hardenedFetch } from "../../lib/hardened-fetch.server"
import type { Route } from "./+types/favorites"
import { AppShell } from "../../components/layout/AppShell"
import { AccountLayout } from "~/components/account/AccountLayout"
import { AccountEmptyState, AccountErrorState } from "~/components/account/AccountPageState"
import { Card, CardContent } from "~/components/ui/card"
import { Button } from "~/components/ui/button"
import { Badge } from "~/components/ui/badge"
import { Heart, Trash2, ArrowRight, ShieldAlert } from "lucide-react"
import { PlaceImage } from "../../components/media/PlaceImage"
import { toast } from "sonner"
import * as React from "react"

interface FavoriteItem {
  id: string
  placeId: string
  place?: {
    published?: boolean
    name?: string
    slug?: string
    city?: string
    shortDescription?: string
    category?: string
    categories?: Array<{ slug?: string }>
    main_photo?: { thumbnail?: string; thumbnail_mini?: string; card?: string }
    ageSummary?: string
  }
}

export async function loader({ request }: Route.LoaderArgs) {
  const { data } = await fetchSession(request.headers)
  if (!data.authenticated) {
    return redirect("/?loginRequired=true")
  }

  const url = new URL(request.url)
  const page = url.searchParams.get("page") || "1"

  const favsRes = await hardenedFetch(request, `/api/v1/me/favorites?page=${page}`)
  
  if (!favsRes.ok) {
    return {
      session: data,
      favoritesList: null,
    }
  }

  const favoritesList = await favsRes.json()

  return {
    session: data,
    favoritesList,
  }
}

export default function AccountFavorites({ loaderData }: Route.ComponentProps) {
  const { session, favoritesList } = loaderData
  const [items, setItems] = React.useState<FavoriteItem[]>(favoritesList?.items || [])
  const [removingPlaceId, setRemovingPlaceId] = React.useState<string | null>(null)

  const handleRemoveFavorite = async (placeId: string) => {
    setRemovingPlaceId(placeId)
    try {
      const res = await fetch(`/resources/favorites?placeId=${placeId}`, {
        method: "DELETE",
        headers: {
          "X-CSRF-Token": session.csrfToken || "",
        },
      })

      if (res.ok) {
        setItems((prev) => prev.filter((item) => item.placeId !== placeId))
        toast.info("Usunięto z ulubionych.")
      } else {
        toast.error("Nie udało się usunąć z ulubionych.")
      }
    } catch {
      toast.error("Wystąpił błąd sieci.")
    } finally {
      setRemovingPlaceId(null)
    }
  }

  const pagination = favoritesList?.pagination || { page: 1, totalPages: 1 }

  return (
    <AppShell>
      <AccountLayout>
        <div className="flex flex-col gap-6">
          <div className="border-b border-border pb-5">
            <div>
              <h1 className="font-serif text-3xl font-semibold tracking-tight text-foreground sm:text-4xl">Ulubione miejsca</h1>
              <p className="mt-2 text-sm text-muted-foreground">
                Twoja prywatna lista zapisanych miejsc przyjaznych rodzinie.
              </p>
            </div>
          </div>

          {favoritesList === null ? <AccountErrorState /> : items.length > 0 ? (
            <div className="flex flex-col gap-4">
              <p className="text-sm font-medium text-muted-foreground" aria-live="polite">{pagination.totalItems} {pagination.totalItems === 1 ? "zapisane miejsce" : "zapisanych miejsc"}</p>
              {items.map((item) => {
                const place = item.place || {}
                const isPublished = place.published !== false

                return (
                  <Card key={item.id} className={`group overflow-hidden bg-card border hover:border-primary/50 hover:shadow-md transition-all duration-300 scroll-mt-20 ${!isPublished ? "opacity-90 border-amber-200/60 bg-amber-50/10" : ""}`}>
                    <CardContent className="p-5 flex flex-col sm:flex-row gap-5">
                      <div className="relative w-full sm:w-36 aspect-video sm:aspect-square rounded-lg overflow-hidden bg-muted flex-shrink-0">
                        <PlaceImage
                          mainPhotoUrl={place.main_photo?.thumbnail}
                          srcSet={place.main_photo ? `${place.main_photo.thumbnail_mini} 150w, ${place.main_photo.thumbnail} 400w, ${place.main_photo.card} 800w` : undefined}
                          sizes="(max-width: 640px) 100vw, 144px"
                          placeName={place.name || "Miejsce"}
                          categorySlug={place.categories?.[0]?.slug}
                          className="h-full w-full object-cover"
                        />
                        {!isPublished && (
                          <div className="absolute inset-0 bg-black/40 backdrop-blur-xs flex items-center justify-center p-2 text-center text-white text-3xs font-mono font-bold uppercase tracking-widest">
                            Miejsce nieaktywne
                          </div>
                        )}
                      </div>
                      <div className="flex-1 flex flex-col justify-between">
                        <div>
                          <div className="flex items-start justify-between gap-4">
                            <div>
                              <p className="font-mono text-3xs text-muted-foreground uppercase tracking-wider mb-0.5 flex items-center gap-1">
                                {place.city || "Brak danych"}
                                {!isPublished && (
                                  <span className="text-amber-600 font-bold uppercase flex items-center gap-0.5">
                                    <ShieldAlert className="size-3" />
                                    Niedostępne
                                  </span>
                                )}
                              </p>
                              <h2 className="font-serif text-lg font-bold group-hover:text-primary transition-colors">
                                {isPublished ? (
                                  <Link to={`/miejsca/${place.slug}`}>
                                    {place.name || "Bez nazwy"}
                                  </Link>
                                ) : (
                                  <span className="text-muted-foreground line-through">
                                    {place.name || "Bez nazwy"}
                                  </span>
                                )}
                              </h2>
                            </div>
                            <Button
                              variant="ghost"
                              size="icon"
                              className="text-muted-foreground hover:text-destructive hover:bg-destructive/10 size-8 rounded-full"
                              onClick={() => handleRemoveFavorite(item.placeId)}
                              aria-label="Usuń z ulubionych"
                              disabled={removingPlaceId === item.placeId}
                            >
                              <Trash2 className="size-4" />
                            </Button>
                          </div>
                          <p className="text-xs text-muted-foreground line-clamp-2 leading-relaxed mt-2">
                            {place.shortDescription || "Brak opisu."}
                          </p>
                        </div>

                        <div className="flex items-center justify-between gap-4 mt-4">
                          <div className="flex flex-wrap gap-1 items-center">
                            {place.category && (
                              <Badge variant="secondary" className="text-3xs py-0 px-2 rounded-full">
                                {place.category}
                              </Badge>
                            )}
                            {place.ageSummary && (
                              <Badge variant="outline" className="text-3xs py-0 px-2 rounded-full font-mono">
                                {place.ageSummary}
                              </Badge>
                            )}
                          </div>
                          {isPublished && (
                            <Button variant="ghost" size="sm" asChild className="text-xs font-bold text-primary group-hover:translate-x-1 transition-transform">
                              <Link to={`/miejsca/${place.slug}`}>
                                Przejdź do miejsca
                                <ArrowRight className="ml-1 size-3.5" />
                              </Link>
                            </Button>
                          )}
                        </div>
                      </div>
                    </CardContent>
                  </Card>
                )
              })}

              {/* Simple Pagination Footer */}
              {pagination.totalPages > 1 && (
                <div className="flex justify-center items-center gap-2 mt-6">
                  {pagination.page > 1 && (
                    <Button variant="outline" size="sm" asChild>
                      <Link to={`/konto/ulubione?page=${pagination.page - 1}`}>Poprzednia</Link>
                    </Button>
                  )}
                  <span className="text-xs text-muted-foreground font-mono">
                    Strona {pagination.page} z {pagination.totalPages}
                  </span>
                  {pagination.page < pagination.totalPages && (
                    <Button variant="outline" size="sm" asChild>
                      <Link to={`/konto/ulubione?page=${pagination.page + 1}`}>Następna</Link>
                    </Button>
                  )}
                </div>
              )}
            </div>
          ) : (
            <AccountEmptyState icon={Heart} title="Tu pojawią się Twoje ulubione" description="Zapisuj miejsca podczas przeglądania Miejsc, aby wrócić do nich w dogodnym momencie." />
          )}
        </div>
      </AccountLayout>
    </AppShell>
  )
}
