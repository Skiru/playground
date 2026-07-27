import { redirect, Link } from "react-router"
import { fetchSession } from "../../lib/api-session.server"
import { hardenedFetch } from "../../lib/hardened-fetch.server"
import type { Route } from "./+types/visits"
import { AppShell } from "../../components/layout/AppShell"
import { AccountLayout } from "~/components/account/AccountLayout"
import { AccountEmptyState, AccountErrorState } from "~/components/account/AccountPageState"
import { Card, CardContent } from "~/components/ui/card"
import { Button } from "~/components/ui/button"
import { Input } from "~/components/ui/input"
import { Label } from "~/components/ui/label"
import { Badge } from "~/components/ui/badge"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "~/components/ui/dialog"
import { Trash2, Edit2, Calendar, MessageSquare, AlertCircle, ShieldAlert } from "lucide-react"
import { toast } from "sonner"
import * as React from "react"

interface VisitItem {
  id: string
  visitedOn: string
  note?: string | null
  place?: {
    published?: boolean
    name?: string
    slug?: string
    city?: string
    shortDescription?: string
    category?: string
    categories?: Array<{ slug?: string }>
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

  const visitsRes = await hardenedFetch(request, `/api/v1/me/visits?page=${page}`)

  if (!visitsRes.ok) {
    return {
      session: data,
      visitsList: null,
    }
  }

  const visitsList = await visitsRes.json()

  return {
    session: data,
    visitsList,
  }
}

export default function AccountVisits({ loaderData }: Route.ComponentProps) {
  const { session, visitsList } = loaderData
  const [items, setItems] = React.useState<VisitItem[]>(visitsList?.items || [])
  
  // Edit state
  const [editingVisit, setEditingVisit] = React.useState<VisitItem | null>(null)
  const [editDate, setEditDate] = React.useState("")
  const [editNote, setEditNote] = React.useState("")
  const [isEditingOpen, setIsEditingOpen] = React.useState(false)

  // Delete state
  const [deletingVisitId, setDeletingVisitId] = React.useState<string | null>(null)
  const [isDeleteOpen, setIsDeleteOpen] = React.useState(false)

  const handleEditClick = (item: VisitItem) => {
    setEditingVisit(item)
    setEditDate(item.visitedOn)
    setEditNote(item.note || "")
    setIsEditingOpen(true)
  }

  const handleSaveEdit = async () => {
    if (!editingVisit) return

    if (!editDate) {
      toast.error("Wybierz datę wizyty.")
      return
    }

    if (editNote && editNote.length > 1000) {
      toast.error("Notatka nie może przekraczać 1000 znaków.")
      return
    }

    try {
      const res = await fetch(`/resources/visits/${editingVisit.id}`, {
        method: "PATCH",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-Token": session.csrfToken || "",
        },
        body: JSON.stringify({ visitedOn: editDate, note: editNote }),
      })

      const data = await res.json()

      if (res.ok) {
        setItems((prev) =>
          prev.map((item) =>
            item.id === editingVisit.id
              ? { ...item, visitedOn: data.visitedOn, note: data.note }
              : item
          )
        )
        setIsEditingOpen(false)
        toast.success("Wizyta zaktualizowana pomyślnie!")
      } else {
        toast.error(data.detail || "Nie udało się zaktualizować wizyty.")
      }
    } catch {
      toast.error("Wystąpił błąd sieci.")
    }
  }

  const handleDeleteClick = (visitId: string) => {
    setDeletingVisitId(visitId)
    setIsDeleteOpen(true)
  }

  const handleConfirmDelete = async () => {
    if (!deletingVisitId) return

    try {
      const res = await fetch(`/resources/visits/${deletingVisitId}`, {
        method: "DELETE",
        headers: {
          "X-CSRF-Token": session.csrfToken || "",
        },
      })

      if (res.ok) {
        setItems((prev) => prev.filter((item) => item.id !== deletingVisitId))
        setIsDeleteOpen(false)
        toast.info("Wizyta została usunięta z historii.")
      } else {
        toast.error("Nie udało się usunąć wizyty.")
      }
    } catch {
      toast.error("Wystąpił błąd sieci.")
    }
  }

