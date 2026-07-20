import * as React from "react"
import { useParams, Link } from "react-router"
import {
  getForumThread,
  listForumPosts,
  createForumPost,
  editOwnForumThread,
  deleteOwnForumThread,
  editOwnForumPost,
  deleteOwnForumPost,
} from "@family-places/api-client"
import { useSession } from "~/lib/session-context"
import { AppShell } from "~/components/layout/AppShell"
import { PageContainer } from "~/components/layout/PageContainer"
import { Card, CardContent, CardHeader, CardTitle, CardFooter } from "~/components/ui/card"
import { Button } from "~/components/ui/button"
import { Avatar, AvatarFallback } from "~/components/ui/avatar"
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from "~/components/ui/dialog"
import { Input } from "~/components/ui/input"
import { Label } from "~/components/ui/label"
import { ReportContentDialog } from "~/components/community/ReportContentDialog"
import {
  MessageSquare,
  Pin,
  Lock,
  Calendar,
  AlertTriangle,
  Edit2,
  Trash2,
  CornerDownRight,
  Flag,
} from "lucide-react"

interface Post {
  id: string
  threadId: string
  authorId: string
  author: { id: string; displayName: string; initials: string }
  parentId?: string | null
  body: string
  status: string
  createdAt: string
  updatedAt: string
}

interface Thread {
  id: string
  categoryId: string
  authorId: string
  author: { id: string; displayName: string; initials: string }
  title: string
  status: string
  createdAt: string
  updatedAt: string
  lastActivityAt: string
  lockedAt?: string | null
  pinnedAt?: string | null
}

