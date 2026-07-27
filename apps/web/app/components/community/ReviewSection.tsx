import * as React from "react"
import { listReviews, addReview, updateReview, deleteReview } from "@family-places/api-client"
import { useSession } from "~/lib/session-context"
import { mapApiError } from "~/utils/error-mapper"
import { Button } from "~/components/ui/button"
import { RatingSummary } from "./RatingSummary"
import { ReviewForm } from "./ReviewForm"
import { ReportContentDialog } from "./ReportContentDialog"
import { Flag, Trash2, Edit2 } from "lucide-react"

interface Review {
  id: string
  placeId: string
  authorId: string
  author?: { id: string; displayName: string; initials: string } | null
  rating: number
  body: string
  visitedOn?: string | null
  status: string
  createdAt: string
  version: number
}

interface ReviewSectionProps {
  placeId: string
}

function toReview(item: Record<string, unknown>): Review {
  const author = item.author

  return {
    id: String(item.id),
    placeId: String(item.placeId),
    authorId: String(item.authorId),
    author: author && typeof author === "object" ? {
      id: String(Reflect.get(author, "id")),
      displayName: String(Reflect.get(author, "displayName")),
      initials: String(Reflect.get(author, "initials")),
    } : null,
    rating: Number(item.rating),
    body: String(item.body),
    visitedOn: typeof item.visitedOn === "string" ? item.visitedOn : null,
    status: String(item.status),
    createdAt: String(item.createdAt),
    version: Number(item.version),
  }
}

