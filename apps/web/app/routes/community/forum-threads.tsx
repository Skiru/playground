import * as React from "react"
import { useParams, Link } from "react-router"
import { listCategoryThreads, createForumThread } from "@family-places/api-client"
import { useSession } from "~/lib/session-context"
import { AppShell } from "~/components/layout/AppShell"
import { PageContainer } from "~/components/layout/PageContainer"
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "~/components/ui/card"
import { Button } from "~/components/ui/button"
import { Avatar, AvatarFallback } from "~/components/ui/avatar"
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger, DialogFooter } from "~/components/ui/dialog"
import { Input } from "~/components/ui/input"
import { Label } from "~/components/ui/label"
import { MessageSquare, Pin, Lock, Plus, Calendar, AlertTriangle } from "lucide-react"

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

interface Category {
  id: string
  slug: string
  name: string
  description: string
}

export default function ForumThreadsPage() {
  const { categorySlug } = useParams()
  const { session } = useSession()
  const [category, setCategory] = React.useState<Category | null>(null)
  const [threads, setThreads] = React.useState<Thread[]>([])
  const [loading, setLoading] = React.useState(true)
  const [error, setError] = React.useState<string | null>(null)

  // Creation State
  const [isCreateOpen, setIsOpen] = React.useState(false)
  const [newTitle, setNewTitle] = React.useState("")
  const [newBody, setNewBody] = React.useState("")
  const [createError, setCreateError] = React.useState<string | null>(null)
  const [submitting, setSubmitting] = React.useState(false)

  const loadThreads = React.useCallback(async () => {
    if (!categorySlug) return
    setLoading(true)
    setError(null)
    try {
      const res = await listCategoryThreads({ path: { categorySlug } })
      if (res.data) {
        setCategory(res.data.category as Category)
        setThreads((res.data.items || []) as Thread[])
      } else {
        setError("Nie znaleziono podanej kategorii forum.")
      }
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Wystąpił błąd.")
    } finally {
      setLoading(false)
    }
  }, [categorySlug])

  React.useEffect(() => {
    loadThreads()
  }, [loadThreads])

  const handleCreateThread = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!category) return
    setCreateError(null)
    setSubmitting(true)

    try {
      const res = await createForumThread({
        path: { categoryId: category.id },
        body: { title: newTitle, body: newBody },
        headers: { "X-CSRF-Token": session.csrfToken || "" },
      })

      if (res.response.status === 201) {
        setIsOpen(false)
        setNewTitle("")
        setNewBody("")
        loadThreads()
      } else {
        const errorData = res.error as any
        setCreateError(errorData?.detail || "Nie udało się utworzyć wątku.")
      }
    } catch (err: unknown) {
      setCreateError(err instanceof Error ? err.message : "Wystąpił błąd.")
    } finally {
      setSubmitting(false)
    }
  }

  // Separate pinned vs standard
  const pinnedThreads = threads.filter((t) => t.pinnedAt)
  const normalThreads = threads.filter((t) => !t.pinnedAt)

  return (
    <AppShell>
      <PageContainer>
        <div className="mx-auto max-w-5xl py-8">
          {error && (
            <div className="bg-destructive/10 text-destructive p-4 rounded-lg mb-6 text-sm" role="alert">
              {error}
            </div>
          )}

          {loading ? (
            <div className="space-y-4">
              <div className="h-10 w-1/3 bg-muted rounded animate-pulse" />
              <div className="h-4 w-1/2 bg-muted rounded animate-pulse mb-6" />
              {[1, 2, 3].map((n) => (
                <Card key={n} className="animate-pulse">
                  <CardHeader>
                    <div className="h-6 w-1/2 bg-muted rounded mb-2" />
                    <div className="h-4 w-1/4 bg-muted rounded" />
                  </CardHeader>
                </Card>
              ))}
            </div>
          ) : !category ? (
            <Card className="text-center py-12">
              <CardContent className="space-y-4">
                <AlertTriangle className="h-12 w-12 text-destructive mx-auto" />
                <h3 className="text-lg font-semibold">Nie znaleziono kategorii</h3>
                <p className="text-muted-foreground">Podana kategoria forum nie istnieje lub została usunięta.</p>
              </CardContent>
            </Card>
          ) : (
            <div>
              <div className="flex flex-col gap-6 md:flex-row md:items-center md:justify-between border-b pb-6 mb-8">
                <div>
                  <div className="flex items-center gap-2 text-sm text-muted-foreground mb-1">
                    <Link to="/forum" className="hover:text-primary transition-colors">Forum</Link>
                    <span>/</span>
                    <span>{category.name}</span>
                  </div>
                  <h1 className="text-3xl font-extrabold tracking-tight">{category.name}</h1>
                  <p className="text-muted-foreground mt-1">{category.description}</p>
                </div>

                {session.authenticated && (
                  <Dialog open={isCreateOpen} onOpenChange={setIsOpen}>
                    <DialogTrigger asChild>
                      <Button className="flex items-center gap-2">
                        <Plus className="h-4 w-4" />
                        <span>Nowy wątek</span>
                      </Button>
                    </DialogTrigger>
                    <DialogContent className="sm:max-w-[500px]">
                      <DialogHeader>
                        <DialogTitle>Utwórz nowy wątek</DialogTitle>
                      </DialogHeader>
                      <form onSubmit={handleCreateThread} className="space-y-4 py-4">
                        {createError && (
                          <div className="bg-destructive/10 text-destructive p-3 rounded text-xs">
                            {createError}
                          </div>
                        )}
                        <div className="space-y-1">
                          <Label htmlFor="title">Tytuł wątku</Label>
                          <Input
                            id="title"
                            value={newTitle}
                            onChange={(e) => setNewTitle(e.target.value)}
                            placeholder="Wpisz krótki, konkretny tytuł"
                            required
                            minLength={5}
                            maxLength={160}
                          />
                        </div>
                        <div className="space-y-1">
                          <Label htmlFor="body">Treść pierwszego posta</Label>
                          <textarea
                            id="body"
                            value={newBody}
                            onChange={(e) => setNewBody(e.target.value)}
                            placeholder="Napisz o czym chcesz porozmawiać..."
                            className="flex min-h-[120px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                            required
                            minLength={1}
                            maxLength={10000}
                          />
                        </div>
                        <DialogFooter className="pt-4">
                          <Button type="button" variant="outline" onClick={() => setIsOpen(false)}>
                            Anuluj
                          </Button>
                          <Button type="submit" disabled={submitting}>
                            {submitting ? "Tworzenie..." : "Utwórz wątek"}
                          </Button>
                        </DialogFooter>
                      </form>
                    </DialogContent>
                  </Dialog>
                )}
              </div>

              {threads.length === 0 ? (
                <Card className="text-center py-12">
                  <CardContent className="space-y-4">
                    <MessageSquare className="h-12 w-12 text-muted-foreground mx-auto" />
                    <h3 className="text-lg font-semibold">Brak wątków</h3>
                    <p className="text-muted-foreground">Bądź pierwszą osobą, która rozpocznie dyskusję w tej kategorii!</p>
                  </CardContent>
                </Card>
              ) : (
                <div className="space-y-4">
                  {/* Pinned threads */}
                  {pinnedThreads.map((thread) => (
                    <Card key={thread.id} className="border-primary/30 bg-primary/5 hover:border-primary transition-all">
                      <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                        <Link to={`/forum/watek/${thread.id}`} className="flex-1 hover:text-primary transition-colors">
                          <div className="flex items-center gap-2">
                            <Pin className="h-4 w-4 text-primary shrink-0" />
                            <CardTitle className="text-lg font-bold">
                              {thread.status === "DELETED_BY_AUTHOR" ? "Wątek usunięty przez autora" : thread.title}
                            </CardTitle>
                          </div>
                        </Link>
                        {thread.lockedAt && <Lock className="h-4 w-4 text-muted-foreground shrink-0 ml-2" />}
                      </CardHeader>
                      <CardContent>
                        <div className="flex items-center gap-2 text-xs text-muted-foreground mt-2">
                          <Avatar className="h-6 w-6">
                            <AvatarFallback className="bg-primary/10 text-primary text-[10px] font-bold">
                              {thread.author.initials}
                            </AvatarFallback>
                          </Avatar>
                          <span className="font-semibold">{thread.author.displayName}</span>
                          <span>•</span>
                          <Calendar className="h-3 w-3" />
                          <span>{new Date(thread.createdAt).toLocaleDateString("pl-PL")}</span>
                        </div>
                      </CardContent>
                    </Card>
                  ))}

                  {/* Normal threads */}
                  {normalThreads.map((thread) => (
                    <Card key={thread.id} className="hover:border-muted-foreground/30 transition-all">
                      <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                        <Link to={`/forum/watek/${thread.id}`} className="flex-1 hover:text-primary transition-colors">
                          <CardTitle className="text-lg font-bold">
                            {thread.status === "DELETED_BY_AUTHOR" ? "Wątek usunięty przez autora" : thread.title}
                          </CardTitle>
                        </Link>
                        {thread.lockedAt && <Lock className="h-4 w-4 text-muted-foreground shrink-0 ml-2" />}
                      </CardHeader>
                      <CardContent>
                        <div className="flex items-center gap-2 text-xs text-muted-foreground mt-2">
                          <Avatar className="h-6 w-6">
                            <AvatarFallback className="bg-primary/10 text-primary text-[10px] font-bold">
                              {thread.author.initials}
                            </AvatarFallback>
                          </Avatar>
                          <span className="font-semibold">{thread.author.displayName}</span>
                          <span>•</span>
                          <Calendar className="h-3 w-3" />
                          <span>{new Date(thread.createdAt).toLocaleDateString("pl-PL")}</span>
                        </div>
                      </CardContent>
                    </Card>
                  ))}
                </div>
              )}
            </div>
          )}
        </div>
      </PageContainer>
    </AppShell>
  )
}