export default function ForumThreadDetailPage() {
  const { threadId } = useParams()
  const { session } = React.useContext(useSession as any) || useSession()
  const [thread, setThread] = React.useState<Thread | null>(null)
  const [posts, setPosts] = React.useState<Post[]>([])
  const [loading, setLoading] = React.useState(true)
  const [error, setError] = React.useState<string | null>(null)

  // Reply state
  const [replyBody, setReplyBody] = React.useState("")
  const [replyToPostId, setReplyToPostId] = React.useState<string | null>(null)
  const [submittingReply, setSubmittingReply] = React.useState(false)
  const [replyError, setReplyError] = React.useState<string | null>(null)

  // Edit Thread state
  const [isEditThreadOpen, setIsEditThreadOpen] = React.useState(false)
  const [editTitle, setEditTitle] = React.useState("")
  const [editThreadError, setEditThreadError] = React.useState<string | null>(null)
  const [editingThread, setEditingThread] = React.useState(false)

  // Edit Post state
  const [editPostId, setEditPostId] = React.useState<string | null>(null)
  const [editPostBody, setEditPostValue] = React.useState("")
  const [editPostError, setEditPostError] = React.useState<string | null>(null)
  const [editingPost, setEditingPost] = React.useState(false)

  const loadThreadAndPosts = React.useCallback(async () => {
    if (!threadId) return
    setLoading(true)
    setError(null)
    try {
      const [threadRes, postsRes] = await Promise.all([
        getForumThread({ path: { threadId } }),
        listForumPosts({ path: { threadId } }),
      ])

      if (threadRes.data && postsRes.data) {
        setThread(threadRes.data as Thread)
        setPosts((postsRes.data.items || []) as Post[])
        setEditTitle(threadRes.data.title as string)
      } else {
        setError("Wątek nie istnieje lub został usunięty.")
      }
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Wystąpił błąd.")
    } finally {
      setLoading(false)
    }
  }, [threadId])

  React.useEffect(() => {
    loadThreadAndPosts()
  }, [loadThreadAndPosts])

  const handleCreateReply = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!thread) return
    setReplyError(null)
    setSubmittingReply(true)

    try {
      const res = await createForumPost({
        path: { threadId: thread.id },
        body: {
          body: replyBody,
          replyToPostId: replyToPostId || undefined,
        },
        headers: { "X-CSRF-Token": session.csrfToken || "" },
      })

      if (res.response.status === 201) {
        setReplyBody("")
        setReplyToPostId(null)
        loadThreadAndPosts()
      } else {
        const errorData = res.error as any
        setReplyError(errorData?.detail || "Nie udało się dodać odpowiedzi.")
      }
    } catch (err: unknown) {
      setReplyError(err instanceof Error ? err.message : "Wystąpił błąd.")
    } finally {
      setSubmittingReply(false)
    }
  }

  const handleEditThread = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!thread) return
    setEditThreadError(null)
    setEditingThread(true)

    try {
      const res = await editOwnForumThread({
        path: { threadId: thread.id },
        body: { title: editTitle },
        headers: { "X-CSRF-Token": session.csrfToken || "" },
      })

      if (res.response.status === 200) {
        setIsEditThreadOpen(false)
        loadThreadAndPosts()
      } else {
        const errorData = res.error as any
        setEditThreadError(errorData?.detail || "Nie udało się edytować wątku.")
      }
    } catch (err: unknown) {
      setEditThreadError(err instanceof Error ? err.message : "Wystąpił błąd.")
    } finally {
      setEditingThread(false)
    }
  }

  const handleDeleteThread = async () => {
    if (!thread) return
    if (!window.confirm("Czy na pewno chcesz usunąć ten wątek? Ta operacja jest nieodwracalna.")) return

    try {
      const res = await deleteOwnForumThread({
        path: { threadId: thread.id },
        headers: { "X-CSRF-Token": session.csrfToken || "" },
      })

      if (res.response.status === 204) {
        window.location.href = "/forum"
      } else {
        alert("Nie udało się usunąć wątku.")
      }
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : "Wystąpił błąd.")
    }
  }

  const handleEditPost = async (e: React.FormEvent, postId: string) => {
    e.preventDefault()
    setEditPostError(null)
    setEditingPost(true)

    try {
      const res = await editOwnForumPost({
        path: { postId },
        body: { body: editPostBody },
        headers: { "X-CSRF-Token": session.csrfToken || "" },
      })

      if (res.response.status === 200) {
        setEditPostId(null)
        loadThreadAndPosts()
      } else {
        const errorData = res.error as any
        setEditPostError(errorData?.detail || "Nie udało się edytować posta.")
      }
    } catch (err: unknown) {
      setEditPostError(err instanceof Error ? err.message : "Wystąpił błąd.")
    } finally {
      setEditingPost(false)
    }
  }

  const handleDeletePost = async (postId: string) => {
    if (!window.confirm("Czy na pewno chcesz usunąć tę odpowiedź?")) return

    try {
      const res = await deleteOwnForumPost({
        path: { postId },
        headers: { "X-CSRF-Token": session.csrfToken || "" },
      })

      if (res.response.status === 204) {
        loadThreadAndPosts()
      } else {
        alert("Nie udało się usunąć posta.")
      }
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : "Wystąpił błąd.")
    }
  }

  return (
    <AppShell>
      <PageContainer>
        <div className="mx-auto max-w-4xl py-8">
          {error && (
            <div className="bg-destructive/10 text-destructive p-4 rounded-lg mb-6 text-sm" role="alert">
              {error}
            </div>
          )}

          {loading ? (
            <div className="space-y-6">
              <div className="space-y-2 animate-pulse">
                <div className="h-8 w-2/3 bg-muted rounded" />
                <div className="h-4 w-1/3 bg-muted rounded" />
              </div>
              <Card className="animate-pulse">
                <CardHeader className="flex flex-row items-center gap-4">
                  <div className="h-10 w-10 rounded-full bg-muted" />
                  <div className="h-4 w-1/4 bg-muted rounded" />
                </CardHeader>
                <CardContent className="h-20 bg-muted rounded-b" />
              </Card>
            </div>
          ) : !thread ? (
            <Card className="text-center py-12">
              <CardContent className="space-y-4">
                <AlertTriangle className="h-12 w-12 text-destructive mx-auto" />
                <h3 className="text-lg font-semibold">Brak wątku</h3>
                <p className="text-muted-foreground">Wątek nie istnieje lub został usunięty przez moderatora.</p>
              </CardContent>
            </Card>
          ) : (
            <div className="space-y-6">
              {/* Header and thread tools */}
              <div className="border-b pb-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                  <div className="flex items-center gap-2 text-sm text-muted-foreground mb-2">
                    <Link to="/forum" className="hover:text-primary transition-colors">Forum</Link>
                    <span>/</span>
                    <Link to={`/forum`} className="hover:text-primary transition-colors">Wątki</Link>
                  </div>

                  <div className="flex items-center gap-2 flex-wrap">
                    {thread.pinnedAt && <Pin className="h-4 w-4 text-primary shrink-0" />}
                    <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight">
                      {thread.status === "DELETED_BY_AUTHOR" ? "Wątek usunięty przez autora" : thread.title}
                    </h1>
                    {thread.lockedAt && <Lock className="h-4 w-4 text-muted-foreground shrink-0 ml-1" />}
                  </div>

                  <div className="flex items-center gap-2 text-xs text-muted-foreground mt-2">
                    <Avatar className="h-6 w-6">
                      <AvatarFallback className="bg-primary/10 text-primary text-[10px] font-bold">
                        {thread.author.initials}
                      </AvatarFallback>
                    </Avatar>
                    <span className="font-semibold">{thread.author.displayName}</span>
                    <span>•</span>
                    <Calendar className="h-3 w-3" />
                    <span>{new Date(thread.createdAt).toLocaleString("pl-PL")}</span>
                  </div>
                </div>

                <div className="flex items-center gap-2">
                  {/* Edit/Delete Own Thread */}
                  {session.authenticated && thread.authorId === session.user?.id && thread.status === "PUBLISHED" && (
                    <>
                      <Button variant="outline" size="sm" onClick={() => setIsEditThreadOpen(true)} className="flex items-center gap-1.5">
                        <Edit2 className="h-3.5 w-3.5" />
                        <span>Edytuj</span>
                      </Button>
                      <Button variant="outline" size="sm" onClick={handleDeleteThread} className="flex items-center gap-1.5 text-destructive hover:bg-destructive/10">
                        <Trash2 className="h-3.5 w-3.5" />
                        <span>Usuń</span>
                      </Button>
                    </>
                  )}

                  {/* Report Thread */}
                  {session.authenticated && thread.status === "PUBLISHED" && (
                    <ReportContentDialog
                      targetId={thread.id}
                      targetType="FORUM_THREAD"
                      trigger={
                        <Button variant="ghost" size="sm" className="text-muted-foreground hover:text-destructive">
                          <Flag className="h-3.5 w-3.5" />
                          <span className="sr-only">Zgłoś wątek</span>
                        </Button>
                      }
                    />
                  )}
                </div>
              </div>

              {/* Edit Thread Dialog */}
              <Dialog open={isEditThreadOpen} onOpenChange={setIsEditThreadOpen}>
                <DialogContent>
                  <DialogHeader>
                    <DialogTitle>Edytuj tytuł wątku</DialogTitle>
                  </DialogHeader>
                  <form onSubmit={handleEditThread} className="space-y-4 py-4">
                    {editThreadError && (
                      <div className="bg-destructive/10 text-destructive p-3 rounded text-xs">
                        {editThreadError}
                      </div>
                    )}
                    <div className="space-y-1">
                      <Label htmlFor="edit-title">Tytuł wątku</Label>
                      <Input
                        id="edit-title"
                        value={editTitle}
                        onChange={(e) => setEditTitle(e.target.value)}
                        required
                        minLength={5}
                        maxLength={160}
                      />
                    </div>
                    <DialogFooter>
                      <Button type="button" variant="outline" onClick={() => setIsEditThreadOpen(false)}>
                        Anuluj
                      </Button>
                      <Button type="submit" disabled={editingThread}>
                        {editingThread ? "Zapisywanie..." : "Zapisz zmiany"}
                      </Button>
                    </DialogFooter>
                  </form>
                </DialogContent>
              </Dialog>

              {/* Posts List */}
              <div className="space-y-6">
                {posts.map((post) => {
                  const isPostAuthor = session.authenticated && post.authorId === session.user?.id
                  const isDeleted = post.status === "DELETED_BY_AUTHOR"

                  return (
                    <Card key={post.id} className={`overflow-hidden border-muted ${post.parentId ? "ml-8 bg-muted/10" : ""}`}>
                      <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
                        <div className="flex items-center gap-2.5">
                          {post.parentId && <CornerDownRight className="h-4 w-4 text-muted-foreground shrink-0" />}
                          <Avatar className="h-8 w-8">
                            <AvatarFallback className="bg-primary/10 text-primary text-xs font-bold">
                              {post.author.initials}
                            </AvatarFallback>
                          </Avatar>
                          <div>
                            <div className="text-sm font-semibold">{post.author.displayName}</div>
                            <div className="text-[10px] text-muted-foreground">
                              {new Date(post.createdAt).toLocaleString("pl-PL")}
                            </div>
                          </div>
                        </div>

                        <div className="flex items-center gap-2">
                          {/* Reply / Edit / Delete / Report actions */}
                          {session.authenticated && !thread.lockedAt && !isDeleted && post.status === "PUBLISHED" && (
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() => {
                                setReplyToPostId(post.id)
                                // Scroll to form
                                document.getElementById("reply-form")?.scrollIntoView({ behavior: "smooth" })
                              }}
                              className="text-xs text-muted-foreground hover:text-primary h-8"
                            >
                              Odpowiedz
                            </Button>
                          )}

                          {isPostAuthor && !isDeleted && post.status === "PUBLISHED" && (
                            <>
                              <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => {
                                  setEditPostId(post.id)
                                  setEditPostValue(post.body)
                                }}
                                className="h-8 w-8 p-0"
                              >
                                <Edit2 className="h-3.5 w-3.5" />
                              </Button>
                              <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => handleDeletePost(post.id)}
                                className="h-8 w-8 p-0 text-destructive hover:bg-destructive/10"
                              >
                                <Trash2 className="h-3.5 w-3.5" />
                              </Button>
                            </>
                          )}

                          {session.authenticated && !isPostAuthor && !isDeleted && post.status === "PUBLISHED" && (
                            <ReportContentDialog
                              targetId={post.id}
                              targetType="FORUM_POST"
                              trigger={
                                <Button variant="ghost" size="sm" className="h-8 w-8 p-0 text-muted-foreground hover:text-destructive">
                                  <Flag className="h-3.5 w-3.5" />
                                </Button>
                              }
                            />
                          )}
                        </div>
                      </CardHeader>
                      <CardContent>
                        {editPostId === post.id ? (
                          <form onSubmit={(e) => handleEditPost(e, post.id)} className="space-y-3">
                            {editPostError && (
                              <div className="bg-destructive/10 text-destructive p-2 rounded text-xs">
                                {editPostError}
                              </div>
                            )}
                            <textarea
                              value={editPostBody}
                              onChange={(e) => setEditPostValue(e.target.value)}
                              className="flex min-h-[80px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm"
                              required
                            />
                            <div className="flex gap-2">
                              <Button type="submit" size="sm" disabled={editingPost}>
                                {editingPost ? "Zapisywanie..." : "Zapisz"}
                              </Button>
                              <Button type="button" size="sm" variant="outline" onClick={() => setEditPostId(null)}>
                                Anuluj
                              </Button>
                            </div>
                          </form>
                        ) : (
                          <p className="text-sm text-foreground leading-relaxed whitespace-pre-line">
                            {isDeleted ? "Treść usunięta przez autora" : post.body}
                          </p>
                        )}
                      </CardContent>
                    </Card>
                  )
                })}
              </div>

              {/* Reply Form */}
              {session.authenticated && !thread.lockedAt && (
                <div id="reply-form" className="pt-6 border-t">
                  <Card className="border-muted bg-muted/5">
                    <CardHeader className="pb-3">
                      <CardTitle className="text-base font-bold flex items-center gap-2">
                        <MessageSquare className="h-4 w-4" />
                        <span>Dodaj odpowiedź</span>
                      </CardTitle>
                      {replyToPostId && (
                        <div className="flex items-center gap-2 text-xs bg-primary/10 text-primary px-3 py-1.5 rounded-md mt-2 w-fit">
                          <span>Odpowiadasz na post użytkownika</span>
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setReplyToPostId(null)}
                            className="h-4 p-0 text-[10px] underline ml-2"
                          >
                            Anuluj odpowiedź
                          </Button>
                        </div>
                      )}
                    </CardHeader>
                    <form onSubmit={handleCreateReply}>
                      <CardContent>
                        {replyError && (
                          <div className="bg-destructive/10 text-destructive p-3 rounded text-xs mb-3">
                            {replyError}
                          </div>
                        )}
                        <textarea
                          value={replyBody}
                          onChange={(e) => setReplyBody(e.target.value)}
                          placeholder="Napisz swoją odpowiedź..."
                          className="flex min-h-[100px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                          required
                          maxLength={10000}
                        />
                      </CardContent>
                      <CardFooter className="justify-end gap-2 border-t pt-3">
                        <Button type="submit" disabled={submittingReply}>
                          {submittingReply ? "Dodawanie..." : "Wyślij"}
                        </Button>
                      </CardFooter>
                    </form>
                  </Card>
                </div>
              )}
            </div>
          )}
        </div>
      </PageContainer>
    </AppShell>
  )
}
