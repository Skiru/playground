import * as React from "react"
import { listComments, addComment, addReply, updateComment, deleteComment } from "@family-places/api-client"
import { useSession } from "~/lib/session-context"
import { Button } from "~/components/ui/button"
import { CommentThread } from "./CommentThread"
import { CommentForm } from "./CommentForm"
import { MessageSquare, ShieldAlert } from "lucide-react"

interface Comment {
  id: string
  placeId: string
  authorId: string
  parentId?: string | null
  body: string
  status: string
  createdAt: string
  updatedAt: string
  version: number
  author?: { id: string; displayName: string; initials: string } | null
}

interface PlaceDiscussionSectionProps {
  placeId: string
}

export function PlaceDiscussionSection({ placeId }: PlaceDiscussionSectionProps) {
  const { session } = useSession()
  const [comments, setComments] = React.useState<Comment[]>([])
  const [loading, setLoading] = React.useState(true)
  const [page, setPage] = React.useState(1)
  const [totalPages, setTotalPages] = React.useState(1)
  const [showMainForm, setShowMainForm] = React.useState(false)
  const [submitting, setSubmitting] = React.useState(false)
  const [formError, setFormError] = React.useState<string | null>(null)

  // Accessible delete confirmation state
  const [deleteTargetId, setDeleteTargetId] = React.useState<string | null>(null)

  const loadComments = React.useCallback(async () => {
    setLoading(true)
    try {
      const res = await listComments({
        path: { placeId },
        query: { page },
      })
      if (res.data) {
        setComments((res.data.items || []) as Comment[])
        setTotalPages(res.data.pagination?.totalPages || 1)
      }
    } catch (err: unknown) {
      console.error(err)
    } finally {
      setLoading(false)
    }
  }, [placeId, page])

  React.useEffect(() => {
    loadComments()
  }, [loadComments])

  const handleMainCommentSubmit = async (body: string) => {
    setSubmitting(true)
    setFormError(null)
    try {
      const res = await addComment({
        path: { placeId },
        body: { body },
        headers: { "X-CSRF-Token": session.csrfToken || "" },
      })

      if (res.response.status === 201) {
        setShowMainForm(false)
        loadComments()
      } else {
        const errorData = res.error as any
        setFormError(errorData?.detail || "Nie udało się dodać komentarza.")
      }
    } catch (err: unknown) {
      setFormError(err instanceof Error ? err.message : "Wystąpił błąd.")
    } finally {
      setSubmitting(false)
    }
  }

  const handleReplySubmit = async (parentId: string, body: string) => {
    setSubmitting(true)
    setFormError(null)
    try {
      const res = await addReply({
        path: { commentId: parentId },
        body: { body },
        headers: { "X-CSRF-Token": session.csrfToken || "" },
      })

      if (res.response.status === 201) {
        loadComments()
      } else {
        const errorData = res.error as any
        setFormError(errorData?.detail || "Nie udało się dodać odpowiedzi.")
      }
    } catch (err: unknown) {
      setFormError(err instanceof Error ? err.message : "Wystąpił błąd.")
    } finally {
      setSubmitting(false)
    }
  }

  const handleEditSubmit = async (commentId: string, body: string, version: number) => {
    setSubmitting(true)
    setFormError(null)
    try {
      const res = await updateComment({
        path: { commentId },
        body: { body, version },
        headers: { "X-CSRF-Token": session.csrfToken || "" },
      })

      if (res.response.status === 200) {
        loadComments()
      } else {
        const errorData = res.error as any
        setFormError(errorData?.detail || "Nie udało się edytować komentarza.")
      }
    } catch (err: unknown) {
      setFormError(err instanceof Error ? err.message : "Wystąpił błąd.")
    } finally {
      setSubmitting(false)
    }
  }

  const handleDelete = async (commentId: string) => {
    try {
      const res = await deleteComment({
        path: { commentId },
        headers: { "X-CSRF-Token": session.csrfToken || "" },
      })
      if (res.response.status === 204) {
        setDeleteTargetId(null)
        loadComments()
      } else {
        alert("Nie udało się usunąć komentarza.")
      }
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : "Wystąpił błąd.")
    }
  }

  const rootComments = comments.filter((c) => c.parentId === null)

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-center justify-between gap-4 border-b pb-4">
        <h2 className="font-serif text-xl sm:text-2xl font-medium text-foreground">
          Dyskusja ({comments.length})
        </h2>
        {!showMainForm && session.authenticated && (
          <Button
            size="sm"
            variant="outline"
            className="font-semibold text-xs"
            onClick={() => setShowMainForm(true)}
          >
            Napisz komentarz
          </Button>
        )}
      </div>

      {showMainForm && (
        <CommentForm
          submitting={submitting}
          formError={formError}
          onSubmit={handleMainCommentSubmit}
          onCancel={() => {
            setShowMainForm(false)
            setFormError(null)
          }}
        />
      )}

      {/* Accessible delete dialog fallback */}
      {deleteTargetId && (
        <div className="border border-destructive/20 bg-destructive/5 p-4 rounded-lg flex items-center justify-between gap-4 text-sm" role="alert">
          <div className="flex items-center gap-2 text-destructive">
            <ShieldAlert className="h-5 w-5 shrink-0" />
            <span>Czy na pewno chcesz usunąć ten komentarz? Wszystkie odpowiedzi zostaną zachowane.</span>
          </div>
          <div className="flex gap-2">
            <Button size="xs" variant="destructive" onClick={() => handleDelete(deleteTargetId)}>Usuń</Button>
            <Button size="xs" variant="outline" onClick={() => setDeleteTargetId(null)}>Anuluj</Button>
          </div>
        </div>
      )}

      {loading ? (
        <div className="text-center py-6 text-xs text-muted-foreground italic">
          Ładowanie komentarzy...
        </div>
      ) : rootComments.length > 0 ? (
        <div className="flex flex-col gap-6">
          {rootComments.map((parent) => {
            const replies = comments.filter((c) => c.parentId === parent.id)
            return (
              <CommentThread
                key={parent.id}
                parent={parent}
                replies={replies}
                onReply={handleReplySubmit}
                onEdit={handleEditSubmit}
                onDeleteRequest={setDeleteTargetId}
                submitting={submitting}
                formError={formError}
              />
            )
          })}
        </div>
      ) : (
        <div className="text-center p-6 border border-dashed rounded-lg">
          <MessageSquare className="h-10 w-10 text-muted-foreground mx-auto mb-2" />
          <p className="text-sm text-muted-foreground italic">
            Brak komentarzy dla tego miejsca. Bądź pierwszym, który rozpocznie dyskusję!
          </p>
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
          <span className="text-xs text-muted-foreground mt-1 font-mono">
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
  )
}
