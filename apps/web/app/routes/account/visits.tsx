import { redirect, Link } from "react-router"
import { fetchSession } from "../../lib/api-session.server"
import type { Route } from "./+types/visits"
import { AppShell } from "../../components/layout/AppShell"
import { PageContainer } from "../../components/layout/PageContainer"
import { Card, CardContent } from "~/components/ui/card"
import { Button } from "~/components/ui/button"
import { Input } from "~/components/ui/input"
import { Label } from "~/components/ui/label"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "~/components/ui/dialog"
import { Compass, Trash2, Edit2, ArrowLeft, Calendar, MessageSquare, AlertCircle } from "lucide-react"
import { toast } from "sonner"
import * as React from "react"

const baseUrl = process.env.API_BASE_URL ?? "http://api"

interface VisitDetail {
  id: string
  placeId: string
  visitedOn: string
  note: string | null
  createdAt: string
  updatedAt: string
  placeName: string
  placeSlug: string
  city: string
}

interface RawVisitItem {
  id: string
  placeId: string
  visitedOn: string
  note: string | null
  createdAt: string
  updatedAt: string
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

  const visitsRes = await fetch(`${baseUrl}/api/v1/me/visits?page=${page}`, {
    headers: forwardHeaders,
  })

  if (!visitsRes.ok) {
    return { session: data, visitsList: { items: [], pagination: { page: 1, pageSize: 20, totalItems: 0, totalPages: 1 } }, places: [] }
  }

  const visitsList = await visitsRes.json()

  // Fetch place details for each visit
  const places = await Promise.all(
    visitsList.items.map(async (visit: RawVisitItem) => {
      try {
        const pRes = await fetch(`${baseUrl}/api/v1/places/${visit.placeId}`, {
          headers: forwardHeaders,
        })
        if (pRes.ok) {
          const place = await pRes.json()
          return {
            ...visit,
            placeName: place.name,
            placeSlug: place.slug,
            city: place.city_name,
          } as VisitDetail
        }
      } catch {
        // Ignored
      }
      return {
        ...visit,
        placeName: "Nieznane miejsce",
        placeSlug: "",
        city: "",
      } as VisitDetail
    })
  )

  return {
    session: data,
    visitsList,
    visits: places,
  }
}

export default function AccountVisits({ loaderData }: Route.ComponentProps) {
  const { session, visits: initialVisits } = loaderData
  const [visits, setVisits] = React.useState<VisitDetail[]>(initialVisits || [])
  
  // Edit state
  const [editingVisit, setEditingVisit] = React.useState<VisitDetail | null>(null)
  const [editDate, setEditDate] = React.useState("")
  const [editNote, setEditNote] = React.useState("")
  const [isEditingOpen, setIsEditingOpen] = React.useState(false)

  // Delete state
  const [deletingVisitId, setDeletingVisitId] = React.useState<string | null>(null)
  const [isDeleteOpen, setIsDeleteOpen] = React.useState(false)

  const handleEditClick = (visit: VisitDetail) => {
    setEditingVisit(visit)
    setEditDate(visit.visitedOn)
    setEditNote(visit.note || "")
    setIsEditingOpen(true)
  }

  const handleSaveEdit = async () => {
    if (!editingVisit) return

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
        setVisits((prev) =>
          prev.map((v) =>
            v.id === editingVisit.id
              ? { ...v, visitedOn: data.visitedOn, note: data.note }
              : v
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
        setVisits((prev) => prev.filter((v) => v.id !== deletingVisitId))
        setIsDeleteOpen(false)
        toast.info("Wizyta została usunięta z historii.")
      } else {
        toast.error("Nie udało się usunąć wizyty.")
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
            <span className="text-foreground font-semibold">Historia wizyt</span>
          </nav>

          <div className="flex items-center justify-between border-b pb-4">
            <div>
              <h1 className="font-serif text-3xl font-medium text-foreground">
                Historia wizyt
              </h1>
              <p className="text-sm text-muted-foreground mt-1">
                Zapisana historia miejsc, które wspólnie odwiedziliście.
              </p>
            </div>
            <Button variant="outline" size="sm" asChild className="font-semibold text-xs">
              <Link to="/konto" className="flex items-center gap-1.5">
                <ArrowLeft className="size-3.5" />
                Powrót
              </Link>
            </Button>
          </div>

          {visits.length > 0 ? (
            <div className="flex flex-col gap-4">
              {visits.map((visit) => (
                <Card key={visit.id} className="bg-card border shadow-2xs hover:shadow-sm transition-all">
                  <CardContent className="p-5 flex flex-col gap-4">
                    <div className="flex items-start justify-between gap-4">
                      <div>
                        <p className="font-mono text-3xs text-muted-foreground uppercase tracking-wider">
                          {visit.city}
                        </p>
                        <h2 className="font-serif text-lg font-bold text-foreground hover:text-primary transition-colors">
                          {visit.placeSlug ? (
                            <Link to={`/miejsca/${visit.placeSlug}`}>{visit.placeName}</Link>
                          ) : (
                            visit.placeName
                          )}
                        </h2>
                      </div>
                      <div className="flex items-center gap-1.5">
                        <Button
                          variant="ghost"
                          size="icon"
                          className="text-muted-foreground hover:text-primary hover:bg-primary/10 size-8 rounded-full"
                          onClick={() => handleEditClick(visit)}
                          aria-label="Edytuj wizytę"
                        >
                          <Edit2 className="size-4" />
                        </Button>
                        <Button
                          variant="ghost"
                          size="icon"
                          className="text-muted-foreground hover:text-destructive hover:bg-destructive/10 size-8 rounded-full"
                          onClick={() => handleDeleteClick(visit.id)}
                          aria-label="Usuń wizytę"
                        >
                          <Trash2 className="size-4" />
                        </Button>
                      </div>
                    </div>

                    <div className="flex items-center gap-1.5 text-xs text-muted-foreground font-mono">
                      <Calendar className="size-3.5 text-primary" />
                      <span>Data wizyty: {visit.visitedOn}</span>
                    </div>

                    {visit.note && (
                      <div className="bg-muted/40 rounded-lg p-3 border text-xs text-muted-foreground flex gap-2">
                        <MessageSquare className="size-4 text-primary flex-shrink-0 mt-0.5" />
                        <p className="leading-relaxed italic whitespace-pre-line">{visit.note}</p>
                      </div>
                    )}
                  </CardContent>
                </Card>
              ))}
            </div>
          ) : (
            <Card className="border-dashed p-12 text-center bg-muted/20">
              <CardContent className="flex flex-col items-center justify-center p-0">
                <Compass className="size-12 text-muted-foreground/60 mb-4" />
                <p className="text-base text-muted-foreground max-w-sm mb-4">
                  Nie masz jeszcze żadnych zapisanych wizyt.
                </p>
                <Button variant="outline" size="sm" asChild>
                  <Link to="/miejsca">Odkrywaj katalog</Link>
                </Button>
              </CardContent>
            </Card>
          )}
        </div>
      </PageContainer>

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
              />
            </div>
            <div className="grid gap-1.5">
              <Label htmlFor="note" className="text-xs font-bold text-muted-foreground uppercase font-mono">
                Prywatna notatka
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