export function ReviewSection({ placeId }: ReviewSectionProps) {
  const { session } = useSession()
  const [reviews, setReviews] = React.useState<Review[]>([])
  const [summary, setSummary] = React.useState({
    averageRating: 0,
    totalReviews: 0,
    histogram: { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 },
  })
  const [loading, setLoading] = React.useState(true)
  const [error, setError] = React.useState<string | null>(null)
  const [page, setPage] = React.useState(1)
  const [totalPages, setTotalPages] = React.useState(1)
  const [sort, setSort] = React.useState<"newest" | "highest" | "lowest">("newest")
  const [showForm, setShowForm] = React.useState(false)
  const [submitting, setSubmitting] = React.useState(false)
  const [formError, setFormError] = React.useState<string | null>(null)
  const [editingReview, setEditingReview] = React.useState<Review | null>(null)

  // Accessible Delete confirmation dialog state
  const [deleteTargetId, setDeleteTargetId] = React.useState<string | null>(null)

  const loadReviews = React.useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const res = await listReviews({
        path: { placeId },
        query: { page, sort },
      })
      if (res.data) {
        setReviews((res.data.items || []).map(toReview))
        const s = res.data.summary
        const itemsCount = res.data.items?.length || 0
        const totalCount = Math.max(Number(s?.totalReviews ?? 0), itemsCount)
        const avgRating = Number(s?.averageRating ?? 0)
        setSummary({
          averageRating: avgRating,
          totalReviews: totalCount,
          histogram: {
            1: Number(s?.histogram?.[1] || 0),
            2: Number(s?.histogram?.[2] || 0),
            3: Number(s?.histogram?.[3] || 0),
            4: Number(s?.histogram?.[4] || 0),
            5: Number(s?.histogram?.[5] || 0),
          }
        })
        const totalPages = (res.data.pagination as { totalPages?: number } | undefined)?.totalPages
        setTotalPages(totalPages || 1)
      } else {
        setError("Nie udało się załadować opinii.")
      }
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Wystąpił błąd.")
    } finally {
      setLoading(false)
    }
  }, [placeId, page, sort])

  React.useEffect(() => {
    let ignore = false
    async function init() {
      setError(null)
      try {
        const res = await listReviews({
          path: { placeId },
          query: { page, sort },
        })
        if (!ignore && res.data) {
          setReviews((res.data.items || []).map(toReview))
          const s = res.data.summary
          const itemsCount = res.data.items?.length || 0
          const totalCount = Math.max(Number(s?.totalReviews ?? 0), itemsCount)
          const avgRating = Number(s?.averageRating ?? 0)
          setSummary({
            averageRating: avgRating,
            totalReviews: totalCount,
            histogram: {
              1: Number(s?.histogram?.[1] || 0),
              2: Number(s?.histogram?.[2] || 0),
              3: Number(s?.histogram?.[3] || 0),
              4: Number(s?.histogram?.[4] || 0),
              5: Number(s?.histogram?.[5] || 0),
            }
          })
          const totalPages = (res.data.pagination as { totalPages?: number } | undefined)?.totalPages
          setTotalPages(totalPages || 1)
        } else if (!ignore) {
          setError("Nie udało się załadować opinii.")
        }
      } catch (err: unknown) {
        if (!ignore) {
          setError(err instanceof Error ? err.message : "Wystąpił błąd.")
        }
      } finally {
        if (!ignore) {
          setLoading(false)
        }
      }
    }
    init()
    return () => {
      ignore = true
    }
  }, [placeId, page, sort])

  const handleFormSubmit = async (data: { rating: number; body: string; visitedOn: string | null }) => {
    setSubmitting(true)
    setFormError(null)

    try {
      if (editingReview) {
        // Update
        const res = await updateReview({
          path: { reviewId: editingReview.id },
          body: {
            rating: data.rating,
            body: data.body,
            visitedOn: data.visitedOn || undefined,
            version: editingReview.version,
          },
          headers: { "X-CSRF-Token": session.csrfToken || "" },
        })

        if (res.response?.status === 200) {
          setShowForm(false)
          setEditingReview(null)
          loadReviews()
        } else {
          const errorData = mapApiError(res.error)
          setFormError(errorData.detail || "Nie udało się zaktualizować opinii.")
        }
      } else {
        // Create
        const res = await addReview({
          path: { placeId },
          body: {
            rating: data.rating,
            body: data.body,
            visitedOn: data.visitedOn || undefined,
          },
          headers: { "X-CSRF-Token": session.csrfToken || "" },
        })

        if (res.response?.status === 201) {
          setShowForm(false)
          loadReviews()
        } else {
          const errorData = mapApiError(res.error)
          setFormError(errorData.detail || "Nie udało się dodać opinii.")
        }
      }
    } catch (err: unknown) {
      setFormError(err instanceof Error ? err.message : "Wystąpił nieoczekiwany błąd.")
    } finally {
      setSubmitting(false)
    }
  }

  const handleDelete = async (reviewId: string) => {
    setFormError(null)
    try {
      const res = await deleteReview({
        path: { reviewId },
        headers: { "X-CSRF-Token": session.csrfToken || "" },
      })
      if (res.response?.status === 204) {
        setDeleteTargetId(null)
        loadReviews()
      } else {
        const errorData = mapApiError(res.error)
        setFormError(errorData.detail || "Nie udało się usunąć opinii.")
      }
    } catch (err: unknown) {
      setFormError(err instanceof Error ? err.message : "Wystąpił błąd.")
    }
  }

  const renderStars = (rating: number) => {
    return (
      <div className="flex text-amber-500 font-bold" aria-label={`Ocena: ${rating} na 5`}>
        {"★".repeat(rating)}{"☆".repeat(5 - rating)}
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-center justify-between gap-4 border-b pb-4">
        <h2 className="font-serif text-xl sm:text-2xl font-medium text-foreground">
          Opinie i oceny rodziców
        </h2>
        {!showForm && session.authenticated && (
          <Button
            size="sm"
            variant="outline"
            className="font-semibold text-xs"
            onClick={() => {
              setEditingReview(null)
              setShowForm(true)
            }}
          >
            Dodaj opinię
          </Button>
        )}
      </div>

      {showForm && (
        <ReviewForm
          initialRating={editingReview?.rating}
          initialBody={editingReview?.body}
          initialVisitedOn={editingReview?.visitedOn || ""}
          submitting={submitting}
          formError={formError}
          onSubmit={handleFormSubmit}
          onCancel={() => {
            setShowForm(false)
            setEditingReview(null)
            setFormError(null)
          }}
        />
      )}

      {error && (
        <div className="text-sm text-destructive bg-destructive/10 p-3 rounded" role="alert">
          {error}
        </div>
      )}

      {formError && !showForm && (
        <div className="text-sm text-destructive bg-destructive/10 p-3 rounded flex items-center gap-2" role="alert">
          <span className="font-semibold">Błąd:</span>
          <span>{formError}</span>
        </div>
      )}

      {/* Accessible delete dialog fallback */}
      {deleteTargetId && (
        <div className="border border-destructive/20 bg-destructive/5 p-4 rounded-lg flex items-center justify-between gap-4 text-sm" role="alert">
          <div className="flex items-center gap-2 text-destructive">
            <span>Czy na pewno chcesz usunąć tę opinię?</span>
          </div>
          <div className="flex gap-2">
            <Button size="xs" variant="destructive" onClick={() => handleDelete(deleteTargetId)}>Usuń</Button>
            <Button size="xs" variant="outline" onClick={() => setDeleteTargetId(null)}>Anuluj</Button>
          </div>
        </div>
      )}

      <RatingSummary summary={summary} />

      {summary.totalReviews > 0 && (
        <div className="flex flex-col gap-4 mt-2">
          <div className="flex items-center justify-between gap-4 border-b pb-2">
            <h3 className="font-semibold text-sm">Lista opinii</h3>
            <div className="flex items-center gap-2 text-xs text-muted-foreground">
              <span>Sortuj:</span>
              <select
                aria-label="Sortuj opinie"
                className="bg-background border rounded px-1.5 py-0.5"
                value={sort}
                onChange={(e) => {
                  setSort(e.target.value as "newest" | "highest" | "lowest")
                  setPage(1)
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
                const isOwn = session.authenticated && session.user?.id === rev.authorId
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
                          <p className="text-[10px] text-muted-foreground mt-1">
                            Dodano: {new Date(rev.createdAt).toLocaleDateString("pl-PL")}
                          </p>
                        </div>
                      </div>
                      <div className="flex flex-col items-end gap-1">
                        {renderStars(rev.rating)}
                        {rev.visitedOn && (
                          <span className="text-[10px] text-muted-foreground">
                            Wizyta: {rev.visitedOn}
                          </span>
                        )}
                      </div>
                    </div>
                    <p className="text-sm text-foreground leading-relaxed whitespace-pre-wrap pl-9">
                      {rev.body}
                    </p>
                    <div className="flex gap-2 justify-end pl-9 items-center">
                      {isOwn && (
                        <>
                          <Button
                            variant="ghost"
                            size="sm"
                            className="h-7 text-xs text-muted-foreground hover:text-foreground flex items-center gap-1"
                            onClick={() => {
                              setEditingReview(rev)
                              setShowForm(true)
                            }}
                          >
                            <Edit2 className="h-3 w-3" />
                            <span>Edytuj</span>
                          </Button>
                          <Button
                            variant="ghost"
                            size="sm"
                            className="h-7 text-xs text-destructive hover:bg-destructive/5 flex items-center gap-1"
                            onClick={() => setDeleteTargetId(rev.id)}
                          >
                            <Trash2 className="h-3 w-3" />
                            <span>Usuń</span>
                          </Button>
                        </>
                      )}
                      {!isOwn && session.authenticated && (
                        <ReportContentDialog
                          targetId={rev.id}
                          targetType="REVIEW"
                          trigger={
                            <Button variant="ghost" size="sm" className="h-7 text-xs text-muted-foreground hover:text-destructive flex items-center gap-1">
                              <Flag className="h-3 w-3" />
                              <span>Zgłoś</span>
                            </Button>
                          }
                        />
                      )}
                    </div>
                  </div>
                )
              })}
            </div>
          )}

          {totalPages > 1 && (
            <div className="flex justify-center gap-4 border-t pt-4">
              <Button
                size="sm"
                variant="outline"
                disabled={page === 1}
                onClick={() => setPage(page - 1)}
              >
                Poprzednia
              </Button>
              <span className="text-xs text-muted-foreground mt-1">
                Strona {page} z {totalPages}
              </span>
              <Button
                size="sm"
                variant="outline"
                disabled={page === totalPages}
                onClick={() => setPage(page + 1)}
              >
                Następna
              </Button>
            </div>
          )}
        </div>
      )}
    </div>
  )
}
