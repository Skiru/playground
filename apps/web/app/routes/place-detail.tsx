/* eslint-disable */
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
  const [reviews, setReviews] = React.useState<any[]>([])
  const [summary, setSummary] = React.useState<any>({ averageRating: 0, totalReviews: 0, histogram: { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 } })
  const [loading, setLoading] = React.useState(true)
  const [error, setError] = React.useState<string | null>(null)
  const [page, setPage] = React.useState(1)
  const [totalPages, setTotalPages] = React.useState(1)
  const [sort, setSort] = React.useState("newest")
  const [showForm, setShowForm] = React.useState(false)
  const [submitting, setSubmitting] = React.useState(false)
  const [formRating, setFormRating] = React.useState(5)
  const [formBody, setFormBody] = React.useState("")
  const [formVisitedOn, setFormVisitedOn] = React.useState("")
  const [editingReviewId, setEditingReviewId] = React.useState<string | null>(null)
  const [formError, setFormError] = React.useState<string | null>(null)

  const loadReviews = async () => {
    setLoading(true)
    setError(null)
    try {
      const res = await fetch(`/api/v1/places/${place.id}/reviews?page=${page}&sort=${sort}`)
      if (!res.ok) throw new Error("Failed to load reviews")
      const data = await res.json()
      setReviews(data.items || [])
      setSummary(data.summary || { averageRating: 0, totalReviews: 0, histogram: { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 } })
      setTotalPages(data.pagination?.totalPages || 1)
    } catch (err: any) {
      setError(err.message)
    } finally {
      setLoading(false)
    }
  }

  React.useEffect(() => {
    loadReviews()
  }, [page, sort])

  const handleSubmitReview = async (e: React.FormEvent) => {
    e.preventDefault()
    setSubmitting(true)
    setFormError(null)

    const payload: any = {
      rating: formRating,
      body: formBody,
      visitedOn: formVisitedOn || null,
    }

    const headers: any = {
      "Content-Type": "application/json",
      "X-CSRF-Token": session?.csrfToken || "",
    }

    try {
      let url = `/api/v1/places/${place.id}/reviews`
      let method = "POST"

      if (editingReviewId) {
        url = `/api/v1/me/reviews/${editingReviewId}`
        method = "PATCH"
        const currentRev = reviews.find(r => r.id === editingReviewId)
        payload.version = currentRev ? currentRev.version : 1
      }

      const res = await fetch(url, {
        method,
        headers,
        body: JSON.stringify(payload)
      })

      if (!res.ok) {
        const errorData = await res.json().catch(() => ({}))
        throw new Error(errorData.detail || "Validation or network error.")
      }

      setShowForm(false)
      setFormBody("")
      setFormRating(5)
      setFormVisitedOn("")
      setEditingReviewId(null)
      loadReviews()
    } catch (err: any) {
      setFormError(err.message)
    } finally {
      setSubmitting(false)
    }
  }

  const handleDeleteReview = async (reviewId: string) => {
    if (!confirm("Czy na pewno chcesz usunąć tę opinię?")) return

    try {
      const res = await fetch(`/api/v1/me/reviews/${reviewId}`, {
        method: "DELETE",
        headers: {
          "X-CSRF-Token": session?.csrfToken || "",
        }
      })
      if (!res.ok) throw new Error("Failed to delete review")
      loadReviews()
    } catch (err: any) {
      alert(err.message)
    }
  }

  const renderStars = (rating: number) => {
    return (
      <div className="flex text-amber-500 font-bold" aria-label={`Ocena: ${rating} na 5`}>
        {"★".repeat(rating)}{"☆".repeat(5 - rating)}
      </div>
    )
  }

  const [comments, setComments] = React.useState<any[]>([])
  const [commentsLoading, setCommentsLoading] = React.useState(true)
  const [commentsPage, setCommentsPage] = React.useState(1)
  const [commentsTotalPages, setCommentsTotalPages] = React.useState(1)
  const [commentFormBody, setCommentFormBody] = React.useState("")
  const [replyingToCommentId, setReplyingToCommentId] = React.useState<string | null>(null)
  const [editingCommentId, setEditingCommentId] = React.useState<string | null>(null)
  const [commentFormError, setCommentFormError] = React.useState<string | null>(null)
  const [commentSubmitting, setCommentSubmitting] = React.useState(false)

  const loadComments = async () => {
    setCommentsLoading(true)
    try {
      const res = await fetch(`/api/v1/places/${place.id}/comments?page=${commentsPage}`)
      if (!res.ok) throw new Error("Failed to load comments")
      const data = await res.json()
      setComments(data.items || [])
      setCommentsTotalPages(data.pagination?.totalPages || 1)
    } catch (err: any) {
      console.error(err)
    } finally {
      setCommentsLoading(false)
    }
  }

  React.useEffect(() => {
    loadComments()
  }, [commentsPage])

  const handleSubmitComment = async (e: React.FormEvent) => {
    e.preventDefault()
    setCommentSubmitting(true)
    setCommentFormError(null)

    const payload: any = {
      body: commentFormBody,
    }

    const headers: any = {
      "Content-Type": "application/json",
      "X-CSRF-Token": session?.csrfToken || "",
    }

    try {
      let url = `/api/v1/places/${place.id}/comments`
      let method = "POST"

      if (editingCommentId) {
        url = `/api/v1/me/place-comments/${editingCommentId}`
        method = "PATCH"
        const currentComm = comments.find(c => c.id === editingCommentId)
        payload.version = currentComm ? currentComm.version : 1
      } else if (replyingToCommentId) {
        url = `/api/v1/place-comments/${replyingToCommentId}/replies`
        method = "POST"
      }

      const res = await fetch(url, {
        method,
        headers,
        body: JSON.stringify(payload)
      })

      if (!res.ok) {
        const errorData = await res.json().catch(() => ({}))
        throw new Error(errorData.detail || "Validation or network error.")
      }

      setCommentFormBody("")
      setEditingCommentId(null)
      setReplyingToCommentId(null)
      loadComments()
    } catch (err: any) {
      setCommentFormError(err.message)
    } finally {
      setCommentSubmitting(false)
    }
  }

  const handleDeleteComment = async (commentId: string) => {
    if (!confirm("Czy na pewno chcesz usunąć ten komentarz?")) return

    try {
      const res = await fetch(`/api/v1/me/place-comments/${commentId}`, {
        method: "DELETE",
        headers: {
          "X-CSRF-Token": session?.csrfToken || "",
        }
      })
      if (!res.ok) throw new Error("Failed to delete comment")
      loadComments()
    } catch (err: any) {
      alert(err.message)
    }
  }

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
              <div className="flex flex-wrap items-center justify-between gap-4 border-b pb-4">
                <h2 className="font-serif text-xl sm:text-2xl font-medium text-foreground">
                  Opinie i oceny rodziców
                </h2>
                {!showForm && (
                  <Button
                    size="sm"
                    variant="outline"
                    className="font-semibold text-xs"
                    onClick={() => {
                      if (!session?.authenticated) {
                        alert("Zaloguj się, aby dodać opinię.");
                        return;
                      }
                      setEditingReviewId(null);
                      setFormRating(5);
                      setFormBody("");
                      setFormVisitedOn("");
                      setShowForm(true);
                    }}
                  >
                    Dodaj opinię
                  </Button>
                )}
              </div>

              {/* Form to Add/Edit Review */}
              {showForm && (
                <form onSubmit={handleSubmitReview} className="border p-4 rounded-lg bg-muted/30 flex flex-col gap-4">
                  <h3 className="font-semibold text-sm">
                    {editingReviewId ? "Edytuj swoją opinię" : "Napisz nową opinię"}
                  </h3>
                  {formError && (
                    <p className="text-xs text-destructive font-medium bg-destructive/10 p-2 rounded">
                      {formError}
                    </p>
                  )}

                  <div className="flex flex-col gap-1.5">
                    <label className="text-xs font-semibold text-muted-foreground">
                      Twoja ocena (1-5 gwiazdek) *
                    </label>
                    <div className="flex gap-1.5">
                      {[1, 2, 3, 4, 5].map((val) => (
                        <button
                          key={val}
                          type="button"
                          className={`text-xl ${val <= formRating ? "text-amber-500" : "text-muted-foreground/30"}`}
                          onClick={() => setFormRating(val)}
                        >
                          ★
                        </button>
                      ))}
                    </div>
                  </div>

                  <div className="flex flex-col gap-1.5">
                    <label htmlFor="review-body" className="text-xs font-semibold text-muted-foreground">
                      Treść opinii * (minimum 20 znaków)
                    </label>
                    <textarea
                      id="review-body"
                      rows={4}
                      className="w-full border rounded-md p-2 bg-background text-sm"
                      placeholder="Co najbardziej podobało się Twoim dzieciom? Jak oceniasz obsługę i czystość?"
                      value={formBody}
                      onChange={(e) => setFormBody(e.target.value)}
                      required
                    />
                    <p className="text-3xs text-muted-foreground text-right">
                      {formBody.length}/5000 znaków (min. 20)
                    </p>
                  </div>

                  <div className="flex flex-col gap-1.5">
                    <label htmlFor="review-visited-on" className="text-xs font-semibold text-muted-foreground">
                      Kiedy tam byłeś? (Opcjonalnie)
                    </label>
                    <input
                      id="review-visited-on"
                      type="date"
                      className="border rounded-md p-2 bg-background text-sm max-w-xs"
                      value={formVisitedOn}
                      onChange={(e) => setFormVisitedOn(e.target.value)}
                    />
                  </div>

                  <div className="flex gap-3 justify-end mt-2">
                    <Button
                      type="button"
                      size="sm"
                      variant="ghost"
                      className="text-xs font-semibold"
                      onClick={() => {
                        setShowForm(false);
                        setEditingReviewId(null);
                        setFormError(null);
                      }}
                    >
                      Anuluj
                    </Button>
                    <Button
                      type="submit"
                      size="sm"
                      className="text-xs font-semibold"
                      disabled={submitting || formBody.trim().length < 20}
                    >
                      {submitting ? "Zapisywanie..." : "Zapisz opinię"}
                    </Button>
                  </div>
                </form>
              )}

              {/* Summary and Histogram */}
              {summary.totalReviews > 0 ? (
                <div className="grid grid-cols-1 md:grid-cols-[1fr_2fr] gap-6 p-4 rounded-xl border bg-muted/10">
                  <div className="flex flex-col items-center justify-center gap-1.5 border-r md:pr-6">
                    <span className="font-serif text-4xl sm:text-5xl font-semibold text-foreground">
                      {summary.averageRating.toFixed(1)}
                    </span>
                    {renderStars(Math.round(summary.averageRating))}
                    <span className="text-2xs text-muted-foreground">
                      na podstawie {summary.totalReviews} {summary.totalReviews === 1 ? "opinii" : summary.totalReviews % 10 >= 2 && summary.totalReviews % 10 <= 4 && (summary.totalReviews % 100 < 10 || summary.totalReviews % 100 >= 20) ? "opinie" : "opinii"}
                    </span>
                  </div>
                  <div className="flex flex-col gap-2 justify-center">
                    {[5, 4, 3, 2, 1].map((stars) => {
                      const count = summary.histogram[stars] || 0;
                      const percentage = summary.totalReviews > 0 ? (count / summary.totalReviews) * 100 : 0;
                      return (
                        <div key={stars} className="flex items-center gap-2 text-xs text-muted-foreground">
                          <span className="w-3 text-right">{stars}</span>
                          <span className="text-amber-500">★</span>
                          <div className="flex-1 h-2 bg-muted rounded-full overflow-hidden">
                            <div
                              className="h-full bg-amber-500 rounded-full"
                              style={{ width: `${percentage}%` }}
                            />
                          </div>
                          <span className="w-8 text-right font-mono">{count}</span>
                        </div>
                      );
                    })}
                  </div>
                </div>
              ) : (
                <div className="text-center p-6 border border-dashed rounded-lg">
                  <p className="text-sm text-muted-foreground italic">
                    Brak opinii dla tego miejsca. Bądź pierwszym, który doda opinię!
                  </p>
                </div>
              )}

              {/* Reviews List */}
              {summary.totalReviews > 0 && (
                <div className="flex flex-col gap-4 mt-2">
                  <div className="flex items-center justify-between gap-4 border-b pb-2">
                    <h3 className="font-semibold text-sm">
                      Lista opinii
                    </h3>
                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                      <span>Sortuj:</span>
                      <select
                        className="bg-background border rounded px-1.5 py-0.5"
                        value={sort}
                        onChange={(e) => {
                          setSort(e.target.value);
                          setPage(1);
                        }}
                      >
                        <option value="newest">Najnowsze</option>
                        <option value="highest">Najwyższe</option>
                        <option value="lowest">Najniższe</option>
                      </select>
                    </div>
                  </div>

                  {loading ? (
                    <div className="text-center py-6 text-xs text-muted-foreground italic">
                      Ładowanie opinii...
                    </div>
                  ) : (
                    <div className="flex flex-col gap-4 divide-y">
                      {reviews.map((rev) => {
                        const isOwn = session?.authenticated && session?.user?.id === rev.authorId;
                        return (
                          <div key={rev.id} className="pt-4 first:pt-0 flex flex-col gap-2">
                            <div className="flex items-start justify-between gap-4">
                              <div className="flex items-center gap-2.5">
                                <div className="rounded-full bg-primary/10 text-primary text-xs font-mono font-bold w-7 h-7 flex items-center justify-center">
                                  {rev.author?.initials || "U"}
                                </div>
                                <div>
                                  <p className="font-semibold text-xs text-foreground leading-none">
                                    {rev.author?.displayName || "Użytkownik"}
                                  </p>
                                  <p className="text-4xs text-muted-foreground font-mono uppercase tracking-wider mt-0.5">
                                    Dodano: {new Date(rev.createdAt).toLocaleDateString("pl-PL")}
                                  </p>
                                </div>
                              </div>
                              <div className="flex flex-col items-end gap-1">
                                {renderStars(rev.rating)}
                                {rev.visitedOn && (
                                  <span className="text-4xs font-mono text-muted-foreground">
                                    Wizyta: {rev.visitedOn}
                                  </span>
                                )}
                              </div>
                            </div>
                            <p className="text-xs sm:text-sm text-foreground leading-relaxed whitespace-pre-wrap pl-9">
                              {rev.body}
                            </p>
                            {isOwn && (
                              <div className="flex gap-2 justify-end pl-9">
                                <button
                                  type="button"
                                  className="text-4xs font-semibold text-muted-foreground hover:text-primary"
                                  onClick={() => {
                                    setEditingReviewId(rev.id);
                                    setFormRating(rev.rating);
                                    setFormBody(rev.body);
                                    setFormVisitedOn(rev.visitedOn || "");
                                    setShowForm(true);
                                  }}
                                >
                                  Edytuj
                                </button>
                                <span className="text-4xs text-muted-foreground/30">|</span>
                                <button
                                  type="button"
                                  className="text-4xs font-semibold text-destructive/80 hover:text-destructive"
                                  onClick={() => handleDeleteReview(rev.id)}
                                >
                                  Usuń
                                </button>
                              </div>
                            )}
                          </div>
                        );
                      })}
                    </div>
                  )}

                  {/* Pagination */}
                  {totalPages > 1 && (
                    <div className="flex justify-center gap-4 border-t pt-4">
                      <Button
                        size="xs"
                        variant="outline"
                        className="text-2xs"
                        disabled={page === 1}
                        onClick={() => setPage(page - 1)}
                      >
                        Poprzednia
                      </Button>
                      <span className="text-xs text-muted-foreground font-mono mt-1">
                        Strona {page} z {totalPages}
                      </span>
                      <Button
                        size="xs"
                        variant="outline"
                        className="text-2xs"
                        disabled={page === totalPages}
                        onClick={() => setPage(page + 1)}
                      >
                        Następna
                      </Button>
                    </div>
                  )}
                </div>
              )}
            </CardContent>
          </Card>

          {/* Dyskusja (Place Comments & Replies) */}
          <Card className="border shadow-2xs bg-card mt-6">
            <CardContent className="p-6 sm:p-8 flex flex-col gap-6">
              <div className="flex flex-wrap items-center justify-between gap-4 border-b pb-4">
                <h2 className="font-serif text-xl sm:text-2xl font-medium text-foreground">
                  Dyskusja ({comments.length})
                </h2>
                {!replyingToCommentId && !editingCommentId && (
                  <Button
                    size="sm"
                    variant="outline"
                    className="font-semibold text-xs"
                    onClick={() => {
                      if (!session?.authenticated) {
                        alert("Zaloguj się, aby napisać komentarz.");
                        return;
                      }
                      setEditingCommentId(null);
                      setReplyingToCommentId(null);
                      setCommentFormBody("");
                      setCommentFormError(null);
                    }}
                  >
                    Napisz komentarz
                  </Button>
                )}
              </div>

              {/* Thread Composer Form */}
              {(session?.authenticated && (editingCommentId || replyingToCommentId || !editingCommentId && !replyingToCommentId)) && (
                <form onSubmit={handleSubmitComment} className="border p-4 rounded-lg bg-muted/30 flex flex-col gap-3">
                  <h3 className="font-semibold text-xs">
                    {editingCommentId
                      ? "Edytuj swój komentarz"
                      : replyingToCommentId
                        ? `Odpowiedź na komentarz`
                        : "Napisz komentarz do tego miejsca"}
                  </h3>
                  {commentFormError && (
                    <p className="text-xs text-destructive font-medium bg-destructive/10 p-2 rounded">
                      {commentFormError}
                    </p>
                  )}
                  <textarea
                    rows={3}
                    className="w-full border rounded-md p-2 bg-background text-sm"
                    placeholder={replyingToCommentId ? "Napisz swoją odpowiedź..." : "Zadaj pytanie, podziel się uwagą..."}
                    value={commentFormBody}
                    onChange={(e) => setCommentFormBody(e.target.value)}
                    required
                  />
                  <div className="flex justify-between items-center gap-4">
                    <span className="text-3xs text-muted-foreground">{commentFormBody.length}/3000 znaków</span>
                    <div className="flex gap-2">
                      {(editingCommentId || replyingToCommentId) && (
                        <Button
                          type="button"
                          size="xs"
                          variant="ghost"
                          className="text-2xs font-semibold"
                          onClick={() => {
                            setEditingCommentId(null);
                            setReplyingToCommentId(null);
                            setCommentFormBody("");
                            setCommentFormError(null);
                          }}
                        >
                          Anuluj
                        </Button>
                      )}
                      <Button
                        type="submit"
                        size="xs"
                        className="text-2xs font-semibold"
                        disabled={commentSubmitting || commentFormBody.trim().length === 0}
                      >
                        {commentSubmitting ? "Wysyłanie..." : "Wyślij"}
                      </Button>
                    </div>
                  </div>
                </form>
              )}

              {/* Comments List */}
              {commentsLoading ? (
                <div className="text-center py-6 text-xs text-muted-foreground italic">
                  Ładowanie komentarzy...
                </div>
              ) : comments.length > 0 ? (
                <div className="flex flex-col gap-6">
                  {comments.filter(c => c.parentId === null).map((parent) => {
                    const isParentDeleted = parent.status === "DELETED_BY_AUTHOR";
                    const isOwnParent = session?.authenticated && session?.user?.id === parent.authorId;
                    const replies = comments.filter(c => c.parentId === parent.id);

                    return (
                      <div key={parent.id} className="flex flex-col gap-3 border-l-2 pl-4 border-muted/50">
                        {/* Parent Comment */}
                        <div className="flex flex-col gap-1.5">
                          <div className="flex items-center justify-between gap-4">
                            <div className="flex items-center gap-2">
                              <div className="rounded-full bg-muted text-muted-foreground text-xs font-mono font-bold w-6 h-6 flex items-center justify-center">
                                {isParentDeleted ? "?" : (parent.author?.initials || "U")}
                              </div>
                              <div>
                                <span className="font-semibold text-xs text-foreground">
                                  {isParentDeleted ? "Usunięty użytkownik" : (parent.author?.displayName || "Ktoś")}
                                </span>
                                <span className="text-4xs text-muted-foreground font-mono ml-2">
                                  {new Date(parent.createdAt).toLocaleDateString("pl-PL")}
                                </span>
                              </div>
                            </div>
                          </div>
                          <p className={`text-xs sm:text-sm pl-8 leading-relaxed whitespace-pre-wrap ${isParentDeleted ? "text-muted-foreground italic font-light" : "text-foreground"}`}>
                            {isParentDeleted ? "Komentarz został usunięty przez autora." : parent.body}
                          </p>

                          {!isParentDeleted && (
                            <div className="flex gap-2.5 justify-end text-4xs font-semibold text-muted-foreground pl-8">
                              {session?.authenticated && (
                                <button
                                  type="button"
                                  className="hover:text-primary"
                                  onClick={() => {
                                    setReplyingToCommentId(parent.id);
                                    setEditingCommentId(null);
                                    setCommentFormBody("");
                                    setCommentFormError(null);
                                  }}
                                >
                                  Odpowiedz
                                </button>
                              )}
                              {isOwnParent && (
                                <>
                                  <span className="text-muted-foreground/30">|</span>
                                  <button
                                    type="button"
                                    className="hover:text-primary"
                                    onClick={() => {
                                      setEditingCommentId(parent.id);
                                      setReplyingToCommentId(null);
                                      setCommentFormBody(parent.body);
                                      setCommentFormError(null);
                                    }}
                                  >
                                    Edytuj
                                  </button>
                                  <span className="text-muted-foreground/30">|</span>
                                  <button
                                    type="button"
                                    className="text-destructive/80 hover:text-destructive"
                                    onClick={() => handleDeleteComment(parent.id)}
                                  >
                                    Usuń
                                  </button>
                                </>
                              )}
                              <span className="text-muted-foreground/30">|</span>
                              <button
                                type="button"
                                className="hover:text-primary text-destructive/60"
                                onClick={() => alert("Dziękujemy za zgłoszenie. Zostanie ono zweryfikowane przez moderatora.")}
                              >
                                Zgłoś
                              </button>
                            </div>
                          )}
                        </div>

                        {/* Child Replies */}
                        {replies.length > 0 && (
                          <div className="flex flex-col gap-3 ml-6 mt-1 bg-muted/10 p-3 rounded-lg border">
                            {replies.map((reply) => {
                              const isReplyDeleted = reply.status === "DELETED_BY_AUTHOR";
                              const isOwnReply = session?.authenticated && session?.user?.id === reply.authorId;

                              return (
                                <div key={reply.id} className="flex flex-col gap-1.5 first:border-0 border-t pt-2 first:pt-0">
                                  <div className="flex items-center justify-between gap-4">
                                    <div className="flex items-center gap-1.5">
                                      <div className="rounded-full bg-muted text-muted-foreground text-4xs font-mono font-bold w-5 h-5 flex items-center justify-center">
                                        {isReplyDeleted ? "?" : (reply.author?.initials || "U")}
                                      </div>
                                      <span className="font-semibold text-2xs text-foreground">
                                        {isReplyDeleted ? "Usunięty użytkownik" : (reply.author?.displayName || "Ktoś")}
                                      </span>
                                      <span className="text-4xs text-muted-foreground font-mono">
                                        {new Date(reply.createdAt).toLocaleDateString("pl-PL")}
                                      </span>
                                    </div>
                                  </div>
                                  <p className={`text-xs pl-7 leading-relaxed whitespace-pre-wrap ${isReplyDeleted ? "text-muted-foreground italic font-light" : "text-foreground"}`}>
                                    {isReplyDeleted ? "Odpowiedź została usunięta przez autora." : reply.body}
                                  </p>

                                  {!isReplyDeleted && (
                                    <div className="flex gap-2 justify-end text-4xs font-semibold text-muted-foreground pl-7">
                                      {isOwnReply && (
                                        <>
                                          <button
                                            type="button"
                                            className="hover:text-primary"
                                            onClick={() => {
                                              setEditingCommentId(reply.id);
                                              setReplyingToCommentId(null);
                                              setCommentFormBody(reply.body);
                                              setCommentFormError(null);
                                            }}
                                          >
                                            Edytuj
                                          </button>
                                          <span className="text-muted-foreground/30">|</span>
                                          <button
                                            type="button"
                                            className="text-destructive/80 hover:text-destructive"
                                            onClick={() => handleDeleteComment(reply.id)}
                                          >
                                            Usuń
                                          </button>
                                        </>
                                      )}
                                      <span className="text-muted-foreground/30">|</span>
                                      <button
                                        type="button"
                                        className="hover:text-primary text-destructive/60"
                                        onClick={() => alert("Dziękujemy za zgłoszenie. Zostanie ono zweryfikowane przez moderatora.")}
                                      >
                                        Zgłoś
                                      </button>
                                    </div>
                                  )}
                                </div>
                              );
                            })}
                          </div>
                        )}
                      </div>
                    );
                  })}
                </div>
              ) : (
                <div className="text-center p-6 border border-dashed rounded-lg">
                  <p className="text-sm text-muted-foreground italic">
                    Brak komentarzy dla tego miejsca. Bądź pierwszym, który rozpocznie dyskusję!
                  </p>
                </div>
              )}

              {/* Comments Pagination */}
              {commentsTotalPages > 1 && (
                <div className="flex justify-center gap-4 border-t pt-4">
                  <Button
                    size="xs"
                    variant="outline"
                    className="text-2xs"
                    disabled={commentsPage === 1}
                    onClick={() => setCommentsPage(commentsPage - 1)}
                  >
                    Poprzednia
                  </Button>
                  <span className="text-xs text-muted-foreground font-mono mt-1">
                    Strona {commentsPage} z {commentsTotalPages}
                  </span>
                  <Button
                    size="xs"
                    variant="outline"
                    className="text-2xs"
                    disabled={commentsPage === commentsTotalPages}
                    onClick={() => setCommentsPage(commentsPage + 1)}
                  >
                    Następna
                  </Button>
                </div>
              )}
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
