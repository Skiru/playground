import * as React from "react"
import { getCommunityFeed } from "@family-places/api-client"
import { AppShell } from "~/components/layout/AppShell"
import { PageContainer } from "~/components/layout/PageContainer"
import { Card, CardContent, CardHeader, CardTitle } from "~/components/ui/card"
import { Button } from "~/components/ui/button"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "~/components/ui/select"
import { Avatar, AvatarFallback } from "~/components/ui/avatar"
import { MessageSquare, Calendar, Filter, Star } from "lucide-react"

interface FeedItem {
  type: string
  id: string
  activityAt: string
  author: { id: string; displayName: string; initials: string }
  title?: string | null
  excerpt?: string
  sourceId: string
  parentSourceId?: string | null
}

export default function CommunityFeedPage() {
  const [items, setItems] = React.useState<FeedItem[]>([])
  const [loading, setLoading] = React.useState(true)
  const [loadingMore, setLoadingMore] = React.useState(false)
  const [nextCursor, setNextCursor] = React.useState<string | null>(null)
  const [hasNextPage, setHasNextPage] = React.useState(false)
  const [typeFilter, setTypeFilter] = React.useState<string>("ALL")
  const [error, setError] = React.useState<string | null>(null)

  const loadFeed = React.useCallback(async (cursor: string | null = null, append = false) => {
    if (cursor) {
      setLoadingMore(true)
    } else {
      setLoading(true)
    }
    setError(null)

    try {
      const res = await getCommunityFeed({
        query: {
          limit: 10,
          cursor: cursor || undefined,
          type: typeFilter === "ALL" ? undefined : typeFilter,
        },
      })

      if (res.data) {
        const fetchedItems = (res.data.items || []) as FeedItem[]
        setItems((prev) => (append ? [...prev, ...fetchedItems] : fetchedItems))
        setNextCursor(res.data.pagination?.nextCursor || null)
        setHasNextPage(res.data.pagination?.hasNextPage || false)
      } else {
        setError("Wystąpił błąd podczas ładowania społeczności.")
      }
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Wystąpił nieoczekiwany błąd.")
    } finally {
      setLoading(false)
      setLoadingMore(false)
    }
  }, [typeFilter])

  React.useEffect(() => {
    loadFeed(null, false)
  }, [loadFeed])

  const getTypeLabel = (type: string) => {
    switch (type) {
      case "forum_thread":
        return "Nowy wątek"
      case "forum_post":
        return "Odpowiedź na forum"
      case "review":
        return "Opinia o miejscu"
      case "place_comment":
        return "Komentarz w dyskusji"
      default:
        return "Aktywność"
    }
  }

  const getTypeColor = (type: string) => {
    switch (type) {
      case "forum_thread":
        return "bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300"
      case "forum_post":
        return "bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300"
      case "review":
        return "bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300"
      case "place_comment":
        return "bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300"
      default:
        return "bg-muted text-muted-foreground"
    }
  }

  return (
    <AppShell>
      <PageContainer>
        <div className="mx-auto max-w-4xl py-8">
          <div className="flex flex-col gap-6 md:flex-row md:items-center md:justify-between mb-8">
            <div>
              <h1 className="text-3xl font-extrabold tracking-tight">Aktywność społeczności</h1>
              <p className="text-muted-foreground mt-1">Zobacz co słychać u innych rodzin w okolicy.</p>
            </div>

            {/* Filter controls */}
            <div className="flex items-center gap-3">
              <Filter className="h-4 w-4 text-muted-foreground" />
              <Select value={typeFilter} onValueChange={(val) => setTypeFilter(val)}>
                <SelectTrigger className="w-[200px]">
                  <SelectValue placeholder="Filtruj aktywności" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="ALL">Wszystkie aktywności</SelectItem>
                  <SelectItem value="forum_thread">Nowe wątki</SelectItem>
                  <SelectItem value="forum_post">Odpowiedzi na forum</SelectItem>
                  <SelectItem value="review">Opinie o miejscach</SelectItem>
                  <SelectItem value="place_comment">Komentarze</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>

          {error && (
            <div className="bg-destructive/10 text-destructive p-4 rounded-lg mb-6 text-sm" role="alert">
              {error}
            </div>
          )}

          {loading ? (
            <div className="space-y-4">
              {[1, 2, 3].map((n) => (
                <Card key={n} className="animate-pulse">
                  <CardHeader className="flex flex-row items-center gap-4 space-y-0">
                    <div className="h-10 w-10 rounded-full bg-muted" />
                    <div className="space-y-2 flex-1">
                      <div className="h-4 w-1/4 bg-muted rounded" />
                      <div className="h-3 w-1/6 bg-muted rounded" />
                    </div>
                  </CardHeader>
                  <CardContent className="space-y-2">
                    <div className="h-4 w-full bg-muted rounded" />
                    <div className="h-4 w-5/6 bg-muted rounded" />
                  </CardContent>
                </Card>
              ))}
            </div>
          ) : items.length === 0 ? (
            <Card className="text-center py-12">
              <CardContent className="space-y-4">
                <MessageSquare className="h-12 w-12 text-muted-foreground mx-auto" />
                <h3 className="text-lg font-semibold">Brak nowych aktywności</h3>
                <p className="text-muted-foreground max-w-sm mx-auto">
                  Nie ma jeszcze żadnych opublikowanych aktywności spełniających te kryteria.
                </p>
              </CardContent>
            </Card>
          ) : (
            <div className="space-y-6">
              {items.map((item) => (
                <Card key={item.id} className="overflow-hidden border-muted hover:border-muted-foreground/30 transition-all">
                  <CardHeader className="flex flex-row items-start justify-between space-y-0 pb-3">
                    <div className="flex items-center gap-3">
                      <Avatar className="h-10 w-10">
                        <AvatarFallback className="bg-primary/10 text-primary font-bold">
                          {item.author.initials}
                        </AvatarFallback>
                      </Avatar>
                      <div>
                        <div className="text-sm font-semibold hover:underline cursor-pointer">
                          {item.author.displayName}
                        </div>
                        <div className="flex items-center gap-1.5 text-xs text-muted-foreground mt-0.5">
                          <Calendar className="h-3 w-3" />
                          <span>{new Date(item.activityAt).toLocaleString("pl-PL")}</span>
                        </div>
                      </div>
                    </div>
                    <span className={`text-xs px-2.5 py-1 rounded-full font-semibold ${getTypeColor(item.type)}`}>
                      {getTypeLabel(item.type)}
                    </span>
                  </CardHeader>
                  <CardContent className="pt-0">
                    {item.title && (
                      <h3 className="text-base font-bold text-foreground mb-1.5 hover:text-primary transition-colors">
                        {item.title}
                      </h3>
                    )}
                    {item.excerpt && (
                      <p className="text-sm text-muted-foreground leading-relaxed whitespace-pre-line">
                        {item.excerpt}
                      </p>
                    )}
                  </CardContent>
                </Card>
              ))}

              {hasNextPage && (
                <div className="flex justify-center pt-4">
                  <Button
                    onClick={() => loadFeed(nextCursor, true)}
                    disabled={loadingMore}
                    variant="outline"
                    className="min-w-[200px]"
                  >
                    {loadingMore ? "Ładowanie..." : "Wczytaj więcej"}
                  </Button>
                </div>
              )}
            </div>
          )}
        </div>
      </PageContainer>
    </AppShell>
  )
}