  const pagination = visitsList?.pagination || { page: 1, totalItems: 0, totalPages: 1 }
  const formatDate = (date: string) => {
    const parsed = new Date(`${date}T12:00:00`)
    return Number.isNaN(parsed.getTime()) ? "Brak daty" : new Intl.DateTimeFormat("pl-PL", { day: "numeric", month: "long", year: "numeric" }).format(parsed)
  }

  return (
    <AppShell>
      <AccountLayout>
        <div className="flex flex-col gap-6">
          <div className="border-b border-border pb-5">
            <div>
              <h1 className="font-serif text-3xl font-semibold tracking-tight text-foreground sm:text-4xl">Historia wizyt</h1>
              <p className="mt-2 text-sm text-muted-foreground">
                Zapisana historia miejsc, które wspólnie odwiedziliście.
              </p>
            </div>
          </div>

          {visitsList === null ? <AccountErrorState /> : items.length > 0 ? (
            <div className="flex flex-col gap-4">
              <p className="text-sm font-medium text-muted-foreground" aria-live="polite">{pagination.totalItems} {pagination.totalItems === 1 ? "zapisana wizyta" : "zapisanych wizyt"}</p>
              {items.map((item) => {
                const place = item.place || {}
                const isPublished = place.published !== false

                return (
                  <Card key={item.id} className={`bg-card border shadow-2xs hover:shadow-sm transition-all scroll-mt-20 ${!isPublished ? "opacity-95 border-amber-200/60 bg-amber-50/10" : ""}`}>
                    <CardContent className="p-5 flex flex-col gap-4">
                      <div className="flex items-start justify-between gap-4">
                        <div>
                          <p className="font-mono text-3xs text-muted-foreground uppercase tracking-wider flex items-center gap-1">
                            {place.city || "Brak danych"}
                            {!isPublished && (
                              <span className="text-amber-600 font-bold uppercase flex items-center gap-0.5">
                                <ShieldAlert className="size-3" />
                                Niedostępne
                              </span>
                            )}
                          </p>
                          <h2 className="font-serif text-lg font-bold text-foreground hover:text-primary transition-colors">
                            {isPublished && place.slug ? (
                              <Link to={`/miejsca/${place.slug}`}>{place.name || "Bez nazwy"}</Link>
                            ) : (
                              <span className="text-muted-foreground line-through">
                                {place.name || "Bez nazwy"}
                              </span>
                            )}
                          </h2>
                        </div>
                        <div className="flex items-center gap-1.5">
                          <Button
                            variant="ghost"
                            size="icon"
                            className="text-muted-foreground hover:text-primary hover:bg-primary/10 size-8 rounded-full"
                            onClick={() => handleEditClick(item)}
                            aria-label="Edytuj wizytę"
                          >
                            <Edit2 className="size-4" />
                          </Button>
                          <Button
                            variant="ghost"
                            size="icon"
                            className="text-muted-foreground hover:text-destructive hover:bg-destructive/10 size-8 rounded-full"
                            onClick={() => handleDeleteClick(item.id)}
                            aria-label="Usuń wizytę"
                          >
                            <Trash2 className="size-4" />
                          </Button>
                        </div>
                      </div>

                      <div className="flex items-center justify-between gap-4">
                        <div className="flex items-center gap-1.5 text-xs text-muted-foreground font-mono">
                          <Calendar className="size-3.5 text-primary" />
                          <span>Data wizyty: {formatDate(item.visitedOn)}</span>
                        </div>
                        <div className="flex items-center gap-1">
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
                      </div>

                      {item.note && (
                        <div className="bg-muted/40 rounded-lg p-3 border text-xs text-muted-foreground flex gap-2">
                          <MessageSquare className="size-4 text-primary flex-shrink-0 mt-0.5" />
                          <p className="leading-relaxed italic whitespace-pre-line">{item.note}</p>
                        </div>
                      )}
                    </CardContent>
                  </Card>
                )
              })}

              {/* Simple Pagination Footer */}
              {pagination.totalPages > 1 && (
                <div className="flex justify-center items-center gap-2 mt-6">
                  {pagination.page > 1 && (
                    <Button variant="outline" size="sm" asChild>
                      <Link to={`/konto/odwiedzone?page=${pagination.page - 1}`}>Poprzednia</Link>
                    </Button>
                  )}
                  <span className="text-xs text-muted-foreground font-mono">
                    Strona {pagination.page} z {pagination.totalPages}
                  </span>
                  {pagination.page < pagination.totalPages && (
                    <Button variant="outline" size="sm" asChild>
                      <Link to={`/konto/odwiedzone?page=${pagination.page + 1}`}>Następna</Link>
                    </Button>
                  )}
                </div>
              )}
            </div>
          ) : (
            <AccountEmptyState title="Tu pojawi się historia Waszych wizyt" description="Po zapisaniu wizyty przy miejscu zobaczysz ją tutaj w porządku chronologicznym." />
          )}
        </div>
      </AccountLayout>

      {/* Editing Dialog */}
      <Dialog open={isEditingOpen} onOpenChange={setIsEditingOpen}>
        <DialogContent className="sm:max-w-[420px] p-6">
          <DialogHeader>
            <DialogTitle className="font-serif text-xl font-bold">Edytuj wizytę</DialogTitle>
            <DialogDescription className="text-xs text-muted-foreground">
              Zaktualizuj datę wizyty i notatki z pobytu.
            </DialogDescription>
          </DialogHeader>

          <div className="grid gap-4 py-4">
            <div className="grid gap-1.5">
              <Label htmlFor="visitedOn" className="text-xs font-bold text-muted-foreground uppercase font-mono">
                Data wizyty
              </Label>
              <Input
                id="visitedOn"
                type="date"
                value={editDate}
                onChange={(e) => setEditDate(e.target.value)}
                max={new Date().toISOString().split("T")[0]}
              />
            </div>
            <div className="grid gap-1.5">
              <Label htmlFor="note" className="text-xs font-bold text-muted-foreground uppercase font-mono flex items-center justify-between">
                <span>Prywatna notatka</span>
                <span className="text-3xs text-muted-foreground normal-case font-normal">
                  Maksymalnie 1000 znaków
                </span>
              </Label>
              <textarea
                id="note"
                rows={4}
                value={editNote}
                onChange={(e) => setEditNote(e.target.value)}
                placeholder="Wpisz swoje wspomnienia, np. co najbardziej podobało się dzieciom..."
                className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-colors placeholder:text-muted-foreground/70 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
              />
            </div>
          </div>

          <div className="flex justify-end gap-2 border-t pt-4">
            <Button variant="outline" size="sm" className="font-bold" onClick={() => setIsEditingOpen(false)}>
              Anuluj
            </Button>
            <Button size="sm" className="font-bold bg-primary hover:bg-primary/95 text-white" onClick={handleSaveEdit}>
              Zapisz zmiany
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Deleting Dialog (AlertDialog mockup via Dialog) */}
      <Dialog open={isDeleteOpen} onOpenChange={setIsDeleteOpen}>
        <DialogContent className="sm:max-w-[360px] p-6 text-center">
          <DialogHeader className="text-center items-center">
            <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-destructive/10 text-destructive mb-4">
              <AlertCircle className="h-6 w-6" />
            </div>
            <DialogTitle className="font-serif text-lg font-bold">Czy na pewno chcesz usunąć tę wizytę?</DialogTitle>
            <DialogDescription className="text-xs text-muted-foreground">
              Ta operacja jest nieodwracalna. Notatka i zapis wizyty zostaną trwale usunięte z historii.
            </DialogDescription>
          </DialogHeader>

          <div className="flex gap-2 w-full mt-6 border-t pt-4">
            <Button variant="outline" className="flex-1 font-bold" onClick={() => setIsDeleteOpen(false)}>
              Anuluj
            </Button>
            <Button variant="destructive" className="flex-1 font-bold bg-destructive hover:bg-destructive/90 text-white" onClick={handleConfirmDelete}>
              Usuń trwale
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </AppShell>
  )
}
