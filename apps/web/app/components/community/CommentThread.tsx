import * as React from "react"
import { useSession } from "~/lib/session-context"
import { CommentForm } from "./CommentForm"
import { ReportContentDialog } from "./ReportContentDialog"
import { Button } from "~/components/ui/button"
import { Avatar, AvatarFallback } from "~/components/ui/avatar"
import { CornerDownRight, Flag, Edit2, Trash2 } from "lucide-react"

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

interface CommentThreadProps {
  parent: Comment
  replies: Comment[]
  onReply: (parentId: string, body: string) => Promise<void>
  onEdit: (commentId: string, body: string, version: number) => Promise<void>
  onDeleteRequest: (commentId: string) => void
  submitting: boolean
  formError: string | null
}

export function CommentThread({
  parent,
  replies,
  onReply,
  onEdit,
  onDeleteRequest,
  submitting,
  formError,
}: CommentThreadProps) {
  const { session } = useSession()
  const [isReplying, setIsReplying] = React.useState(false)
  const [editingCommentId, setEditingCommentId] = React.useState<string | null>(null)

  const isParentDeleted = parent.status === "DELETED_BY_AUTHOR"
  const isOwnParent = session.authenticated && session.user?.id === parent.authorId

  const handleReplySubmit = async (body: string) => {
    await onReply(parent.id, body)
    setIsReplying(false)
  }

  const handleEditSubmit = async (body: string, id: string, version: number) => {
    await onEdit(id, body, version)
    setEditingCommentId(null)
  }

  return (
    <div className="flex flex-col gap-3 border-l-2 pl-4 border-muted/50">
      {/* Parent Comment */}
      <div className="flex flex-col gap-1.5">
        <div className="flex items-center justify-between gap-4">
          <div className="flex items-center gap-2">
            <Avatar className="h-6 w-6">
              <AvatarFallback className="bg-muted text-muted-foreground text-[10px] font-bold">
                {isParentDeleted ? "?" : (parent.author?.initials || "U")}
              </AvatarFallback>
            </Avatar>
            <div>
              <span className="font-semibold text-xs text-foreground">
                {isParentDeleted ? "Usunięty użytkownik" : (parent.author?.displayName || "Ktoś")}
              </span>
              <span className="text-[10px] text-muted-foreground font-mono ml-2">
                {new Date(parent.createdAt).toLocaleDateString("pl-PL")}
              </span>
            </div>
          </div>
        </div>

        {editingCommentId === parent.id ? (
          <CommentForm
            initialBody={parent.body}
            isEdit
            submitting={submitting}
            formError={formError}
            onSubmit={(body) => handleEditSubmit(body, parent.id, parent.version)}
            onCancel={() => setEditingCommentId(null)}
          />
        ) : (
          <p className={`text-xs sm:text-sm pl-8 leading-relaxed whitespace-pre-wrap ${isParentDeleted ? "text-muted-foreground italic" : "text-foreground"}`}>
            {isParentDeleted ? "Treść usunięta przez autora" : parent.body}
          </p>
        )}

        {!isParentDeleted && !editingCommentId && (
          <div className="flex gap-2.5 justify-end text-[10px] font-semibold text-muted-foreground pl-8 items-center">
            {session.authenticated && (
              <Button
                variant="ghost"
                size="xs"
                className="h-6 text-[10px] px-1 text-muted-foreground hover:text-primary"
                onClick={() => {
                  setIsReplying(true)
                  setEditingCommentId(null)
                }}
              >
                Odpowiedz
              </Button>
            )}
            {isOwnParent && (
              <>
                <span className="text-muted-foreground/30">|</span>
                <Button
                  variant="ghost"
                  size="xs"
                  className="h-6 text-[10px] px-1 text-muted-foreground hover:text-foreground"
                  onClick={() => {
                    setEditingCommentId(parent.id)
                    setIsReplying(false)
                  }}
                >
                  <Edit2 className="h-2.5 w-2.5 mr-1" />
                  Edytuj
                </Button>
                <span className="text-muted-foreground/30">|</span>
                <Button
                  variant="ghost"
                  size="xs"
                  className="h-6 text-[10px] px-1 text-destructive hover:bg-destructive/5"
                  onClick={() => onDeleteRequest(parent.id)}
                >
                  <Trash2 className="h-2.5 w-2.5 mr-1" />
                  Usuń
                </Button>
              </>
            )}
            {session.authenticated && (
              <>
                <span className="text-muted-foreground/30">|</span>
                <ReportContentDialog
                  targetId={parent.id}
                  targetType="PLACE_COMMENT"
                  trigger={
                    <Button variant="ghost" size="xs" className="h-6 text-[10px] px-1 text-muted-foreground hover:text-destructive">
                      <Flag className="h-2.5 w-2.5 mr-1" />
                      Zgłoś
                    </Button>
                  }
                />
              </>
            )}
          </div>
        )}
      </div>

      {isReplying && (
        <div className="ml-6">
          <CommentForm
            isReply
            submitting={submitting}
            formError={formError}
            onSubmit={handleReplySubmit}
            onCancel={() => setIsReplying(false)}
          />
        </div>
      )}

      {/* Child Replies */}
      {replies.length > 0 && (
        <div className="flex flex-col gap-3 ml-6 mt-1 bg-muted/10 p-3 rounded-lg border">
          {replies.map((reply) => {
            const isReplyDeleted = reply.status === "DELETED_BY_AUTHOR"
            const isOwnReply = session.authenticated && session.user?.id === reply.authorId

            return (
              <div key={reply.id} className="flex flex-col gap-1.5 first:border-0 border-t pt-2 first:pt-0">
                <div className="flex items-center justify-between gap-4">
                  <div className="flex items-center gap-1.5">
                    <CornerDownRight className="h-3 w-3 text-muted-foreground shrink-0" />
                    <Avatar className="h-5 w-5">
                      <AvatarFallback className="bg-muted text-muted-foreground text-[8px] font-bold">
                        {isReplyDeleted ? "?" : (reply.author?.initials || "U")}
                      </AvatarFallback>
                    </Avatar>
                    <span className="font-semibold text-[10px] text-foreground">
                      {isReplyDeleted ? "Usunięty użytkownik" : (reply.author?.displayName || "Ktoś")}
                    </span>
                    <span className="text-[10px] text-muted-foreground font-mono">
                      {new Date(reply.createdAt).toLocaleDateString("pl-PL")}
                    </span>
                  </div>
                </div>

                {editingCommentId === reply.id ? (
                  <CommentForm
                    initialBody={reply.body}
                    isEdit
                    submitting={submitting}
                    formError={formError}
                    onSubmit={(body) => handleEditSubmit(body, reply.id, reply.version)}
                    onCancel={() => setEditingCommentId(null)}
                  />
                ) : (
                  <p className={`text-xs pl-7 leading-relaxed whitespace-pre-wrap ${isReplyDeleted ? "text-muted-foreground italic font-light" : "text-foreground"}`}>
                    {isReplyDeleted ? "Treść usunięta przez autora" : reply.body}
                  </p>
                )}

                {!isReplyDeleted && editingCommentId !== reply.id && (
                  <div className="flex gap-2 justify-end text-[10px] font-semibold text-muted-foreground pl-7 items-center">
                    {isOwnReply && (
                      <>
                        <Button
                          variant="ghost"
                          size="xs"
                          className="h-6 text-[10px] px-1 text-muted-foreground hover:text-foreground"
                          onClick={() => {
                            setEditingCommentId(reply.id)
                            setIsReplying(false)
                          }}
                        >
                          <Edit2 className="h-2.5 w-2.5 mr-1" />
                          Edytuj
                        </Button>
                        <span className="text-muted-foreground/30">|</span>
                        <Button
                          variant="ghost"
                          size="xs"
                          className="h-6 text-[10px] px-1 text-destructive hover:bg-destructive/5"
                          onClick={() => onDeleteRequest(reply.id)}
                        >
                          <Trash2 className="h-2.5 w-2.5 mr-1" />
                          Usuń
                        </Button>
                      </>
                    )}
                    {session.authenticated && (
                      <>
                        <span className="text-muted-foreground/30">|</span>
                        <ReportContentDialog
                          targetId={reply.id}
                          targetType="PLACE_COMMENT"
                          trigger={
                            <Button variant="ghost" size="xs" className="h-6 text-[10px] px-1 text-muted-foreground hover:text-destructive">
                              <Flag className="h-2.5 w-2.5 mr-1" />
                              Zgłoś
                            </Button>
                          }
                        />
                      </>
                    )}
                  </div>
                )}
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}
